/*
 * NO ITEM IS EVER LOST (PLAN.md D-186, the owner's priority). For every block with a repeater
 * — cards, accordion, gallery, logos, stats, downloads — on the copy:
 *
 *   items added, moved and removed on the page → each other layout chosen in the inspector →
 *   undo → redo → the draft saved and the builder opened again.
 *
 * After every step the items are the same, in number and in content, in the document and in
 * the canvas. Found by the owner: three cards added on the page, then the layout changed in
 * the inspector, and the new cards were gone — the inspector's form, drawn before them, was
 * sent back whole and written over the block.
 *
 * And the item an item's tools act on is outlined, the tools in its top right corner (D-187,
 * replacing D-186's "on no words"). A question's space is typed, never opening it.
 *
 * Nothing is published: each page's draft is discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const TYPES = ['cards', 'accordion', 'gallery', 'logos', 'stats', 'downloads'];

/** The block's items in the document, and how many the canvas draws. */
function seen(page, key) {
  return page.evaluate((k) => {
    const b = window.pb.block(k);
    const field = Object.keys(b.content).find((f) => Array.isArray(b.content[f]));
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    return { layout: b.layout, items: JSON.stringify(b.content[field]), count: b.content[field].length, drawn: doc.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item]`).length };
  }, key);
}

/**
 * The item pointed at, and where its tools stand (D-187, the owner): the item outlined, the
 * tools in its top right corner inside it — or, where the item is smaller than the tools, a tab
 * on that corner — and always within the block. What is wrong, if anything.
 */
async function pointAt(page, key, n) {
  await page.evaluate((k, i) => {
    const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item]`)[i];
    el.scrollIntoView({ block: 'center' });
    el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
  }, key, n);
  await wait(400);
  return page.evaluate((k, i) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const block = doc.querySelector(`[data-bx-key="${k}"]`);
    const item = block.querySelectorAll('[data-bx-item]')[i];
    const tools = doc.querySelector('.bx-item-tools');
    const type = window.pb.block(k).type;
    if (!tools) { return [`${type} item ${i}: no tools`]; }
    const t = tools.getBoundingClientRect();
    const r = item.getBoundingClientRect();
    const b = block.getBoundingClientRect();
    const out = [];
    if (!item.hasAttribute('data-bx-pointed') || doc.querySelectorAll('[data-bx-pointed]').length !== 1) { out.push(`${type} item ${i}: not the one item outlined`); }
    const tab = tools.getAttribute('data-bx-side') === 'tab';
    const inside = t.left >= r.left - 0.5 && t.right <= r.right + 0.5 && t.top >= r.top - 0.5 && t.bottom <= r.bottom + 0.5;
    // Inside: within 8px of the corner. A tab, wider than its item and kept within the block:
    // over the corner, its foot on the item's top edge (or on the block's top, where it is).
    const corner = tab
      ? t.left <= r.right + 0.5 && r.right <= t.right + 0.5 && (Math.abs(t.bottom - r.top) <= 1 || Math.abs(t.top - b.top) <= 1)
      : Math.abs(r.right - t.right) <= 8 && Math.abs(t.top - r.top) <= 8;
    if (tab ? (t.width <= r.width && t.height <= r.height) : !inside) { out.push(`${type} item ${i}: tools ${tab ? 'a tab on an item that holds them' : 'not inside the item'}`); }
    const at = (x) => `${Math.round(x.left)},${Math.round(x.top)}–${Math.round(x.right)},${Math.round(x.bottom)}`;
    if (!corner) { out.push(`${type} item ${i}: tools ${at(t)} not at the top right corner of ${at(r)}${tab ? ' (a tab)' : ''}`); }
    if (t.left < b.left - 0.5 || t.right > b.right + 0.5 || t.top < b.top - 0.5 || t.bottom > b.bottom + 0.5) { out.push(`${type} item ${i}: tools outside the block`); }
    return out;
  }, key, n);
}

export default {
  name: 'repeaters',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('repeaters: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
    const done = new Set();
    for (const pageId of [1, Number(every.slice(5))]) {
      await openBuilder(page, BASE, pageId);
      try {
        const blocks = await page.evaluate((types) => {
          const out = []; const had = new Set();
          window.pb.doc.blocks.forEach((b) => { if (types.includes(b.type) && !had.has(b.type)) { had.add(b.type); out.push([b.key, b.type]); } });
          return out;
        }, TYPES.filter((t) => !done.has(t)));
        for (const [key, type] of blocks) {
          done.add(type);
          const bad = [];
          const check = async (step, expected, layout) => {
            const now = await seen(page, key);
            if (now.items !== expected.items || now.drawn !== expected.count || (layout && now.layout !== layout)) {
              bad.push(`${step}: ${now.count} in the document, ${now.drawn} drawn, layout ${now.layout} (expected ${expected.count}${layout ? `, ${layout}` : ''})`);
            }
          };
          await page.evaluate((k) => window.pb.select('block', k), key);
          await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${key}"]`, { timeout: 10000 }).catch(() => {});
          await wait(500);
          const start = await seen(page, key);
          // On the page: two added, the last moved before the one ahead of it, the first removed.
          for (let i = 0; i < 2; i += 1) {
            await clickInCanvas(page, `[data-bx-key="${key}"] [data-bx-add-item]`);
            await wait(900);
          }
          const added = await seen(page, key);
          if (added.count !== start.count + 2) { bad.push(`added: ${added.count}, expected ${start.count + 2}`); }
          const covered = [];
          covered.push(...await pointAt(page, key, added.count - 1));
          await clickInCanvas(page, '.bx-item-tools [data-bx-action="item-before"]').catch(() => bad.push('no tools to move with'));
          await wait(900);
          covered.push(...await pointAt(page, key, 0));
          await clickInCanvas(page, '.bx-item-tools [data-bx-action="item-remove"]').catch(() => bad.push('no tools to remove with'));
          await wait(900);
          // And words typed on the page, in an item's line.
          const line = await page.evaluate((k) => {
            const doc = document.querySelector('[data-pb-canvas]').contentDocument;
            const b = window.pb.block(k);
            const el = [...doc.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item] [data-bx-field]`)]
              .find((f) => ['text', 'textarea'].includes((window.pb.inline.spec(b.type, f.getAttribute('data-bx-field')) || {}).type));
            return el ? el.getAttribute('data-bx-field') : null;
          }, key);
          if (line) {
            // Where the words start: the item's tools stand in its right corner, over it (D-187).
            await clickInCanvas(page, `[data-bx-key="${key}"] [data-bx-field="${line}"]`, { side: 'left' });
            await wait(400);
            await page.keyboard.press('End');
            await page.keyboard.type(' typed', { delay: 30 });
            await page.keyboard.press('Escape');
            await page.mouse.click(10, 10);
            await wait(1200);
            await page.evaluate((k) => window.pb.select('block', k), key);
            await wait(1500);
          }
          const expected = await seen(page, key);
          if (line && !/ typed/.test(expected.items)) { bad.push(`the words typed in ${line} are not in the document`); }
          if (expected.count !== start.count + 1 || expected.drawn !== expected.count) { bad.push(`after add, move, remove: ${expected.count} in the document, ${expected.drawn} drawn`); }

          // Each other layout, chosen in the inspector as the owner chose it.
          const layouts = await page.evaluate((t) => (window.pb.data.layouts[t] || []).map((l) => l.value), type);
          let last = expected.layout;
          for (const layout of layouts.filter((l) => l !== expected.layout)) {
            await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${key}"] input[type="radio"][value="${layout}"]`, { timeout: 10000 }).catch(() => {});
            const clicked = await page.evaluate((k, l) => {
              const radio = document.querySelector(`[data-pb-inspector] [data-block-fields="${k}"] input[type="radio"][value="${l}"]`);
              if (!radio) { return false; }
              radio.closest('label').click();
              return true;
            }, key, layout);
            if (!clicked) { bad.push(`no tile for ${layout}`); continue; }
            await wait(2500);
            await check(`layout ${layout}`, expected, layout);
            last = layout;
          }
          await page.click('[data-pb-undo]');
          await wait(2000);
          await check('undo', expected, null);
          await page.click('[data-pb-redo]');
          await wait(2000);
          await check('redo', expected, last);
          await settle(page);
          await openBuilder(page, BASE, pageId);
          await check('opened again', expected, last);
          report.verdict(`${type}: items added, moved and removed on the page survive every layout, undo, redo and the draft opened again`,
            bad.length === 0, bad.join('; ') || `${expected.count} items through ${layouts.length} layouts`);
          report.verdict(`${type}: the item pointed at is outlined, its tools in its top right corner and within the block`, covered.length === 0, covered.join('; ') || 'the first item and the last, pointed at');
        }
      } finally {
        await settle(page).catch(() => {});
        await openBuilder(page, BASE, pageId);
        if (await page.$('[data-pb-discard]:not([hidden])')) {
          await page.click('[data-pb-discard]');
          await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
        }
      }
    }
    report.verdict('every block with a repeater was tried', TYPES.every((t) => done.has(t)), [...done].join(', '));
  },
};
