/** Particule discrete (stele care urcă lent). Un singur canvas, ~30 fps, oprit când pagina e ascunsă sau la nivelul „minim”. */
export class Particles {
  constructor(canvas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: true });
    this.running = false;
    this.last = 0;
    this.raf = 0;
    const colors = ['#b39cff', '#3ee6ff', '#eef0ff', '#f23db3'];
    this.points = Array.from({ length: 70 }, () => ({
      x: Math.random() * 1920, y: Math.random() * 1080, r: 0.6 + Math.random() * 1.8,
      v: 6 + Math.random() * 16, p: Math.random() * Math.PI * 2, c: colors[Math.floor(Math.random() * colors.length)],
    }));
    this.frame = this.frame.bind(this);
    document.addEventListener('visibilitychange', () => (document.hidden ? this.pause() : this.resume()));
  }

  start() { if (!this.running) { this.running = true; this.raf = requestAnimationFrame(this.frame); } }

  pause() { this.running = false; cancelAnimationFrame(this.raf); }

  resume() { if (this.enabled !== false) this.start(); }

  setEnabled(enabled) {
    this.enabled = enabled;
    if (enabled) this.start();
    else { this.pause(); this.ctx.clearRect(0, 0, 1920, 1080); }
  }

  frame(t) {
    if (!this.running) return;
    this.raf = requestAnimationFrame(this.frame);
    const dt = t - this.last;
    if (dt < 33) return; // ~30 fps
    this.last = t;
    const s = Math.min(dt, 100) / 1000;
    const { ctx } = this;
    ctx.clearRect(0, 0, 1920, 1080);
    for (const p of this.points) {
      p.y -= p.v * s;
      p.p += s * 1.4;
      if (p.y < -10) { p.y = 1090; p.x = Math.random() * 1920; }
      ctx.globalAlpha = 0.25 + 0.45 * (0.5 + 0.5 * Math.sin(p.p));
      ctx.fillStyle = p.c;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
  }
}
