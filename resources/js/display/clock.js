/**
 * Ora serverului: diferența față de ceasul PC-ului se calculează din antetul X-Server-Time (la mijlocul cererii),
 * iar afișarea folosește întotdeauna fusul Europe/Bucharest (inclusiv trecerea la ora de vară/iarnă).
 */
const ZONE = 'Europe/Bucharest';
const timeFmt = new Intl.DateTimeFormat('ro-RO', { timeZone: ZONE, hour: '2-digit', minute: '2-digit', hour12: false });
const dateFmt = new Intl.DateTimeFormat('ro-RO', { timeZone: ZONE, weekday: 'long', day: 'numeric', month: 'long' });
const partsFmt = new Intl.DateTimeFormat('en-GB', { timeZone: ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

export class ServerClock {
  constructor() { this.offset = 0; this.synced = false; }

  sample(serverMs, sentAt, receivedAt) {
    if (!Number.isFinite(serverMs)) return;
    const offset = serverMs - (sentAt + receivedAt) / 2;
    // netezire: evită salturile din cauza latenței variabile
    this.offset = this.synced ? this.offset * 0.7 + offset * 0.3 : offset;
    this.synced = true;
  }

  now() { return Date.now() + this.offset; }

  time(ms = this.now()) { return timeFmt.format(ms); }

  date(ms = this.now()) { return dateFmt.format(ms); }

  /** Ora și minutul locale (pentru reîncărcarea zilnică). */
  localHM(ms = this.now()) {
    const [h, m] = partsFmt.format(ms).split(':').map(Number);
    return { h, m };
  }
}
