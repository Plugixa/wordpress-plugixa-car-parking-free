/*
 * Copies front-end vendor libraries out of node_modules into ./vendor so they
 * can be enqueued by WordPress without shipping node_modules. Runs on
 * `npm install` via the postinstall hook. Safe to run repeatedly.
 *
 * Usage:  node copy-vendor.mjs [destination]
 *
 * With no argument the files go to ./vendor beside this script. The release
 * script names a directory inside its stage instead, so what is packaged comes
 * from node_modules at the version package-lock.json pins and not from
 * whatever an earlier install left in ./vendor. With a destination, a file
 * that is missing from node_modules is an error: a release must not go out
 * with half a calendar.
 *
 * Sources are found from this script's own directory, so it does not matter
 * where it is run from.
 */
import { mkdirSync, copyFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

const here = import.meta.dirname;
const packageDir = join(here, 'node_modules', 'flatpickr');
const strict = Boolean(process.argv[2]);
const destination = strict ? resolve(process.argv[2]) : join(here, 'vendor');

// The .min.* files are what WordPress enqueues; the unminified twins ship
// alongside them because the WordPress.org review requires human-readable
// source for any compressed third-party code in the plugin.
const files = [
  ['dist/flatpickr.min.js', 'flatpickr/flatpickr.min.js'],
  ['dist/flatpickr.min.css', 'flatpickr/flatpickr.min.css'],
  ['dist/flatpickr.js', 'flatpickr/flatpickr.js'],
  ['dist/flatpickr.css', 'flatpickr/flatpickr.css'],
  ['LICENSE.md', 'flatpickr/LICENSE.md'],
];

// The calendar's translations: one small, unminified file per language. The
// page loads the one that matches the site's language, and English is built
// into the library itself, so `default.js` and the all-in-one `index.js` are
// left behind.
const l10n = join(packageDir, 'dist', 'l10n');
if (existsSync(l10n)) {
  for (const name of readdirSync(l10n)) {
    if (name.endsWith('.js') && name !== 'index.js' && name !== 'default.js') {
      files.push([`dist/l10n/${name}`, `flatpickr/l10n/${name}`]);
    }
  }
} else if (strict) {
  console.error(`[copy-vendor] missing ${l10n}. Run \`npm install\` in assets/frontend.`);
  process.exit(1);
}

let copied = 0;
const missing = [];
for (const [from, to] of files) {
  const src = join(packageDir, from);
  const dest = join(destination, to);
  if (!existsSync(src)) {
    missing.push(src);
    console.warn(`[copy-vendor] missing ${src} — skipping`);
    continue;
  }
  mkdirSync(dirname(dest), { recursive: true });
  copyFileSync(src, dest);
  copied++;
}

if (strict && missing.length) {
  console.error(`[copy-vendor] ${missing.length} file(s) missing from node_modules. Run \`npm install\` in assets/frontend.`);
  process.exit(1);
}

console.log(`[copy-vendor] copied ${copied} files into ${strict ? destination : 'vendor/'}`);
