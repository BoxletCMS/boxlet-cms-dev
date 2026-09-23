/*
 * The Columns block in the editor (PLAN.md D-008, D-041).
 *
 * What a person does: add Columns from the library, see its three empty columns on the
 * canvas, type a column's heading, add a fourth column, choose four in a row, and give the
 * first column a picture. Every verdict is read off the canvas, which the server draws
 * from what the inspector would post — so it is the page as it would be saved.
 *
 * NOTHING IS SAVED: the block is added in the editor and left, so the site is as it was.
 * That a saved block renders is asserted by tests/columns_test.php on the served HTML.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, SLOW } from '../harness.mjs';
import { openPicker, posted } from '../media-helpers.mjs';

const PAGE = 1;
const SETTLE = 1500; // the canvas redraw is debounced by 300ms, then a round trip
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The new block's section on the canvas, measured. */
const columnsOnCanvas = (page, index) => page.evaluate((i) => {
  const frame = document.querySelector('iframe[data-canvas]');
  const section = frame && frame.contentDocument
    ? frame.contentDocument.querySelector(`[data-bx-index="${i}"]`)
    : null;
  if (!section) return null;
  const items = Array.from(section.querySelectorAll('.columns-item'));
  return {
    layout: (section.className.match(/layout-(\w+)/) || [])[1] || '',
    count: items.length,
    empty: items.filter((item) => item.classList.contains('is-empty')).length,
    outline: items[0] ? frame.contentWindow.getComputedStyle(items[0]).outlineStyle : '',
    height: items[0] ? Math.round(items[0].getBoundingClientRect().height) : 0,
    rows: new Set(items.map((item) => Math.round(item.getBoundingClientRect().top))).size,
    firstHeading: ((items[0] || {}).querySelector ? (items[0].querySelector('h3') || {}).textContent : '') || '',
    firstPicture: items[0] ? items[0].querySelector('.columns-media img') !== null : false,
  };
}, index);

export default {
  name: 'columns',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('columns: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    // Wide enough that the canvas is a desktop page: on a 1400-wide window it is about 650
    // across, where four in a row rightly folds to two rows of two, as on a tablet.
    await page.setViewport({ width: 1920, height: 1100, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 0;
    }, { timeout: 20000 });

    // ---- add it from the library -----------------------------------------------------------
    const before = await page.$$eval('[data-block-group]', (list) => list.length);
    /* WHERE IT LANDS IS CHOSEN FIRST (D-103, and the design artifact says the same): a
       library card pressed with nowhere aimed at used to add the block as a band of its own
       at the end of the page, which is a guess. So a + in a column is pressed, and this
       one is the last band's, which is where this block used to end up anyway. */
    await page.evaluate(() => {
      const doc = document.querySelector('iframe[data-canvas]').contentDocument;
      const slots = [...doc.querySelectorAll('.bx-slot')];
      slots[slots.length - 1].click();
    });
    await wait(600);
    await page.click('[data-add-type="columns"]');
    const added = await page.waitForFunction((n) => document.querySelectorAll('[data-block-group]').length === n,
      { timeout: 10000 }, before + 1).then(() => true).catch(() => false);
    report.verdict('Columns can be added from the library', added, `${before} blocks before`);
    if (!added) return;
    await wait(SETTLE);

    const index = await page.evaluate(() => {
      const shown = Array.from(document.querySelectorAll('[data-block-group]')).find((g) => !g.hidden);
      return shown ? shown.getAttribute('data-block-group') : null;
    });
    const group = `[data-block-group="${index}"]`;
    const items = await page.$$eval(`${group} [data-repeater-item]`, (list) => list.length);
    const fresh = await columnsOnCanvas(page, index);
    await page.evaluate((i) => {
      const frame = document.querySelector('iframe[data-canvas]');
      frame.contentDocument.querySelector(`[data-bx-index="${i}"]`).scrollIntoView({ block: 'center' });
    }, index);
    await wait(300);
    await report.shot(page, '01-new-columns', { fullPage: false });
    report.verdict('a new Columns block starts with three columns, in the inspector and on the canvas',
      items === 3 && fresh !== null && fresh.count === 3, `${items} items in the inspector; canvas ${JSON.stringify(fresh)}`);
    report.verdict('its empty columns are outlined and given height, not an empty band',
      fresh !== null && fresh.empty === 3 && fresh.outline === 'dashed' && fresh.height >= 100,
      JSON.stringify(fresh));

    // ---- a heading typed into a column reaches the canvas ------------------------------------
    await page.type(`${group} [name$="[items][0][heading]"]`, 'Design', { delay: SLOW });
    await wait(SETTLE);
    const typed = await columnsOnCanvas(page, index);
    report.verdict('a column\'s heading is drawn as it is typed, and the column stops being empty',
      typed !== null && typed.firstHeading === 'Design' && typed.empty === 2, JSON.stringify(typed));

    // ---- a fourth column, four in a row --------------------------------------------------------
    // This used to press Add and THEN choose the row size, which is the manual way round.
    // Choosing four in a row now asks for the fourth column itself (D-091), reported by the
    // owner as the control not working: three columns, an empty cell, and no field to type
    // into. So the Add is gone from this check and what it proves has changed with it.
    const itemFields = () => page.$$eval(`${group} [data-repeater-item]`, (els) => els.length);
    const beforeRowSize = await itemFields();
    await page.select(`${group} select[name$="[layout]"]`, 'four');
    await wait(SETTLE);
    const four = await columnsOnCanvas(page, index);
    const afterRowSize = await itemFields();
    report.verdict('choosing four in a row adds the fourth column, in the panel and on the page',
      four !== null && four.count === 4 && four.layout === 'four' && four.rows === 1
        && beforeRowSize === 3 && afterRowSize === 4,
      `${beforeRowSize} fields -> ${afterRowSize}; canvas ${JSON.stringify(four)}`);

    // A narrower row is a choice about arrangement, not an instruction to delete a column.
    await page.select(`${group} select[name$="[layout]"]`, 'two');
    await wait(SETTLE);
    const narrowed = await columnsOnCanvas(page, index);
    report.verdict('going back to two in a row keeps every column',
      narrowed !== null && narrowed.count === 4 && narrowed.layout === 'two' && await itemFields() === 4,
      `canvas ${JSON.stringify(narrowed)}, ${await itemFields()} fields`);
    await page.select(`${group} select[name$="[layout]"]`, 'four');
    await wait(SETTLE);

    // ---- a picture in the first column --------------------------------------------------------
    // A photograph by name, never merely the first card: that is the site's logo.
    const field = `${group} select[name$="[items][0][image]"]`;
    await openPicker(page, field);
    const card = await page.$$eval('.media-picker-panel:not([hidden]) [data-pick]', (cards) => {
      const photo = cards.find((c) => /workshop|hands|desk|studio/.test(c.textContent)) || cards[0];
      photo.click();
      return photo.getAttribute('data-pick');
    }).catch(() => null);
    const chosen = card === null ? null : { chosen: card, value: await posted(page, field) };
    await wait(SETTLE);
    const pictured = await columnsOnCanvas(page, index);
    await page.evaluate((i) => {
      const frame = document.querySelector('iframe[data-canvas]');
      frame.contentDocument.querySelector(`[data-bx-index="${i}"]`).scrollIntoView({ block: 'center' });
    }, index);
    await wait(300);
    await report.shot(page, '02-four-with-picture', { fullPage: false });
    report.verdict('a picture chosen for a column is drawn in it',
      chosen !== null && chosen.value !== '' && pictured !== null && pictured.firstPicture,
      `chose ${JSON.stringify(chosen)}; canvas ${JSON.stringify(pictured)}`);

    // Leave without saving.
    await page.evaluate(() => { window.onbeforeunload = null; });
  },
};
