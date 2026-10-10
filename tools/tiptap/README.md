# The TipTap bundle recipe

Maintainer-only. Nobody installing Boxlet ever runs anything in here: the bundle is
committed at `public/assets/vendor/tiptap.bundle.min.js` and loaded with a plain script
tag, like every other vendored asset. This directory is the recipe that produced it.

The exception to "no npm, no build step" is recorded as PLAN.md D-017 and covers this one
bundle. A second one would need its own decision.

## Rebuilding

Built outside the project, so no `node_modules` ever sits in it:

```sh
cp -r tools/tiptap ~/boxlet-build/
cd ~/boxlet-build/tiptap
npm ci
npm run build
node header.mjs > header.txt
cat header.txt tiptap.bundle.min.js > …/public/assets/vendor/tiptap.bundle.min.js
```

The header names every bundled package, its version and its licence. `header.mjs` writes
it from the build's metafile (`meta.json`, which `npm run build` leaves), the lockfile and
each package's own licence: it is generated, never typed by hand.

Built and verified on Node 18.19.1 with esbuild 0.24.2.

## What the bundle exposes

`window.BoxletTipTap` — `Editor`, `StarterKit`, `Link`, `BubbleMenu` (D-186: the canvas's
rich text toolbar over a selection, placed by Floating UI, which it brings), `Node` (D-201: a
replacement tag's chip) and `Extension` (D-217: a paragraph's alignment), and nothing else.
`public/assets/richtext.js` configures the schema there, not here: the nodes and marks it
enables are exactly the storage whitelist (SPEC §5.3), so the editor cannot offer markup
the server would discard. Two settings in it were decided by measuring the editor's output
rather than by reading about it, and both have comments saying why:

- `trailingNode: false`, or any content not ending in a paragraph gains an empty one, and
  a field changes on its first save.
- the link's `HTMLAttributes: { target: null, rel: null }`, or every link is stored with
  attributes the whitelist does not allow.
