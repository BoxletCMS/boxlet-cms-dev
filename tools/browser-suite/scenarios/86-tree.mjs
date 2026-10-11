/*
 * A BLOCK DRAGGED IN THE STRUCTURE TREE LANDS IN THE COLUMN IT IS DROPPED IN (PLAN.md O-56):
 * each column of a band of columns is a list of its own in the tree, named, an empty one too.
 * One list for the band took every drop into column one.
 *
 * Through real pointer moves on the tree's grips. ON THE COPY, on a page of its own: two
 * bands, the second of two columns; the first band's block dragged into the second's empty
 * second column; then the page deleted.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, deletePage, clickAndWait, openBuilder, addBlock, openTab, settle } from '../harness.mjs';

const TITLE = `Zz tree ${Date.now()}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

/** A pointer drag in small steps, from one element's middle to a point. */
async function drag(page, from, to) {
  await page.mouse.move(from.x, from.y);
  await page.mouse.down();
  for (let step = 1; step <= 16; step += 1) {
    await page.mouse.move(from.x + ((to.x - from.x) * step) / 16, from.y + ((to.y - from.y) * step) / 16);
    await wait(30);
  }
  await wait(250);
  await page.mouse.up();
  await wait(600);
}

export default {
  name: 'tree',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('tree: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    let id = null;
    try {
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', TITLE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      id = idFrom(page.url());
      await openBuilder(page, BASE, id);
      const first = await addBlock(page, 'text');
      const second = await addBlock(page, 'text');
      // The second band of two columns, its block in the first: the scenario's own document.
      const bands = await page.evaluate((a, b) => {
        const one = window.pb.block(a).section;
        const two = window.pb.block(b).section;
        window.pb.change((doc) => { doc.sections.find((s) => s.key === two).layout = 'halves'; }, { structure: true, sections: [two] });
        return { one, two };
      }, first, second);
      await settle(page);
      await openTab(page, 'structure');
      await wait(400);
      const drawn = await page.evaluate((two) => ({
        names: [...document.querySelectorAll(`[data-tree-section="${two}"] .pb-tree-column`)].map((p) => p.textContent.trim()),
        lists: document.querySelectorAll(`[data-tree-blocks="${two}"]`).length,
      }), bands.two);
      report.verdict('a band of two columns is drawn in the tree as two lists, each named',
        drawn.lists === 2 && drawn.names.join('|') === 'Column 1|Column 2', JSON.stringify(drawn));

      const from = await page.$eval(`[data-tree-block="${first}"] .pb-tree-grip`, (el) => { el.scrollIntoView({ block: 'center' }); const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
      const to = await page.$eval(`[data-tree-blocks="${bands.two}"][data-tree-column="1"]`, (el) => { const r = el.getBoundingClientRect(); return { x: r.left + 40, y: r.top + r.height / 2 }; });
      await drag(page, from, to);
      await settle(page);
      const after = await page.evaluate((a, b, one, two) => {
        const block = window.pb.block(a);
        const frame = document.querySelector('[data-pb-canvas]').contentDocument;
        const columns = [...frame.querySelectorAll(`[data-bx-section="${two}"] .section-column`)];
        return {
          section: block ? block.section === two : false,
          column: block ? block.column : null,
          other: window.pb.block(b).column,
          bandGone: !window.pb.section(one),
          drawnIn: columns.findIndex((c) => c.querySelector(`[data-bx-key="${a}"]`)),
          tree: [...document.querySelectorAll(`[data-tree-blocks="${two}"][data-tree-column="1"] [data-tree-block]`)].map((li) => li.getAttribute('data-tree-block')),
        };
      }, first, second, bands.one, bands.two);
      await report.shot(page, '01-dropped-in-column-two', { fullPage: false });
      report.verdict('a block dragged into the second column is in it: in the document, the tree and on the page; the other stays in the first',
        after.section && after.column === 1 && after.other === 0 && after.bandGone && after.drawnIn === 1 && after.tree.join() === first,
        JSON.stringify(after));
    } finally {
      if (id !== null) {
        await settle(page).catch(() => {});
        await deletePage(page, BASE, id);
        const left = await page.$(`tr[data-page-id="${id}"]`) !== null;
        report.verdict('tree: its page is gone again', !left, `left: ${left}`);
      }
    }
  },
};
