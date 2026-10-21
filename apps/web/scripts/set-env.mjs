// Bakes a client's identity into the build from environment variables, so one
// repo deploys to many clients without per-client source edits: each Cloudflare
// Pages project (or any CI) sets the variables and the build bakes them in.
//
// A static SPA cannot read Cloudflare's *runtime* variables (its JS runs in the
// browser, not on the edge), so the values must be injected here, at build time.
//
// It stamps two committed files, each part guarded by its own variables:
//
//   API_BASE_URL / PUBLIC_SITE_URL  -> src/environments/environment.ts
//   SITE_NAME / FAVICON_URL /        -> index.html: the pre-bootstrap tab title,
//   BRAND_PRIMARY                       the favicon, and an inline brand colour
//                                       so even the FIRST paint of a white-label
//                                       build is branded (no flash of the
//                                       product default before the API answers).
//
// With none set — a plain local `ng build` — this is a no-op and the committed
// defaults are used untouched, so it never dirties the working tree. Those
// committed copies must therefore stay the generic defaults; the stamped files
// are build output, not something to commit.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const src = (rel) => fileURLToPath(new URL('../src/' + rel, import.meta.url));

const apiFromEnv = process.env.API_BASE_URL?.trim();
const siteFromEnv = process.env.PUBLIC_SITE_URL?.trim();
const siteName = process.env.SITE_NAME?.trim();
const faviconUrl = process.env.FAVICON_URL?.trim();
const brandPrimary = process.env.BRAND_PRIMARY?.trim();

if (!apiFromEnv && !siteFromEnv && !siteName && !faviconUrl && !brandPrimary) {
  console.log('[set-env] no client vars set — keeping the committed generic defaults');
  process.exit(0);
}

// --- environment.ts (API + public site) -------------------------------------
if (apiFromEnv || siteFromEnv) {
  // Defaults cover whichever variable a deployment leaves unset.
  const apiBaseUrl = apiFromEnv ?? 'https://api-midwesternlearning.midwesternog.com/v1';
  const publicSiteUrl = siteFromEnv ?? 'https://midwesternlearning.midwesternog.com';
  const production = (process.env.NG_PRODUCTION ?? 'true').toLowerCase() !== 'false';

  if (!/^https?:\/\//i.test(apiBaseUrl) || !/^https?:\/\//i.test(publicSiteUrl)) {
    console.error('[set-env] API_BASE_URL and PUBLIC_SITE_URL must be absolute http(s) URLs.');
    process.exit(1);
  }

  writeFileSync(
    src('environments/environment.ts'),
    `import type { AppEnvironment } from './environment.model';

/**
 * GENERATED AT BUILD TIME by scripts/set-env.mjs from environment variables.
 *
 * Do not hand-edit for a deployment — set API_BASE_URL and PUBLIC_SITE_URL in
 * the Cloudflare Pages project (or your CI) and they are baked in here. The
 * copy committed to git holds the defaults used when neither variable is set.
 */
export const environment: AppEnvironment = {
  production: ${production},

  api: {
    // Base URL of the API, including the version segment, no trailing slash.
    baseUrl: ${JSON.stringify(apiBaseUrl)},
  },

  publicSiteUrl: ${JSON.stringify(publicSiteUrl)},
};
`,
  );
  console.log(`[set-env] environment.ts — api.baseUrl=${apiBaseUrl} publicSiteUrl=${publicSiteUrl}`);
}

// --- index.html (tab title, favicon, first-paint brand colour) --------------
if (siteName || faviconUrl || brandPrimary) {
  if (brandPrimary && !/^#[0-9a-f]{6}$/i.test(brandPrimary)) {
    console.error('[set-env] BRAND_PRIMARY must be a 6-digit hex colour like #2400a0.');
    process.exit(1);
  }

  let index = readFileSync(src('index.html'), 'utf8');

  if (siteName) {
    index = index.replace(/<title>[^<]*<\/title>/, `<title>${siteName}</title>`);
  }

  if (faviconUrl) {
    if (!/^https?:\/\//i.test(faviconUrl) && !faviconUrl.startsWith('/')) {
      console.error('[set-env] FAVICON_URL must be an absolute URL or a root-relative path.');
      process.exit(1);
    }
    index = index.replace(
      /(<link rel="shortcut icon" href=")[^"]*(")/,
      `$1${faviconUrl}$2`,
    );
  }

  if (brandPrimary) {
    const rgb = [1, 3, 5].map((i) => parseInt(brandPrimary.slice(i, i + 2), 16)).join(', ');
    // Inline so it applies on the very first paint, overriding the compiled
    // default in styles.scss before BrandingService has run.
    const style = `  <style>:root{--brand-primary:${brandPrimary};--brand-primary-rgb:${rgb};}</style>\n</head>`;
    index = index.replace('</head>', style);
  }

  writeFileSync(src('index.html'), index);
  console.log(`[set-env] index.html — title=${siteName ?? '(unchanged)'} favicon=${faviconUrl ?? '(unchanged)'} brand=${brandPrimary ?? '(unchanged)'}`);
}
