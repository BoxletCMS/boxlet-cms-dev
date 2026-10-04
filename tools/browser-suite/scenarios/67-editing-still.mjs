/*
 * OPENING A FIELD MOVES NOTHING (PLAN.md D-186, the owner): a press that starts writing in a
 * field — a line, a paragraph, rich text, a link's words — leaves the field, the element after
 * it and the whole block exactly where they stood, to half a pixel. A Cards description grew
 * up and down as it was pressed: the editor wrapped its words in a paragraph with the page's
 * margins.
 *
 * Every field that shows words, in every block of the demo's home page and of its page of
 * every block, measured with its block already selected and then pressed. And the first press
 * on each block with nothing selected (D-187): selecting showed the empty fields' places and
 * moved the page under the press; now they keep their place unseen.
 *
 * Nothing is typed. The drafts the builder may save are discarded at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const TOLERANCE = 0.5;

/** The field, the element after it in the page that is not inside it, and its block, in page coordinates. */
function measure(page, key, path) {
  return page.evaluate((k, p) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const block = doc.querySelector(`[data-bx-key="${k}"]`);
    const field = block ? block.querySelector(`[data-bx-field="${p}"]`) : null;
    if (!field) { return null; }
    const y = doc.defaultView.scrollY;
    const r = (el) => { const b = el.getBoundingClientRect(); return { top: b.top + y, left: b.left, width: b.width, height: b.height }; };
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_ELEMENT);
    walker.currentNode = field;
    let next = null;
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (!field.contains(n) && n.getBoundingClientRect().height > 0 && !n.closest('.bx-layer')) { next = n; break; }
    }
    return { field: r(field), next: next ? r(next) : null, nextName: next ? next.tagName.toLowerCase() + (next.className ? '.' + String(next.className).split(' ')[0] : '') : null, block: r(block) };
  }, key, path);
}

function moved(a, b) {
  const out = [];
  for (const part of ['field', 'next', 'block']) {
    if (!a[part] || !b[part]) { continue; }
    for (const side of ['top', 'left', 'width', 'height']) {
      const d = b[part][side] - a[part][side];
      if (Math.abs(d) > TOLERANCE) { out.push(`${part} ${side} ${d > 0 ? '+' : ''}${d.toFixed(1)}`); }
    }
  }
  return out;
}

export default {
  name: 'editing-still',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('editing still: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, 1);
    const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
    const byType = {};
    const first = {};
    for (const pageId of [1, Number(every.slice(5))]) {
      await openBuilder(page, BASE, pageId);
      try {
        const fields = await page.evaluate(() => {
          const doc = document.querySelector('[data-pb-canvas]').contentDocument;
          const out = [];
          window.pb.doc.blocks.forEach((b) => {
            const host = doc.querySelector(`[data-bx-key="${b.key}"]`);
            if (!host) { return; }
            host.querySelectorAll('[data-bx-field]').forEach((f) => {
              const path = f.getAttribute('data-bx-field');
              const spec = window.pb.inline.spec(b.type, path);
              if (spec && spec.type !== 'media' && f.closest('[data-bx-key]') === host) { out.push([b.key, b.type, path]); }
            });
          });
          return out;
        });
        for (const [key, type, path] of fields) {
          byType[type] = byType[type] || { fields: 0, moved: [] };
          await page.evaluate((k) => window.pb.select('block', k), key);
          await wait(700);
          const scrolled = await page.evaluate((k, p) => {
            const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${p}"]`);
            if (!el || el.getBoundingClientRect().height === 0) { return false; }
            el.scrollIntoView({ block: 'center' });
            return true;
          }, key, path);
          // Shown only once something is in it, or hidden by its layout: not a field to press.
          if (!scrolled) { continue; }
          await wait(300);
          const before = await measure(page, key, path);
          const point = await page.evaluate((k, p) => {
            const frame = document.querySelector('[data-pb-canvas]');
            const el = frame.contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${p}"]`);
            const f = frame.getBoundingClientRect();
            const r = el.getBoundingClientRect();
            const s = window.pb.canvas.scale;
            return { x: f.left + (r.left + Math.min(r.width / 2, 12)) * s, y: f.top + (r.top + r.height / 2) * s };
          }, key, path);
          await page.mouse.click(point.x, point.y);
          await wait(600);
          const writing = await page.evaluate(() => {
            const a = document.querySelector('[data-pb-canvas]').contentDocument.activeElement;
            return !!(a && (a.isContentEditable));
          });
          const after = await measure(page, key, path);
          byType[type].fields += 1;
          if (!writing) {
            byType[type].moved.push(`${path}: pressing it started no writing`);
          } else if (before && after) {
            const m = moved(before, after);
            if (m.length) { byType[type].moved.push(`${path} (next: ${before.nextName}): ${m.join(', ')}`); }
          }
          // Let go of it as an owner would: Escape, then the focus taken away.
          await page.keyboard.press('Escape');
          await page.evaluate(() => { const a = document.querySelector('[data-pb-canvas]').contentDocument.activeElement; if (a && a.blur) { a.blur(); } });
          await wait(500);
        }
        // THE FIRST PRESS, ON A BLOCK NOT SELECTED (D-187, the owner): selecting it shows its
        // empty fields' places, and the page moved under the press. Each block's first field
        // that shows at rest, nothing selected before it.
        const firsts = await page.evaluate(() => {
          const doc = document.querySelector('[data-pb-canvas]').contentDocument;
          return window.pb.doc.blocks.map((b) => {
            const host = doc.querySelector(`[data-bx-key="${b.key}"]`);
            const f = host ? [...host.querySelectorAll('[data-bx-field]')].find((el) => ['text', 'textarea', 'richtext', 'link'].includes((window.pb.inline.spec(b.type, el.getAttribute('data-bx-field')) || {}).type)
              && el.closest('[data-bx-key]') === host && el.getBoundingClientRect().height > 0 && el.textContent.trim() !== '') : null;
            return f ? [b.key, b.type, f.getAttribute('data-bx-field')] : null;
          }).filter(Boolean);
        });
        for (const [key, type, path] of firsts) {
          first[type] = first[type] || { blocks: 0, moved: [] };
          await page.evaluate(() => window.pb.select(null));
          await wait(500);
          await page.evaluate((k, p) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${p}"]`).scrollIntoView({ block: 'center' }), key, path);
          await wait(300);
          const before = await measure(page, key, path);
          const point = await page.evaluate((k, p) => {
            const frame = document.querySelector('[data-pb-canvas]');
            const el = frame.contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-field="${p}"]`);
            const f = frame.getBoundingClientRect();
            const r = el.getBoundingClientRect();
            const s = window.pb.canvas.scale;
            return { x: f.left + (r.left + Math.min(r.width / 2, 12)) * s, y: f.top + (r.top + r.height / 2) * s };
          }, key, path);
          await page.mouse.click(point.x, point.y);
          await wait(700);
          const after = await measure(page, key, path);
          first[type].blocks += 1;
          if (before && after) {
            const m = moved(before, after);
            if (m.length) { first[type].moved.push(`${key} ${path} (next: ${before.nextName}): ${m.join(', ')}`); }
          }
          await page.keyboard.press('Escape');
          await page.evaluate(() => { const a = document.querySelector('[data-pb-canvas]').contentDocument.activeElement; if (a && a.blur) { a.blur(); } });
          await wait(400);
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
    for (const type of Object.keys(byType).sort()) {
      const t = byType[type];
      report.verdict(`${type}: pressing a field to write in it moves nothing, the field, what follows or the block (${t.fields} fields)`,
        t.fields > 0 && t.moved.length === 0, t.moved.join('; ') || `${t.fields} fields, none moved by more than ${TOLERANCE}px`);
    }
    const unmoved = Object.keys(first).sort().filter((t) => first[t].moved.length === 0);
    const movedFirst = Object.keys(first).sort().flatMap((t) => first[t].moved.map((m) => `${t} ${m}`));
    report.verdict(`the first press on a block not selected moves nothing (${Object.values(first).reduce((n, t) => n + t.blocks, 0)} blocks)`,
      unmoved.length > 0 && movedFirst.length === 0, movedFirst.slice(0, 10).join('; ') || unmoved.join(', '));
    // Every block type with a field of words — a line, a paragraph, rich text, a link's — from
    // what the builder knows of each.
    const wordy = await page.evaluate(() => Object.keys(window.pb.data.inline.fields).filter((type) => Object.values(window.pb.data.inline.fields[type])
      .some((f) => (f.type === 'repeater' ? Object.values(f.fields || {}) : [f]).some((g) => ['text', 'textarea', 'richtext', 'link'].includes(g.type)))).sort());
    const missed = wordy.filter((type) => !byType[type]);
    report.verdict('every block that shows words was pressed', missed.length === 0, missed.length ? `not pressed: ${missed.join(', ')}` : wordy.join(', '));
  },
};
