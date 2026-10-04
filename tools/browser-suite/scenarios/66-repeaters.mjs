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
 * And an item's tools lie on no words (D-186): on a logo or a number they did, and a press on
 * the name removed the item. A question's space is typed, never opening it.
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
 * The item pointed at, and what its tools lie on: every field whose words — its lines of text,
 * or the field itself while empty and showing a placeholder — the tools cross, of any item or
 * block: a press meant for the words would press a tool instead.
 */
async function pointAt(page, key, n) {
  await page.evaluate((k, i) => {
    const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll(`[data-bx-key="${k}"] [data-bx-item]`)[i];
    el.scrollIntoView({ block: 'center' });
    el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
  }, key, n);
  await wait(400);
  return page.evaluate((k) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const tools = doc.querySelector('.bx-item-tools');
    if (!tools) { return ['no tools']; }
    const t = tools.getBoundingClientRect();
    const type = window.pb.block(k).type;
    return [...doc.querySelectorAll('[data-bx-field]')].filter((f) => {
      const owner = f.closest('[data-bx-key]');
      const spec = owner ? window.pb.inline.spec(window.pb.block(owner.getAttribute('data-bx-key')).type, f.getAttribute('data-bx-field')) : null;
      if (!spec || spec.type === 'media') { return false; }
      // Its words, line by line; the element itself while it is empty and shows a placeholder.
      const range = doc.createRange();
      range.selectNodeContents(f);
      const lines = f.textContent.trim() === '' ? [f.getBoundingClientRect()] : [...range.getClientRects()];
      return lines.some((r) => r.width > 0 && r.height > 0 && r.left < t.right && t.left < r.right && r.top < t.bottom && t.top < r.bottom);
    }).map((f) => `${type} tools over ${f.getAttribute('data-bx-field')}`);
  }, key);
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
            await clickInCanvas(page, `[data-bx-key="${key}"] [data-bx-field="${line}"]`);
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
          report.verdict(`${type}: an item's tools lie on no words`, covered.length === 0, covered.join('; ') || 'the first item and the last, pointed at');
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
