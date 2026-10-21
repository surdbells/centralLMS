// Fans the source art (assets/*.png from generate-source-art.mjs) into every
// native + PWA icon and splash size via @capacitor/assets.
//
// Why a wrapper, not an inline npm script: the old `assets` script single-quoted
// its colour flags (`--iconBackgroundColor '#3316a6'`). npm/pnpm run scripts
// through cmd.exe on Windows, which does NOT strip single quotes, so
// @capacitor/assets received the literal `'#3316a6'` and crashed with
// "Unable to parse color from string: '#3316a6'", aborting icon generation (the
// app then kept Capacitor's default icon). Passing the colours as an argv array
// to a child process — no shell — sidesteps shell quoting on every platform,
// and lets the light background colour follow BRAND_COLOR for a per-client
// build. (The Android adaptive icon uses the generated icon-background.png
// regardless; these colours flatten the iOS icon and back the splash screens.)

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const brand = process.env.BRAND_COLOR?.trim() || '#3316a6';
// Dark surfaces keep the app's own night field unless an override is given.
const iconDark = process.env.BRAND_COLOR_DARK?.trim() || '#161c2b';
const splashDark = process.env.SPLASH_COLOR_DARK?.trim() || '#0f1420';

// Resolve the @capacitor/assets CLI entry and run it with this Node, so the
// arguments never pass through a shell.
const pkgJsonPath = require.resolve('@capacitor/assets/package.json');
const cli = resolve(dirname(pkgJsonPath), require(pkgJsonPath).bin['capacitor-assets']);

const args = [
  cli,
  'generate',
  '--pwaManifestPath', 'src/manifest.webmanifest',
  '--iconBackgroundColor', brand,
  '--iconBackgroundColorDark', iconDark,
  '--splashBackgroundColor', brand,
  '--splashBackgroundColorDark', splashDark,
];

console.log(`[assets] @capacitor/assets — iconBackgroundColor=${brand}`);

const res = spawnSync(process.execPath, args, { cwd: root, stdio: 'inherit' });

if (res.error) {
  console.error('[assets] could not run @capacitor/assets:', res.error.message);
  process.exit(1);
}

process.exit(res.status ?? 1);
