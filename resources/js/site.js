/*
 * Another World Cluj — script de bază (mic, fără dependențe).
 * Module grele (rezervare, chat) se încarcă doar pe paginile/interacțiunile care le folosesc.
 */

const doc = document;
doc.documentElement.classList.remove('no-js');
doc.documentElement.classList.add('js');

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const config = window.AW || {};

/* ——— Meniu mobil ——— */
function initNav() {
  const header = doc.querySelector('.site-header');
  const toggle = doc.querySelector('.nav-toggle');
  if (!header || !toggle) return;
  const close = () => {
    header.dataset.open = 'false';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', toggle.dataset.labelOpen);
    doc.body.style.overflow = '';
  };
  toggle.addEventListener('click', () => {
    const open = header.dataset.open !== 'true';
    header.dataset.open = String(open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? toggle.dataset.labelClose : toggle.dataset.labelOpen);
    doc.body.style.overflow = open ? 'hidden' : '';
    if (open) header.querySelector('.site-nav a')?.focus();
  });
  doc.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && header.dataset.open === 'true') {
      close();
      toggle.focus();
    }
  });
  window.matchMedia('(min-width: 64.01rem)').addEventListener('change', close);
}

/* ——— Banner promoțional închis de vizitator ——— */
function initBanners() {
  doc.querySelectorAll('[data-banner-id]').forEach((banner) => {
    const key = `aw_banner_${banner.dataset.bannerId}`;
    try {
      if (localStorage.getItem(key)) banner.hidden = true;
    } catch (_) {}
    banner.querySelector('[data-banner-close]')?.addEventListener('click', () => {
      banner.hidden = true;
      try { localStorage.setItem(key, '1'); } catch (_) {}
    });
  });
}

/* ——— Consimțământ cookies + analytics ——— */
const CONSENT_COOKIE = 'aw_consent';
const CONSENT_VERSION = 2;

function readConsent() {
  const match = doc.cookie.match(/(?:^|; )aw_consent=([^;]+)/);
  if (!match) return null;
  try {
    const value = JSON.parse(decodeURIComponent(match[1]));
    return value.v === CONSENT_VERSION ? value : null;
  } catch (_) {
    return null;
  }
}

function writeConsent(analytics, marketing) {
  const value = encodeURIComponent(JSON.stringify({ v: CONSENT_VERSION, analytics, marketing, ts: Date.now() }));
  const secure = location.protocol === 'https:' ? '; Secure' : '';
  doc.cookie = `${CONSENT_COOKIE}=${value}; Max-Age=${60 * 60 * 24 * 180}; Path=/; SameSite=Lax${secure}`;
}

window.dataLayer = window.dataLayer || [];
function gtag() { window.dataLayer.push(arguments); }

let analyticsLoaded = false;
function applyConsent(consent) {
  if (!consent) return;
  gtag('consent', 'update', {
    analytics_storage: consent.analytics ? 'granted' : 'denied',
    ad_storage: consent.marketing ? 'granted' : 'denied',
    ad_user_data: consent.marketing ? 'granted' : 'denied',
    ad_personalization: consent.marketing ? 'granted' : 'denied',
  });
  if ((consent.analytics || consent.marketing) && !analyticsLoaded) loadAnalytics(consent);
}

function loadScript(src) {
  const s = doc.createElement('script');
  s.src = src;
  s.async = true;
  if (config.nonce) s.nonce = config.nonce;
  doc.head.appendChild(s);
}

function loadAnalytics(consent) {
  analyticsLoaded = true;
  const a = config.analytics || {};
  if (a.gtm) {
    window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
    loadScript(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(a.gtm)}`);
  } else if (a.ga4 || a.ads) {
    loadScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(a.ga4 || a.ads)}`);
    gtag('js', new Date());
    if (a.ga4 && consent.analytics) gtag('config', a.ga4, { anonymize_ip: true });
    if (a.ads && consent.marketing) gtag('config', a.ads);
  }
  if (a.meta && consent.marketing) {
    /* Meta Pixel — doar cu acord de marketing */
    const f = window;
    if (!f.fbq) {
      const n = (f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); });
      n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
      loadScript('https://connect.facebook.net/en_US/fbevents.js');
      f.fbq('init', a.meta);
      f.fbq('track', 'PageView');
    }
  }
}

/* Evenimente analitice fără date personale; trimise în dataLayer doar dacă există configurare. */
export function track(event, params = {}) {
  window.dataLayer.push({ event, ...params });
}
window.awTrack = track;

function initConsent() {
  const banner = doc.getElementById('cookie-banner');
  const dialog = doc.getElementById('cookie-dialog');
  const consent = readConsent();
  if (consent) {
    applyConsent(consent);
  } else if (banner && config.needsConsent) {
    banner.hidden = false;
  }

  const decide = (analytics, marketing) => {
    writeConsent(analytics, marketing);
    applyConsent({ analytics, marketing });
    if (banner) banner.hidden = true;
    if (dialog?.open) dialog.close();
  };

  doc.querySelectorAll('[data-consent="accept"]').forEach((b) => b.addEventListener('click', () => decide(true, true)));
  doc.querySelectorAll('[data-consent="reject"]').forEach((b) => b.addEventListener('click', () => decide(false, false)));
  doc.querySelectorAll('[data-consent="preferences"]').forEach((b) => b.addEventListener('click', (e) => {
    e.preventDefault();
    if (!dialog) return;
    const current = readConsent() || { analytics: false, marketing: false };
    dialog.querySelector('[name="analytics"]').checked = !!current.analytics;
    dialog.querySelector('[name="marketing"]').checked = !!current.marketing;
    dialog.showModal();
  }));
  dialog?.querySelector('form')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const before = readConsent();
    const analytics = form.analytics.checked;
    const marketing = form.marketing.checked;
    decide(analytics, marketing);
    // retragerea acordului: scripturile deja încărcate se opresc doar la reîncărcarea paginii
    if (before && ((before.analytics && !analytics) || (before.marketing && !marketing))) location.reload();
  });
  dialog?.querySelector('[data-dialog-close]')?.addEventListener('click', () => dialog.close());
}

/* ——— Portalul din hero: video încărcat după prima afișare, doar când are sens ——— */
function initPortal() {
  const hero = doc.querySelector('[data-portal]');
  if (!hero) return;
  const windowEl = hero.querySelector('.portal__window');
  const control = hero.querySelector('.portal__control');
  const sources = windowEl?.dataset.video ? JSON.parse(windowEl.dataset.video) : null;
  const connection = navigator.connection || {};
  const constrained = connection.saveData || /(^|-)2g$/.test(connection.effectiveType || '');
  let video = null;

  const setPaused = (paused) => {
    hero.classList.toggle('is-paused', paused);
    if (control) {
      control.setAttribute('aria-pressed', String(paused));
      control.setAttribute('aria-label', paused ? control.dataset.labelPlay : control.dataset.labelPause);
      control.querySelector('[data-icon="pause"]').hidden = paused;
      control.querySelector('[data-icon="play"]').hidden = !paused;
    }
    if (video) paused ? video.pause() : video.play().catch(() => {});
  };

  const attachVideo = () => {
    if (!sources || video || reduceMotion.matches || constrained) return;
    video = doc.createElement('video');
    video.muted = true;
    video.loop = true;
    video.playsInline = true;
    video.setAttribute('aria-hidden', 'true');
    video.preload = 'auto';
    video.poster = windowEl.querySelector('img')?.currentSrc || '';
    sources.forEach(({ src, type }) => {
      const s = doc.createElement('source');
      s.src = src;
      s.type = type;
      video.appendChild(s);
    });
    video.addEventListener('playing', () => video.classList.add('is-ready'), { once: true });
    windowEl.appendChild(video);
    if (!hero.classList.contains('is-paused')) video.play().catch(() => {});
  };

  control?.addEventListener('click', () => setPaused(!hero.classList.contains('is-paused')));
  if (reduceMotion.matches) setPaused(true);
  reduceMotion.addEventListener('change', (e) => setPaused(e.matches));

  // Video-ul nu concurează cu LCP: pornește după load + timp liber.
  const start = () => ('requestIdleCallback' in window ? requestIdleCallback(attachVideo, { timeout: 2500 }) : setTimeout(attachVideo, 1200));
  if (doc.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });

  // Oprim redarea când portalul nu este vizibil (economie de baterie).
  new IntersectionObserver(([entry]) => {
    if (!video || hero.classList.contains('is-paused')) return;
    entry.isIntersecting ? video.play().catch(() => {}) : video.pause();
  }).observe(hero);
}

/* ——— Galerie cu lightbox accesibil ——— */
function initLightbox() {
  const items = [...doc.querySelectorAll('[data-lightbox]')];
  if (!items.length) return;
  const dialog = doc.getElementById('lightbox');
  if (!dialog) return;
  const img = dialog.querySelector('img');
  const caption = dialog.querySelector('[data-caption]');
  let index = 0;
  const show = (i) => {
    index = (i + items.length) % items.length;
    const item = items[index];
    img.src = item.dataset.full;
    img.alt = item.dataset.alt || '';
    caption.textContent = item.dataset.caption || item.dataset.alt || '';
  };
  items.forEach((item, i) => item.addEventListener('click', () => { show(i); dialog.showModal(); }));
  dialog.querySelector('[data-prev]')?.addEventListener('click', () => show(index - 1));
  dialog.querySelector('[data-next]')?.addEventListener('click', () => show(index + 1));
  dialog.querySelector('[data-close]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') show(index - 1);
    if (e.key === 'ArrowRight') show(index + 1);
  });
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
}

/* ——— Trailere: video-ul se încarcă doar la click ——— */
function initTrailers() {
  doc.querySelectorAll('[data-trailer]').forEach((wrap) => {
    const button = wrap.querySelector('button');
    button?.addEventListener('click', () => {
      const video = doc.createElement('video');
      video.controls = true;
      video.playsInline = true;
      video.preload = 'auto';
      video.src = wrap.dataset.trailer;
      video.setAttribute('aria-label', wrap.dataset.label || '');
      wrap.replaceChildren(video);
      video.play().catch(() => {});
      video.focus();
      track('video_start', { video_title: wrap.dataset.label || '' });
    });
  });
}

/* ——— Evenimente declarate în pagină (ex: vizualizare joc) ——— */
function initPageEvents() {
  doc.querySelectorAll('[data-track-view]').forEach((el) => {
    try { track(el.dataset.trackView, JSON.parse(el.dataset.trackParams || '{}')); } catch (_) {}
  });
  doc.querySelectorAll('[data-track-click]').forEach((el) => {
    el.addEventListener('click', () => {
      try { track(el.dataset.trackClick, JSON.parse(el.dataset.trackParams || '{}')); } catch (_) {}
    });
  });
}

/* ——— Chat: modulul se descarcă abia la prima interacțiune ——— */
function initChatLauncher() {
  const launcher = doc.querySelector('[data-chat-launch]');
  if (!launcher) return;
  let loaded = null;
  const open = async () => {
    loaded ??= import('./chat.js');
    const module = await loaded;
    module.openChat(launcher);
  };
  launcher.addEventListener('click', open);
  doc.querySelectorAll('[data-open-chat]').forEach((el) => el.addEventListener('click', (e) => { e.preventDefault(); open(); }));
}

/* ——— Formulare: blochează dublul click, marchează timpul de completare (anti-spam) ——— */
function initForms() {
  doc.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });
  doc.querySelectorAll('form[data-guard]').forEach((form) => {
    const started = form.querySelector('[name="_started"]');
    if (started) started.value = String(Math.floor(Date.now() / 1000));
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[type="submit"]');
      if (btn) {
        btn.setAttribute('aria-disabled', 'true');
        btn.dataset.label = btn.textContent;
        btn.textContent = btn.dataset.busy || btn.textContent;
      }
    });
  });
}

/* ——— Rezervarea: modul separat ——— */
function initBooking() {
  const root = doc.querySelector('[data-booking]');
  if (root) import('./booking.js').then((m) => m.initBooking(root));
  const manage = doc.querySelector('[data-reschedule]');
  if (manage) import('./booking.js').then((m) => m.initReschedule(manage));
}

initNav();
initBanners();
initConsent();
initPortal();
initLightbox();
initTrailers();
initPageEvents();
initChatLauncher();
initForms();
initBooking();
