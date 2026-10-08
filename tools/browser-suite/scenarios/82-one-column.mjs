/*
 * A BAND OF ONE COLUMN TAKES A SECOND BLOCK (PLAN.md D-208, the owner): a Text under a Hero,
 * Downloads under a Text, in the same band. The "+" between bands makes a new band, so without
 * this the only way was a drag in the structure rail.
 *
 *   - unchosen, a band of one column carries no "+ Block": the page is not covered;
 *   - chosen, it carries one under its last block, from the column's left edge, clear of the
 *     "+" between bands and of every toolbar;
 *   - pressed, the inserter offers blocks and no patterns; a Text chosen lands in that band,
 *     after the block that was there, and the band is drawn with both;
 *   - the "+" between bands still makes a band of its own;
 *   - the draft's preview draws both blocks in one band.
 *
 * On the copy; the draft is discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const inCanvas = (page, fn, arg) => page.evaluate((f, a) => (new Function('doc', 'pb', 'a', f))(document.querySelector('[data-pb-canvas]').contentDocument, window.pb, a), fn, arg);

export default {
  name: 'one-column',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('one column: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    let drafted = false;
    try {
      const band = await page.evaluate(() => {
        const ones = window.pb.doc.sections.filter((s) => (s.layout || 'one') === 'one' && window.pb.blocksIn(s.key).length === 1);
        const s = ones[ones.length - 1];
        return s ? { key: s.key, index: window.pb.doc.sections.indexOf(s), first: window.pb.blocksIn(s.key)[0].key, bands: window.pb.doc.sections.length } : null;
      });
      if (!band) {
        report.fail('one column: a band of one block to work on', 'the page has none');
        return;
      }
      const unchosen = await inCanvas(page, 'return doc.querySelectorAll(".bx-add-column").length;');
      report.verdict('unchosen, a band of one column carries no "+ Block"', unchosen === 0, String(unchosen));

      await clickInCanvas(page, '[data-bx-section]', { index: band.index, at: 'bottom', side: 'left' });
      await wait(1000);
      const offered = await inCanvas(page, `
        const element = doc.querySelector('[data-bx-section="' + a + '"]');
        const column = element.querySelector('.section-column').getBoundingClientRect();
        const add = [...doc.querySelectorAll('.bx-add-column')];
        const b = add[0] ? add[0].getBoundingClientRect() : null;
        const crosses = (m, r) => m.left < r.right && m.right > r.left && m.top < r.bottom && m.bottom > r.top;
        return { buttons: add.length, column: add[0] ? add[0].getAttribute('data-bx-insert-column') : null,
          under: !!(b && b.top >= column.bottom - 2), left: !!(b && Math.abs(b.left - column.left) < 2),
          clear: !!b && [...doc.querySelectorAll('.bx-toolbar, .bx-plus, .bx-add-end')].every((t) => !crosses(b, t.getBoundingClientRect())) };`, band.key);
      report.verdict('chosen, it carries "+ Block" under its last block, from the column\'s edge, crossing nothing', offered.buttons === 1 && offered.column === '0' && offered.under && offered.left && offered.clear, JSON.stringify(offered));
      await report.shot(page, '01-chosen', { fullPage: false });

      await clickInCanvas(page, '.bx-add-column[data-bx-insert-column="0"]');
      await wait(600);
      const inserter = await inCanvas(page, 'const i = doc.querySelector(".bx-inserter"); return i ? { blocks: i.querySelectorAll("[data-bx-add-block]").length, patterns: i.querySelectorAll("[data-bx-add-pattern]").length } : null;');
      report.verdict('pressed, the inserter offers blocks and no patterns', inserter !== null && inserter.blocks > 5 && inserter.patterns === 0, JSON.stringify(inserter));
      await clickInCanvas(page, '.bx-inserter [data-bx-add-block="text"]');
      drafted = true;
      await wait(2000);
      const landed = await page.evaluate((b) => {
        const blocks = window.pb.blocksIn(b.key).map((x) => ({ type: x.type, column: x.column, key: x.key }));
        const element = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-section="${b.key}"]`);
        const drawn = element ? [...element.querySelectorAll('[data-bx-key]')].map((n) => n.getAttribute('data-bx-key')) : [];
        return { blocks, drawn, bands: window.pb.doc.sections.length };
      }, band);
      report.verdict('a Text chosen lands in that band, after its block, and is drawn there',
        landed.blocks.length === 2 && landed.blocks[0].key === band.first && landed.blocks[1].type === 'text' && landed.blocks.every((x) => x.column === 0)
          && landed.drawn.join() === landed.blocks.map((x) => x.key).join() && landed.bands === band.bands, JSON.stringify(landed));
      await report.shot(page, '02-two-blocks', { fullPage: false });

      // The "+" between bands is still the way to a band of its own.
      const plus = await inCanvas(page, 'return doc.querySelectorAll(".bx-plus").length;');
      await clickInCanvas(page, '.bx-plus', { index: Math.max(0, plus - 1) });
      await wait(600);
      await clickInCanvas(page, '.bx-inserter [data-bx-add-block="text"]');
      await wait(2000);
      const bands = await page.evaluate(() => window.pb.doc.sections.length);
      report.verdict('the "+" between bands still makes a band of its own', bands === band.bands + 1, `${band.bands} bands, then ${bands}`);

      await settle(page);
      const preview = await page.evaluate(async (b) => (await fetch(`${b}/admin/pages/1/preview`)).text(), BASE);
      // That band by its place now (the band just added before it moved it down one), and not
      // a count anywhere, which passed on the page's own band of two (measured: Experience).
      const stored = await page.evaluate((html, index) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const bands = [...doc.querySelectorAll('main > section')];
        const it = bands[index];
        return { bands: bands.length, blocks: it ? it.querySelectorAll('.section-column > *').length : -1, text: it ? !!it.querySelector('.block-text, [class*="text"]') : false };
      }, preview, await page.evaluate((key) => window.pb.doc.sections.findIndex((x) => x.key === key), band.key));
      report.verdict('the draft\'s preview draws both blocks in that one band', stored.blocks === 2 && stored.text, JSON.stringify(stored));
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
