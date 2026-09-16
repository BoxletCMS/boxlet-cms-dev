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
```

Then copy `tiptap.bundle.min.js` back over `public/assets/vendor/tiptap.bundle.min.js`,
**keeping the header**, which names every bundled package, its version and its licence.
Regenerate the header if the versions change; it is generated from the build's metafile
and the lockfile, not typed by hand.

Built and verified on Node 18.19.1 with esbuild 0.24.2.

## What the bundle exposes

`window.BoxletTipTap` — `Editor`, `StarterKit` and `Link`, and nothing else.
`public/assets/richtext.js` configures the schema there, not here: the nodes and marks it
enables are exactly the storage whitelist (SPEC §5.3), so the editor cannot offer markup
the server would discard. Two settings in it were decided by measuring the editor's output
rather than by reading about it, and both have comments saying why:

- `trailingNode: false`, or any content not ending in a paragraph gains an empty one, and
  a field changes on its first save.
- the link's `HTMLAttributes: { target: null, rel: null }`, or every link is stored with
  attributes the whitelist does not allow.
