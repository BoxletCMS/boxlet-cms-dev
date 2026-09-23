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
 * EVERY BLOCK IS AIMED BEFORE IT IS ADDED (D-099, the owner's choice). A library card with
 * nothing aimed at does nothing, deliberately, so each block presses the column's "+ Block"
 * first. That is the flow, not a workaround for it.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

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

const canvas = (page) => page.frames().find((f) => f.url().includes('/canvas'));

/** Aim at a column, press a card, and report what the panel and the canvas then hold. */
async function add(page, block) {
  const frame = canvas(page);
  const slots = await frame.$$('.bx-slot');
  if (slots.length === 0) {
    return { added: false, why: 'no column offered a place to put a block' };
  }
  // The LAST slot: each block added lands under the one before it, so the last is the one
  // at the foot of the column.
  await slots[slots.length - 1].click();
  await wait(700);
  const pressed = await page.$$eval(`.panel-library [data-add-type="${block.type}"]`, (cards) => {
    if (cards.length !== 1) { return false; }
    cards[0].click();

    return true;
  }).catch(() => false);
  if (!pressed) {
    return { added: false, why: `the library offers no card for ${block.type}` };
  }
  await wait(SETTLE * 2);

  return page.evaluate((b) => {
    const group = [...document.querySelectorAll('[data-block-group]')].find((g) => !g.hidden);
    const doc = document.querySelector('iframe[data-canvas]').contentDocument;
    if (!group) { return { added: false, why: 'nothing was added, or nothing was selected' }; }
    const named = (suffix) => group.querySelectorAll(`[name$="[${suffix}]"]`).length;

    return {
      added: true,
      type: (group.querySelector('input[name$="[type]"]') || {}).value,
      // A repeater's rows are counted by the name its items carry: items[0][…], items[1][…].
      field: b.rows === undefined ? named(b.field) : group.querySelectorAll(`[name*="[${b.field}]["]`).length,
      rows: b.rows === undefined ? null : new Set([...group.querySelectorAll(`[name*="[${b.field}]["]`)]
        .map((i) => (i.getAttribute('name').match(/\[\d+\]/) || [''])[0])).size,
      drawn: doc.querySelectorAll(`.${b.draws}`).length,
      onCanvas: doc.querySelectorAll('.section-column > *').length,
    };
  }, block);
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

      // ---- a band to put them in ------------------------------------------------------
      await page.waitForFunction(() => {
        const frame = document.querySelector('iframe[data-canvas]');

        return frame && frame.contentDocument && frame.contentDocument.querySelector('.bx-insert');
      }, { timeout: 20000 });
      await canvas(page).click('.bx-insert');
      await wait(SETTLE * 2);
      const band = await canvas(page).evaluate(() => document.querySelectorAll('.bx-slot').length);
      report.verdict('an empty page offers a band, and the band a place to put a block',
        band === 1, `slots after + Section: ${band}`);
      if (band !== 1) {
        return;
      }

      // ---- each of the nine, added the way an owner adds one --------------------------
      const seen = [];
      for (const block of BLOCKS) {
        const got = await add(page, block);
        seen.push({ type: block.type, ...got });
        report.verdict(`${block.type}: the card adds it, its own fields open, and the canvas draws it`,
          got.added === true && got.type === block.type && got.field > 0 && got.drawn > 0
            && (block.rows === undefined || got.rows === block.rows),
          JSON.stringify(got));
        if (got.added !== true) {
          break;
        }
      }
      await report.shot(page, '01-nine-blocks');

      const all = seen.length === BLOCKS.length && seen.every((s) => s.added === true);
      report.verdict('all nine stand in one column, in the order they were added',
        all && seen[seen.length - 1].onCanvas === BLOCKS.length,
        `${seen.filter((s) => s.added).length} of ${BLOCKS.length} added; last saw ${(seen[seen.length - 1] || {}).onCanvas} on the canvas`);
      if (!all) {
        return;
      }

      /* ---- fill what is required, because that is what an owner does ----------------
         A page of nine untouched blocks is REFUSED, and rightly: five of them declare a
         required field — the Quote's words, the CTA's heading, the Embed's address, and,
         inside a repeater, an Accordion's question and a Stat's number. A <summary> with
         nothing in it is an unpressable control, so requiring it is the block being honest.
         Measured: the first run of this scenario came back with exactly those five.

         NAMED ABOVE, NOT FOUND BY THE `required` ATTRIBUTE — because there isn't one. The
         panel marks a required field in its LABEL (pages.required_marker), and the browser's
         own required= is deliberately absent: the server is the validator here. The first
         version of this step looked for the attribute, found none, and filled nothing, which
         is the kind of step that passes while doing nothing at all. Set rather than typed:
         only the selected block's group is on screen. */
      /* SCOPED TO THE BLOCK THAT OWNS THE FIELD. Written first as a plain
         `[data-block-group] [name$="[heading]"]`, which took the GALLERY's heading (four
         blocks on this page have one) and put the Embed's address into the CTA's button,
         producing "Enter the text of the link" out of nowhere. A field suffix names a field
         within a block, never within a page. */
      const filled = await page.evaluate((jobs) => {
        let done = 0;
        for (const [type, suffix, value] of jobs) {
          const group = [...document.querySelectorAll('[data-block-group]')]
            .find((g) => (g.querySelector('input[name$="[type]"]') || {}).value === type);
          /* EVERY ROW, not the first. A required field inside a repeater is required in
             each item: three Accordion rows arrive and each wants a question, and filling
             only [items][0][question] left the save refusing the other two. BlockForm
             reports the first item error and no more, so the message said "required" once
             for a block that was short two answers. */
          const fields = group ? [...group.querySelectorAll(`[name$="${suffix}"]`)] : [];
          for (const field of fields) {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            done += 1;
          }
        }

        return done;
      }, BLOCKS.flatMap((b) => Object.entries(b.fill || {}).map(([suffix, value]) => [b.type, suffix, value])));
      // Three of the five are one field; two are one field per repeater row, of which each
      // block arrives with three. So: 3 + 3 + 3.
      const wanted = 3 + 3 + 3;
      report.verdict('every field a block calls required is there to be filled in',
        filled === wanted, `${filled} required fields found and filled, expected ${wanted}`);

      // AND THE PANEL SAYS SO, in the label, which is the only place it does.
      const marked = await page.evaluate(() => [...document.querySelectorAll('[data-block-group] label')]
        .filter((l) => l.textContent.includes('(required)')).length);
      report.verdict('a required field says so on its label',
        marked >= 5, `${marked} labels marked required`);
      await wait(SETTLE);

      // ---- and the save keeps them, and a visitor gets them ---------------------------
      await page.evaluate(() => {
        const form = document.querySelector('form[data-builder]');
        form.requestSubmit(form.querySelector('button[name="action"][value="save"]:not(.visually-hidden)'));
      });
      await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
      const notices = await page.evaluate(() => Array.from(document.querySelectorAll('.alert, [role="alert"], .field-error'))
        .map((a) => a.textContent.trim().slice(0, 120)));
      report.verdict('a page of all nine saves once what is required is filled in',
        notices.length === 0, JSON.stringify(notices));

      const stored = await page.evaluate(() => document.querySelectorAll('[data-block-group]').length);
      report.verdict('all nine come back from the database', stored === BLOCKS.length, `${stored} blocks after the save`);
    } finally {
      await page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});
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
