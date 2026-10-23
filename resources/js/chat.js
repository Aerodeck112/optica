/*
 * Asistentul virtual. Modulul se încarcă doar la primul click pe buton.
 * Rezervarea propusă de asistent se creează DOAR după ce utilizatorul apasă „Confirm rezervarea” pe rezumat.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
let panel = null;
let state = { conversationId: null, busy: false, launcher: null, i18n: {}, endpoint: '', confirmEndpoint: '', fallback: '' };

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (v === null || v === undefined || v === false) return;
    if (k === 'text') node.textContent = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v === true ? '' : v);
  });
  [].concat(children).forEach((c) => c && node.append(c));
  return node;
}

/* Text simplu cu linkuri sigure (doar http/https din același site sau absolute) — fără HTML din model. */
function richText(text) {
  const frag = document.createDocumentFragment();
  const parts = String(text).split(/(https?:\/\/[^\s)]+)/g);
  parts.forEach((part) => {
    if (/^https?:\/\//.test(part)) {
      frag.append(el('a', { href: part, rel: 'noopener', target: '_blank', text: part.replace(/^https?:\/\//, '') }));
    } else {
      part.split('\n').forEach((line, i) => {
        if (i > 0) frag.append(document.createElement('br'));
        frag.append(document.createTextNode(line));
      });
    }
  });
  return frag;
}

function addMessage(role, text, extra = null) {
  const log = panel.querySelector('.chat-log');
  const msg = el('div', { class: `chat-msg chat-msg--${role}` });
  if (text) msg.append(richText(text));
  if (extra) msg.append(extra);
  log.append(msg);
  log.scrollTop = log.scrollHeight;
  return msg;
}

function summaryCard(summary) {
  const t = state.i18n;
  const dl = el('dl', {}, summary.rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
  const confirmBtn = el('button', { type: 'button', class: 'btn btn--primary btn--small', text: t.confirm_booking });
  const cancelBtn = el('button', { type: 'button', class: 'btn btn--ghost btn--small', text: t.cancel_booking_draft });
  const card = el('div', { class: 'chat-summary' }, [el('strong', { text: summary.title }), dl, summary.note ? el('p', { class: 'small muted', text: summary.note }) : null, el('div', { class: 'cluster' }, [confirmBtn, cancelBtn])]);
  confirmBtn.addEventListener('click', async () => {
    confirmBtn.disabled = true;
    cancelBtn.disabled = true;
    await send(null, { confirm: summary.id });
  });
  cancelBtn.addEventListener('click', () => {
    card.remove();
    panel.querySelector('textarea').focus();
  });
  return card;
}

async function send(text, opts = {}) {
  if (state.busy) return;
  state.busy = true;
  const typing = addMessage('system', '…');
  try {
    const url = opts.confirm ? state.confirmEndpoint : state.endpoint;
    const body = opts.confirm ? { conversation_id: state.conversationId, summary_id: opts.confirm } : { conversation_id: state.conversationId, message: text };
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    typing.remove();
    if (!res.ok || data.status === 'unavailable') {
      unavailable(data.message);
      return;
    }
    state.conversationId = data.conversation_id || state.conversationId;
    (data.messages || []).forEach((m) => addMessage('assistant', m.text, m.summary ? summaryCard(m.summary) : null));
    if (data.booking_code) window.awTrack?.('booking_created_ai', { source: 'ai' });
    if (data.handoff) addMessage('system', state.i18n.handoff, el('a', { class: 'btn btn--ghost btn--small', href: state.fallback, text: state.i18n.handoff }));
  } catch (_) {
    typing.remove();
    unavailable();
  } finally {
    state.busy = false;
  }
}

function unavailable(message) {
  addMessage('system', message || state.i18n.unavailable, state.fallback ? el('p', {}, el('a', { href: state.fallback, text: state.i18n.handoff })) : null);
}

function build() {
  const t = state.i18n;
  const textarea = el('textarea', { class: 'textarea', rows: '1', placeholder: t.placeholder, 'aria-label': t.placeholder, maxlength: '800' });
  const form = el('form', { class: 'chat-form' }, [textarea, el('button', { type: 'submit', class: 'btn btn--primary btn--small', text: t.send })]);
  panel = el('section', { class: 'chat-panel', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'chat-title' }, [
    el('header', { class: 'chat-panel__head' }, [
      el('div', {}, [el('h2', { id: 'chat-title', text: t.title }), el('p', { text: t.disclaimer })]),
      el('button', { type: 'button', class: 'btn btn--ghost btn--small', 'aria-label': 'Închide', text: '×', onclick: close }),
    ]),
    el('div', { class: 'chat-log', role: 'log', 'aria-live': 'polite' }),
    form,
  ]);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = textarea.value.trim();
    if (!text) return;
    addMessage('user', text);
    textarea.value = '';
    send(text);
  });
  textarea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit();
    }
  });
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  document.body.append(panel);
  addMessage('assistant', t.greeting);
}

function close() {
  panel.hidden = true;
  state.launcher.hidden = false;
  state.launcher.focus();
}

export function openChat(launcher) {
  if (!panel) {
    state.launcher = launcher;
    state.endpoint = launcher.dataset.endpoint;
    state.confirmEndpoint = launcher.dataset.confirmEndpoint;
    state.fallback = launcher.dataset.fallback || '/contact';
    state.i18n = JSON.parse(launcher.dataset.i18n || '{}');
    build();
  }
  panel.hidden = false;
  launcher.hidden = true;
  panel.querySelector('textarea').focus();
}
