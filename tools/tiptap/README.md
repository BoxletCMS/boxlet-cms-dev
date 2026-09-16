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

## Switching the live checkout between Trix and the spike

One command each way, from anywhere:

```sh
git -C /home/svejedobro-boxlet/htdocs/boxlet.svejedobro.hr switch main          # Trix
git -C /home/svejedobro-boxlet/htdocs/boxlet.svejedobro.hr switch spike/tiptap  # TipTap
```

Nothing else to do. Both editors' assets are committed, there is no build step at runtime,
and the branches differ in no migration, so the database is untouched by the switch. A
hard refresh in the browser is worth it: asset URLs are versioned by file, so a changed
file gets a new URL, but a page already open keeps the scripts it loaded.
