/*
 * DRAGGING A BLOCK BETWEEN COLUMNS (PLAN.md D-103) — the last of the review's step 4.
 *
 * Until this, a block could be ADDED to a column and never MOVED into one. The review calls
 * it two levels of drag: bands reorder among themselves, blocks move within and between
 * columns. What makes it possible at all is that the editor's canvas now always draws
 * columns, so every band has somewhere to drop into.
 *
 * WITHIN ONE BAND, deliberately. Dragging a block out of a band leaves that band empty, and
 * an empty band is taken by the next save — so a check that did it could not put the page
 * back, and a check that cannot put the page back is how the demo page lost a block twice
 * (memory: probe cleanup is not optional). Crossing between two columns of one band
 * exercises the same path: the block changes the column it says it stands in, and the save
 * has to store it there.
 *
 * SORTABLE IS DRIVEN THROUGH ITS OWN onEnd RATHER THAN BY A POINTER. A synthetic drag over
 * an iframe is the kind of instrument that reports what it wishes had happened; moving the
 * node and telling Sortable the drag ended is the same thing it does itself, and what is
 * being checked is what the editor does with the result.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 2;
const SETTLE = 1600;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const submit = async (page) => {
  await page.evaluate(() => {
    const form = document.querySelector('form[data-builder]');
    form.requestSubmit(form.querySelector('button[name="action"][value="save"]:not(.visually-hidden)'));
  });
  await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});

  return page.evaluate(() => Array.from(document.querySelectorAll('.alert, [role="alert"], .field-error'))
    .map((a) => a.textContent.trim().slice(0, 120)));
};

const ready = async (page) => {
  await page.waitForFunction(() => {
    const frame = document.querySelector('iframe[data-canvas]');
    const columns = frame && frame.contentDocument
      ? frame.contentDocument.querySelectorAll('.section-column') : [];

    return columns.length > 0;
  }, { timeout: 20000 }).catch(() => {});
  await wait(SETTLE);
};

/** Move a block into a column and tell Sortable the drag ended, as a pointer would. */
const dragInto = (frame, key, column) => frame.evaluate((bandKey, at) => {
  const band = document.querySelector(`[data-bx-section="${bandKey}"]`);
  const columns = band.querySelectorAll('.section-column');
  const block = [...band.querySelectorAll('.section-column > *')][0];
  columns[at].appendChild(block);
  columns[at].bxSortable.options.onEnd();

  return block.getAttribute('data-bx-key');
}, key, column);

export default {
  name: 'drag-columns',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('drag-columns: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1700, height: 1100, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await ready(page);
    const frame = page.frames().find((f) => f.url().includes('/canvas'));

    // EVERY BAND HAS A COLUMN TO DROP INTO, which is what the editor's own shape is for.
    // The counts are the page's own: this scenario carried a literal 6 until D-105 put four
    // more blocks on the About page, and went red with nothing wrong.
    const shape = await frame.evaluate(() => ({
      bands: document.querySelectorAll('[data-bx-section]').length,
      columns: document.querySelectorAll('.section-column').length,
      dragging: [...document.querySelectorAll('.section-column')].filter((c) => c.bxSortable).length,
      page: !!document.querySelector('[data-bx-blocks]').bxSortable,
    }));
    report.verdict('every column can be dragged into, and the bands can be reordered',
      shape.columns === shape.bands && shape.dragging === shape.columns && shape.page,
      JSON.stringify(shape));

    // ---- give a band two columns, then move its block across -------------------------
    const band = await frame.evaluate(() => [...document.querySelectorAll('[data-bx-section]')][1]
      .getAttribute('data-bx-section'));
    await page.click(`[data-outline-section="${band}"]`);
    await wait(900);
    await page.evaluate(() => {
      const select = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden)
        .querySelector('select[name$="[layout]"]');
      select.value = 'halves';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(SETTLE * 2);

    const moved = await dragInto(page.frames().find((f) => f.url().includes('/canvas')), band, 1);
    await wait(SETTLE);

    const form = await page.evaluate((key) => {
      const group = [...document.querySelectorAll('[data-block-group]')]
        .find((g) => g.getAttribute('data-block-key') === key);

      return group === undefined ? null : {
        band: group.getAttribute('data-section-key'),
        column: (group.querySelector('[data-block-column]') || {}).value,
        // The hidden input is what the save reads; the attribute is what the editor reads.
        says: (group.querySelector('[data-block-section]') || {}).value,
        undo: !document.querySelector('[data-undo-button]').disabled,
      };
    }, moved);
    report.verdict('a block dragged into another column says so in the form',
      form !== null && form.band === band && form.column === '1' && form.says === band && form.undo,
      JSON.stringify(form));
    await report.shot(page, '01-moved');

    const notices = await submit(page);
    report.verdict('a block moved between columns saves', notices.length === 0, JSON.stringify(notices));

    // ---- and the visitor gets it in the column it was moved to -----------------------
    //
    // The only verdict that settles it: the canvas is the server's drawing of what the form
    // would post, so a block stored in the wrong column still draws in the right one.
    const live = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate(() => {
        const cols = document.querySelector('.section-cols');

        return cols === null ? null : [...cols.children].map((c) => c.children.length);
      }));
    report.verdict('the visitor sees the block in the column it was moved to',
      live !== null && live.join(',') === '0,1', JSON.stringify(live));

    // ---- and the page is put back ----------------------------------------------------
    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await ready(page);
    const backFrame = page.frames().find((f) => f.url().includes('/canvas'));
    const home = await backFrame.evaluate((bandKey) => {
      const band = document.querySelector(`[data-bx-section="${bandKey}"]`);
      const block = band ? [...band.querySelectorAll('.section-column > *')][0] : null;
      if (!block) { return false; }
      const first = band.querySelectorAll('.section-column')[0];
      first.appendChild(block);
      first.bxSortable.options.onEnd();

      return true;
    }, band);
    if (!home) {
      report.fail('drag-columns: the scenario puts the page back',
        'the block it moved is not in the band it moved it within, so it will not drag anything else');

      return;
    }
    await wait(SETTLE);
    await page.click(`[data-outline-section="${band}"]`);
    await wait(900);
    await page.evaluate(() => {
      const select = [...document.querySelectorAll('[data-section-group]')].find((g) => !g.hidden)
        .querySelector('select[name$="[layout]"]');
      select.value = 'one';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await wait(SETTLE * 2);
    const cleaned = await submit(page);

    const back = await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' })
      .then(() => page.evaluate(() => ({
        columns: document.querySelectorAll('.section-cols').length,
        bands: document.querySelectorAll('main > section').length,
      })));
    report.verdict('the scenario puts the page back',
      cleaned.length === 0 && back.columns === 0 && back.bands === shape.bands,
      `${JSON.stringify(back)} ${JSON.stringify(cleaned)}`);
  },
};
