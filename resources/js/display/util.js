/** Utilitare mici pentru player: construirea DOM-ului fără innerHTML (textul vine întotdeauna ca text). */
export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
    else if (key.startsWith('--')) node.style.setProperty(key, value);
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of [].concat(children)) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

/** Imagine cu fallback local: dacă un avatar lipsește, se folosește avatarul neutru; dacă și acesta lipsește, se ascunde. */
export function img(src, attrs = {}, fallback = '/media/avatars/neutral.svg') {
  const node = el('img', { ...attrs, src, alt: attrs.alt ?? '', decoding: 'async', draggable: 'false' });
  node.addEventListener('error', () => {
    if (fallback && node.getAttribute('src') !== fallback) node.setAttribute('src', fallback);
    else node.style.visibility = 'hidden';
  }, { once: false });
  return node;
}

/** QR-ul vine de la server ca SVG generat local (fără servicii externe); se parsează ca SVG, nu ca HTML. */
export function svgNode(markup) {
  if (!markup) return el('div');
  const doc = new DOMParser().parseFromString(markup, 'image/svg+xml');
  const svg = doc.documentElement;
  if (!svg || svg.nodeName.toLowerCase() !== 'svg') return el('div');
  svg.querySelectorAll('script, foreignObject').forEach((n) => n.remove());
  return document.importNode(svg, true);
}

export function weightedPick(list, weightOf = (x) => x.weight || 1) {
  const total = list.reduce((sum, x) => sum + Math.max(1, weightOf(x)), 0);
  let r = Math.random() * total;
  for (const x of list) {
    r -= Math.max(1, weightOf(x));
    if (r <= 0) return x;
  }
  return list[list.length - 1];
}

export function pad(n) { return String(n).padStart(2, '0'); }

export function formatDuration(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  return h > 0 ? `${h}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`;
}

export const storage = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch { /* spațiu plin sau stocare blocată */ } },
  remove(key) { try { localStorage.removeItem(key); } catch { /* ignorat */ } },
};
