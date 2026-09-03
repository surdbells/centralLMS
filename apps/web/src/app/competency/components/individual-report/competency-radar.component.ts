import { Component, Input, OnChanges } from '@angular/core';
import { CommonModule } from '@angular/common';

interface Point { x: number; y: number; }
interface AxisLabel { x: number; y: number; anchor: 'start' | 'middle' | 'end'; lines: string[]; dy0: number; }
interface Scale { y: number; text: string; }

/**
 * The competency profile as a radar (spider) chart: one spoke per competency,
 * a person's demonstrated proficiency against the ideal the job requires.
 *
 * Drawn as plain SVG — the way the rest of the reporting is — so it scales,
 * prints, and needs no chart library. All the geometry is worked out here and
 * the template only draws what it is handed.
 */
@Component({
  selector: 'app-competency-radar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <figure class="cr" *ngIf="axes >= 3">
      <figcaption class="cr-title">Competency Profile Chart</figcaption>
      <div class="cr-legend">
        <span class="cr-key"><i class="cr-swatch cr-prof"></i>Proficiency rating</span>
        <span class="cr-key"><i class="cr-swatch cr-ideal"></i>Ideal Rating</span>
      </div>
      <svg [attr.viewBox]="'0 0 ' + W + ' ' + H" preserveAspectRatio="xMidYMid meet" class="cr-svg" role="img"
           aria-label="Competency profile radar chart comparing proficiency against ideal rating">
        <!-- rings -->
        <polygon *ngFor="let ring of rings" [attr.points]="ring" class="cr-ring"></polygon>
        <!-- spokes -->
        <line *ngFor="let s of spokes" [attr.x1]="s.x1" [attr.y1]="s.y1" [attr.x2]="s.x2" [attr.y2]="s.y2" class="cr-spoke"></line>
        <!-- scale numbers up the top axis -->
        <text *ngFor="let t of scale" [attr.x]="cx - 6" [attr.y]="t.y + 3" class="cr-scale">{{ t.text }}</text>
        <!-- ideal (orange), drawn first so proficiency sits over it -->
        <polygon [attr.points]="idealPoly" class="cr-area cr-ideal-area"></polygon>
        <circle *ngFor="let d of idealDots" [attr.cx]="d.x" [attr.cy]="d.y" r="3.5" class="cr-dot cr-ideal-dot"></circle>
        <!-- proficiency (blue) -->
        <polygon [attr.points]="profPoly" class="cr-area cr-prof-area"></polygon>
        <circle *ngFor="let d of profDots" [attr.cx]="d.x" [attr.cy]="d.y" r="3.5" class="cr-dot cr-prof-dot"></circle>
        <!-- competency labels -->
        <text *ngFor="let l of labels" [attr.x]="l.x" [attr.y]="l.y" [attr.text-anchor]="l.anchor" class="cr-label">
          <tspan *ngFor="let line of l.lines; let first = first" [attr.x]="l.x" [attr.dy]="first ? l.dy0 : 12">{{ line }}</tspan>
        </text>
      </svg>
    </figure>
  `,
  styles: [`
    .cr { margin: 0 0 1.25rem; text-align: center; break-inside: avoid; }
    .cr-title { font-size: 1rem; font-weight: 700; color: #3a4a5a; margin-bottom: .25rem; }
    .cr-legend { display: flex; justify-content: center; gap: 1.5rem; margin-bottom: .25rem; }
    .cr-key { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: #444; }
    .cr-swatch { width: 22px; height: 3px; border-radius: 2px; display: inline-block; position: relative; }
    .cr-swatch::after { content: ''; position: absolute; top: 50%; left: 50%; width: 7px; height: 7px; border-radius: 50%; transform: translate(-50%, -50%); background: inherit; }
    .cr-prof { background: #1f5f8b; }
    .cr-ideal { background: #e8833a; }
    .cr-svg { width: 100%; max-width: 720px; height: auto; }
    .cr-ring { fill: none; stroke: #dcdcdc; stroke-width: 1; }
    .cr-spoke { stroke: #dcdcdc; stroke-width: 1; }
    .cr-scale { fill: #8a8a8a; font-size: 11px; text-anchor: end; }
    .cr-label { fill: #4a4a4a; font-size: 11px; }
    .cr-area { fill-opacity: .08; stroke-width: 2; stroke-linejoin: round; }
    .cr-prof-area { fill: #1f5f8b; stroke: #1f5f8b; }
    .cr-ideal-area { fill: #e8833a; stroke: #e8833a; }
    .cr-prof-dot { fill: #1f5f8b; }
    .cr-ideal-dot { fill: #e8833a; }
  `],
})
export class CompetencyRadarComponent implements OnChanges {
  /** One entry per competency, in the order the spokes go round. */
  @Input() categories: string[] = [];
  /** The demonstrated level per competency (same order as categories). */
  @Input() proficiency: number[] = [];
  /** The required/ideal level per competency. */
  @Input() ideal: number[] = [];
  /** Top of the scale. Levels run 0..3. */
  @Input() max = 3;

  readonly W = 760;
  readonly H = 620;
  readonly cx = 380;
  readonly cy = 320;
  private readonly R = 210;

  axes = 0;
  rings: string[] = [];
  spokes: Array<{ x1: number; y1: number; x2: number; y2: number }> = [];
  scale: Scale[] = [];
  labels: AxisLabel[] = [];
  idealPoly = '';
  profPoly = '';
  idealDots: Point[] = [];
  profDots: Point[] = [];

  ngOnChanges(): void {
    this.build();
  }

  private build(): void {
    const n = this.categories.length;
    this.axes = n;
    if (n < 3) {
      return;
    }

    // Ring / scale ticks, every half-level like the source report.
    const ticks: number[] = [];
    for (let t = 0.5; t <= this.max + 1e-9; t += 0.5) {
      ticks.push(Math.round(t * 10) / 10);
    }

    this.rings = ticks.map((t) =>
      this.range(n).map((k) => this.pointStr(t, k)).join(' '));

    this.spokes = this.range(n).map((k) => {
      const p = this.point(this.max, k);
      return { x1: this.cx, y1: this.cy, x2: p.x, y2: p.y };
    });

    // The 0 at the centre and each tick up the top axis (axis 0, pointing up).
    this.scale = [{ y: this.cy, text: '0' }, ...ticks.map((t) => ({
      y: this.cy - (t / this.max) * this.R,
      text: String(t),
    }))];

    this.labels = this.range(n).map((k) => this.label(k, n));

    this.idealPoly = this.polygon(this.ideal);
    this.profPoly = this.polygon(this.proficiency);
    this.idealDots = this.dots(this.ideal);
    this.profDots = this.dots(this.proficiency);
  }

  // --- geometry -------------------------------------------------------------

  private angle(k: number): number {
    // Start at the top and go clockwise, like the source chart.
    return (-90 + (360 / this.categories.length) * k) * Math.PI / 180;
  }

  private point(value: number, k: number): Point {
    const clamped = Math.max(0, Math.min(this.max, value));
    const r = (clamped / this.max) * this.R;
    const a = this.angle(k);
    return { x: this.cx + r * Math.cos(a), y: this.cy + r * Math.sin(a) };
  }

  private pointStr(value: number, k: number): string {
    const p = this.point(value, k);
    return `${this.round(p.x)},${this.round(p.y)}`;
  }

  private polygon(values: number[]): string {
    return this.range(this.categories.length)
      .map((k) => this.pointStr(values[k] ?? 0, k))
      .join(' ');
  }

  private dots(values: number[]): Point[] {
    return this.range(this.categories.length).map((k) => {
      const p = this.point(values[k] ?? 0, k);
      return { x: this.round(p.x), y: this.round(p.y) };
    });
  }

  private label(k: number, n: number): AxisLabel {
    const a = this.angle(k);
    const cos = Math.cos(a);
    const sin = Math.sin(a);
    // Sit the label a little beyond the outer ring.
    const rr = this.R + 14;
    const x = this.round(this.cx + rr * cos);
    const y = this.round(this.cy + rr * sin);

    const anchor: AxisLabel['anchor'] = cos > 0.25 ? 'start' : cos < -0.25 ? 'end' : 'middle';
    const lines = this.wrap(this.categories[k] ?? '', 20);
    // Centre a multi-line block on the label point.
    const dy0 = -((lines.length - 1) * 12) / 2 + (sin < -0.6 ? -4 : sin > 0.6 ? 4 : 0);

    return { x, y, anchor, lines, dy0 };
  }

  /** Greedy word wrap into at most three lines. */
  private wrap(text: string, maxChars: number): string[] {
    const words = text.split(/\s+/).filter(Boolean);
    const lines: string[] = [];
    let current = '';

    for (const word of words) {
      const candidate = current === '' ? word : current + ' ' + word;
      if (candidate.length > maxChars && current !== '') {
        lines.push(current);
        current = word;
      } else {
        current = candidate;
      }
    }
    if (current !== '') {
      lines.push(current);
    }

    if (lines.length > 3) {
      const kept = lines.slice(0, 3);
      kept[2] = kept[2].replace(/\s+\S*$/, '') + '…';
      return kept;
    }

    return lines.length ? lines : [''];
  }

  private range(n: number): number[] {
    return Array.from({ length: n }, (_, i) => i);
  }

  private round(v: number): number {
    return Math.round(v * 100) / 100;
  }
}
