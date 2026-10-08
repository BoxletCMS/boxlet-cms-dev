/*
 * A BAND'S COLUMNS ARE FILLED ONE BY ONE (PLAN.md D-099, D-101; restored in D-206). D-175's
 * builder retired 24-columns and 44-sections with the old builder, and with them the only
 * check that a block could be put into a column: a band could then be given two columns that
 * nothing could be put in (the owner, 2026-10-08). It stands for both of them: the empty
 * column (24) and the filled one, saved (44). A band of one column is 82-one-column.
 *
 *   - given two columns, the empty one carries "+ Block" at rest, in its middle;
 *   - pressed, the inserter offers blocks and no patterns; a Text chosen lands in that column,
 *     in the document and on the canvas, and the band is drawn with both;
 *   - selected, a band's columns that hold something carry "+ Block" under what they hold;
 *   - the draft's preview, the visitor's drawing, has the Text in the band's second column.
 *
 * On the copy; the draft is discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const inCanvas = (page, fn, arg) => page.evaluate((f, a) => (new Function('doc', 'pb', 'a', f))(document.querySelector('[data-pb-canvas]').contentDocument, window.pb, a), fn, arg);

export default {
  name: 'columns',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('columns: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    let drafted = false;
    try {
      // A band of one block in one column: the last such on the page.
      const band = await page.evaluate(() => {
        const ones = window.pb.doc.sections.filter((s) => (s.layout || 'one') === 'one' && window.pb.blocksIn(s.key).length === 1);
        const s = ones[ones.length - 1];
        return s ? { key: s.key, index: window.pb.doc.sections.indexOf(s) } : null;
      });
      if (!band) {
        report.fail('columns: a band of one column to work on', 'the page has none');
        return;
      }
      await clickInCanvas(page, '[data-bx-section]', { index: band.index, at: 'bottom', side: 'left' });
      await wait(1000);

      await page.click('[data-pb-inspector] label.tile-option:has(input[name="s.layout"][value="halves"])');
      drafted = true;
      await wait(2000);
      const offered = await inCanvas(page, `
        const element = doc.querySelector('[data-bx-section="' + a + '"]');
        const columns = element ? [...element.querySelectorAll('.section-column')] : [];
        // The empty column's own: the band being selected, the first column carries one too.
        const add = [...doc.querySelectorAll('.bx-add-column[data-bx-insert-column="1"]')];
        const empty = columns[1] ? columns[1].getBoundingClientRect() : null;
        const b = add[0] ? add[0].getBoundingClientRect() : null;
        const cs = add[0] ? doc.defaultView.getComputedStyle(add[0]) : null;
        return { columns: columns.length, buttons: [...doc.querySelectorAll('.bx-add-column')].map((x) => x.getAttribute('data-bx-insert-column')), words: add[0] ? add[0].textContent.trim() : '',
          inside: !!(b && empty && b.left >= empty.left && b.right <= empty.right && b.top >= empty.top && b.bottom <= empty.bottom),
          visible: !!(cs && cs.visibility === 'visible' && cs.opacity === '1' && b.width > 20),
          // Under no toolbar: the band's sits at its top right, over the empty column.
          clear: [...doc.querySelectorAll('.bx-add-column')].every((x) => { const m = x.getBoundingClientRect(); return [...doc.querySelectorAll('.bx-toolbar')].every((t) => { const r = t.getBoundingClientRect(); return !(m.left < r.right && m.right > r.left && m.top < r.bottom && m.bottom > r.top); }); }) };`, band.key);
      report.verdict('given two columns, the empty one carries "+ Block" at rest, in it', offered.columns === 2 && offered.buttons.includes('1') && offered.inside && offered.visible && offered.clear && offered.words !== '', JSON.stringify(offered));
      await report.shot(page, '01-empty-column', { fullPage: false });

      await clickInCanvas(page, '.bx-add-column[data-bx-insert-column="1"]');
      await wait(600);
      const inserter = await inCanvas(page, 'const i = doc.querySelector(".bx-inserter"); return i ? { blocks: i.querySelectorAll("[data-bx-add-block]").length, patterns: i.querySelectorAll("[data-bx-add-pattern]").length } : null;');
      report.verdict('pressed, the inserter offers blocks and no patterns', inserter !== null && inserter.blocks > 5 && inserter.patterns === 0, JSON.stringify(inserter));
      await clickInCanvas(page, '.bx-inserter [data-bx-add-block="text"]');
      await wait(2000);
      const landed = await page.evaluate((key) => {
        const blocks = window.pb.blocksIn(key).map((b) => ({ type: b.type, column: b.column, key: b.key }));
        const element = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-section="${key}"]`);
        const second = element ? element.querySelectorAll('.section-column')[1] : null;
        const added = blocks.find((b) => b.type === 'text' && b.column === 1);
        return { blocks, drawn: !!(second && added && second.querySelector(`[data-bx-key="${added.key}"]`)), selected: window.pb.selection };
      }, band.key);
      report.verdict('a Text chosen lands in that column, in the document and on the canvas', landed.drawn && landed.blocks.length === 2, JSON.stringify(landed));

      await clickInCanvas(page, `[data-bx-section="${band.key}"]`, { at: 'bottom', side: 'left' });
      await wait(1000);
      const under = await inCanvas(page, `
        const element = doc.querySelector('[data-bx-section="' + a + '"]');
        const columns = [...element.querySelectorAll('.section-column')];
        return [...doc.querySelectorAll('.bx-add-column')].map((x) => { const b = x.getBoundingClientRect(); const c = columns[Number(x.getAttribute('data-bx-insert-column'))].getBoundingClientRect(); return b.top >= c.bottom - 2; });`, band.key);
      report.verdict('selected, its columns that hold something carry "+ Block" under it', under.length === 2 && under.every(Boolean), JSON.stringify(under));
      await report.shot(page, '02-filled-columns', { fullPage: false });

      // As a visitor will get it: the draft's preview, the server's drawing of what is stored.
      await settle(page);
      const preview = await page.evaluate(async (b) => (await fetch(`${b}/admin/pages/1/preview`)).text(), BASE);
      const stored = await page.evaluate((html, key) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const bands = [...doc.querySelectorAll('main > section')];
        const halves = bands.filter((s) => s.querySelectorAll('.section-column').length === 2 && s.querySelectorAll('.section-column')[1].querySelector('.block-text, .text, [class*="text"]'));
        return { halves: halves.length };
      }, preview, band.key);
      report.verdict('the draft\'s preview draws the Text in the band\'s second column', stored.halves >= 1, JSON.stringify(stored));
    } finally {
      if (drafted) {
        await openBuilder(page, BASE, 1).catch(() => {});
        if (await page.$('[data-pb-discard]:not([hidden])')) {
          await page.click('[data-pb-discard]');
          await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
        }
        report.verdict('cleanup: the draft is discarded', await page.$eval('[data-pb-discard]', (b) => b.hidden).catch(() => false));
      }
    }
  },
};
