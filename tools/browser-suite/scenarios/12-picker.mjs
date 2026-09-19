/*
 * 4c: WHAT THE PICKER LOOKS LIKE. Choosing, changing and clearing are 13-choosing.mjs.
 *
 * The architect's ruling: at rest it must read as a button that opens a chooser, not as a
 * text field. The first version set the button's text to the bare filename and was taken
 * for an input. The four shots here are what the owner judges.
 *
 * NOTHING HERE SAVES, which is why it needs no cleanup: it opens the editor, looks at the
 * control in each state, and leaves the page exactly as it found it.
 */
import { existsSync } from 'node:fs';
import { BASE, ADMIN } from '../config.mjs';
import { login, controlsOnPanels, fixtures } from '../harness.mjs';
import {
  PHOTOS, CONTENT_FIELD, SURFACE_FIELD, uploadPhoto, openPicker, pick, firstPageId, photographs,
} from '../media-helpers.mjs';

export default {
  name: 'picker',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('picker: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // Whatever two photographs are actually there, not two names written down here: the
    // fixed names stopped existing in 4f and this scenario has skipped itself ever since.
    const photos = photographs(2);
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'picker', photos.length >= 2 ? photos : [], `two photographs in ${PHOTOS}`) === null) {
      return;
    }

    for (const photo of photos) {
      await uploadPhoto(page, photo);
    }
    const inLibrary = await page.$$eval('tr.media-row', (els) => els.length).catch(() => 0);
    report.verdict('two pictures are in the library to choose between', inLibrary >= 2,
      `${inLibrary} card(s) in the library`);
    if (inLibrary < 2) {
      return;
    }

    const pageId = await firstPageId(page);
    if (!pageId) {
      report.fail('picker: find a page to edit', 'no page link in the tree');
      return;
    }

    await page.goto(`${BASE}/admin/pages/${pageId}/form`, { waitUntil: 'networkidle2' });
    await report.shot(page, '01-editor');

    const upgraded = await page.$eval(CONTENT_FIELD, (el) => ({
      selectHidden: el.hidden,
      hasButton: !!el.parentNode.querySelector('.media-picker-current'),
      buttonText: (el.parentNode.querySelector('.media-picker-current') || {}).textContent || '',
      stillPosts: el.name,
    })).catch(() => null);

    report.verdict('the picker replaces the select, which stays as the field that posts',
      upgraded !== null && upgraded.selectHidden && upgraded.hasButton,
      upgraded === null ? 'no media field on this page'
        : `select hidden=${upgraded.selectHidden}, button="${upgraded.buttonText.trim()}", posts ${upgraded.stillPosts}`);

    const restingState = async (name) => {
      await report.shot(page, name);
      return page.$eval(CONTENT_FIELD, (el) => {
        const button = el.parentNode.querySelector('.media-picker-current');
        return {
          empty: button.classList.contains('media-picker-empty'),
          thumb: !!button.querySelector('.media-picker-thumb'),
          image: !!button.querySelector('img.media-picker-thumb'),
          name: (button.querySelector('.media-picker-name') || {}).textContent || '',
          verb: (button.querySelector('.media-picker-verb') || {}).textContent || '',
        };
      });
    };

    // The surface picker lives in the section style panel, a row of columns sized for a
    // <select>. An earlier version looked only at the content field and passed 13/13 while
    // the surface control rendered as a vertical ladder of single letters — a name broken
    // one character per line in a 10rem column. Geometry, not a screenshot: a collapsed
    // control is taller than it is wide, and that fails here.
    await page.$$eval('details.block-style', (els) => els.forEach((el) => { el.open = true; }));
    const shapes = await page.evaluate((selectors) => {
      const measure = (selector) => {
        const field = document.querySelector(selector);
        const button = field && field.parentNode.querySelector('.media-picker-current');
        if (!button) return null;
        const box = button.getBoundingClientRect();
        const name = button.querySelector('.media-picker-name');
        return {
          width: Math.round(box.width),
          height: Math.round(box.height),
          nameWidth: name ? Math.round(name.getBoundingClientRect().width) : 0,
        };
      };
      return { content: measure(selectors.content), surface: measure(selectors.surface) };
    }, { content: CONTENT_FIELD, surface: SURFACE_FIELD });

    for (const [where, shape] of Object.entries(shapes)) {
      if (shape === null) {
        report.skip(`the ${where} picker has a usable shape`, 'no picker of that kind on this page');
        continue;
      }
      report.verdict(`the ${where} picker is a row, not a column of letters`,
        shape.width > shape.height * 2 && shape.nameWidth > 40,
        `${shape.width}x${shape.height}px, name ${shape.nameWidth}px wide`);
    }

    // ---- the four states the owner judges -----------------------------------------------
    // ESTABLISH the empty state instead of assuming it: the demo page's first block holds a
    // picture, so this verdict read "big-photo / Change" while the control was behaving
    // perfectly — the scenario was asserting someone else's state.
    //
    // Cleared THROUGH THE PICKER'S OWN CONTROL, because that is the only thing that redraws
    // the button. My first attempt set select.value = '' and dispatched change; nothing
    // happened, and the verdict failed exactly as before. media-picker.js repaints in
    // paint(), which only choose() calls — and nothing listens for change on the select it
    // hides. Pressing "no picture" is what the owner does and what the code responds to.
    // Nothing is saved: the form is left untouched, as this scenario's header promises.
    await openPicker(page, CONTENT_FIELD);
    await page.click('.media-picker-panel:not([hidden]) .media-picker-none');
    const emptyClosed = await restingState('02a-closed-empty');
    report.verdict('with no picture the control says so and offers the verb',
      emptyClosed.empty && emptyClosed.thumb && !emptyClosed.image && emptyClosed.verb !== '',
      `"${emptyClosed.name.trim()}" / "${emptyClosed.verb.trim()}", placeholder square=${emptyClosed.thumb}`);

    await openPicker(page, CONTENT_FIELD);
    await report.shot(page, '02b-open-empty');

    // WITH THE DIALOG OPEN, which is the one state the guard cannot reach by itself: the
    // picker's search field has no box until this panel is up, so every other call skips
    // it. The editor's collapsed panels are already open above, so their fields count here
    // too — on the builder the guard otherwise passes mostly by skipping.
    await controlsOnPanels(page, report, 'picker, open');
    const panelEmpty = await page.$$eval('.media-picker-panel:not([hidden]) [data-pick]', (els) => els.length);
    report.verdict('the open panel offers the library\'s pictures', panelEmpty > 0,
      `${panelEmpty} picture(s) offered`);
    await page.keyboard.press('Escape');

    // Choosing, but never saving: the form is left untouched when this scenario ends.
    await pick(page, CONTENT_FIELD, 0);
    const chosenClosed = await restingState('02c-closed-with-picture');
    report.verdict('with a picture the control shows it, names it, and offers to change it',
      !chosenClosed.empty && chosenClosed.image && chosenClosed.name.trim() !== '' && chosenClosed.verb !== '',
      `thumbnail=${chosenClosed.image}, "${chosenClosed.name.trim()}" / "${chosenClosed.verb.trim()}"`);

    await openPicker(page, CONTENT_FIELD);
    await report.shot(page, '02d-open-with-picture');
    await page.keyboard.press('Escape');
  },
};
