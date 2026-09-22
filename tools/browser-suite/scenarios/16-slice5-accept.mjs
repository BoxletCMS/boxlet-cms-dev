/*
 * Slice 5's acceptance criterion, word for word from docs/SPEC.md §8:
 *
 *   "upload a 4 MB photo, place it in a hero, confirm the served file is WebP and under
 *    200 KB, confirm a second request does not hit PHP."
 *
 * WEBP OR AVIF. The encoder generates both (MediaEncoder::FORMATS) and a browser that
 * accepts AVIF is served AVIF, which §5.1 intends. §8's wording predates that, so what is
 * ACTUALLY served is recorded against both readings.
 *
 * NOTHING IS LEFT BEHIND on the development site (D-033). The photograph is placed in the
 * home page's hero IN THE EDITOR, never saved: the canvas draws a picture exactly as the
 * page does (MediaPicture), so what it asks for is what a visitor would be served. At the
 * end the photograph is deleted from the library by its own id — and only when this run
 * uploaded it.
 *
 * "DOES NOT HIT PHP" IS MEASURED, on the development site's real web server (D-020). A
 * variant is a file on disk; a server that answers it from disk says so in its ETag, which
 * nginx builds from the file's modification time and size, in hex. PHP never sends one
 * for a page here. So the second request's ETag is compared with the file itself: equal
 * means the web server read the file, and Boxlet's PHP was never asked.
 */
import { statSync } from 'node:fs';
import { BASE, ADMIN, PHOTOS, CHECKOUT } from '../config.mjs';
import { login } from '../harness.mjs';
import { attemptDelete } from '../media-helpers.mjs';

const PHOTO = `${PHOTOS}/big-photo.jpg`;
const NAME = 'big-photo';
const LIMIT = 200 * 1024;
const PAGE = 1;
const SETTLE = 2000;

const human = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(2)} MB` : `${Math.round(n / 1024)} KB`);
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The library card for the photograph, with its id, or null. */
const cardFor = (page) => page.$$eval('tr.media-row', (els, wanted) => {
  const found = els.find((el) => el.querySelector('.media-name')?.textContent.trim() === wanted);
  if (!found) return null;
  const href = found.querySelector('a.media-link')?.getAttribute('href') || '';
  return { id: Number((href.match(/\/(\d+)$/) || [])[1]), facts: found.textContent.replace(/\s+/g, ' ').trim() };
}, NAME);

export default {
  name: 'slice5-accept',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('slice5: log in', `could not log in; at ${page.url()}`);
      return;
    }
    const size = statSync(PHOTO).size;

    // ---- upload the photograph -----------------------------------------------------------
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const uploadedHere = (await cardFor(page)) === null;
    if (uploadedHere) {
      const input = await page.$('input[name="files[]"]');
      if (input === null) { report.fail('slice5: upload a 4 MB photo', 'no file input on the library'); return; }
      // Choosing a file uploads it at once (D-038): the navigation is the upload.
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 180000 }),
        input.uploadFile(PHOTO),
      ]);
    }
    const card = await cardFor(page);
    if (card === null || !Number.isInteger(card.id)) {
      report.fail('slice5: upload a 4 MB photo', `no library card named ${NAME} after uploading`);
      return;
    }
    report.verdict('upload a 4 MB photo', size > 4 * 1000 * 1000,
      `${human(size)}, media ${card.id}${uploadedHere ? '' : ' (already in the library)'}; ${card.facts.slice(0, 90)}`);

    try {
      // ---- place it in the hero, in the editor ---------------------------------------------
      await page.setViewport({ width: 1920, height: 1100, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
      await page.waitForFunction(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame && frame.contentDocument
          && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
      }, { timeout: 20000 });
      // Two different things, and they stopped being the same on D-094: data-block-group is
      // WHERE the block is drawn, and the key in a field name is WHICH block it is. This read
      // took the group and used it as both, so the select below had matched nothing since.
      const found = await page.$$eval('[data-block-group]', (groups) => {
        const hero = groups.find((g) => g.querySelector('input[name$="[type]"]')?.value === 'hero');
        if (!hero) { return null; }
        const field = hero.querySelector('input[name$="[type]"]');
        return { index: hero.getAttribute('data-block-group'), key: (field.name.match(/^blocks\[([^\]]+)\]/) || [])[1] };
      });
      if (found === null || !found.key) { report.fail('place it in a hero', `page ${PAGE} has no hero`); return; }
      const { index, key } = found;
      // Chosen on the canvas first, as a person does: only the selected block is redrawn.
      const frame = page.frames().find((f) => f.url().includes('/canvas'));
      await (await frame.$(`[data-bx-index="${index}"]`)).click();
      await page.waitForFunction((i) => !document.querySelector(`[data-block-group="${i}"]`).hidden, { timeout: 8000 }, index);
      await page.select(`[data-block-group="${index}"] select[name="blocks[${key}][image]"]`, String(card.id));
      // The canvas redraws the block from the server: wait for the picture, not a clock.
      await page.waitForFunction((i, wanted) => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame.contentDocument.querySelector(`[data-bx-index="${i}"] img[src*="${wanted}"]`) !== null;
      }, { timeout: 20000 }, index, NAME).catch(() => {});
      await wait(SETTLE);

      const drawn = await page.evaluate((i, wanted) => {
        const frame = document.querySelector('iframe[data-canvas]');
        const img = frame.contentDocument.querySelector(`[data-bx-index="${i}"] img[src*="${wanted}"]`);
        return img ? { current: img.currentSrc, largest: img.src, width: img.naturalWidth } : null;
      }, index, NAME);
      await report.shot(page, '01-hero-with-4mb-photo', { fullPage: false });
      report.verdict('place it in a hero', drawn !== null,
        drawn ? `the hero draws it at ${drawn.width} across` : 'no <img> for it in the hero');
      if (drawn === null) return;

      // ---- what is served ------------------------------------------------------------------
      const fetchOf = (url) => page.evaluate(async (u) => {
        const response = await fetch(u, { cache: 'reload' });
        const blob = await response.blob();
        return { status: response.status, type: response.headers.get('content-type'), bytes: blob.size, etag: response.headers.get('etag') };
      }, url);
      const chosen = await fetchOf(drawn.current);
      const largest = await fetchOf(drawn.largest);
      const preset = (drawn.current.match(/\/m\/([a-z]+)\//) || [])[1] || '?';
      const isWebp = (chosen.type || '').includes('webp');
      const isAvif = (chosen.type || '').includes('avif');

      report.verdict('the served file is WebP (SPEC §8) or AVIF (§5.1)', isWebp || isAvif,
        `a 1920-wide window at 2x chose ${preset}: ${chosen.type}, ${human(chosen.bytes)}`);
      report.verdict('the served file is under 200 KB', chosen.bytes < LIMIT,
        `${human(chosen.bytes)} of ${human(size)}; the largest candidate, ${drawn.largest.split('/m/')[1]}, is ${human(largest.bytes)}`);

      // ---- a second request, answered from disk --------------------------------------------
      const second = await fetchOf(drawn.current);
      const path = decodeURIComponent(new URL(drawn.current).pathname);
      const onDisk = statSync(`${CHECKOUT}/public${path}`);
      const expected = `"${Math.floor(onDisk.mtimeMs / 1000).toString(16)}-${onDisk.size.toString(16)}"`;
      report.verdict('a second request does not hit PHP', second.etag === expected,
        `ETag ${second.etag}; the file on disk gives ${expected} (mtime and size in hex, which only the web server reading the file can send)`);
    } finally {
      // Leave the editor unsaved, then take the photograph out again if this run put it in.
      await page.evaluate(() => { window.onbeforeunload = null; });
      if (uploadedHere) {
        await page.goto(`${BASE}/admin/media/${card.id}`, { waitUntil: 'networkidle2' });
        const said = await attemptDelete(page);
        await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
        report.verdict('the photograph is removed again', (await cardFor(page)) === null, said || '(no message)');
      }
    }
  },
};
