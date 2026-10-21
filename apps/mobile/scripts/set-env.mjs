// Bakes a client's identity into the build from environment variables, so one
// repo ships to many clients without per-client source edits — the same
// build-time-injection idea as @central/web's scripts/set-env.mjs. Wired into
// the `build` script (runs before `ng build`).
//
// It stamps three committed files, each guarded by its own variable so an unset
// one is left untouched:
//
//   API_BASE_URL   -> src/environments/environment.prod.ts (the API the bundle
//                     talks to). `ng build` defaults to production, whose
//                     fileReplacements swap environment.ts for this one.
//   CAP_APP_NAME   -> the installed PWA name: manifest.webmanifest name /
//                     short_name / description and index.html <title>.
//   BRAND_COLOR    -> manifest theme_color + background_color and the
//                     index.html theme-color meta.
//
// With none set — a plain local build — this is a no-op and the committed
// defaults (the generic Central LMS app) are used untouched. Those committed
// copies must therefore stay the generic defaults, never a client's values;
// the injected files are build output, not something to commit.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const src = (rel) => fileURLToPath(new URL('../src/' + rel, import.meta.url));

const apiBaseUrl = process.env.API_BASE_URL?.trim();
const appName = process.env.CAP_APP_NAME?.trim();
const shortName = process.env.CAP_APP_SHORT_NAME?.trim() || appName?.split(/\s+/)[0];
const brandColor = process.env.BRAND_COLOR?.trim();

if (!apiBaseUrl && !appName && !brandColor) {
  console.log('[set-env] no client vars set — keeping the committed generic defaults');
  process.exit(0);
}

// --- the API the production bundle talks to ---------------------------------
if (apiBaseUrl) {
  if (!/^https?:\/\//i.test(apiBaseUrl)) {
    console.error('[set-env] API_BASE_URL must be an absolute http(s) URL, including the /v1 segment and no trailing slash.');
    process.exit(1);
  }

  const production = (process.env.NG_PRODUCTION ?? 'true').toLowerCase() !== 'false';

  writeFileSync(
    src('environments/environment.prod.ts'),
    `/**
 * GENERATED AT BUILD TIME by scripts/set-env.mjs from environment variables.
 *
 * Do not hand-edit or commit for a deployment — set API_BASE_URL in your CI (or
 * the hand-build environment) and it is baked in here. The copy committed to
 * git holds the generic default used when API_BASE_URL is not set.
 */
export const environment = {
  production: ${production},
  apiBaseUrl: ${JSON.stringify(apiBaseUrl)},
};
`,
  );
  console.log(`[set-env] environment.prod.ts — apiBaseUrl=${apiBaseUrl}`);
}

// --- the installed PWA name and brand colour (manifest + index.html) --------
if (appName || brandColor) {
  if (brandColor && !/^#[0-9a-f]{6}$/i.test(brandColor)) {
    console.error('[set-env] BRAND_COLOR must be a 6-digit hex colour like #3316a6.');
    process.exit(1);
  }

  // manifest.webmanifest — the source of the installed app's name and colours.
  const manifestPath = src('manifest.webmanifest');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

  if (appName) {
    manifest.name = appName;
    manifest.short_name = shortName;
    manifest.description = `Your courses, progress and certificates — the ${appName} learner app.`;
  }
  if (brandColor) {
    manifest.theme_color = brandColor;
    manifest.background_color = brandColor;
  }

  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  console.log(`[set-env] manifest.webmanifest — name=${manifest.name} theme=${manifest.theme_color}`);

  // index.html — the browser tab title and the address-bar theme colour.
  const indexPath = src('index.html');
  let index = readFileSync(indexPath, 'utf8');

  if (appName) {
    index = index.replace(/<title>[^<]*<\/title>/, `<title>${appName}</title>`);
  }
  if (brandColor) {
    index = index.replace(
      /(<meta\s+name="theme-color"\s+content=")[^"]*(")/,
      `$1${brandColor}$2`,
    );
  }

  writeFileSync(indexPath, index);
  console.log('[set-env] index.html — title/theme-color stamped');
}
