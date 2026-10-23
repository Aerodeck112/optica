/*
 * Asistentul de rezervare. Prețurile și disponibilitatea vin exclusiv de la server (aceleași servicii ca recepția și AI-ul);
 * aici doar afișăm și păstrăm slotul ales pentru scurt timp.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const track = (event, params) => window.awTrack?.(event, params);

async function api(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.message || 'request_failed');
    error.status = response.status;
    error.data = data;
    throw error;
  }
  return data;
}

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

export function initBooking(root) {
  const data = JSON.parse(root.querySelector('script[type="application/json"][data-booking-config]').textContent);
  const t = data.i18n;
  const form = root.querySelector('form');
  const state = {
    packageId: Number(form.querySelector('[name="package_id"]:checked')?.value) || null,
    participants: Number(form.querySelector('[name="participants"]')?.value) || null,
    date: null,
    slot: null,
    hold: null,
    holdTimer: null,
    month: null,
  };
  let started = false;

  const pkg = () => data.packages.find((p) => p.id === state.packageId) || null;
  const els = {
    timeStep: root.querySelector('[data-step="time"]'),
    calendar: root.querySelector('[data-calendar]'),
    slots: root.querySelector('[data-slots]'),
    slotStatus: root.querySelector('[data-slot-status]'),
    requestFields: root.querySelector('[data-request-fields]'),
    instantFields: root.querySelector('[data-instant-fields]'),
    startsAt: form.querySelector('[name="starts_at"]'),
    holdId: form.querySelector('[name="hold_id"]'),
    holdToken: form.querySelector('[name="hold_token"]'),
    extras: root.querySelector('[data-extras]'),
    summary: root.querySelector('[data-summary]'),
    submit: form.querySelector('[type="submit"]'),
    participants: form.querySelector('[name="participants"]'),
    participantsHint: root.querySelector('[data-participants-hint]'),
    minorsCheck: root.querySelector('[data-minors-check]'),
    ageInputs: [...form.querySelectorAll('[data-age-group]')],
    ageError: root.querySelector('[data-age-error]'),
    steps: [...root.querySelectorAll('[data-step-indicator]')],
    noJsTime: root.querySelector('[data-nojs-time]'),
  };

  // fără JS: câmpurile simple de dată/oră; cu JS: calendar și ore reale
  els.noJsTime?.remove();

  /* ——— Experiența ——— */
  form.querySelectorAll('[name="package_id"]').forEach((input) => {
    input.addEventListener('change', () => {
      state.packageId = Number(input.value);
      if (!started) { started = true; track('begin_checkout', { item_category: pkg()?.type }); }
      releaseHold();
      applyPackage();
      renderExtras();
      refreshQuote();
    });
  });

  /* ——— Participanți ——— */
  root.querySelectorAll('[data-step-btn]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const p = pkg();
      const min = p?.min_participants || 1;
      const max = p?.max_participants || 50;
      const value = Math.min(max, Math.max(min, (Number(els.participants.value) || min) + Number(btn.dataset.stepBtn)));
      els.participants.value = value;
      els.participants.dispatchEvent(new Event('change'));
    });
  });
  els.participants?.addEventListener('change', () => {
    state.participants = Number(els.participants.value) || null;
    releaseHold();
    if (state.date) loadSlots(state.date);
    checkAges();
    refreshQuote();
  });
  els.ageInputs.forEach((input) => input.addEventListener('input', checkAges));

  function checkAges() {
    const total = els.ageInputs.reduce((sum, i) => sum + (Number(i.value) || 0), 0);
    const minors = els.ageInputs.filter((i) => i.dataset.minor === '1').reduce((sum, i) => sum + (Number(i.value) || 0), 0);
    const mismatch = total > 0 && state.participants && total !== state.participants;
    if (els.ageError) els.ageError.hidden = !mismatch;
    if (els.minorsCheck) {
      els.minorsCheck.hidden = minors === 0;
      els.minorsCheck.querySelector('input').required = minors > 0;
    }
  }

  function applyPackage() {
    const p = pkg();
    if (!p) return;
    const min = p.min_participants || 1;
    const max = p.max_participants || 50;
    els.participants.min = min;
    els.participants.max = max;
    if (!els.participants.value || Number(els.participants.value) < min) els.participants.value = Math.max(min, Math.min(max, 2));
    if (Number(els.participants.value) > max) els.participants.value = max;
    state.participants = Number(els.participants.value);
    if (els.participantsHint) els.participantsHint.textContent = t.participants_hint.replace(':min', min).replace(':max', max);

    const instant = p.mode === 'instant';
    els.instantFields.hidden = !instant;
    els.requestFields.hidden = instant;
    els.requestFields.querySelectorAll('input,select').forEach((i) => { i.disabled = instant; });
    els.submit.textContent = instant ? t.submit_instant : t.submit_request;
    els.submit.dataset.busy = t.submitting;
    if (instant) renderCalendar();
    state.slot = null;
    els.startsAt.value = '';
    updateSteps();
  }

  /* ——— Calendar ——— */
  function renderCalendar() {
    const today = new Date(data.today + 'T12:00:00');
    if (!state.month) state.month = new Date(today.getFullYear(), today.getMonth(), 1);
    const max = new Date(data.max_date + 'T12:00:00');
    const month = state.month;
    const first = new Date(month.getFullYear(), month.getMonth(), 1);
    const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
    const offset = (first.getDay() + 6) % 7;
    const title = new Intl.DateTimeFormat(data.locale, { month: 'long', year: 'numeric' }).format(first);

    const grid = el('div', { class: 'calendar__grid', role: 'grid', 'aria-label': title });
    t.weekdays.forEach((d) => grid.append(el('div', { class: 'calendar__dow', role: 'columnheader', text: d })));
    for (let i = 0; i < offset; i++) grid.append(el('div', { role: 'gridcell' }));
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(month.getFullYear(), month.getMonth(), day, 12);
      const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const closed = data.closed_weekdays.includes(((date.getDay() + 6) % 7) + 1) || data.closed_dates.includes(iso);
      const disabled = date < today || date > max || closed;
      const btn = el('button', {
        type: 'button',
        class: 'calendar__day' + (iso === data.today ? ' is-today' : ''),
        disabled,
        'aria-pressed': String(state.date === iso),
        'aria-label': new Intl.DateTimeFormat(data.locale, { weekday: 'long', day: 'numeric', month: 'long' }).format(date) + (closed ? `, ${t.closed}` : ''),
        text: String(day),
        onclick: () => selectDate(iso),
      });
      grid.append(el('div', { role: 'gridcell' }, btn));
    }

    const prevDisabled = month <= new Date(today.getFullYear(), today.getMonth(), 1);
    const nextDisabled = new Date(month.getFullYear(), month.getMonth() + 1, 1) > max;
    els.calendar.replaceChildren(
      el('div', { class: 'calendar__head' }, [
        el('h3', { text: title, 'aria-live': 'polite' }),
        el('div', { class: 'calendar__nav' }, [
          el('button', { type: 'button', 'aria-label': t.prev_month, disabled: prevDisabled, text: '‹', onclick: () => { state.month = new Date(month.getFullYear(), month.getMonth() - 1, 1); renderCalendar(); } }),
          el('button', { type: 'button', 'aria-label': t.next_month, disabled: nextDisabled, text: '›', onclick: () => { state.month = new Date(month.getFullYear(), month.getMonth() + 1, 1); renderCalendar(); } }),
        ]),
      ]),
      grid,
    );
  }

  function selectDate(iso) {
    state.date = iso;
    releaseHold();
    renderCalendar();
    loadSlots(iso);
  }

  async function loadSlots(iso) {
    const p = pkg();
    if (!p || !state.participants) return;
    els.slots.replaceChildren(el('p', { class: 'muted', text: t.loading }));
    try {
      const params = new URLSearchParams({ package: p.id, date: iso, participants: state.participants });
      selectedAddons().forEach(([id, qty]) => params.append(`addons[${id}]`, qty));
      const res = await api(`${data.endpoints.slots}?${params}`);
      if (res.status === 'closed') return els.slots.replaceChildren(el('p', { class: 'muted', text: t.closed_day }));
      const free = res.slots.filter((s) => s.available);
      if (!free.length) return els.slots.replaceChildren(el('p', { class: 'muted', text: t.no_slots }));
      const list = el('div', { class: 'slots', role: 'group', 'aria-label': t.choose_time });
      res.slots.forEach((slot) => {
        list.append(el('button', {
          type: 'button',
          class: 'slot',
          disabled: !slot.available,
          'aria-pressed': String(state.slot === slot.starts_at),
          text: slot.local_time,
          onclick: () => chooseSlot(slot),
        }));
      });
      els.slots.replaceChildren(list, res.message ? el('p', { class: 'small muted mt-4', text: res.message }) : '');
    } catch (e) {
      els.slots.replaceChildren(el('p', { class: 'field__error', text: e.status === 429 ? t.error_rate : t.error_generic }));
    }
  }

  async function chooseSlot(slot) {
    const p = pkg();
    await releaseHold();
    state.slot = slot.starts_at;
    els.startsAt.value = slot.starts_at;
    els.slots.querySelectorAll('.slot').forEach((b) => b.setAttribute('aria-pressed', String(b.textContent === slot.local_time)));
    track('select_slot', { item_category: p?.type });
    try {
      const res = await api(data.endpoints.hold, {
        method: 'POST',
        body: JSON.stringify({ package_id: p.id, starts_at: slot.starts_at, participants: state.participants, addons: Object.fromEntries(selectedAddons()) }),
      });
      state.hold = res;
      els.holdId.value = res.hold_id;
      els.holdToken.value = res.token;
      startTimer(new Date(res.expires_at));
    } catch (e) {
      state.slot = null;
      els.startsAt.value = '';
      els.slotStatus.textContent = e.data?.message || t.error_generic;
      loadSlots(state.date);
    }
    updateSteps();
    refreshQuote();
  }

  function startTimer(until) {
    clearInterval(state.holdTimer);
    const tick = () => {
      const left = Math.max(0, Math.round((until - Date.now()) / 1000));
      const mm = String(Math.floor(left / 60));
      const ss = String(left % 60).padStart(2, '0');
      els.slotStatus.textContent = left > 0 ? t.slot_held.replace(':time', `${mm}:${ss}`) : t.slot_hold_expired;
      if (left === 0) {
        clearInterval(state.holdTimer);
        state.hold = null;
        els.holdId.value = '';
        els.holdToken.value = '';
      }
    };
    tick();
    state.holdTimer = setInterval(tick, 1000);
  }

  async function releaseHold() {
    clearInterval(state.holdTimer);
    if (els.slotStatus) els.slotStatus.textContent = '';
    if (!state.hold) return;
    const hold = state.hold;
    state.hold = null;
    els.holdId.value = '';
    els.holdToken.value = '';
    try {
      await api(data.endpoints.release, { method: 'POST', body: JSON.stringify({ hold_id: hold.hold_id, token: hold.token }) });
    } catch (_) {}
  }
  window.addEventListener('pagehide', () => {
    if (state.hold && navigator.sendBeacon) {
      const blob = new Blob([JSON.stringify({ hold_id: state.hold.hold_id, token: state.hold.token, _token: csrf() })], { type: 'application/json' });
      navigator.sendBeacon(data.endpoints.release, blob);
    }
  });

  /* ——— Extra ——— */
  function renderExtras() {
    const p = pkg();
    if (!els.extras) return;
    const addons = p?.addons || [];
    if (!addons.length) {
      els.extras.replaceChildren(el('p', { class: 'muted', text: t.no_extras }));
      return;
    }
    els.extras.replaceChildren(...addons.map((a) => el('label', { class: 'check' }, [
      el('input', { type: 'checkbox', name: `addons[${a.id}]`, value: '1', onchange: () => { releaseHold(); if (state.date) loadSlots(state.date); refreshQuote(); } }),
      el('span', {}, [el('strong', { text: a.name }), a.price_label ? ` — ${a.price_label}` : '', a.description ? el('span', { class: 'field__hint', style: 'display:block', text: a.description }) : '']),
    ])));
  }
  function selectedAddons() {
    return [...form.querySelectorAll('[name^="addons["]:checked')].map((i) => [i.name.match(/\d+/)[0], 1]);
  }

  /* ——— Rezumat (calculat pe server) ——— */
  let quoteSeq = 0;
  async function refreshQuote() {
    const p = pkg();
    if (!p || !els.summary) return;
    const seq = ++quoteSeq;
    try {
      const res = await api(data.endpoints.quote, {
        method: 'POST',
        body: JSON.stringify({ package_id: p.id, participants: state.participants, starts_at: state.slot, addons: Object.fromEntries(selectedAddons()) }),
      });
      if (seq !== quoteSeq) return;
      renderSummary(res);
    } catch (_) {}
  }

  function renderSummary(q) {
    const rows = [
      [t.summary_experience, q.package],
      [t.summary_when, q.when || '—'],
      [t.summary_participants, String(state.participants || '—')],
      q.slot_minutes ? [t.summary_slot_duration, q.slot_minutes] : null,
      q.play_minutes ? [t.summary_play_time, q.play_minutes] : null,
    ].filter(Boolean);
    const dl = el('dl', {}, rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
    const total = el('div', { class: 'booking-summary__total' }, [el('span', { text: t.summary_total }), el('strong', { text: q.total || t.summary_price_tbd })]);
    const extra = [];
    if (q.lines?.length) extra.push(el('ul', { class: 'small muted mt-4', role: 'list' }, q.lines.map((l) => el('li', { text: l }))));
    if (q.deposit) extra.push(el('p', { class: 'small mt-4', text: `${t.summary_deposit}: ${q.deposit}` }));
    if (q.note) extra.push(el('p', { class: 'small muted mt-4', text: q.note }));
    els.summary.replaceChildren(el('h2', { class: 'h4', text: t.summary_title }), dl, total, ...extra);
  }

  function updateSteps() {
    const done = { experience: !!state.packageId, group: !!state.participants, time: !!state.slot || pkg()?.mode === 'request' };
    let currentSet = false;
    els.steps.forEach((li) => {
      const key = li.dataset.stepIndicator;
      const isDone = done[key];
      li.classList.toggle('is-done', !!isDone);
      if (!isDone && !currentSet) { li.setAttribute('aria-current', 'step'); currentSet = true; } else li.removeAttribute('aria-current');
    });
  }

  form.addEventListener('submit', (e) => {
    const p = pkg();
    if (p?.mode === 'instant' && !els.startsAt.value) {
      e.preventDefault();
      els.slotStatus.textContent = t.choose_time_first;
      els.timeStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  if (state.packageId) {
    applyPackage();
    renderExtras();
    refreshQuote();
  }
  checkAges();
  updateSteps();
}

/* Reprogramare din pagina de gestionare: alege o zi și o oră liberă. */
export function initReschedule(root) {
  const data = JSON.parse(root.querySelector('script[data-reschedule-config]').textContent);
  const dateInput = root.querySelector('[name="date"]');
  const slotsBox = root.querySelector('[data-slots]');
  const startsAt = root.querySelector('[name="starts_at"]');
  const submit = root.querySelector('[type="submit"]');
  submit.disabled = true;
  dateInput.addEventListener('change', async () => {
    slotsBox.textContent = data.i18n.loading;
    const params = new URLSearchParams({ package: data.package_id, date: dateInput.value, participants: data.participants, exclude: data.booking_ref });
    try {
      const res = await api(`${data.endpoints.slots}?${params}`);
      const free = (res.slots || []).filter((s) => s.available);
      if (!free.length) { slotsBox.textContent = data.i18n.no_slots; return; }
      const group = el('div', { class: 'slots', role: 'group' });
      free.forEach((slot) => group.append(el('button', {
        type: 'button', class: 'slot', 'aria-pressed': 'false', text: slot.local_time,
        onclick: (ev) => {
          group.querySelectorAll('.slot').forEach((b) => b.setAttribute('aria-pressed', 'false'));
          ev.currentTarget.setAttribute('aria-pressed', 'true');
          startsAt.value = slot.starts_at;
          submit.disabled = false;
        },
      })));
      slotsBox.replaceChildren(group);
    } catch (_) {
      slotsBox.textContent = data.i18n.error_generic;
    }
  });
}
