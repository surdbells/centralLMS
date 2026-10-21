// Generates src/environments/environment.prod.ts from a build-time environment
// variable, so one repo ships to many clients without per-client source edits:
// a CI job (or a hand build) sets API_BASE_URL and the production build bakes it
// into the bundle. This mirrors @central/web's scripts/set-env.mjs.
//
// `ng build` defaults to the production configuration, whose fileReplacements
// swap environment.ts for environment.prod.ts — so that is the file written.
//
// With API_BASE_URL unset — e.g. a plain local build — this is a no-op and the
// committed environment.prod.ts (the generic default) is used untouched, so it
// never dirties the working tree. The committed copy must therefore stay the
// generic default, not a client's value.

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const apiFromEnv = process.env.API_BASE_URL?.trim();

if (!apiFromEnv) {
  console.log('[set-env] API_BASE_URL not set — keeping committed environment.prod.ts');
  process.exit(0);
}

if (!/^https?:\/\//i.test(apiFromEnv)) {
  console.error('[set-env] API_BASE_URL must be an absolute http(s) URL, including the /v1 segment and no trailing slash.');
  process.exit(1);
}

// The production bundle is what ships in a store; a build that injects an API
// is a release build unless told otherwise.
const production = (process.env.NG_PRODUCTION ?? 'true').toLowerCase() !== 'false';

const target = fileURLToPath(new URL('../src/environments/environment.prod.ts', import.meta.url));

const contents = `/**
 * GENERATED AT BUILD TIME by scripts/set-env.mjs from environment variables.
 *
 * Do not hand-edit or commit for a deployment — set API_BASE_URL in your CI (or
 * the hand-build environment) and it is baked in here. The copy committed to
 * git holds the generic default used when API_BASE_URL is not set.
 */
export const environment = {
  production: ${production},
  apiBaseUrl: ${JSON.stringify(apiFromEnv)},
};
`;

writeFileSync(target, contents);
console.log(`[set-env] Wrote environment.prod.ts — apiBaseUrl=${apiFromEnv} production=${production}`);
