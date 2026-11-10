/*
 * Motorul de playlist. Alege secvența următoare și face tranziția fără pâlpâire.
 * Reguli:
 *  - conținutul principal (live / clasament) are o durată minimă; între apariții rulează materiale alese după pondere,
 *    fără aceeași reclamă de două ori la rând (când există alternative) și cu limită de reclame consecutive;
 *  - pe ecranul Live, în timpul jocului reclamele sunt scurte, lipsesc în ultimele minute și pot fi oprite („Live fără întreruperi”);
 *  - începutul și sfârșitul sesiunii au prioritate (prezentarea echipei, „Misiune încheiată”);
 *  - comenzile rapide din CRM au expirare: după ea, playlistul revine singur la automat;
 *  - un material defect (imagine sau video) se sare și se evită 10 minute.
 */
import * as S from './scenes.js';
import { weightedPick } from './util.js';

const PLAYER_DATA_SCENES = ['live', 'leaderboard', 'podium', 'ended', 'intro'];

export class Engine {
  constructor(ctx, container, hud) {
    this.ctx = ctx;
    this.container = container;
    this.hud = hud;
    this.current = null;
    this.endTimer = null;
    this.gen = 0;
    this.pending = [];
    this.lastWasMain = false;
    this.adsInRow = 0;
    this.lastFillerId = null;
    this.lastAsset = null;
    this.seqIndex = -1;
    this.failed = new Map();
    this.errors = 0;
  }

  get state() { return this.ctx.state; }

  /** Datele noi: actualizare pe loc (fără a reporni video-ul sau animațiile), plus evenimentele de sesiune. */
  onState() {
    if (this.current?.scene.update && !this.current.scene.update(this.state)) {
      // scena principală nu mai corespunde (ex. a pornit altă sesiune): o înlocuim, păstrând ritmul playlistului
      this.replaceMain();
    }
    if (this.current?.spec.type === 'asset' && !this.findAsset(this.current.spec.asset?.id) && !this.overrideActive('asset')) {
      this.next(); // materialul a fost dezactivat din CRM
    }
  }

  /** Programează o secvență prioritară și întrerupe imediat secvența curentă. */
  interrupt(specs) {
    this.pending = specs;
    this.next();
  }

  start() { this.next(); }

  next() {
    clearTimeout(this.endTimer);
    let spec = null;
    try {
      spec = this.decide();
    } catch {
      spec = { type: 'logo', duration: 5 };
    }
    this.show(spec);
  }

  replaceMain() {
    const spec = this.current?.spec;
    if (!spec) return this.next();
    const remaining = Math.max(8, (this.current.endsAt - Date.now()) / 1000);
    this.show({ ...spec, reveal: false, duration: remaining }, true);
  }

  async show(spec, keepTiming = false) {
    const gen = ++this.gen;
    clearTimeout(this.endTimer);
    let scene;
    try {
      scene = this.build(spec, gen);
    } catch {
      this.errors += 1;
      scene = S.logoScene(this.ctx, 5);
      spec = { type: 'logo', duration: 5 };
    }
    if (scene.ready) {
      const ok = await Promise.race([scene.ready, new Promise((r) => setTimeout(() => r(false), 8000))]);
      if (gen !== this.gen) { scene.dispose(); return; }
      if (!ok) {
        scene.dispose();
        this.errors += 1;
        if (spec.asset) this.failed.set(spec.asset.id, Date.now() + 10 * 60 * 1000);
        this.next();
        return;
      }
    }
    const previous = this.current;
    this.container.append(scene.el);
    requestAnimationFrame(() => requestAnimationFrame(() => scene.el.classList.add('is-in')));
    this.hud.classList.toggle('is-hidden', scene.hud === false);
    if (previous) {
      previous.scene.el.classList.remove('is-in');
      previous.scene.el.classList.add('is-out');
      setTimeout(() => { previous.scene.dispose(); previous.scene.el.remove(); }, 900);
    }
    const duration = spec.duration ?? scene.duration ?? 10;
    this.current = { spec, scene, endsAt: Date.now() + duration * 1000 };
    if (spec.type !== 'video') {
      this.endTimer = setTimeout(() => { if (gen === this.gen) this.next(); }, duration * 1000);
    }
    if (!keepTiming) this.lastWasMain = !!spec.main;
  }

  build(spec, gen) {
    const ctx = this.ctx;
    switch (spec.type) {
      case 'intro': return S.introScene(ctx);
      case 'ended': return S.endedScene(ctx, spec.data);
      case 'live': return S.liveScene(ctx, { reveal: spec.reveal });
      case 'leaderboard': return S.leaderboardScene(ctx);
      case 'podium': return S.podiumScene(ctx);
      case 'asset':
        if (spec.asset.kind === 'video') {
          return S.videoScene(ctx, spec.asset, spec.duration, (ok) => {
            if (gen !== this.gen) return;
            if (!ok) { this.failed.set(spec.asset.id, Date.now() + 10 * 60 * 1000); this.errors += 1; }
            this.next();
          });
        }
        return S.imageScene(ctx, spec.asset, spec.duration);
      case 'game': return S.gameScene(ctx, spec.game, spec.duration);
      case 'clock': return S.clockScene(ctx, spec.duration);
      case 'message': return S.messageScene(ctx, spec.message, spec.duration);
      default: return S.logoScene(ctx, spec.duration || 5);
    }
  }

  /* ——————————————— decizia ——————————————— */

  decide() {
    if (this.ctx.offline) return this.offlinePick();
    if (this.pending.length) return this.pending.shift();

    const playlist = this.state.playlist || { items: [], min_main: 45, max_ads: 2, mode: 'smart' };
    const main = this.mainSpec(playlist);
    const override = this.override();
    if (override) {
      const left = Math.max(5, (Date.parse(override.expires_at) - this.ctx.clock.now()) / 1000);
      if (override.command === 'live' && this.state.screen?.type === 'live_arena') return { type: 'live', main: true, duration: Math.min(60, left) };
      if (override.command === 'leaderboard' && this.state.legends) return { type: 'leaderboard', main: true, duration: Math.min(60, left) };
      if (override.command === 'asset') {
        const asset = this.findAsset(Number(override.asset_id));
        if (asset && !this.isFailed(asset)) return { type: 'asset', asset, duration: asset.kind === 'video' ? Math.min(600, Math.max(asset.duration, 30)) : Math.min(60, left) };
      }
      if (override.command === 'pause_ads') return main;
    }

    const live = this.liveRules();
    if (live.blockAds) return main;
    const maxAds = live.active ? 1 : playlist.max_ads;

    if (!this.current || this.current.spec.type === 'intro') return main; // la pornire și după prezentare: conținutul principal
    if (playlist.mode === 'sequential') return this.sequential(playlist, main, live);

    if (this.lastWasMain) {
      const filler = this.pickFiller(playlist, live);
      if (filler) { this.adsInRow = 1; return filler; }
      return main;
    }
    if (this.adsInRow < maxAds && Math.random() < 0.4) {
      const filler = this.pickFiller(playlist, live);
      if (filler) { this.adsInRow += 1; return filler; }
    }
    this.adsInRow = 0;
    return main;
  }

  mainSpec(playlist) {
    const item = playlist.items.find((i) => i.main) || { type: this.state.screen?.type === 'live_arena' ? 'live' : 'leaderboard', duration: 50 };
    return { type: item.type, main: true, duration: Math.max(item.duration || 50, playlist.min_main || 45) };
  }

  sequential(playlist, main, live) {
    const items = playlist.items;
    for (let step = 0; step < items.length; step++) {
      this.seqIndex = (this.seqIndex + 1) % items.length;
      const item = items[this.seqIndex];
      if (item.main) return main;
      const spec = this.specFor(item, live);
      if (spec) return spec;
    }
    return main;
  }

  pickFiller(playlist, live) {
    let candidates = playlist.items.filter((i) => !i.main && this.eligible(i, live));
    if (candidates.length > 1) {
      const others = candidates.filter((i) => i.id !== this.lastFillerId);
      if (others.length) candidates = others;
    }
    for (let tries = 0; tries < 4 && candidates.length; tries++) {
      const item = weightedPick(candidates);
      const spec = this.specFor(item, live);
      if (spec) { this.lastFillerId = item.id; return spec; }
      candidates = candidates.filter((c) => c !== item);
    }
    return null;
  }

  eligible(item, live) {
    if (live.active && ['leaderboard', 'podium'].includes(item.type)) return false;
    switch (item.type) {
      case 'promo': return this.pool('image', item).length > 0;
      case 'video': return this.pool('video', item).length > 0 && (!live.active || live.adCap >= 8);
      case 'game': return (this.state.games || []).length > 0;
      case 'leaderboard': return !!this.state.legends;
      case 'podium': return (this.state.legends?.rows || []).length >= 1;
      case 'message': return !!item.message;
      case 'live': return this.state.screen?.type === 'live_arena';
      default: return true;
    }
  }

  specFor(item, live) {
    const cap = (d) => (live.active ? Math.min(d, live.adCap) : d);
    switch (item.type) {
      case 'promo':
      case 'video': {
        const asset = this.pickAsset(item.type === 'video' ? 'video' : 'image', item);
        if (!asset) return null;
        const duration = item.type === 'video' ? cap(Math.min(asset.duration || item.duration, Math.max(item.duration, 5))) : cap(item.duration || asset.duration);
        return { type: 'asset', asset, duration };
      }
      case 'game': {
        const games = this.state.games || [];
        const game = (item.game_id && games.find((g) => g.id === item.game_id)) || weightedPick(games.filter((g) => g.id !== this.lastGameId).length ? games.filter((g) => g.id !== this.lastGameId) : games, () => 1);
        if (!game) return null;
        this.lastGameId = game.id;
        return { type: 'game', game, duration: cap(item.duration) };
      }
      case 'leaderboard': return { type: 'leaderboard', duration: item.duration };
      case 'podium': return { type: 'podium', duration: Math.max(10, item.duration) };
      case 'message': return { type: 'message', message: item.message, duration: cap(item.duration) };
      case 'clock': return { type: 'clock', duration: cap(item.duration) };
      case 'logo': return { type: 'logo', duration: cap(item.duration) };
      default: return null;
    }
  }

  pool(kind, item) {
    const assets = (this.state.assets || []).filter((a) => a.kind === kind && !this.isFailed(a));
    if (item?.asset_id) return assets.filter((a) => a.id === item.asset_id);
    return assets;
  }

  pickAsset(kind, item) {
    let pool = this.pool(kind, item);
    if (pool.length > 1 && this.lastAsset) pool = pool.filter((a) => a.id !== this.lastAsset);
    if (!pool.length) return null;
    const asset = weightedPick(pool);
    this.lastAsset = asset.id;
    return asset;
  }

  findAsset(id) { return (this.state.assets || []).find((a) => a.id === id) || null; }

  isFailed(asset) {
    const until = this.failed.get(asset.id);
    if (!until) return false;
    if (until < Date.now()) { this.failed.delete(asset.id); return false; }
    return true;
  }

  override() {
    const o = this.state.override;
    return o && Date.parse(o.expires_at) > this.ctx.clock.now() ? o : null;
  }

  overrideActive(command) { return this.override()?.command === command; }

  /** Regulile de reclamă în timpul jocului (doar pe ecranul Live, cât timp sesiunea rulează). */
  liveRules() {
    const settings = this.state.settings || {};
    const session = this.state.live?.session;
    if (!session || this.state.screen?.type !== 'live_arena') return { active: false, blockAds: false, adCap: Infinity };
    const now = this.ctx.clock.now();
    const pausedAt = session.paused_at ? Date.parse(session.paused_at) : null;
    const elapsed = ((pausedAt ?? now) - Date.parse(session.started_at)) / 1000 - (session.paused_seconds || 0);
    const remaining = (session.planned_seconds || 0) - elapsed;
    const quiet = remaining < (settings.live_quiet_minutes ?? 5) * 60;
    return {
      active: true,
      adCap: Math.max(4, settings.live_ad_seconds ?? 8),
      blockAds: !settings.live_ads || settings.live_uninterrupted || quiet || this.overrideActive('pause_ads'),
    };
  }

  /** Fără conexiune peste prag: doar conținut promoțional local, fără date despre jucători. */
  offlinePick() {
    const options = [];
    if ((this.state.assets || []).some((a) => a.kind === 'image' && !this.isFailed(a))) options.push({ item: { type: 'promo', duration: 8, weight: 5 }, weight: 5 });
    if ((this.state.games || []).length) options.push({ item: { type: 'game', duration: 10, weight: 4 }, weight: 4 });
    options.push({ item: { type: 'logo', duration: 6 }, weight: 2 }, { item: { type: 'clock', duration: 6 }, weight: 2 });
    const pick = weightedPick(options);
    return this.specFor(pick.item, { active: false }) || { type: 'logo', duration: 6 };
  }

  /** Scena curentă afișează date despre jucători (de oprit când datele devin prea vechi). */
  showsPlayerData() { return PLAYER_DATA_SCENES.includes(this.current?.spec.type); }

  tick(now) { this.current?.scene.tick?.(now); }
}
