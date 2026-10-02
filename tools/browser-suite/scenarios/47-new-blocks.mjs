/*
 * THE NINE BLOCKS OF D-105, EACH ADDED BY HAND AND EDITED (PLAN.md D-105).
 *
 * The PHP tests already say each block renders what it promises, and 42-library says every
 * card draws its icon and its line. Neither of them presses anything. What is unchecked
 * between them is the part an owner actually does: press the card, and find a panel with the
 * block's own fields in it and the block drawn on the canvas. A repeater that renders no rows,
 * a select with no options, a field whose name does not match what the save reads — all of
 * those pass every test above and are the whole of the block for the person using it.
 *
 * ON ITS OWN PAGE, WHICH IT THEN DELETES. Nine blocks added to a demo page would be nine
 * blocks to take away again, and a cleanup that has to undo nine things is a cleanup that
 * half-finishes: that is how the demo page lost a block twice (memory: probe cleanup is not
 * optional). A page of its own is removed in one action, and the check for it being gone is
 * a verdict rather than a hope.
 *
 * EACH IS ADDED FROM THE RAIL'S ADD TAB (D-175): at the end of the page the first time, and
 * after the band of the block just added from then on, which is where the rail says it goes.
 * Its fields are the inspector's; what it draws is the canvas's.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, openBuilder, addBlock, selectBlock, publish } from '../harness.mjs';

const PAGE = 'zz new blocks ' + Date.now();
const SETTLE = 1500;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* What each block must offer once it is added: a field the panel has to render, and the
   class its template draws. Both are named here rather than derived, so a block that quietly
   stops drawing its grid is caught by this file changing, not by it passing. */
const BLOCKS = [
  { type: 'picture', field: 'caption', draws: 'picture-frame' },
  { type: 'quote', field: 'quote', draws: 'quote-words', fill: { '[quote]': 'Written by the check.' } },
  { type: 'divider', field: 'height', draws: 'divider' },
  { type: 'gallery', field: 'items', draws: 'gallery-grid', rows: 3 },
  {
    type: 'accordion', field: 'items', draws: 'accordion-items', rows: 3,
    fill: { '[question]': 'Written by the check?' },
  },
  { type: 'cta', field: 'heading', draws: 'cta-heading', fill: { '[heading]': 'Written by the check' } },
  { type: 'stats', field: 'items', draws: 'stats-grid', rows: 3, fill: { '[value]': '12' } },
  { type: 'logos', field: 'items', draws: 'logos-row', rows: 3 },
  { type: 'embed', field: 'url', draws: 'embed', fill: { '[url]': 'https://vimeo.com/148751763' } },
];

/** A block added from the rail, and what its inspector and the canvas then hold. */
async function add(page, block) {
  const key = await addBlock(page, block.type).catch(() => null);
  if (key === null) {
    return { added: false, why: `the rail adds no ${block.type}, or it was not selected` };
  }
  await page.$eval('#ins-content', (d) => { d.open = true; }).catch(() => {});
  await wait(SETTLE);

  return page.evaluate((b, k) => {
    const form = document.querySelector(`[data-pb-inspector] [data-block-fields="${k}"]`);
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    if (!form) { return { added: false, why: 'its inspector did not open' }; }
    const named = (suffix) => form.querySelectorAll(`[name$="[${suffix}]"]`).length;
    const rows = [...form.querySelectorAll(`[name*="[${b.field}]["]`)];

    return {
      added: true,
      key: k,
      type: (form.querySelector('input[name$="[type]"]') || {}).value,
      // A repeater's rows are counted by the name its items carry: items[0][…], items[1][…].
      field: b.rows === undefined ? named(b.field) : rows.length,
      rows: b.rows === undefined ? null : new Set(rows.map((i) => (i.getAttribute('name').match(/\]\[(\d+)\]/) || [''])[0])).size,
      drawn: doc.querySelectorAll(`[data-bx-key="${k}"] .${b.draws}, [data-bx-key="${k}"].${b.draws}`).length,
      required: [...form.querySelectorAll('label')].filter((l) => l.textContent.includes('(required)')).length,
    };
  }, block, key);
}

/** Its required fields filled in its own inspector, every row of a repeater. */
async function fill(page, key, entries) {
  await selectBlock(page, key);
  await page.$eval('#ins-content', (d) => { d.open = true; }).catch(() => {});
  const done = await page.evaluate((k, jobs) => {
    const form = document.querySelector(`[data-pb-inspector] [data-block-fields="${k}"]`);
    let count = 0;
    for (const [suffix, value] of jobs) {
      for (const field of form.querySelectorAll(`[name$="${suffix}"]`)) {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        count += 1;
      }
    }
    return count;
  }, key, entries);
  await wait(SETTLE);
  return done;
}

export default {
  name: 'new-blocks',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('new-blocks: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1700, height: 1100, deviceScaleFactor: 1 });

    let pageId = null;
    try {
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', PAGE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      pageId = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      if (pageId === null) {
        report.fail('new-blocks: a page to put them on', 'the new page has no id, so nothing here can run');

        return;
      }
      await openBuilder(page, BASE, pageId);

      // ---- each of the nine, added the way an owner adds one --------------------------
      const seen = [];
      for (const block of BLOCKS) {
        const got = await add(page, block);
        seen.push({ type: block.type, ...got });
        report.verdict(`${block.type}: the rail adds it, its inspector opens on its own fields, and the canvas draws it`,
          got.added === true && got.type === block.type && got.field > 0 && got.drawn > 0
            && (block.rows === undefined || got.rows === block.rows),
          JSON.stringify(got));
        if (got.added !== true) {
          break;
        }
      }
      await report.shot(page, '01-nine-blocks');

      const all = seen.length === BLOCKS.length && seen.every((s) => s.added === true);
      const order = await page.evaluate(() => window.pb.doc.sections.map((s) => window.pb.blocksIn(s.key).map((b) => b.type).join('+')).join(','));
      report.verdict('all nine stand on the page, in the order they were added',
        all && order === BLOCKS.map((b) => b.type).join(','), order);
      if (!all) {
        return;
      }
      report.verdict('a required field says so on its label', seen.reduce((n, s) => n + s.required, 0) >= 5,
        `${seen.reduce((n, s) => n + s.required, 0)} labels marked required`);

      /* ---- fill what is required, because that is what an owner does ----------------
         A page of nine untouched blocks is REFUSED, and rightly: five of them declare a
         required field — the Quote's words, the CTA's heading, the Embed's address, and,
         inside a repeater, an Accordion's question and a Stat's number. Named above, in
         each block's own inspector, every row of a repeater (BlockForm reports the first
         item error and no more). */
      let filled = 0;
      for (const block of BLOCKS.filter((b) => b.fill)) {
        filled += await fill(page, seen.find((x) => x.type === block.type).key, Object.entries(block.fill));
      }
      // Three of the five are one field; two are one field per repeater row, of which each
      // block arrives with three. So: 3 + 3 + 3.
      report.verdict('every field a block calls required is there to be filled in', filled === 3 + 3 + 3, `${filled} required fields found and filled, expected 9`);

      // ---- and Publish keeps them, and a visitor gets them ------------------------------
      await publish(page).catch(() => {});
      const state = await page.$eval('[data-pb-state]', (p) => p.textContent.trim());
      const refused = await page.$$eval('.pb-notice-error', (n) => n.map((e) => e.textContent.trim()));
      report.verdict('a page of all nine is published once what is required is filled in', /Published/.test(state) && refused.length === 0, JSON.stringify({ state, refused }));

      await openBuilder(page, BASE, pageId);
      const stored = await page.evaluate(() => window.pb.doc.blocks.length);
      report.verdict('all nine come back from the database', stored === BLOCKS.length, `${stored} blocks after publishing`);
    } finally {
      if (pageId !== null) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        // The row's menu is a closed <details>; a click on a button inside one does nothing.
        await page.$eval(`tr[data-page-id="${pageId}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
        await page.$eval(`form[action$="/pages/${pageId}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${pageId}/delete"] button`).catch(() => {});
      }
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const stray = await page.evaluate((t) => document.body.textContent.includes(t), PAGE);
      report.verdict('the scenario takes its page away again', !stray, `page left behind: ${stray}`);
    }
  },
};
