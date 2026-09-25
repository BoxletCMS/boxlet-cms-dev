/*
 * 4c: CHOOSING A PICTURE and what that stores. What the control looks like is 12-picker.mjs.
 *
 * Choose, change, clear, and a section's background picture (D-024) — each saved and then
 * read back from storage rather than from the form that was just submitted.
 *
 * WHAT "RENDERS" MEANS SINCE 4d. Both kinds of picture now reach the front end as a real
 * <picture>, so the placeholder that carried data-media-id is gone wherever the variants
 * exist. These checks follow the id into the variant URL instead — /m/<preset>/<id>-<name>
 * — and require the browser to have decoded it.
 *
 * That is a DELIBERATE CONTRACT CHANGE, not a selector quietly retargeted to whatever still
 * matches: the placeholder is now the fallback for a picture with no variants, so asserting
 * on it here would be asserting on the failure path and would pass hardest when 4d is most
 * broken.
 *
 * Whether those pictures survive all five characters is 14-front.mjs.
 *
 * Everything it stores it takes back: every picture field is left as it was found (D-117).
 */
import { existsSync } from 'node:fs';
import { BASE, ADMIN } from '../config.mjs';
import { login, fixtures } from '../harness.mjs';
import {
  PHOTOS, CONTENT_FIELD, SURFACE_FIELD, uploadPhoto, pick, posted, save, firstPageId, photographs,
} from '../media-helpers.mjs';

export default {
  name: 'choosing',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('choosing: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // Read from the directory, not named here — see media-helpers.mjs.
    const photos = photographs(2);
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'choosing', photos.length >= 2 ? photos : [], `two photographs in ${PHOTOS}`) === null) {
      return;
    }

    for (const photo of photos) {
      await uploadPhoto(page, photo);
    }
    if (await page.$$eval('tr.media-row', (els) => els.length).catch(() => 0) < 2) {
      report.fail('choosing: two pictures to choose between', 'fewer than two in the library');
      return;
    }

    const pageId = await firstPageId(page);
    if (!pageId) {
      report.fail('choosing: find a page to edit', 'no page link in the tree');
      return;
    }
    const formUrl = `${BASE}/admin/pages/${pageId}/form`;
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    /* EVERY PICTURE FIELD AS IT STANDS, before anything is chosen, so the end can put back
       exactly this (PLAN.md D-090, D-117). The end used to set them ALL to nothing, on the
       belief that the demo's first page has no pictures. It had them — the owner put a
       gallery and three pictures on it — and a whole-suite run on 2026-09-25 took every one
       of them off the development site. */
    const original = await page.$$eval('select[data-media-field]',
      (els) => els.map((el) => ({ name: el.name, value: el.value })));

    // ---- choose ---------------------------------------------------------------------------
    const first = await pick(page, CONTENT_FIELD, 0);
    report.verdict('choosing a picture writes it into the field that posts',
      first.value === first.chosen && first.value !== '',
      `chose ${first.chosen}, the field posts ${first.value}`);

    await save(page);
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const storedFirst = await posted(page, CONTENT_FIELD);
    report.verdict('the chosen picture is saved and comes back',
      storedFirst === first.chosen, `saved ${first.chosen}, reloaded as ${storedFirst}`);

    // It reaches the page a visitor gets, as a <picture> whose variant URL carries the
    // chosen id. Decoded, not merely present: a broken image is still an element with a src.
    const slug = await page.$eval('input[name="slug"]', (el) => el.value).catch(() => '');
    const published = await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
    const shown = await page.$$eval('section picture img',
      (els) => els.map((el) => ({ src: el.getAttribute('src'), decoded: el.naturalWidth })));
    await report.shot(page, '01-front-end');
    const mine = shown.filter((img) => new RegExp(`/m/[a-z]+/${first.chosen}-`).test(img.src || ''));
    report.verdict('the front end renders the picture that was chosen',
      published.status() === 200 && mine.length > 0 && mine.every((img) => img.decoded > 0),
      `/${slug} shows ${JSON.stringify(shown)}, expected a decoded variant of ${first.chosen}`);

    // ---- change ---------------------------------------------------------------------------
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const second = await pick(page, CONTENT_FIELD, 1);
    await save(page);
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const storedSecond = await posted(page, CONTENT_FIELD);
    report.verdict('changing the picture replaces the first one',
      storedSecond === second.chosen && storedSecond !== first.chosen,
      `was ${first.chosen}, chose ${second.chosen}, stored ${storedSecond}`);

    // ---- clear ----------------------------------------------------------------------------
    await page.$eval(CONTENT_FIELD, (el) => {
      el.parentNode.querySelector('.media-picker-current').click();
    });
    await page.waitForSelector('.media-picker-panel:not([hidden]) .media-picker-none', { timeout: 15000 });
    await page.click('.media-picker-panel:not([hidden]) .media-picker-none');
    const cleared = await posted(page, CONTENT_FIELD);
    await save(page);
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const storedCleared = await posted(page, CONTENT_FIELD);
    await report.shot(page, '02-cleared');
    report.verdict('choosing no picture clears the field and is saved',
      cleared === '' && storedCleared === '',
      `the field posts "${cleared}" and reloads as "${storedCleared}"`);

    // ---- a section's background picture (D-024) --------------------------------------------
    const hasSurface = await page.$(SURFACE_FIELD);
    if (!hasSurface) {
      report.skip('a section background picture is chosen the same way', 'no section style image field');
    } else {
      // The section style panel is a <details>; it has to be open before the field is
      // reachable by a click.
      await page.$$eval('details.block-style', (els) => els.forEach((el) => { el.open = true; }));
      const surface = await pick(page, SURFACE_FIELD, 0);
      await save(page);
      await page.goto(formUrl, { waitUntil: 'networkidle2' });
      await page.$$eval('details.block-style', (els) => els.forEach((el) => { el.open = true; }));
      const storedSurface = await posted(page, SURFACE_FIELD);
      await report.shot(page, '03-surface-image');

      report.verdict('a section background picture is chosen the same way and stored (D-024)',
        storedSurface === surface.chosen && storedSurface !== '',
        `chose ${surface.chosen}, stored ${storedSurface}; what it looks like behind a section is 14-front`);
    }

    // ---- the canvas follows the choice, without saving --------------------------------------
    //
    // Selection is made THROUGH THE CANVAS, the way 07-live-canvas does it and the way a
    // person does. builder.js keeps `selected` as module state that only that path sets, and
    // redraw() returns immediately when api.selected() is -1 — so forcing a group's hidden
    // flag off selects nothing, redraw never runs, and the canvas correctly shows nothing.
    // An earlier version did that and reported the product as broken.
    await page.goto(`${BASE}/admin/pages/${pageId}`, { waitUntil: 'networkidle2' });
    const ready = await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 }).then(() => true).catch(() => false);

    if (!ready) {
      report.fail('the canvas follows a chosen picture without saving', 'the canvas never loaded');
    } else {
      // The block that actually has a media field, not block 0: the demo's first block is a
      // hero, and selecting the wrong one measures a section nobody edited.
      const index = await page.evaluate((selector) => {
        const field = document.querySelector(selector);
        const group = field && field.closest('[data-block-group]');
        return group ? group.getAttribute('data-block-group') : null;
      }, CONTENT_FIELD);

      if (index === null) {
        report.skip('the canvas follows a chosen picture', 'no media field in the visual editor');
      } else {
        const frame = page.frames().find((f) => f.url().includes('/canvas'));
        await (await frame.$(`[data-bx-index="${index}"]`)).click();
        await page.waitForFunction((i) => {
          const group = document.querySelector(`[data-block-group="${i}"]`);
          return group && !group.hidden;
        }, { timeout: 8000 }, index);

        const picked = await pick(page, CONTENT_FIELD, 0);
        // builder-blocks.js debounces the redraw; 300ms is the figure in the 2h brief.
        await new Promise((resolve) => setTimeout(resolve, 1200));
        // The canvas draws the block through the same renderer, so the same contract change
        // applies: the id now arrives inside the variant URL, not on a placeholder.
        const drawn = await page.evaluate(() => {
          const canvas = document.querySelector('iframe[data-canvas]');
          if (!canvas || !canvas.contentDocument) return null;
          return Array.from(canvas.contentDocument.querySelectorAll('img')).map((el) => el.getAttribute('src'));
        });
        await report.shot(page, '04-canvas');
        const inCanvas = (drawn || []).filter((src) => new RegExp(`/m/[a-z]+/${picked.chosen}-`).test(src || ''));
        report.verdict('the canvas follows a chosen picture without saving',
          inCanvas.length > 0,
          drawn === null ? 'no reachable canvas'
            : `chose ${picked.chosen}, the canvas shows ${JSON.stringify(drawn)}`);
      }
    }

    // ---- leave the page as it was found --------------------------------------------------
    //
    // BOTH kinds of field, content and section background, and every one of them back to
    // the value it had when this scenario opened the page — not to nothing. Set directly
    // and fire the events the editors listen for, rather than re-driving the picker: a
    // restore that went through the control it is undoing can fail the same way.
    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    await page.$$eval('select[data-media-field]', (els, was) => {
      const by = Object.fromEntries(was.map((field) => [field.name, field.value]));
      for (const el of els) {
        el.value = by[el.name] ?? '';
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, original);
    await save(page);

    await page.goto(formUrl, { waitUntil: 'networkidle2' });
    const now = await page.$$eval('select[data-media-field]',
      (els) => els.map((el) => ({ name: el.name, value: el.value })));
    const differs = original.filter((field) => {
      const found = now.find((other) => other.name === field.name);
      return !found || found.value !== field.value;
    });
    report.verdict('the page is left with exactly the pictures it had, of either kind',
      differs.length === 0,
      differs.length === 0
        ? `${original.filter((f) => f.value !== '').length} picture(s) as they were`
        : `not as found: ${JSON.stringify(differs)}`);
  },
};
