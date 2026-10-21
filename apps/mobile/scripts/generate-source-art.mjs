// Generates the branded source art for @capacitor/assets from an original
// graduation-cap mark on the Central brand indigo — no external logo needed.
// Run with `pnpm --filter @central/mobile art`; then `pnpm ... assets` fans the
// PNGs out into the native projects. sharp rasterises the SVGs.
import sharp from 'sharp';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');

// The brand field. Overridable for a per-client build via BRAND_COLOR (the
// deeper shade, used for the icon/splash field and to match the native splash
// background) and BRAND_COLOR_LIGHT (the gradient's lighter end). With neither
// set, the Central indigo. Keep these in step with capacitor.config.ts's
// BRAND_COLOR and, if changed, the `assets` script's --*BackgroundColor flags.
const INDIGO = process.env.BRAND_COLOR ?? '#3316a6';
const INDIGO_LIGHT = process.env.BRAND_COLOR_LIGHT ?? '#5a3ff0';
const NIGHT = '#0f1420';
const NIGHT_2 = '#1b2233';

/** The mark, drawn in a 100×100 box centred near (50, 42). */
const cap = (fill = '#ffffff') => `
  <g>
    <path d="M50 36 L86 39.5 L86 60" fill="none" stroke="${fill}" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="86" cy="63.6" r="4.6" fill="${fill}"/>
    <path d="M33 48 L33 61 Q50 73 67 61 L67 48 Z" fill="${fill}"/>
    <path d="M50 16 L94 37 L50 58 L6 37 Z" fill="${fill}"/>
  </g>`;

const grad = (id, a, b) =>
  `<linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient>`;

/** Place the 100-box mark centred on (cx, cy) at the given scale. */
const placed = (cx, cy, scale, fill) =>
  `<g transform="translate(${cx - 50 * scale},${cy - 42.5 * scale}) scale(${scale})">${cap(fill)}</g>`;

async function render(svg, size, out) {
  await mkdir(dirname(out), { recursive: true });
  await sharp(Buffer.from(svg)).resize(size, size).png().toFile(out);
}

// --- the app icon: full indigo square + white mark (~60%) -------------------
const iconOnly = (s) => `<svg xmlns="http://www.w3.org/2000/svg" width="${s}" height="${s}" viewBox="0 0 1024 1024">
  <defs>${grad('g', INDIGO_LIGHT, INDIGO)}</defs>
  <rect width="1024" height="1024" fill="url(#g)"/>
  <circle cx="512" cy="512" r="360" fill="#ffffff" opacity="0.06"/>
  ${placed(512, 512, 7, '#ffffff')}
</svg>`;

// --- Android adaptive: background + a smaller foreground (safe zone) ---------
const iconBackground = `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <defs>${grad('g', INDIGO_LIGHT, INDIGO)}</defs>
  <rect width="1024" height="1024" fill="url(#g)"/>
</svg>`;

const iconForeground = `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  ${placed(512, 512, 5.1, '#ffffff')}
</svg>`;

// --- splash: brand field + centred mark -------------------------------------
const splash = (bgA, bgB, glow) => `<svg xmlns="http://www.w3.org/2000/svg" width="2732" height="2732" viewBox="0 0 2732 2732">
  <defs>${grad('g', bgA, bgB)}</defs>
  <rect width="2732" height="2732" fill="url(#g)"/>
  <circle cx="1366" cy="1300" r="560" fill="#ffffff" opacity="${glow}"/>
  ${placed(1366, 1300, 7.2, '#ffffff')}
</svg>`;

const jobs = [
  [iconOnly(1024), 1024, resolve(root, 'assets/icon-only.png')],
  [iconForeground, 1024, resolve(root, 'assets/icon-foreground.png')],
  [iconBackground, 1024, resolve(root, 'assets/icon-background.png')],
  [splash(INDIGO_LIGHT, INDIGO, 0.06), 2732, resolve(root, 'assets/splash.png')],
  [splash(NIGHT_2, NIGHT, 0.04), 2732, resolve(root, 'assets/splash-dark.png')],
  // Web favicon (referenced by index.html).
  [iconOnly(256), 256, resolve(root, 'src/assets/icon/favicon.png')],
];

for (const [svg, size, out] of jobs) {
  // eslint-disable-next-line no-await-in-loop
  await render(svg, size, out);
  console.log('wrote', out.replace(root + '\\', '').replace(root + '/', ''));
}
