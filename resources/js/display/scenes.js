/*
 * Scenele ecranelor. Fiecare scenă întoarce { el, duration, kind, update?, tick?, dispose, ready? }.
 * Textul vine întotdeauna prin textContent; doar QR-ul și logo-ul sunt SVG-uri locale.
 */
import { el, img, svgNode, formatDuration } from './util.js';

const NEUTRAL = '/media/avatars/neutral.svg';

function baseScene(kind, node, duration) {
  const timers = new Set();
  return {
    kind, el: node, duration, timers,
    every(ms, fn) { const id = setInterval(fn, ms); timers.add(id); return id; },
    after(ms, fn) { const id = setTimeout(() => { timers.delete(id); fn(); }, ms); timers.add(id); return id; },
    dispose() { timers.forEach((id) => { clearInterval(id); clearTimeout(id); }); timers.clear(); },
  };
}

function qrBlock(brand, compact = false) {
  if (!brand?.qr) return null;
  return el('div', { class: compact ? 'game__qr' : 'qr' }, compact
    ? [el('div', { class: 'qr__code' }, svgNode(brand.qr)), el('span', { text: brand.qr_label })]
    : [el('div', { class: 'qr__code' }, svgNode(brand.qr)), el('div', { class: 'qr__text' }, [el('strong', { text: brand.qr_text }), el('span', { text: brand.qr_label })])]);
}

/* ———————————————————— Următoarea rundă (cronometru invers) ———————————————————— */

/** Cronometrul arată timpul până la ora programată; nu declară sesiunea „live” (o pornește doar operatorul). */
function countdownText(next, now) {
  const left = Math.round((Date.parse(next.at) - now) / 1000);
  if (left <= 0) return 'Începe acum';
  if (left >= 24 * 3600) return null;
  return formatDuration(left);
}

function nextRound(next) {
  return el('div', { class: 'next-round reveal', '--d': '0.25s' }, [
    el('small', { text: 'Următoarea rundă începe în' }),
    el('strong', { class: 'next-round__timer', 'data-countdown': '' }),
    el('span', { class: 'next-round__at', text: next.label }),
  ]);
}

function tickCountdown(root, next, now) {
  if (!next) return;
  const timer = root.querySelector('[data-countdown]');
  if (!timer) return;
  const text = countdownText(next, now);
  timer.textContent = text ?? next.label;
  timer.classList.toggle('is-now', text === 'Începe acum');
}

/* ———————————————————— LIVE ARENA ———————————————————— */

/** Aranjamentul cardurilor după numărul de participanți: textul nu se micșorează excesiv; peste 10 se paginează. */
function teamLayout(n) {
  if (n <= 2) return { cols: 2, av: 230, nick: 50 };
  if (n <= 3) return { cols: 3, av: 210, nick: 46 };
  if (n <= 4) return { cols: 4, av: 190, nick: 42 };
  if (n <= 6) return { cols: 3, av: 128, nick: 40 };
  if (n <= 8) return { cols: 4, av: 120, nick: 38 };
  return { cols: 5, av: 112, nick: 34 };
}

/** Nickname cu puncte de rupere după „_”, „.”, „-” (nu în mijlocul cuvintelor). */
function nickNodes(name) {
  const nodes = [];
  String(name).split(/(?<=[_.\-])/).forEach((part, i) => {
    if (i) nodes.push(document.createElement('wbr'));
    nodes.push(document.createTextNode(part));
  });
  return nodes;
}

function playerCard(p, i, reveal) {
  const node = el('div', { class: `card${reveal ? ' pop' : ''}`, '--d': reveal ? `${0.35 + i * 0.12}s` : null, 'data-key': p.k }, [
    p.place ? el('div', { class: 'card__place', 'data-place': String(p.place), text: String(p.place) }) : null,
    el('div', { class: 'ring card__avatar-wrap' }, img(p.avatar || NEUTRAL, { class: 'card__avatar', '--fd': `${(i % 5) * -1.3}s` })),
    el('div', { class: `card__name${p.alias ? ' is-alias' : ''}` }, nickNodes(p.name)),
    p.result ? el('div', { class: 'card__result', text: p.team ? `${p.team} · ${p.result}` : p.result }) : (p.level ? el('div', { class: 'card__level', text: p.level }) : null),
  ]);
  return node;
}

export function liveScene(ctx, { reveal = false } = {}) {
  const session = ctx.state.live?.session;
  if (!session) return idleScene(ctx);
  const title = el('div', { class: 'h-display live__title grad', text: session.game?.title || 'Aventura VR' });
  const pill = el('div', { class: 'live-pill' }, [el('span', { class: 'live-pill__dot' }), el('span', { 'data-pill': '', text: 'LIVE' })]);
  const count = el('span', { class: 'live__count' });
  const timerLabel = el('div', { class: 'timer__label' });
  const timerValue = el('div', { class: 'timer__value' });
  const bar = el('div', { class: 'progress__bar' });
  const team = el('div', { class: 'team' });
  const pageInfo = el('div', { class: 'team__page' });
  const nextChip = el('div', { class: 'next-chip', hidden: true }, [el('span', { text: 'Runda următoare' }), el('strong', { 'data-countdown': '' })]);
  let nextInfo = ctx.state.live?.next || null;

  const node = el('section', { class: 'scene' }, el('div', { class: 'safe live' }, [
    el('div', { class: 'live__head' }, [
      el('div', {}, [pill, el('div', { class: reveal ? 'reveal' : null, '--d': '0.1s' }, title), el('div', { class: 'live__meta' }, [count, el('span', { text: '·' }), el('span', { 'data-status': '' })])]),
      el('div', { class: 'timer' }, [timerLabel, timerValue, el('div', { class: 'progress' }, bar), nextChip]),
    ]),
    el('div', {}, [team, pageInfo]),
  ]));
  const scene = baseScene('live', node, null);
  let current = session;
  let page = 0;
  let renderedKey = '';

  function renderTeam(animate) {
    const players = current.players || [];
    const sorted = players.some((p) => p.place) ? [...players].sort((a, b) => (a.place || 99) - (b.place || 99)) : players;
    // peste 10 jucători: pagini egale (12 → 6 + 6), ca fiecare pagină să arate la fel de bine
    const pages = Math.max(1, Math.ceil(sorted.length / 10));
    const perPage = Math.ceil(sorted.length / pages) || 1;
    page %= pages;
    const visible = sorted.slice(page * perPage, page * perPage + perPage);
    const layout = teamLayout(perPage);
    team.dataset.cols = String(layout.cols);
    team.style.setProperty('--av', `${layout.av}px`);
    team.style.setProperty('--nick', `${layout.nick}px`);
    team.replaceChildren(...visible.map((p, i) => playerCard(p, i, animate)));
    pageInfo.textContent = pages > 1 ? `Echipa ${page + 1} / ${pages}` : '';
    count.textContent = `${current.count} ${current.count === 1 ? 'jucător' : 'jucători'}`;
  }

  function keyOf(s) { return JSON.stringify([s.id, s.players, s.status, s.planned_seconds]); }

  scene.tick = (now) => {
    const started = Date.parse(current.started_at);
    const pausedAt = current.paused_at ? Date.parse(current.paused_at) : null;
    const elapsed = Math.max(0, ((pausedAt ?? now) - started) / 1000 - (current.paused_seconds || 0));
    const planned = current.planned_seconds || 0;
    const remaining = planned - elapsed;
    const stale = ctx.isStale();
    pill.classList.toggle('is-paused', current.status === 'paused' && !stale);
    pill.classList.toggle('is-stale', stale);
    pill.querySelector('[data-pill]').textContent = stale ? 'RECONECTARE' : current.status === 'paused' ? 'PAUZĂ' : 'LIVE';
    node.querySelector('[data-status]').textContent = current.status === 'paused' ? 'Joc în pauză' : 'Joc în desfășurare';
    if (remaining >= 0) {
      timerLabel.textContent = 'Timp rămas (estimat)';
      timerValue.textContent = formatDuration(remaining);
    } else {
      timerLabel.textContent = 'Timp scurs';
      timerValue.textContent = formatDuration(elapsed);
    }
    bar.style.transform = `scaleX(${planned ? Math.min(1, elapsed / planned) : 0})`;
    nextChip.hidden = !nextInfo;
    if (nextInfo) {
      const text = countdownText(nextInfo, now);
      nextChip.querySelector('[data-countdown]').textContent = text && text !== 'Începe acum' ? `${nextInfo.time} · în ${text}` : nextInfo.label;
    }
  };

  scene.update = (state) => {
    const next = state.live?.session;
    if (!next || next.id !== current.id) return false; // altă sesiune: scenă nouă
    nextInfo = state.live?.next || null;
    const key = keyOf(next);
    current = next;
    if (key !== renderedKey) { renderedKey = key; renderTeam(false); }
    return true;
  };

  renderedKey = keyOf(current);
  renderTeam(reveal);
  scene.tick(ctx.clock.now());
  if ((current.players || []).length > 10) scene.every(9000, () => { page += 1; renderTeam(false); });
  ctx.setBackground(current.game?.image);
  return scene;
}

export function idleScene(ctx) {
  const next = ctx.state.live?.next;
  const games = ctx.state.games || [];
  const images = (ctx.state.assets || []).filter((a) => a.kind === 'image');
  const slides = (games.length ? games.map((g) => ({ image: g.image, title: g.title, sub: [g.min_age, g.players].filter(Boolean).join(' · ') }))
    : images.map((a) => ({ image: a.url, title: a.caption || '', sub: '' }))).slice(0, 10);
  const show = el('div', { class: 'showcase' }, slides.map((s, i) => el('div', { class: `showcase__slide${i === 0 ? ' is-on' : ''}` }, [
    img(s.image, {}, null), s.title ? el('div', { class: 'showcase__cap' }, [el('strong', { text: s.title }), s.sub ? el('span', { text: s.sub }) : null]) : null,
  ])));
  const left = el('div', {}, next
    ? [el('div', { class: 'eyebrow reveal', text: 'Live Arena' }), el('h1', { class: 'h-display idle__title grad reveal', '--d': '0.1s', text: 'Următoarea aventură începe curând' }),
      nextRound(next), qrBlock(ctx.state.brand)]
    : [el('div', { class: 'eyebrow reveal', text: 'Arena VR · Cluj-Napoca' }), el('h1', { class: 'h-display idle__title grad reveal', '--d': '0.1s', text: 'Intră în Another World' }),
      el('p', { class: 'empty__text reveal', '--d': '0.2s', style: { textAlign: 'left', marginTop: '30px' }, text: 'Jocuri VR multiplayer pentru prieteni, familie și echipe.' }), qrBlock(ctx.state.brand)]);
  const node = el('section', { class: 'scene' }, el('div', { class: 'safe idle' }, [left, slides.length ? show : el('div')]));
  const scene = baseScene('idle', node, null);
  scene.tick = (now) => tickCountdown(node, next, now);
  scene.tick(ctx.clock.now());
  let index = 0;
  if (slides.length > 1) {
    scene.every(7000, () => {
      const all = show.children;
      all[index].classList.remove('is-on');
      index = (index + 1) % all.length;
      all[index].classList.add('is-on');
    });
  }
  scene.update = (state) => !state.live?.session && JSON.stringify(state.live?.next) === JSON.stringify(next);
  ctx.setBackground(null);
  return scene;
}

export function introScene(ctx) {
  const session = ctx.state.live?.session;
  const node = el('section', { class: 'scene' }, el('div', { class: 'center intro' }, [
    el('div', { class: 'ring intro__portal' }),
    el('div', { class: 'intro__text' }, [
      el('div', { class: 'intro__kicker reveal', '--d': '0.6s', text: 'Live Arena' }),
      el('div', { class: 'h-display intro__title grad reveal', '--d': '0.9s', text: 'O nouă aventură începe' }),
      session?.game?.title ? el('div', { class: 'intro__game reveal', '--d': '1.4s', text: session.game.title }) : null,
    ]),
  ]));
  ctx.setBackground(session?.game?.image);
  return baseScene('intro', node, 5);
}

export function endedScene(ctx, ended) {
  const podium = ended.podium && ended.podium.length ? ended.podium : null;
  const crew = el('div', { class: 'ended__crew' }, (ended.players || []).map((p, i) => el('div', { class: `chip pop${p.alias ? ' is-alias' : ''}`, '--d': `${0.5 + i * 0.08}s` }, [
    img(p.avatar || NEUTRAL), el('span', { text: p.name }),
  ])));
  const head = el('div', { class: 'ended__head' }, [
    el('div', { class: 'eyebrow reveal', text: ended.game?.title || 'Aventura VR' }),
    el('div', { class: 'h-display ended__title grad reveal', '--d': '0.15s', text: 'Misiune încheiată' }),
    el('div', { class: 'ended__game reveal', '--d': '0.3s', text: podium ? 'Felicitări tuturor exploratorilor!' : `${(ended.players || []).length} exploratori s-au întors din altă lume` }),
  ]);
  const body = podium ? podiumNode(podium, { delay: 0.6, source: ended.results?.source }) : crew;
  const node = el('section', { class: 'scene' }, el('div', { class: 'safe', style: { display: 'grid', alignContent: 'center', gap: '50px' } }, [head, body]));
  ctx.setBackground(ended.game?.image);
  return baseScene('ended', node, podium ? 18 : 13);
}

/* ———————————————————— HALL OF LEGENDS ———————————————————— */

function podiumNode(rows, { delay = 0.3, source = null, unit = null } = {}) {
  // ordinea vizuală: 2 — 1 — 3; poziția afișată este cea reală (egalitățile păstrează aceeași poziție)
  const order = [rows[1], rows[0], rows[2]];
  const visualRank = [2, 1, 3];
  const cols = order.map((row, i) => {
    if (!row) return el('div');
    const value = row.result ?? (row.sessions !== undefined ? `${row.sessions} ${row.sessions === 1 ? 'aventură' : 'aventuri'}` : null);
    const members = row.members ? el('div', { class: 'ended__game', text: row.members.join(' · ') }) : null;
    return el('div', { class: 'podium__col pop', 'data-rank': String(visualRank[i]), '--d': `${delay + [0.5, 1.0, 0][i]}s` }, [
      row.avatar ? el('div', { class: 'ring podium__avatar' }, img(row.avatar)) : null,
      el('div', { class: 'podium__rank', text: `#${row.rank ?? row.place}` }),
      el('div', { class: 'podium__name', text: row.name }),
      members,
      value ? el('div', { class: 'podium__value', text: value }) : null,
      row.level ? el('div', { class: 'card__level', text: row.level }) : null,
      el('div', { class: 'podium__base' }),
    ]);
  });
  return el('div', {}, [
    el('div', { class: 'podium' }, cols),
    source ? el('div', { style: { textAlign: 'center' } }, el('span', { class: 'badge-source', text: source === 'sync' ? 'Rezultate sincronizate automat' : 'Rezultate introduse de echipa arenei' })) : null,
    unit,
  ]);
}

function legendRow(row, i) {
  return el('div', { class: 'row reveal', '--d': `${0.2 + i * 0.07}s`, 'data-key': row.k }, [
    el('div', { class: 'row__rank', text: String(row.rank) }),
    img(row.avatar, { class: 'row__avatar' }),
    el('div', { class: 'row__name' }, [document.createTextNode(row.name), row.level ? el('span', { class: 'row__level', text: row.level }) : null]),
    el('div', { class: 'row__count' }, [document.createTextNode(String(row.sessions)), el('small', { text: row.sessions === 1 ? 'aventură' : 'aventuri' })]),
  ]);
}

function emptyLegends(ctx) {
  return el('div', { class: 'empty' }, el('div', {}, [
    el('div', { class: 'h-display empty__title grad reveal', text: 'Primele legende se scriu acum' }),
    el('p', { class: 'empty__text reveal', '--d': '0.2s', text: 'Fiecare aventură finalizată în arenă te urcă în Hall of Legends. Cere-ți nickname-ul la recepție.' }),
    el('div', { style: { display: 'flex', justifyContent: 'center' } }, qrBlock(ctx.state.brand)),
  ]));
}

export function leaderboardScene(ctx) {
  const data = ctx.state.legends;
  const settings = ctx.state.settings || {};
  const top = Math.max(3, Math.min(20, settings.top_n || 10));
  const period = el('div', { class: 'period reveal', '--d': '0.2s', text: data?.period || 'Din toate timpurile' });
  const head = el('div', { class: 'legends__head' }, [
    el('div', {}, [el('div', { class: 'h-display legends__title grad reveal', text: ctx.state.screen?.title || 'Hall of Legends' }),
      el('div', { class: 'legends__metric reveal', '--d': '0.1s', text: data?.metric || 'Jucătorii cu cele mai multe aventuri finalizate' })]),
    period,
  ]);
  const podiumWrap = el('div', { class: 'legends__podium' });
  const listWrap = el('div', { class: 'legends__list' });
  const body = el('div', { class: 'legends__body' }, [podiumWrap, listWrap]);
  const node = el('section', { class: 'scene' }, el('div', { class: 'safe legends' }, [head, body]));
  const scene = baseScene('leaderboard', node, null);
  let rows = data?.rows || [];
  let podiumKey = '';
  let listKey = '';
  let page = 0;

  function pagesOf(list) {
    const rest = list.slice(3);
    const pages = [rest.slice(0, top - 3)];
    const extra = rest.slice(top - 3);
    for (let i = 0; i < extra.length && pages.length < 4; i += 7) pages.push(extra.slice(i, i + 7));
    return pages;
  }

  /** Podiumul se redesenează doar când se schimbă top 3; paginile listei nu repornesc animațiile podiumului. */
  function render(animate) {
    if (!rows.length) {
      podiumKey = 'empty';
      body.replaceChildren(emptyLegends(ctx));
      body.style.gridTemplateColumns = '1fr';
      return;
    }
    if (podiumKey === 'empty' || !body.contains(podiumWrap)) {
      body.replaceChildren(podiumWrap, listWrap);
      body.style.gridTemplateColumns = '';
    }
    const top3 = JSON.stringify(rows.slice(0, 3));
    if (top3 !== podiumKey) {
      podiumKey = top3;
      podiumWrap.replaceChildren(podiumNode(rows.slice(0, 3), { delay: animate ? 0.2 : 0 }));
    }
    const pages = pagesOf(rows);
    page %= pages.length;
    const key = JSON.stringify([page, pages[page]]);
    if (key === listKey) return;
    listKey = key;
    const more = pages.length > 1 ? el('div', { class: 'list__more', text: page === 0 ? `Urmează locurile ${3 + pages[0].length + 1}+` : `Pagina ${page + 1} / ${pages.length}` }) : null;
    listWrap.replaceChildren(el('div', { class: 'list' }, pages[page].map((r, i) => legendRow(r, i))), more || '');
  }

  scene.update = (state) => {
    period.textContent = state.legends?.period || 'Din toate timpurile';
    rows = state.legends?.rows || [];
    render(false);
    return true;
  };
  render(true);
  scene.every(Math.max(6, settings.page_seconds || 12) * 1000, () => {
    if (pagesOf(rows).length > 1) { page += 1; render(false); }
  });
  ctx.setBackground(null);
  return scene;
}

export function podiumScene(ctx) {
  const rows = ctx.state.legends?.rows || [];
  const node = el('section', { class: 'scene' }, el('div', { class: 'safe', style: { display: 'grid', alignContent: 'center', gap: '60px' } }, [
    el('div', { class: 'ended__head' }, [el('div', { class: 'eyebrow reveal', text: ctx.state.legends?.period || 'Din toate timpurile' }),
      el('div', { class: 'h-display ended__title grad reveal', '--d': '0.15s', text: 'Legendele arenei' })]),
    podiumNode(rows.slice(0, 3), { delay: 0.5 }),
  ]));
  ctx.setBackground(null);
  return baseScene('podium', node, null);
}

/* ———————————————————— Materiale și scene de legătură ———————————————————— */

export function imageScene(ctx, asset, duration) {
  const portrait = asset.orientation === 'portrait';
  const fit = portrait ? 'contain' : asset.fit;
  const picture = img(asset.url, { class: `media__img${fit === 'cover' ? ' is-kenburns' : ''}`, '--dur': `${duration + 1}s` }, null);
  const node = el('section', { class: 'scene' }, el('div', { class: 'media', 'data-fit': fit, '--shade': asset.caption ? '1' : '0' }, [
    fit === 'contain' ? el('div', { class: 'media__backdrop', style: { backgroundImage: `url("${asset.url}")` } }) : null,
    picture,
    asset.caption ? el('div', { class: 'media__caption', text: asset.caption }) : null,
  ]));
  const scene = baseScene('promo', node, duration);
  // așteaptă decodarea (fără cadre goale); un fișier defect sare la următorul material
  scene.ready = (picture.decode ? picture.decode() : Promise.resolve()).then(() => true, () => false);
  scene.hud = false;
  return scene;
}

export function videoScene(ctx, asset, duration, onDone) {
  const fit = asset.orientation === 'portrait' ? 'contain' : asset.fit;
  const video = el('video', { class: 'media__video', muted: true, playsinline: true, preload: 'auto', poster: asset.poster || null });
  video.muted = true;
  video.volume = Math.max(0, Math.min(1, (ctx.state.settings?.volume || 0) / 100));
  if ((ctx.state.settings?.volume || 0) > 0) video.muted = false;
  const node = el('section', { class: 'scene' }, el('div', { class: 'media', 'data-fit': fit, '--shade': asset.caption ? '1' : '0' }, [
    fit === 'contain' && asset.poster ? el('div', { class: 'media__backdrop', style: { backgroundImage: `url("${asset.poster}")` } }) : null,
    video,
    asset.caption ? el('div', { class: 'media__caption', text: asset.caption }) : null,
  ]));
  const scene = baseScene('video', node, null);
  scene.hud = false;
  let finished = false;
  const finish = (ok) => { if (!finished) { finished = true; onDone(ok); } };
  scene.ready = new Promise((resolve) => {
    const fail = () => resolve(false);
    video.addEventListener('playing', () => resolve(true), { once: true });
    video.addEventListener('error', fail, { once: true });
    scene.after(7000, fail); // nu pornește: trecem mai departe
    video.src = asset.url;
    video.play().catch(() => {
      // politica browserului poate bloca sunetul: reîncercăm mut
      video.muted = true;
      video.play().catch(fail);
    });
  });
  video.addEventListener('ended', () => finish(true));
  video.addEventListener('stalled', () => scene.after(8000, () => { if (video.readyState < 3) finish(false); }));
  video.addEventListener('error', () => finish(false));
  scene.after(Math.max(5, duration) * 1000, () => finish(true));
  const dispose = scene.dispose;
  scene.dispose = () => {
    dispose();
    // eliberează decodorul și memoria video
    video.pause();
    video.removeAttribute('src');
    video.load();
  };
  return scene;
}

export function gameScene(ctx, game, duration) {
  const picture = img(game.image, { class: 'game__img', '--dur': `${duration + 1}s` }, null);
  const facts = [game.min_age ? `Vârstă ${game.min_age}` : null, game.players ? `${game.players} jucători` : null, game.minutes].filter(Boolean);
  const node = el('section', { class: 'scene' }, el('div', { class: 'game' }, [
    picture,
    el('div', { class: 'game__copy' }, [
      el('div', { class: 'eyebrow reveal', text: 'Joacă la Another World Cluj' }),
      el('div', { class: 'h-display game__title grad reveal', '--d': '0.15s', text: game.title }),
      game.tagline ? el('div', { class: 'game__tag reveal', '--d': '0.3s', text: game.tagline }) : null,
      facts.length ? el('div', { class: 'facts reveal', '--d': '0.45s' }, facts.map((f) => el('span', { text: f }))) : null,
    ]),
    qrBlock(ctx.state.brand, true),
  ]));
  const scene = baseScene('game', node, duration);
  scene.ready = (picture.decode ? picture.decode() : Promise.resolve()).then(() => true, () => false);
  scene.hud = true;
  return scene;
}

export function logoScene(ctx, duration) {
  const logo = el('div', { class: 'logo-anim__logo' });
  const tpl = document.getElementById('logo-stacked');
  if (tpl) logo.append(tpl.content.cloneNode(true));
  logo.querySelector('svg')?.setAttribute('width', '100%');
  const node = el('section', { class: 'scene' }, el('div', { class: 'center' }, [
    el('div', { class: 'ring logo-anim__ring' }),
    el('div', {}, [logo, el('div', { class: 'logo-anim__tag', text: 'Arena VR multiplayer · Cluj-Napoca' })]),
  ]));
  ctx.setBackground(null);
  const scene = baseScene('logo', node, duration);
  scene.hud = false;
  return scene;
}

export function clockScene(ctx, duration) {
  const time = el('div', { class: 'clock__time grad' });
  const date = el('div', { class: 'clock__date' });
  const node = el('section', { class: 'scene' }, el('div', { class: 'center' }, el('div', {}, [time, date, el('div', { class: 'clock__place', text: 'Cluj-Napoca' })])));
  const scene = baseScene('clock', node, duration);
  scene.tick = (now) => { time.textContent = ctx.clock.time(now); date.textContent = ctx.clock.date(now); };
  scene.tick(ctx.clock.now());
  scene.hud = false;
  ctx.setBackground(null);
  return scene;
}

export function messageScene(ctx, text, duration) {
  const node = el('section', { class: 'scene' }, el('div', { class: 'center' }, el('div', {}, [
    el('div', { class: 'eyebrow reveal', text: 'Another World Cluj' }),
    el('div', { class: 'h-display message__text grad reveal', '--d': '0.15s', text }),
  ])));
  ctx.setBackground(null);
  return baseScene('message', node, duration);
}
