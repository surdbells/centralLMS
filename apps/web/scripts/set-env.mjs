// Generates src/environments/environment.ts from build-time environment
// variables, so one repo deploys to many clients without per-client source
// edits: each Cloudflare Pages project (or any CI) sets API_BASE_URL and
// PUBLIC_SITE_URL, and the build bakes them into the bundle.
//
// A static SPA cannot read Cloudflare's *runtime* variables (its JS runs in the
// browser, not on the edge), so the values must be injected here, at build time.
//
// With neither variable set — e.g. a local `ng build` — this is a no-op and the
// committed environment.ts (the defaults) is used untouched, so it never dirties
// the working tree.

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const apiFromEnv = process.env.API_BASE_URL?.trim();
const siteFromEnv = process.env.PUBLIC_SITE_URL?.trim();

if (!apiFromEnv && !siteFromEnv) {
  console.log('[set-env] API_BASE_URL / PUBLIC_SITE_URL not set — keeping committed environment.ts');
  process.exit(0);
}

// Defaults cover whichever variable a deployment leaves unset.
const apiBaseUrl = apiFromEnv ?? 'https://api-midwesternlearning.midwesternog.com/v1';
const publicSiteUrl = siteFromEnv ?? 'https://midwesternlearning.midwesternog.com';
const production = (process.env.NG_PRODUCTION ?? 'true').toLowerCase() !== 'false';

if (!/^https?:\/\//i.test(apiBaseUrl) || !/^https?:\/\//i.test(publicSiteUrl)) {
  console.error('[set-env] API_BASE_URL and PUBLIC_SITE_URL must be absolute http(s) URLs.');
  process.exit(1);
}

const target = fileURLToPath(new URL('../src/environments/environment.ts', import.meta.url));

const contents = `import type { AppEnvironment } from './environment.model';

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
`;

writeFileSync(target, contents);
console.log(`[set-env] Wrote environment.ts — api.baseUrl=${apiBaseUrl} publicSiteUrl=${publicSiteUrl} production=${production}`);
