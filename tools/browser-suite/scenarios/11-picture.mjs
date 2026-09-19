/*
 * 4b: ONE PICTURE — its own screen. The library that lists them is 10-media.mjs.
 *
 * What it means (alt text, per language), what stays in frame when it is cropped (the
 * focal point), and what happens when a page still shows it.
 *
 * The in-use refusal is checked when something claims the picture. Since the demo seed
 * stopped shipping media ids nothing does, so that verdict reports itself NOT CHECKABLE
 * rather than passing vacuously — the rule is covered at the PHP level in
 * tests/media_item_test.php either way.
 */
import { existsSync } from 'node:fs';
import { BASE, ADMIN } from '../config.mjs';
import { login, submitVia, controlsOnPanels, fixtures, retype, SLOW } from '../harness.mjs';
import {
  PHOTO, text, uploadPhoto, cardFor, claimants, clearReferences, attemptDelete, markerFor,
} from '../media-helpers.mjs';

/** What the library calls the photograph this scenario uploads, derived from the file. */
const MARKER = markerFor(PHOTO);

export default {
  name: 'picture',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('picture: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'picture', [PHOTO], 'one photograph to upload') === null) {
      return;
    }

    await uploadPhoto(page, PHOTO);
    const card = await cardFor(page, MARKER);
    const mediaId = card && card.href ? Number((card.href.match(/\/admin\/media\/(\d+)/) || [])[1]) : null;
    if (!Number.isInteger(mediaId)) {
      report.fail('a picture to work with', `could not read an id from ${card && card.href}`);
      return;
    }

    try {
      // ---- details, the preview and the actions -------------------------------------------
      await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
      await report.shot(page, '01-one-picture');

      // The screen that proves the rule is not about class names: the alt fields sit in
      // form.media-meta and a fieldset, neither of which is called a panel, and both of
      // which paint. A guard written as a grep would have reported this one as broken.
      await controlsOnPanels(page, report, 'picture');

      const detail = await page.evaluate(() => {
        const preview = document.querySelector('img.media-preview-image');
        const replace = document.querySelector('[data-replace-panel]');
        return {
          facts: document.querySelectorAll('.media-facts-list dd').length,
          previewDecoded: preview ? preview.naturalWidth : 0,
          locales: document.querySelectorAll('.media-meta fieldset').length,
          focal: !!document.querySelector('[data-focal-form]'),
          actions: Array.from(document.querySelectorAll('.media-actions .button'))
            .filter((b) => b.offsetParent !== null).map((b) => b.textContent.replace(/\s+/g, ' ').trim()),
          replaceOpen: replace ? !replace.hidden : null,
        };
      });

      report.verdict('the picture screen shows its details and the uncropped preview',
        detail.facts >= 4 && detail.previewDecoded > 0,
        `${detail.facts} facts, preview decoded at ${detail.previewDecoded}px wide`);

      // The owner's review (D-038): no focal point, and three actions of one word and an icon,
      // Replace's form closed until it is asked for.
      report.verdict('the picture screen offers Crop, Replace and Delete, and no focal point',
        !detail.focal && detail.replaceOpen === false
          && ['Crop', 'Replace', 'Delete'].every((word) => detail.actions.some((label) => label.startsWith(word))),
        `actions ${JSON.stringify(detail.actions)}, focal form ${detail.focal}, replace open ${detail.replaceOpen}`);

      await page.click('[data-replace-toggle]');
      const opened = await page.$eval('[data-replace-panel]', (el) => !el.hidden && !!el.querySelector('.dropzone').offsetParent);
      await report.shot(page, '02-replace-open', { fullPage: false });
      report.verdict('pressing Replace opens its form', opened, opened ? 'the file input is shown' : 'still closed');

      // ---- alt text per language ------------------------------------------------------------
      const altField = await page.$('.media-meta input[name^="alt_"]');
      if (!altField) {
        report.skip('alt text is saved per language', 'no alt field on the screen');
      } else {
        // D-025, ASSERTED BEFORE THE SAVE BELOW. A filename tidies into a description,
        // so the field arrives already filled in and marked as a guess. It has to be checked
        // here because saving is what CONFIRMS a suggestion: after the save the badge is
        // correctly gone, and the same check placed lower would assert the opposite state
        // while reading exactly like this one.
        // No badge any more (D-038): the suggestion is simply there to keep or change.
        const badge = await page.$('.media-meta .media-suggested');
        const arrivedWith = await page.$eval('.media-meta input[name^="alt_"]', (el) => el.value);
        await report.shot(page, '03-suggested-alt', { fullPage: false });

        report.verdict('a suggested alt arrives filled in, with no badge (D-025, D-038)',
          badge === null && arrivedWith !== '',
          `the field reads "${arrivedWith}"${badge === null ? '' : ', and a badge is still drawn'}`);

        const phrase = `A harbour at dawn ${Date.now().toString(36).slice(-4)}`;
        // NOT click({ clickCount: 3 }). A triple-click through this driver selects NOTHING,
        // so the Backspace removes a single character and the new text is typed into the
        // middle of the old value — which reads back as a mangled string and looks exactly
        // like the product failing to save. That has cost time twice.
        await altField.click();
        await page.keyboard.down('Control');
        await page.keyboard.press('KeyA');
        await page.keyboard.up('Control');
        await page.keyboard.press('Backspace');
        await altField.type(phrase, { delay: SLOW });
        await submitVia(page, '.media-meta input[name^="alt_"]', 30000);

        await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
        const readBack = await page.$eval('.media-meta input[name^="alt_"]', (el) => el.value);
        report.verdict('alt text is saved per language and comes back', readBack === phrase,
          `${detail.locales} language fieldset(s); typed "${phrase}", read back "${readBack}"`);

      }
    } finally {
      try {
        // ---- what happens when a page still shows it ----------------------------------------
        await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
        const claimedBy = await claimants(page);

        if (claimedBy.length === 0) {
          report.skip('deleting a picture a page uses is refused, naming the page',
            'nothing claims this picture: the demo seed no longer ships media ids');
        } else {
          const refused = await attemptDelete(page);
          report.verdict('deleting a picture a page uses is refused, naming the page',
            refused !== null && claimedBy.some((p) => refused.includes(p.title)),
            refused === null ? 'the delete was NOT refused' : `refused with "${refused}"`);
          await clearReferences(page, mediaId, claimedBy);
        }

        await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
        const gone = await attemptDelete(page);
        const left = await page.$$eval('tr.media-row .media-name',
          (els, wanted) => els.filter((el) => new RegExp(wanted).test(el.textContent)).length,
          MARKER).catch(() => -1);

        report.verdict('the scenario removes the picture it uploaded',
          gone !== null && /deleted/i.test(gone) && left === 0,
          `after clearing ${claimedBy.length} reference(s): "${gone}"; ${left} ${MARKER} card(s) left`);
      } catch (error) {
        report.fail('the scenario removes the picture it uploaded', error.message.split('\n')[0]);
      }
    }
  },
};
