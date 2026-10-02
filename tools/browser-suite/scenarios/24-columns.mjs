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
// The copy, which holds the photographs 25-columns-look uploads: the development site was
// reinstalled with nothing in its library (D-167), and this needs a picture to choose.
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, retype, SLOW } from '../harness.mjs';
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
  const items = Array.from(section.querySelectorAll('.cards-item'));
  return {
    layout: (section.className.match(/layout-(\w+)/) || [])[1] || '',
    // How many stand in a row is an option since D-166, a class on the cards.
    perRow: ((section.querySelector('.cards') || { className: '' }).className.match(/per-row-(\d)/) || [])[1] || '',
    count: items.length,
    empty: items.filter((item) => item.classList.contains('is-empty')).length,
    outline: items[0] ? frame.contentWindow.getComputedStyle(items[0]).outlineStyle : '',
    height: items[0] ? Math.round(items[0].getBoundingClientRect().height) : 0,
    rows: new Set(items.map((item) => Math.round(item.getBoundingClientRect().top))).size,
    firstHeading: ((items[0] || {}).querySelector ? (items[0].querySelector('h3') || {}).textContent : '') || '',
    firstPicture: items[0] ? items[0].querySelector('.cards-media img') !== null : false,
  };
}, index);

export default {
  name: 'columns',
  // Runs against the throwaway copy (config.mjs). Nothing is saved.
  copy: true,

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
    await page.click('[data-add-type="cards"]');
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
    report.verdict('a new Cards block starts with three cards, in the inspector and on the canvas',
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

    // ---- four in a row, then two ---------------------------------------------------------------
    // How many cards stand in a row is an option since D-166, typed as a number. It no longer
    // adds cards to fill the row (D-091's top-up stays with the Gallery): a row of three cards
    // set four across is three cards and a space, which is what the owner chose.
    const itemFields = () => page.$$eval(`${group} [data-repeater-item]`, (els) => els.length);
    const perRow = `${group} input[name$="[options][per_row]"]`;
    const setRow = async (value) => {
      await retype(page, perRow, value);
      await page.$eval(perRow, (el) => el.dispatchEvent(new Event('change', { bubbles: true })));
      await wait(SETTLE);
    };
    await setRow('4');
    const four = await columnsOnCanvas(page, index);
    report.verdict('four in a row draws the cards four across, and adds none',
      four !== null && four.perRow === '4' && four.count === 3 && four.rows === 1 && await itemFields() === 3,
      `canvas ${JSON.stringify(four)}, ${await itemFields()} fields`);
    await setRow('2');
    const narrowed = await columnsOnCanvas(page, index);
    report.verdict('two in a row folds them into two rows and keeps every card',
      narrowed !== null && narrowed.perRow === '2' && narrowed.count === 3 && narrowed.rows === 2,
      `canvas ${JSON.stringify(narrowed)}`);

    // ---- a picture in the first column --------------------------------------------------------
    // A photograph by name, never merely the first card: that is the site's logo.
    const field = `${group} select[name$="[items][0][image]"]`;
    await openPicker(page, field);
    const card = await page.$$eval('dialog[data-browser][open] [data-pick]', (cards) => {
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
