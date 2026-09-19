/*
 * D-026: cropping a picture by hand.
 *
 * What the PHP tests cannot answer. tests/media_crop_test.php proves the arithmetic and the
 * refusals by calling the server directly; it never opens the dialog. This asks whether a
 * person can: whether the box appears, whether the shapes lock it, whether the two buttons
 * do what they say — and, the reason the architect asked for it, whether Cropper triggers a
 * Content Security Policy violation.
 *
 * THE CSP CHECK IS THE POINT. Trix was rejected partly because it injects a stylesheet the
 * admin's policy refuses, and the policy is never loosened for a library. Cropper looked
 * safe under a static read of its source — no createElement('style'), styles set through
 * cssText — but reading minified code is a weak instrument. The console is the authority,
 * so violations are collected here rather than assumed away.
 *
 * It uploads its own picture and removes it, along with whatever the crop created.
 */
import { existsSync } from 'node:fs';
import { BASE, ADMIN } from '../config.mjs';
import { login, fixtures } from '../harness.mjs';
import { PHOTO, uploadPhoto, cardFor, attemptDelete, markerFor } from '../media-helpers.mjs';

/** What the library calls the photograph this scenario uploads, derived from the file. */
const MARKER = markerFor(PHOTO);

export default {
  name: 'crop',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('crop: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'crop', [PHOTO], 'one photograph to crop') === null) {
      return;
    }

    // Collected for the whole scenario: a policy violation anywhere while the dialog is
    // open is a failure, whichever step provoked it.
    const refused = [];
    page.on('console', (message) => {
      const text = message.text();
      if (/Content Security Policy|Refused to/i.test(text)) {
        refused.push(text.slice(0, 160));
      }
    });

    await uploadPhoto(page, PHOTO);
    const card = await cardFor(page, MARKER);
    const mediaId = card && card.href ? Number((card.href.match(/\/admin\/media\/(\d+)/) || [])[1]) : null;
    if (!Number.isInteger(mediaId)) {
      report.fail('crop: a picture to crop', `could not read an id from ${card && card.href}`);
      return;
    }

    const created = [mediaId];

    try {
      await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });

      // The button is hidden in the markup and revealed by media-crop.js. Hidden here would
      // mean the script did not run — which is exactly how a missing asset would look.
      const opener = await page.$eval('[data-crop-open]', (el) => !el.hidden).catch(() => null);
      report.verdict('the crop control appears once its script has run',
        opener === true,
        opener === null ? 'no crop button on the screen at all' : `button visible: ${opener}`);

      if (opener !== true) {
        return;
      }

      await page.click('[data-crop-open]');
      const boxed = await page.waitForSelector('.cropper-container', { timeout: 15000 })
        .then(() => true).catch(() => false);
      report.verdict('the crop box opens over the picture', boxed,
        boxed ? 'cropper-container is present' : 'Cropper never built its box');

      // Each shape locks the box and says so, including to assistive technology.
      const shapes = await page.$$eval('[data-crop-ratio]', (els) => els.map((el) => el.getAttribute('data-crop-name')));
      const pressed = [];
      for (const shape of shapes) {
        await page.click(`[data-crop-name="${shape}"]`);
        pressed.push(await page.$eval(`[data-crop-name="${shape}"]`, (el) => el.getAttribute('aria-pressed')));
      }
      report.verdict('every shape can be chosen and reports itself chosen',
        shapes.length === 5 && pressed.every((state) => state === 'true'),
        `shapes ${JSON.stringify(shapes)}, pressed ${JSON.stringify(pressed)}`);

      await page.click('[data-crop-name="card"]');
      await report.shot(page, '01-crop-dialog', { fullPage: false });

      // ---- save as new: the original stays ------------------------------------------------
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
        page.click('button[name="action"][value="new"]'),
      ]);

      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      const afterNew = await page.$$eval('tr.media-row', (els) => els.length);
      const cropCard = await cardFor(page, 'crop');
      if (cropCard && cropCard.href) {
        const id = Number((cropCard.href.match(/\/admin\/media\/(\d+)/) || [])[1]);
        if (Number.isInteger(id) && !created.includes(id)) {
          created.push(id);
        }
      }

      report.verdict('saving as new adds the crop and leaves the original',
        afterNew >= 2 && cropCard !== null && cropCard.naturalWidth > 0,
        `${afterNew} cards; crop card ${cropCard ? `"${cropCard.name}" decoded at ${cropCard.naturalWidth}px` : 'MISSING'}`);

      // ---- replace: the id stays, the picture changes -------------------------------------
      await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
      // The SECOND dd is the dimensions; the first is "Uploaded as". Reading the first and
      // calling it dimensions produced a detail line that compared a size to a file name.
      const before = await page.$$eval('.media-facts-list dd',
        (els) => (els[1] ? els[1].textContent.trim() : '?')).catch(() => '?');
      await page.click('[data-crop-open]');
      await page.waitForSelector('.cropper-container', { timeout: 15000 });
      await page.click('[data-crop-name="thumb"]');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }),
        page.click('button[name="action"][value="replace"]'),
      ]);

      await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
      const stillThere = await page.$eval('h1', (el) => el.textContent.trim()).catch(() => null);
      const after = await page.$$eval('.media-facts-list dd', (els) => els.map((el) => el.textContent.trim()));

      report.verdict('replacing keeps the same picture at the same address',
        stillThere !== null && after.length > 0,
        `/admin/media/${mediaId} still shows "${stillThere}", dimensions now ${after[1] || '?'} (were ${before})`);

      // ---- the whole point of asking a browser ---------------------------------------------
      report.verdict('cropping causes no Content Security Policy violation',
        refused.length === 0,
        refused.length === 0 ? 'the console stayed quiet' : JSON.stringify(refused.slice(0, 3)));
    } finally {
      for (const id of created) {
        try {
          await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' });
          await attemptDelete(page);
        } catch {
          // Reported below by what is left behind, rather than throwing out of cleanup.
        }
      }
      // By the ids this run made, not by a name: a name pattern also caught a picture the
      // owner had cropped days before ("buddhist-jpg-crop") and reported it as left behind.
      let left = 0;
      for (const id of created) {
        const response = await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' }).catch(() => null);
        if (response === null || response.status() !== 404) {
          left++;
        }
      }
      report.verdict('the scenario removes what it made', left === 0, `${left} of ${created.length} picture(s) it made still there: ids ${created.join(', ')}`);
    }
  },
};
