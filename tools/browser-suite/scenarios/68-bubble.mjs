/*
 * THE RICH TEXT TOOLBAR IS A BUBBLE OVER THE SELECTION (PLAN.md D-186, the owner; Notion's
 * model, TipTap's BubbleMenu), replacing the places of D-183:
 *
 *   - a caret alone: no toolbar;
 *   - a word selected: the toolbar within 16px above it (under it where there is no room
 *     above), centred on it unless the canvas's edge pushed it along, crossing none of the
 *     editor's other marks;
 *   - while a field is written in, the block's toolbar and an item's tools are put away, and
 *     back after Escape;
 *   - ⌘B and ⌘I work with or without the toolbar, ⌘K opens the link popover beside the
 *     selection with the caret in its address.
 *
 * Every rich text field of the demo's home page and of its page of every block, on desktop and
 * phone; the owner's two, Image and text and a card's description, pictured. On the copy;
 * what is typed is taken back and the drafts discarded.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, blockKey, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

/** Where on screen a point of the canvas is: the canvas is shown scaled. */
async function onScreen(page, selector, fraction) {
  return page.evaluate((q, f) => {
    const frame = document.querySelector('[data-pb-canvas]');
    const el = frame.contentDocument.querySelector(q);
    el.scrollIntoView({ block: 'center' });
    // The middle of the first line's second word, or of its first where there is one.
    const walker = frame.contentDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node && node.textContent.trim() === '') { node = walker.nextNode(); }
    const r = frame.getBoundingClientRect();
    const s = window.pb.canvas.scale;
    if (!node) {
      const b = el.getBoundingClientRect();
      return { x: r.left + (b.left + 12) * s, y: r.top + (b.top + b.height / 2) * s };
    }
    const words = [...node.textContent.matchAll(/\S{3,}/g)];
    const word = words[Math.min(1, words.length - 1)] || { index: 0, 0: node.textContent };
    const range = frame.contentDocument.createRange();
    range.setStart(node, word.index);
    range.setEnd(node, word.index + Math.max(1, Math.floor(word[0].length * f)));
    const b = range.getBoundingClientRect();
    return { x: r.left + b.right * s, y: r.top + (b.top + b.height / 2) * s };
  }, selector, fraction);
}

/** The selection, the bubble and what it crosses; the toolbars put away or not. */
function state(page) {
  return page.evaluate(() => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const win = doc.defaultView;
    const sel = doc.getSelection();
    const range = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
    const s = range && !range.collapsed ? range.getBoundingClientRect() : null;
    const bubble = doc.querySelector('.bx-bubble-layer .bx-rich-tools');
    const shown = bubble && win.getComputedStyle(bubble).visibility === 'visible' ? bubble.getBoundingClientRect() : null;
    const visible = (n) => n && win.getComputedStyle(n).visibility === 'visible' && n.getBoundingClientRect().width > 0;
    const layer = doc.querySelector('.bx-layer:not(.bx-bubble-layer)');
    // Not the selected block's frame, which stands around the words as its outline did (D-199).
    const crossed = shown ? [...(layer ? layer.children : [])].filter((n) => !n.classList.contains('bx-frame')).concat([...doc.querySelectorAll('.bx-add-item-cell')])
      .filter((n) => visible(n) && !n.hidden)
      .filter((n) => { const r = n.getBoundingClientRect(); return shown.left < r.right - 0.5 && r.left < shown.right - 0.5 && shown.top < r.bottom - 0.5 && r.top < shown.bottom - 0.5; })
      .map((n) => n.className) : [];
    return {
      words: range ? range.toString() : '',
      selection: s && { top: s.top, bottom: s.bottom, centre: s.left + s.width / 2 },
      bubble: shown && { top: shown.top, bottom: shown.bottom, left: shown.left, right: shown.right, centre: shown.left + shown.width / 2 },
      room: { left: 0, right: win.innerWidth },
      crossed,
      blockBar: visible(layer && layer.querySelector('.bx-toolbar-block')),
      itemTools: visible(layer && layer.querySelector('.bx-toolbar-item')),
    };
  });
}

/** What is wrong with the bubble over the selection, if anything. */
function judged(s) {
  if (!s.selection || s.words.trim() === '') { return 'no word selected'; }
  if (!s.bubble) { return `no toolbar over "${s.words}"`; }
  const above = s.selection.top - s.bubble.bottom;
  const below = s.bubble.top - s.selection.bottom;
  const out = [];
  if (!(above >= 0 && above <= 16) && !(below >= 0 && below <= 16)) { out.push(`${Math.round(above)}px above, ${Math.round(below)}px below the selection`); }
  const pushed = s.bubble.left <= 9 || s.bubble.right >= s.room.right - 9;
  if (!pushed && Math.abs(s.bubble.centre - s.selection.centre) > 1.5) { out.push(`centred ${Math.round(s.bubble.centre - s.selection.centre)}px off the selection`); }
  if (s.crossed.length) { out.push(`crosses ${s.crossed.join(', ')}`); }
  if (s.blockBar) { out.push('the block\'s toolbar is shown'); }
  if (s.itemTools) { out.push('an item\'s actions are shown'); }
  return out.join(', ');
}

/** Pressed once to write, then a word double-pressed: the caret state, then the word's. */
async function selectWord(page, selector) {
  const p = await onScreen(page, selector, 0.5);
  await page.mouse.click(p.x, p.y);
  await wait(700);
  const caret = await state(page);
  const q = await onScreen(page, selector, 0.5);
  await page.mouse.click(q.x, q.y, { count: 2 });
  await wait(500);
  return { caret, word: await state(page) };
}

async function leave(page) {
  await page.keyboard.press('Escape');
  await wait(300);
  await page.evaluate(() => { const a = document.querySelector('[data-pb-canvas]').contentDocument.activeElement; if (a && a.blur) { a.blur(); } });
  await wait(400);
}

export default {
  name: 'bubble',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('bubble: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
    try {
      // ---- the owner's two, pictured, with the keys ------------------------------------------------
      const imageText = await blockKey(page, 'image_text');
      const cards = await blockKey(page, 'cards');
      for (const [name, selector] of [['image-text', `[data-bx-key="${imageText}"] [data-bx-field="body"]`], ['cards', `[data-bx-key="${cards}"] [data-bx-field="items.1.body"]`]]) {
        const { caret, word } = await selectWord(page, selector);
        await shot(report, page, `01-${name}-word`);
        report.verdict(`${name}: a caret alone has no toolbar; the block's toolbar and an item's tools are put away`, !caret.bubble && !caret.blockBar && !caret.itemTools, JSON.stringify(caret));
        const wrong = judged(word);
        report.verdict(`${name}: a word selected has the toolbar within 16px above it, centred, crossing nothing`, wrong === '', wrong || `"${word.words.trim()}", ${Math.round(word.selection.top - word.bubble.bottom)}px above`);
        // ⌘B (Ctrl on this machine), the toolbar or not; taken back with the editor's own undo.
        const key = selector.match(/data-bx-key="([^"]+)"/)[1];
        const path = selector.match(/data-bx-field="([^"]+)"/)[1];
        await page.keyboard.down('Control'); await page.keyboard.press('b'); await page.keyboard.up('Control');
        await wait(400);
        const bold = await page.evaluate((k, p) => window.pb.inline.get(window.pb.block(k), p), key, path);
        await page.keyboard.down('Control'); await page.keyboard.press('z'); await page.keyboard.up('Control');
        await wait(400);
        report.verdict(`${name}: Ctrl+B makes the selected word bold`, bold.includes(`<strong>${word.words.trim()}</strong>`), bold.slice(0, 160));
        // ⌘K: the popover beside the selection, the caret in its address; Escape twice, back.
        await page.keyboard.down('Control'); await page.keyboard.press('k'); await page.keyboard.up('Control');
        await wait(500);
        const link = await page.evaluate(() => {
          const panel = document.querySelector('[data-pb-link]');
          const a = document.activeElement;
          return panel ? { words: panel.querySelector('[data-pb-link-text]').value, caret: !!(a && panel.contains(a)) } : null;
        });
        const linking = await state(page);
        if (name === 'cards') { await shot(report, page, '02-cards-link'); }
        await page.keyboard.press('Escape');
        await wait(300);
        await leave(page);
        const after = await state(page);
        report.verdict(`${name}: Ctrl+K opens the link popover on the selected word, the caret in it, the toolbars put away; Escape brings the block's toolbar back`,
          link !== null && link.words === word.words.trim() && link.caret && !linking.blockBar && !linking.itemTools && after.blockBar && !after.bubble,
          JSON.stringify({ link, linking: { blockBar: linking.blockBar, itemTools: linking.itemTools }, after: { blockBar: after.blockBar, bubble: !!after.bubble } }));
      }

      // ---- every rich field, desktop and phone -------------------------------------------------------
      for (const pageId of [1, Number(every.slice(5))]) {
        await openBuilder(page, BASE, pageId);
        for (const device of ['desktop', 'phone']) {
          await page.click(`[data-device="${device}"]`);
          await wait(900);
          const fields = await page.evaluate(() => {
            const doc = document.querySelector('[data-pb-canvas]').contentDocument;
            const out = [];
            window.pb.doc.blocks.forEach((b) => {
              const seen = new Set();
              doc.querySelectorAll(`[data-bx-key="${b.key}"] [data-bx-field]`).forEach((el) => {
                const path = el.getAttribute('data-bx-field');
                const name = path.replace(/\.\d+\./, '.n.');
                if ((window.pb.inline.spec(b.type, path) || {}).type !== 'richtext' || seen.has(name) || el.textContent.trim() === '') { return; }
                seen.add(name);
                out.push({ key: b.key, type: b.type, path });
              });
            });
            return out;
          });
          const bad = [];
          for (const f of fields) {
            await page.evaluate((k) => window.pb.select('block', k), f.key);
            await wait(400);
            const { caret, word } = await selectWord(page, `[data-bx-key="${f.key}"] [data-bx-field="${f.path}"]`);
            if (caret.bubble) { bad.push(`${f.type} ${f.path}: a toolbar for a caret`); }
            const wrong = judged(word);
            if (wrong) { bad.push(`${f.type} ${f.path}: ${wrong}`); }
            await leave(page);
          }
          report.verdict(`page ${pageId}, ${device}: the bubble over a selected word in every rich field (${fields.length}: ${[...new Set(fields.map((f) => f.type))].join(', ')})`,
            fields.length >= 2 && bad.length === 0, bad.join(' | ') || 'all within 16px, centred, crossing nothing');
        }
        await page.click('[data-device="desktop"]');
        await wait(600);
      }
    } finally {
      for (const pageId of [1, Number(every.slice(5))]) {
        await settle(page).catch(() => {});
        await openBuilder(page, BASE, pageId);
        if (await page.$('[data-pb-discard]:not([hidden])')) {
          await page.click('[data-pb-discard]');
          await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
        }
      }
    }
  },
};
