// @capacitor/assets rewrites the web manifest's icon list with a hardcoded
// "image/png" type, even for the .webp files it emits. This corrects each
// icon's type to match its extension. Run after `assets` (see the `brand`
// script); it leaves every other manifest field untouched.
import { readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const path = resolve(root, 'src/manifest.webmanifest');

const typeFor = { webp: 'image/webp', png: 'image/png', svg: 'image/svg+xml', ico: 'image/x-icon' };

const manifest = JSON.parse(await readFile(path, 'utf8'));
for (const icon of manifest.icons ?? []) {
  const ext = (icon.src.split('.').pop() ?? '').toLowerCase();
  if (typeFor[ext]) {
    icon.type = typeFor[ext];
  }
}
await writeFile(path, JSON.stringify(manifest, null, 2) + '\n');
console.log('manifest icon types corrected');
