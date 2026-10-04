/*
 * The bundle's header, from the build's metafile and the lockfile (README): every package that
 * put code into the bundle, its version and its licence. Run after `npm run build`, where
 * node_modules is:
 *
 *   node header.mjs > header.txt
 */
import { readFileSync } from 'node:fs';

const meta = JSON.parse(readFileSync('meta.json', 'utf8'));
const lock = JSON.parse(readFileSync('package-lock.json', 'utf8'));
const exported = readFileSync('entry.js', 'utf8').match(/export \{[^}]*\bas (\w+)|export \{ (\w+) \}/g)
  .map((line) => line.match(/as (\w+)|\{ (\w+) \}/).slice(1).find(Boolean));
const used = new Set();
for (const input of Object.keys(meta.inputs)) {
  const at = input.lastIndexOf('node_modules/');
  if (at < 0) { continue; }
  const rest = input.slice(at + 'node_modules/'.length).split('/');
  used.add(rest[0].startsWith('@') ? `${rest[0]}/${rest[1]}` : rest[0]);
}
const rows = [...used].sort().map((name) => {
  // The lockfile holds the version; the licence is the package's own word for it.
  const own = JSON.parse(readFileSync(`node_modules/${name}/package.json`, 'utf8'));
  const version = (lock.packages[`node_modules/${name}`] || {}).version || own.version;
  return ` *   ${name.padEnd(35)}${version.padEnd(10)}${own.license || '?'}`;
});
const licences = [...new Set(rows.map((r) => r.trim().split(/\s+/).pop()))];
const esbuild = lock.packages['node_modules/esbuild'].version;
console.log(`/*
 * TipTap, bundled for Boxlet. Do not edit: rebuild it (PLAN.md D-017).
 *
 * Recipe: tools/tiptap/ (package.json, package-lock.json, entry.js). Built OUTSIDE the
 * project so no node_modules ever sits in it:
 *
 *   cp -r tools/tiptap ~/boxlet-build/ && cd ~/boxlet-build/tiptap
 *   npm ci && npm run build && node header.mjs > header.txt
 *   # then this header and tiptap.bundle.min.js, one after the other, over this file
 *
 * Nobody installing Boxlet builds anything; this file ships as it is, like every other
 * vendored asset. The exception to "no npm, no build step" is for the maintainer only
 * and was approved as D-017.
 *
 * Exposed as window.BoxletTipTap: ${exported.join(', ')}.
 * Built with esbuild ${esbuild}, format iife, target es2019, on Node ${process.version}.
 *
 * Bundled packages (${rows.length}), all ${licences.length === 1 ? licences[0] : licences.join(' or ')}:
${rows.join('\n')}
 *
 */`);
