/*
 * A PARAGRAPH'S ALIGNMENT (PLAN.md D-217, the owner): as the block, centred or right, from the
 * inspector's rich text toolbar and from the one over a selection on the page.
 *
 *   - Centre in All content: the document stores the class text-center, the canvas draws the
 *     paragraph centred, and the button is lit;
 *   - on the page, over a selection: right, and then "as the block", which takes it away;
 *   - published: the visitor's paragraph is centred, its box with its words, so a paragraph
 *     held to 38em stands in the middle of the block and not at its left;
 *   - a list item is not aligned: the stored shape has no paragraph there, so the toolbar
 *     changes nothing it would lose on save.
 *
 * ON THE COPY, on a page of its own, deleted at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, deletePage, clickAndWait, clickInCanvas, openBuilder, addBlock, selectBlock, publish, settle, SLOW } from '../harness.mjs';

const TITLE = `Zz align ${Date.now()}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

/** The block's stored body, the first paragraph's alignment on the canvas, and which button is lit. */
const read = (page, key, toolbar) => page.evaluate((k, bar) => {
  const doc = document.querySelector('[data-pb-canvas]').contentDocument;
  const p = doc.querySelector(`[data-bx-key="${k}"] [data-bx-field="body"] p`);
  const lit = [...document.querySelectorAll(`${bar} [data-rt^="align"]`), ...doc.querySelectorAll('.bx-rich-tools [data-rt^="align"]')]
    .filter((b) => b.getAttribute('aria-pressed') === 'true').map((b) => b.getAttribute('data-rt'));
  return { body: window.pb.block(k).content.body, drawn: p ? getComputedStyle(p).textAlign : null, lit: [...new Set(lit)] };
}, key, toolbar);

export default {
  name: 'align',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('align: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
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
      const key = await addBlock(page, 'text');

      // ---- centred in All content ------------------------------------------------------------
      const form = await selectBlock(page, key);
      await page.$eval('#ins-content', (d) => { d.open = true; });
      await page.click(`${form} .ProseMirror`);
      await page.keyboard.type('A paragraph the owner wants in the middle of the block, and long enough to wrap.', { delay: SLOW });
      await page.click(`${form} [data-richtext-toolbar] [data-rt="alignCenter"]`);
      await settle(page);
      const centred = await read(page, key, form);
      report.verdict('Centre in All content: stored as text-center, drawn centred on the canvas, the button lit',
        /<p class="text-center">/.test(centred.body) && centred.drawn === 'center' && centred.lit.includes('alignCenter') && !centred.lit.includes('alignLeft'),
        JSON.stringify(centred));
      await report.shot(page, '01-centred-inspector', { fullPage: false });

      // ---- on the page, over a selection -----------------------------------------------------
      await clickInCanvas(page, `[data-bx-key="${key}"] [data-bx-field="body"]`);
      await wait(500);
      await page.keyboard.down('Shift');
      for (let i = 0; i < 5; i += 1) {
        await page.keyboard.press('ArrowLeft');
        await wait(80);
      }
      await page.keyboard.up('Shift');
      await wait(300);
      const offered = await page.evaluate(() => [...document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('.bx-rich-tools [data-rt^="align"]')].map((b) => b.getAttribute('data-rt')));
      await clickInCanvas(page, '.bx-rich-tools [data-rt="alignRight"]');
      await settle(page);
      const right = await read(page, key, form);
      await report.shot(page, '02-right-on-the-page', { fullPage: false });
      await clickInCanvas(page, '.bx-rich-tools [data-rt="alignLeft"]');
      await settle(page);
      const back = await read(page, key, form);
      report.verdict('on the page: the three offered over a selection, right stored and drawn, "as the block" takes it away',
        offered.join(',') === 'alignLeft,alignCenter,alignRight' && /<p class="text-right">/.test(right.body) && right.drawn === 'right'
          && !/class=/.test(back.body) && back.drawn !== 'right' && back.lit.includes('alignLeft'),
        JSON.stringify({ offered, right, back }));
      await page.keyboard.press('Escape');
      await page.mouse.click(10, 10);
      await wait(400);

      // ---- a list item is not aligned ---------------------------------------------------------
      await selectBlock(page, key);
      await page.$eval('#ins-content', (d) => { d.open = true; });
      // Pressed just inside the last letter, so the caret is at the end of the words: a press in
      // the middle and Ctrl+End left it at the start (measured: the item took the paragraph in).
      const end = await page.$eval(`${form} .ProseMirror`, (el) => {
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        let last = null;
        for (let t = walker.nextNode(); t; t = walker.nextNode()) { last = t; }
        const range = document.createRange();
        range.setStart(last, last.length - 1);
        range.setEnd(last, last.length);
        const r = range.getBoundingClientRect();
        return { x: r.right - 1, y: r.top + r.height / 2 };
      });
      await page.mouse.click(end.x, end.y);
      await wait(300);
      await page.keyboard.press('End');
      await page.keyboard.press('Enter');
      await page.keyboard.type('An item', { delay: SLOW });
      await page.click(`${form} [data-richtext-toolbar] [data-rt="bullet"]`);
      await page.click(`${form} [data-richtext-toolbar] [data-rt="alignCenter"]`);
      await settle(page);
      const listed = await read(page, key, form);
      report.verdict('a list item is not aligned: nothing is stored that the save would lose',
        /<ul><li>An item<\/li><\/ul>/.test(listed.body) && !/<li[^>]*class/.test(listed.body), JSON.stringify(listed.body));

      // Centred again, for the visitor.
      await page.click(`${form} .ProseMirror p`);
      await page.click(`${form} [data-richtext-toolbar] [data-rt="alignCenter"]`);
      await publish(page);

      // ---- published -----------------------------------------------------------------------
      const visitor = await page.browser().newPage();
      try {
        await visitor.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        await visitor.goto(`${BASE}/${TITLE.toLowerCase().replace(/\s+/g, '-')}`, { waitUntil: 'networkidle2' });
        const seen = await visitor.evaluate(() => {
          const p = document.querySelector('main .richtext > p.text-center');
          if (!p) return null;
          const column = p.parentElement.getBoundingClientRect();
          const measure = () => { const box = p.getBoundingClientRect(); return { left: Math.round(box.left - column.left), right: Math.round(column.right - box.right), width: Math.round(box.width) }; };
          const whole = measure();
          // Held narrower than its column, as a paragraph held to 38em in a wider block is
          // (site.css): the box goes to the middle with its words, not only the words in it.
          p.style.maxInlineSize = '20em';
          const held = measure();
          p.style.maxInlineSize = '';
          return { align: getComputedStyle(p).textAlign, column: Math.round(column.width), whole, held };
        });
        await visitor.evaluate(() => { const p = document.querySelector('main .richtext'); if (p) p.scrollIntoView({ block: 'center' }); });
        await report.shot(visitor, '03-visitor', { fullPage: false });
        report.verdict('the visitor\'s paragraph is centred, its box in the middle of the block with its words',
          seen !== null && seen.align === 'center' && Math.abs(seen.whole.left - seen.whole.right) <= 2
            && seen.held.width < seen.column - 40 && Math.abs(seen.held.left - seen.held.right) <= 2, JSON.stringify(seen));
      } finally {
        await visitor.close();
      }
    } finally {
      if (id !== null) {
        await settle(page).catch(() => {});
        await deletePage(page, BASE, id);
        const left = await page.$(`tr[data-page-id="${id}"]`) !== null;
        report.verdict('align: its page is gone again', !left, `left: ${left}`);
      }
    }
  },
};
