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
 * And the item selected is outlined, its actions a second segment of the block's bar (D-188,
 * the owner, replacing D-187's tools in its corner). A question's space is typed, never
 * opening it.
 *
 * Nothing is published: each page's draft is discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, selectItem, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const TYPES = ['cards', 'accordion', 'gallery', 'logos', 'stats', 'downloads'];

/** The block's items in the document, and how many the canvas draws. */
function seen(page, key) {
  return page.evaluate((k) => {
    const b = window.pb.block(k);
    const field = Object.keys(b.content).find((f) => Array.isArray(b.content[f]));
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    // The layout it is drawn in: its own, or the character's where it follows ('', D-191).
    return { layout: b.layout || window.pb.data.composed[b.type], items: JSON.stringify(b.content[field]), count: b.content[field].length, drawn: doc.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item]`).length };
  }, key);
}

/**
 * The item selected (D-188, the owner): pressed as the owner presses it, it alone outlined, and
 * its actions the block's bar's second segment, named for it ("Logo 2"). Nothing of the bar
 * crosses the words of the block — its items' and its heading — save, as the last resort, its
 * own picture (D-185). What is wrong, if anything.
 */
async function selectedItem(page, key, n) {
  await selectItem(page, key, n);
  return page.evaluate((k, i) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const block = doc.querySelector(`[data-bx-key="${k}"]`);
    const b = window.pb.block(k);
    const item = block.querySelector(`[data-bx-item="items.${i}"]`);
    const out = [];
    const outlined = doc.querySelectorAll('[data-bx-item-selected]');
    if (outlined.length !== 1 || outlined[0] !== item) { out.push(`${b.type} item ${i}: not the one item outlined (${outlined.length})`); }
    const bar = doc.querySelector('.bx-toolbar-block');
    const segment = bar ? bar.querySelector('.bx-toolbar-item') : null;
    const noun = window.pb.data.inline.fields[b.type].items.item;
    if (!segment) { out.push(`${b.type} item ${i}: no segment of the block's bar`); return out; }
    const name = segment.querySelector('.bx-toolbar-name').textContent.trim();
    if (name !== `${noun} ${i + 1}`) { out.push(`${b.type} item ${i}: the segment says "${name}"`); }
    if (['item-before', 'item-after', 'item-remove'].some((a) => !segment.querySelector(`[data-bx-action="${a}"]`))) { out.push(`${b.type} item ${i}: an action missing`); }
    if (doc.querySelector('.bx-layer > :not(.bx-toolbar) [data-bx-action^="item-"], .bx-layer > [data-bx-item-tools]')) { out.push(`${b.type} item ${i}: tools on the page besides the bar`); }
    const r = bar.getBoundingClientRect();
    block.querySelectorAll('[data-bx-field]').forEach((f) => {
      const spec = window.pb.inline.spec(b.type, f.getAttribute('data-bx-field')) || {};
      if (bar.getAttribute('data-bx-side') === 'inside' && spec.type === 'media') { return; }
      const q = f.getBoundingClientRect();
      if (q.width > 0 && q.height > 0 && r.left < q.right - 0.5 && q.left < r.right - 0.5 && r.top < q.bottom - 0.5 && q.top < r.bottom - 0.5) {
        out.push(`${b.type} item ${i}: the bar crosses ${f.getAttribute('data-bx-field')}`);
      }
    });
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
          covered.push(...await selectedItem(page, key, added.count - 1));
          await clickInCanvas(page, '.bx-toolbar-item [data-bx-action="item-before"]').catch(() => bad.push('no action to move with'));
          await wait(900);
          // Moved, it stays selected where it went.
          const moved = await page.evaluate(() => (window.pb.item || {}).at);
          if (moved !== added.count - 2) { bad.push(`after the move the selected item is ${moved}, expected ${added.count - 2}`); }
          covered.push(...await selectedItem(page, key, 0));
          await clickInCanvas(page, '.bx-toolbar-item [data-bx-action="item-remove"]').catch(() => bad.push('no action to remove with'));
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
            await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${key}"] input[type="radio"][data-tile="${layout}"]`, { timeout: 10000 }).catch(() => {});
            const clicked = await page.evaluate((k, l) => {
              const radio = document.querySelector(`[data-pb-inspector] [data-block-fields="${k}"] input[type="radio"][data-tile="${l}"]`);
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
          report.verdict(`${type}: the item selected is outlined, its actions the block's bar's, named for it, and the bar on none of the block's words`, covered.length === 0, covered.join('; ') || 'the first item and the last, selected');
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
