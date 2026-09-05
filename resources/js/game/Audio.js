/** Small synthesized effects keep combat readable without extra asset downloads. */
export class GameAudio {
    constructor() { this.context = null; this.muted = false; }
    unlock() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        this.context ??= new AudioContext();
        if (this.context.state === 'suspended') this.context.resume().catch(() => {});
    }
    play(kind) {
        const ctx = this.context;
        if (!ctx || this.muted || ctx.state !== 'running') return;
        const notes = {
            wand: [640, 180, 0.12, 'triangle', 0.035], crossbow: [190, 45, 0.16, 'sawtooth', 0.025],
            emberstaff: [95, 35, 0.32, 'sawtooth', 0.035], hit: [130, 60, 0.07, 'square', 0.02],
            hurt: [80, 35, 0.22, 'sawtooth', 0.045], kill: [160, 30, 0.23, 'triangle', 0.06],
            pickup: [440, 880, 0.18, 'sine', 0.06], sigil: [330, 990, 0.65, 'sine', 0.07],
            empty: [70, 60, 0.05, 'square', 0.02], portal: [180, 720, 1.1, 'sine', 0.08],
            warning: [280, 220, 0.14, 'triangle', 0.025],
        };
        const [from, to, duration, type, volume] = notes[kind] || notes.pickup;
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = type;
        oscillator.frequency.setValueAtTime(from, ctx.currentTime);
        oscillator.frequency.exponentialRampToValueAtTime(to, ctx.currentTime + duration);
        gain.gain.setValueAtTime(volume, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start();
        oscillator.stop(ctx.currentTime + duration);
        oscillator.onended = () => { oscillator.disconnect(); gain.disconnect(); };
    }
    dispose() { this.context?.close().catch(() => {}); this.context = null; }
}
