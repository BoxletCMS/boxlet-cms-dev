/*
 * NOTHING THE EDITOR DRAWS LIES OVER ANOTHER (PLAN.md D-179), measured rather than looked at:
 * every mark of the canvas's layer — the selection's toolbar, the "+" on each boundary, "Add
 * section at the end", a band's badges, an item's tools — and the page's own "+ Card", as
 * rectangles, with every band and every block of the demo's home page selected in turn, on
 * desktop, tablet and phone, and with a card added. No two may cross.
 *
 * And the rest of the owner's review of phase 5: an error's words never over the words beside
 * them and its field shown in the inspector; the layout tiles of every block told apart and
 * never cut; the breadcrumb never cut, whatever the hint beside it.
 *
 * ON THE COPY. Nothing is published: the draft the builder saves by itself is discarded.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, clickInCanvas, blockKey, settle } from '../harness.mjs';

const HOME = 1;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

/** Every pair of the layer's marks (and the page's "+ Card") that cross, by what they are. */
function crossings(page) {
  return page.evaluate(() => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const layer = doc.querySelector('.bx-layer');
    const marks = [...(layer ? layer.children : [])]
      .filter((n) => !n.matches('.bx-hidden-here, .bx-inserter') && !n.hidden)
      .concat([...doc.querySelectorAll('.bx-add-item-cell')])
      .map((n) => ({ what: n.className + (n.getAttribute('data-bx-insert') ? `#${n.getAttribute('data-bx-insert')}` : ''), r: n.getBoundingClientRect() }))
      .filter((m) => m.r.width > 0 && m.r.height > 0);
    const found = [];
    for (let i = 0; i < marks.length; i += 1) {
      for (let j = i + 1; j < marks.length; j += 1) {
        const a = marks[i].r;
        const b = marks[j].r;
        if (a.left < b.right - 0.5 && b.left < a.right - 0.5 && a.top < b.bottom - 0.5 && b.top < a.bottom - 0.5) {
          found.push(`${marks[i].what} × ${marks[j].what}`);
        }
      }
    }
    // The block's toolbar over no field's words, of its block or any other (D-182).
    const bar = layer ? layer.querySelector('.bx-toolbar-block') : null;
    if (bar) {
      const b = bar.getBoundingClientRect();
      doc.querySelectorAll('[data-bx-field]').forEach((field) => {
        const f = field.getBoundingClientRect();
        if (f.width > 0 && f.height > 0 && b.left < f.right - 0.5 && f.left < b.right - 0.5 && b.top < f.bottom - 0.5 && f.top < b.bottom - 0.5) {
          found.push(`the block's toolbar × ${field.getAttribute('data-bx-field')}`);
        }
      });
    }
    // And each "+" on its boundary, where the band it adds before begins.
    window.pb.doc.sections.forEach((s, i) => {
      const plus = doc.querySelector(`.bx-plus[data-bx-insert="${i}"]`);
      const band = doc.querySelector(`[data-bx-section="${s.key}"]`);
      if (plus && band) {
        const p = plus.getBoundingClientRect();
        const off = Math.abs(p.top + p.height / 2 - band.getBoundingClientRect().top);
        if (off > 1) { found.push(`"+" ${i} stands ${Math.round(off)}px off its boundary`); }
      }
    });
    return { count: marks.length, found };
  });
}

/** Every band and every block selected in turn; what crossed, where. */
async function everySelection(page) {
  const all = await page.evaluate(() => window.pb.doc.sections.map((s) => ['section', s.key]).concat(window.pb.doc.blocks.map((b) => ['block', b.key])));
  const bad = [];
  let marks = 0;
  for (const [kind, key] of [[null, null], ...all]) {
    await page.evaluate((k, id) => window.pb.select(k, id), kind, key);
    await wait(350);
    const c = await crossings(page);
    marks += c.count;
    if (c.found.length) { bad.push(`${kind || 'nothing'} ${key || ''}: ${c.found.join('; ')}`); }
  }
  return { checked: all.length + 1, marks, bad };
}

export default {
  name: 'overlay',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('overlay: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await openBuilder(page, BASE, HOME);
    await page.evaluate(() => { window.__selections = []; window.pb.on('select', (sel) => window.__selections.push(JSON.stringify(sel || null) + '@' + Math.round(performance.now()))); window.pb.on('replace', () => window.__selections.push('replace@' + Math.round(performance.now()))); });
    try {
      // ---- every selection, on every device -------------------------------------------------------
      for (const device of ['desktop', 'tablet', 'phone']) {
        await page.click(`[data-device="${device}"]`);
        await wait(900);
        const seen = await everySelection(page);
        report.verdict(`${device}: no two of the editor's marks cross, the block's toolbar covers no words, and every "+" is on its boundary, with each band and block selected (${seen.checked} states, ${seen.marks} marks)`,
          seen.bad.length === 0 && seen.marks > seen.checked, seen.bad.join(' | ') || 'none cross');
      }
      await page.click('[data-device="desktop"]');
      await wait(900);

      // ---- the top block of a band: its toolbar outside the block, never on its words (D-182) ----
      // The rule changed on purpose: D-179 put it inside the band, over the block's top; the
      // owner's review of phase 6 has it outside the block, above, or under it where above
      // has no room.
      const cards = await blockKey(page, 'cards');
      await page.evaluate((k) => window.pb.select('block', k), cards);
      await wait(400);
      const top = await page.evaluate((k) => {
        const doc = document.querySelector('[data-pb-canvas]').contentDocument;
        const bar = doc.querySelector('.bx-toolbar-block').getBoundingClientRect();
        const block = doc.querySelector(`[data-bx-key="${k}"]`).getBoundingClientRect();
        return { outside: bar.bottom <= block.top + 0.5 || bar.top >= block.bottom - 0.5, bar: [Math.round(bar.top), Math.round(bar.bottom)], block: [Math.round(block.top), Math.round(block.bottom)] };
      }, cards);
      await shot(report, page, '01-top-block');
      report.verdict('a block\'s toolbar stands outside the block', top.outside, JSON.stringify(top));

      // ---- a card added: "+" never over "+ Card" ----------------------------------------------------
      const before = await page.evaluate((k) => window.pb.block(k).content.items.length, cards);
      await clickInCanvas(page, `[data-bx-key="${cards}"] [data-bx-add-item]`);
      await wait(1500);
      const added = await crossings(page);
      const sample = await page.evaluate((k) => { const items = window.pb.block(k).content.items; return items[items.length - 1]; }, cards);
      await shot(report, page, '02-card-added');
      report.verdict('a card added: nothing crosses "+ Card"', added.found.length === 0, added.found.join('; ') || 'none cross');
      report.verdict('a new card says it is new', sample.heading === 'New card' && /A short description\./.test(sample.body), JSON.stringify(sample));
      await page.evaluate((k, n) => {
        const el = document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"] [data-bx-item="items.${n}"]`);
        el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true }));
      }, cards, before);
      await wait(300);
      const tools = await crossings(page);
      report.verdict('an item\'s tools cross nothing', tools.found.length === 0, tools.found.join('; ') || 'none cross');

      // ---- writing rich text: the block's toolbar steps back, and the small one keeps clear ------
      const imageText = await blockKey(page, 'image_text');
      await clickInCanvas(page, `[data-bx-key="${imageText}"] [data-bx-field="body"]`, { at: 'top', side: 'left' });
      await wait(800);
      const writing = await page.evaluate(() => {
        const doc = document.querySelector('[data-pb-canvas]').contentDocument;
        const layer = doc.querySelector('.bx-layer');
        const bar = layer.querySelector('.bx-toolbar-block');
        const tools = layer.querySelector('.bx-rich-tools');
        if (!bar || !tools) { return { bar: !!bar, tools: !!tools }; }
        const a = bar.getBoundingClientRect();
        const b = tools.getBoundingClientRect();
        const style = doc.defaultView.getComputedStyle(bar);
        return { cross: a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom, faint: Number(style.opacity) < 1, presses: style.pointerEvents };
      });
      await shot(report, page, '02b-writing');
      report.verdict('writing rich text, the block\'s toolbar is faint and takes no press, and the small toolbar does not sit on it',
        writing.cross === false && writing.faint && writing.presses === 'none', JSON.stringify(writing));
      await page.keyboard.press('Escape');
      await page.mouse.click(10, 10);
      await wait(500);

      // ---- an error: under its field, over nothing, and its field shown in the inspector ---------------
      const cta = await blockKey(page, 'cta');
      await clickInCanvas(page, `[data-bx-key="${cta}"] [data-bx-field="heading"]`);
      await page.keyboard.down('Control');
      await page.keyboard.press('KeyA');
      await page.keyboard.up('Control');
      await page.keyboard.press('Backspace');
      await page.keyboard.press('Enter');
      await page.waitForFunction((k) => window.pb.errors[k] && window.pb.errors[k].heading, { timeout: 10000 }, cta).catch(() => {});
      await page.waitForSelector('[data-pb-inspector] [aria-invalid="true"]', { timeout: 10000 }).catch(() => {});
      await wait(800);
      const error = await page.evaluate((k) => {
        const doc = document.querySelector('[data-pb-canvas]').contentDocument;
        const host = doc.querySelector(`[data-bx-key="${k}"]`);
        const note = host.querySelector('[data-bx-note] .bx-error-words');
        if (!note) { return null; }
        const n = note.getBoundingClientRect();
        const over = [...host.querySelectorAll('[data-bx-field]')].filter((f) => {
          const r = f.getBoundingClientRect();
          return r.width > 0 && n.left < r.right && r.left < n.right && n.top < r.bottom && r.top < n.bottom;
        }).map((f) => f.getAttribute('data-bx-field'));
        const field = document.querySelector('[data-pb-inspector] [aria-invalid="true"]');
        const panel = document.querySelector('[data-pb-inspector]').getBoundingClientRect();
        const f = field ? field.getBoundingClientRect() : null;
        return { over, under: n.top >= host.querySelector('[data-bx-field="heading"]').getBoundingClientRect().bottom - 1, field: field ? field.name : null, open: field ? field.closest('details').open : false, inView: f ? f.top >= panel.top && f.bottom <= panel.bottom : false, selection: JSON.stringify(window.pb.selection), lately: (window.__selections || []).slice(-6) };
      }, cta);
      await shot(report, page, '03-error');
      report.verdict('an error\'s words stand under their field and over no other', error !== null && error.over.length === 0 && error.under, JSON.stringify(error));
      report.verdict('the inspector opens All content on the field in error, marks it and scrolls to it', error !== null && /\[heading\]$/.test(error.field || '') && error.open && error.inView, JSON.stringify(error));
      const crumbs = await page.evaluate(() => {
        const trail = document.querySelector('[data-pb-trail]');
        const hint = document.querySelector('.pb-stage-hint').getBoundingClientRect();
        const t = trail.getBoundingClientRect();
        return { whole: trail.scrollWidth <= trail.clientWidth + 1, clear: t.right <= hint.left + 1 || hint.width === 0, text: trail.textContent.trim() };
      });
      report.verdict('the breadcrumb is shown whole, the hint giving way', crumbs.whole && crumbs.clear && /Call to action/.test(crumbs.text), JSON.stringify(crumbs));
      await page.setViewport({ width: 1100, height: 900, deviceScaleFactor: 1 });
      await wait(700);
      const narrow = await page.evaluate(() => { const t = document.querySelector('[data-pb-trail]'); return { whole: t.scrollWidth <= t.clientWidth + 1, text: t.textContent.trim() }; });
      report.verdict('and in a narrower window', narrow.whole && /Call to action/.test(narrow.text), JSON.stringify(narrow));
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
      await wait(500);
    } finally {
      await settle(page).catch(() => {});
      await openBuilder(page, BASE, HOME);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      const state = await page.$eval('[data-pb-state]', (p) => p.className);
      report.verdict('overlay: the draft is discarded', /status-published/.test(state), state);
    }

    // ---- the layout tiles of every block, on the page that has them all ---------------------------------
    const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
    if (!/^page:\d+$/.test(every)) {
      report.fail('overlay: the page with every block', `the demo has no /blocks page: ${every}`);
      return;
    }
    await openBuilder(page, BASE, Number(every.slice(5)));
    const keys = await page.evaluate(() => window.pb.doc.blocks.map((b) => [b.key, b.type]));
    const tiles = [];
    for (const [key, type] of keys) {
      await page.evaluate((k) => window.pb.select('block', k), key);
      await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${key}"]`, { timeout: 10000 }).catch(() => {});
      await wait(200);
      const seen = await page.$$eval('[data-pb-inspector] .tile-option .tile-label', (ls) => ls.map((l) => ({ text: l.textContent, cut: l.scrollWidth > l.clientWidth + 1 || l.scrollHeight > l.clientHeight + 1, title: l.closest('label').title })));
      if (seen.length) { tiles.push({ type, seen }); }
    }
    const clash = tiles.filter((t) => new Set(t.seen.map((s) => s.text)).size !== t.seen.length).map((t) => t.type);
    const cut = tiles.flatMap((t) => t.seen.filter((s) => s.cut).map((s) => `${t.type}: ${s.text}`));
    const hero = tiles.find((t) => t.type === 'hero');
    report.verdict(`no two layout tiles of a block say the same (${tiles.length} blocks with tiles)`, tiles.length >= 10 && clash.length === 0, clash.join(', ') || 'all told apart');
    report.verdict('no tile\'s name is cut short', cut.length === 0, cut.join(', ') || 'all whole');
    report.verdict('a short tile name keeps its full name as its tooltip', !!hero && hero.seen.some((s) => s.text === 'Behind · left' && /words on the left/.test(s.title)), JSON.stringify(hero ? hero.seen : null));
    if (hero) {
      await page.evaluate(() => {
        const key = window.pb.doc.blocks.find((b) => b.type === 'hero').key;
        window.pb.select('block', key);
      });
      await wait(900);
      await shot(report, page, '04-hero-tiles');
    }
    await page.evaluate(() => window.pb.select(null));
    await settle(page).catch(() => {});
    if (await page.$('[data-pb-discard]:not([hidden])')) {
      await page.click('[data-pb-discard]');
      await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
    }
  },
};
