/*
 * Playerul ecranelor din arenă (Live Arena / Hall of Legends).
 *  - activare cu cod de unică folosință; apoi credențiala dispozitivului (localStorage), trimisă ca Bearer;
 *  - date prin polling cu ETag, aplicate fără reîncărcarea paginii și fără a reporni video-ul curent;
 *  - fără conexiune: ultima stare rămâne scurt, cu indicator discret; „LIVE” devine „RECONECTARE”;
 *    după pragul configurat se trece pe conținut promoțional local, fără date despre jucători;
 *  - heartbeat la 60 s; reîncărcare la comanda din CRM și o dată pe zi, noaptea, pentru funcționare îndelungată.
 */
import { ServerClock } from './display/clock.js';
import { Sync } from './display/sync.js';
import { Engine } from './display/engine.js';
import { Particles } from './display/fx.js';
import { el, storage } from './display/util.js';

const cfg = JSON.parse(document.getElementById('display-config').textContent);
const stage = document.getElementById('stage');
const scenes = stage.querySelector('[data-scenes]');
const hud = stage.querySelector('[data-hud]');
const conn = stage.querySelector('[data-conn]');
const bgImage = stage.querySelector('[data-bg-image]');
const clock = new ServerClock();
const bootedAt = Date.now();
const TOKEN_KEY = `aw-display:token:${cfg.slug}`;
const SNAP_KEY = `aw-display:snapshot:${cfg.slug}`;
const SEEN_KEY = `aw-display:seen:${cfg.slug}`;

function fit() {
  const scale = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
  stage.style.transform = `translate(-50%, -50%) scale(${scale})`;
}
fit();
window.addEventListener('resize', fit);

/** Copia locală păstrează doar materialele promoționale și configurația, niciodată date despre jucători. */
function loadSnapshot() {
  try {
    const snap = JSON.parse(storage.get(SNAP_KEY) || 'null');
    if (snap && Date.now() - snap.savedAt < 14 * 24 * 3600 * 1000) return snap.state;
  } catch { /* copie coruptă: se ignoră */ }
  return null;
}
function saveSnapshot(state) {
  const { screen, settings, playlist, assets, games, brand } = state;
  storage.set(SNAP_KEY, JSON.stringify({ savedAt: Date.now(), state: { screen, settings, playlist, assets, games, brand, live: null, legends: null, override: null } }));
}

const fallbackState = {
  screen: { type: cfg.type, title: cfg.type === 'live_arena' ? 'Live Arena' : 'Hall of Legends' },
  settings: { show_clock: true, animation: 'full', poll_seconds: 8, offline_after_seconds: 120 },
  playlist: { mode: 'smart', min_main: 45, max_ads: 2, items: [] },
  assets: [], games: [], brand: null, live: null, legends: null, override: null,
};

let lastBackground = null;
const ctx = {
  state: loadSnapshot() || fallbackState,
  clock,
  offline: true, // până la primul răspuns de la server
  isStale: () => !sync || sync.age() > Math.max(25, (ctx.state.settings?.poll_seconds || 8) * 3),
  setBackground(url) {
    if (url === lastBackground) return;
    lastBackground = url;
    if (url) {
      bgImage.style.backgroundImage = `url("${url}")`;
      bgImage.classList.add('is-on');
    } else {
      bgImage.classList.remove('is-on');
    }
  },
};

const particles = new Particles(stage.querySelector('[data-particles]'));
const engine = new Engine(ctx, scenes, hud);
let sync = null;
let started = false;
let firstState = true;

function applyLook(settings) {
  const level = settings?.animation || 'full';
  document.body.classList.toggle('anim-reduced', level === 'reduced');
  document.body.classList.toggle('anim-minimal', level === 'minimal');
  particles.setEnabled(level !== 'minimal' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  stage.querySelector('.hud__right').style.visibility = settings?.show_clock === false ? 'hidden' : 'visible';
}

function readSeen() { try { return JSON.parse(sessionStorage.getItem(SEEN_KEY) || '{}'); } catch { return {}; } }
function writeSeen(seen) { try { sessionStorage.setItem(SEEN_KEY, JSON.stringify(seen)); } catch { /* ignorat */ } }

/** Începutul și sfârșitul unei sesiuni au prioritate pe ecranul Live. */
function detectEvents(state) {
  if (state.screen?.type !== 'live_arena') return;
  const now = clock.now();
  const seen = readSeen();
  const specs = [];
  const session = state.live?.session;
  if (session && seen.live !== session.id) {
    const startedAgo = (now - Date.parse(session.started_at)) / 1000;
    seen.live = session.id;
    if (state.settings?.intro !== false && startedAgo < 600 && !(firstState && startedAgo > 120)) {
      specs.push({ type: 'intro', duration: 5 }, { type: 'live', reveal: true, main: true, duration: Math.max(45, state.playlist?.min_main || 45) });
    }
  }
  const ended = state.live?.ended;
  if (ended && seen.ended !== ended.id) {
    seen.ended = ended.id;
    if ((now - Date.parse(ended.ended_at)) / 1000 < 180) specs.push({ type: 'ended', data: ended });
  }
  writeSeen(seen);
  if (specs.length && started) engine.interrupt(specs);
}

function handleReload(state) {
  if (!state.reload_at) return;
  const at = Date.parse(state.reload_at);
  let handled = null;
  try { handled = sessionStorage.getItem('aw-display:reload'); } catch { /* ignorat */ }
  if (at > clock.now() - 10 * 60 * 1000 && handled !== state.reload_at && at > bootedAt - 5000 + clock.offset) {
    try { sessionStorage.setItem('aw-display:reload', state.reload_at); } catch { /* ignorat */ }
    window.location.reload();
  }
}

function onState(state) {
  const wasOffline = ctx.offline;
  ctx.offline = false;
  handleReload(state);
  ctx.state = state;
  applyLook(state.settings);
  saveSnapshot(state);
  keepMedia(state);
  if (!started) {
    started = true;
    engine.start();
  } else {
    engine.onState();
    if (wasOffline) engine.next();
  }
  detectEvents(state);
  firstState = false;
}

/* ——— cache-ul media (service worker): păstrează doar materialele curente ——— */
function keepMedia(state) {
  const controller = navigator.serviceWorker?.controller;
  if (!controller) return;
  const urls = new Set(['/media/avatars/neutral.svg', '/media/brand/aw-logo-horizontal.svg']);
  (state.assets || []).forEach((a) => { if (a.kind === 'image') urls.add(a.url); if (a.poster) urls.add(a.poster); });
  (state.games || []).forEach((g) => urls.add(g.image));
  controller.postMessage({ type: 'keep', urls: [...urls].map((u) => new URL(u, location.origin).href) });
}

/* ——— ceas, conexiune, heartbeat, întreținere ——— */
const hudClock = stage.querySelector('[data-hud-clock]');
const hudDate = stage.querySelector('[data-hud-date]');
let errors = 0;
setInterval(() => {
  const now = clock.now();
  hudClock.textContent = clock.time(now);
  hudDate.textContent = clock.date(now);
  engine.tick(now);

  if (!sync) return;
  const age = sync.age();
  const stale = ctx.isStale();
  const offlineAfter = Math.max(30, ctx.state.settings?.offline_after_seconds || 120);
  conn.classList.toggle('is-on', stale);
  conn.classList.toggle('is-offline', age > offlineAfter);
  conn.textContent = age > offlineAfter ? 'Fără conexiune — afișăm conținut local' : 'Reconectare…';
  if (!ctx.offline && age > offlineAfter) {
    // datele despre jucători nu se mai prezintă ca actuale și nu se păstrează
    ctx.offline = true;
    ctx.state = { ...ctx.state, live: null, legends: null, override: null };
    if (engine.showsPlayerData()) engine.next();
  }

  // reîncărcare zilnică între 04:30 și 04:40 (memorie curată pentru funcționare continuă), doar cu conexiune
  const { h, m } = clock.localHM(now);
  if (h === 4 && m >= 30 && m < 40 && Date.now() - bootedAt > 6 * 3600 * 1000 && age < 60) window.location.reload();
}, 1000);

window.addEventListener('error', () => { errors += 1; });
window.addEventListener('unhandledrejection', () => { errors += 1; });

function heartbeat() {
  if (cfg.mode !== 'device' || !sync?.token) return;
  const body = {
    version: cfg.version,
    resolution: `${window.screen.width}x${window.screen.height}`,
    uptime: Math.round((Date.now() - bootedAt) / 1000),
    heap_mb: performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null,
    scene: engine.current?.spec.type || null,
    offline: ctx.offline,
    errors: errors + engine.errors,
  };
  fetch(cfg.heartbeatUrl, {
    method: 'POST', cache: 'no-store', credentials: 'omit',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${sync.token}`, 'X-Display-Version': cfg.version },
    body: JSON.stringify(body),
  }).catch(() => {});
}

/* ——— activarea dispozitivului ——— */
function showActivation(message = '') {
  sync?.stop();
  sync = null;
  ctx.state = { ...ctx.state, live: null, legends: null };
  document.querySelector('.activate')?.remove();
  const input = el('input', { class: 'activate__input', name: 'code', autocomplete: 'off', spellcheck: 'false', maxlength: '12', placeholder: 'XXXX-XXXX', 'aria-label': 'Cod de activare' });
  const error = el('p', { class: 'activate__error', role: 'alert', text: message });
  const form = el('form', { class: 'activate__form' }, [input, el('button', { class: 'activate__btn', type: 'submit', text: 'Activează' })]);
  const box = el('div', { class: 'activate' }, el('div', { class: 'activate__box' }, [
    el('h1', { text: cfg.type === 'live_arena' ? 'Live Arena' : 'Hall of Legends' }),
    el('p', { text: 'Introdu codul de activare generat din CRM: Ecrane afișare → Ecrane → Asociază dispozitiv.' }),
    form, error,
  ]));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.textContent = '';
    try {
      const res = await fetch(cfg.activateUrl, {
        method: 'POST', cache: 'no-store', credentials: 'omit',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ code: input.value, resolution: `${window.screen.width}x${window.screen.height}` }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.token) {
        error.textContent = data.error || 'Cod invalid sau expirat.';
        return;
      }
      storage.set(TOKEN_KEY, data.token);
      box.remove();
      document.body.style.cursor = 'none';
      connect(data.token);
    } catch {
      error.textContent = 'Fără conexiune la server. Verifică internetul și încearcă din nou.';
    }
  });
  document.body.style.cursor = 'auto';
  stage.append(box);
  setTimeout(() => input.focus(), 50);
}

function connect(token) {
  sync = new Sync({
    url: cfg.stateUrl,
    token,
    clock,
    version: cfg.version,
    credentials: cfg.mode === 'preview' ? 'same-origin' : 'omit',
    onState,
    onUnauthorized: () => {
      if (cfg.mode === 'preview') return;
      storage.remove(TOKEN_KEY);
      storage.remove(SNAP_KEY);
      showActivation('Accesul acestui dispozitiv a fost revocat sau a expirat. Introdu un cod nou.');
    },
  });
  sync.start();
  setTimeout(heartbeat, 5000);
}
setInterval(heartbeat, 60000);

if ('serviceWorker' in navigator && cfg.swUrl) {
  navigator.serviceWorker.register(cfg.swUrl, { scope: '/display/' }).catch(() => {});
}

if (cfg.mode === 'preview') {
  connect(null);
} else {
  const token = storage.get(TOKEN_KEY);
  if (token) connect(token);
  else showActivation();
}

// până la primul răspuns (sau fără conexiune la pornire), rulează conținutul local disponibil
setTimeout(() => { if (!started) { started = true; engine.start(); } }, 4000);
