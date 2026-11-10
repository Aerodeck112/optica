/**
 * Sincronizarea stării: polling eficient cu ETag (304 când nu s-a schimbat nimic), timeout pe cerere,
 * o singură cerere în zbor, backoff exponențial la erori și reconectare automată.
 * Infrastructura (găzduire cPanel) nu garantează conexiuni permanente, de aceea nu folosim WebSocket/SSE.
 */
export class Sync {
  constructor({ url, token, clock, onState, onUnauthorized, version, credentials = 'omit' }) {
    Object.assign(this, { url, token, clock, onState, onUnauthorized, version, credentials });
    this.etag = null;
    this.timer = null;
    this.inflight = false;
    this.failures = 0;
    this.lastOk = 0;
    this.pollSeconds = 8;
    this.stopped = false;
  }

  start() { this.poll(); }

  stop() { this.stopped = true; clearTimeout(this.timer); }

  schedule(seconds) {
    clearTimeout(this.timer);
    if (!this.stopped) this.timer = setTimeout(() => this.poll(), seconds * 1000);
  }

  /** Vârsta ultimei confirmări de la server, în secunde (Infinity dacă nu a existat niciuna). */
  age() { return this.lastOk ? (Date.now() - this.lastOk) / 1000 : Infinity; }

  async poll() {
    if (this.inflight || this.stopped) return;
    this.inflight = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 9000);
    const sentAt = Date.now();
    try {
      const headers = { Accept: 'application/json', 'X-Display-Version': this.version, 'X-Display-Resolution': `${window.screen.width}x${window.screen.height}` };
      if (this.token) headers.Authorization = `Bearer ${this.token}`;
      if (this.etag) headers['If-None-Match'] = this.etag;
      const res = await fetch(this.url, { headers, signal: controller.signal, cache: 'no-store', credentials: this.credentials });
      this.clock.sample(Number(res.headers.get('X-Server-Time')), sentAt, Date.now());
      if (res.status === 401 || res.status === 403) {
        this.stop();
        this.onUnauthorized?.();
        return;
      }
      if (res.status === 304) {
        this.ok();
      } else if (res.ok) {
        const data = await res.json();
        this.etag = res.headers.get('ETag');
        this.ok();
        this.pollSeconds = Math.min(30, Math.max(4, Number(data?.settings?.poll_seconds) || 8));
        this.onState(data);
      } else {
        throw new Error(`HTTP ${res.status}`);
      }
      this.schedule(this.pollSeconds);
    } catch {
      this.failures += 1;
      const backoff = Math.min(30, 5 * 2 ** Math.min(this.failures - 1, 3));
      this.schedule(backoff * (0.8 + Math.random() * 0.4));
    } finally {
      clearTimeout(timeout);
      this.inflight = false;
    }
  }

  ok() { this.failures = 0; this.lastOk = Date.now(); }
}
