/*
 * AN EMPTY FIELD'S PLACEHOLDER IS SEEN WHOLE (PLAN.md D-187, the owner): "+ Add picture" on
 * Brutalist's hero stood at the top, half out of the canvas. Every placeholder shown — an
 * empty field's words, a split hero's "+ Add picture", a repeater's "+ Card"; each block of the
 * demo's home page and of its page of every block selected in turn, on desktop, tablet and
 * phone, under each of the five characters — lies inside the canvas and is cut by nothing.
 *
 * Nothing is changed but the selection; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

/** The selected block's placeholders that leave the canvas or are cut, by what they are. */
function cut(page, key) {
  return page.evaluate((k) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const win = doc.defaultView;
    const width = doc.documentElement.clientWidth;
    const out = [];
    let shown = 0;
    // An empty field's words, a split hero's "+ Add picture", a repeater's "+ Card".
    doc.querySelectorAll(`[data-bx-key="${k}"] :is([data-bx-placeholder]:empty, .hero-add-picture, .bx-add-item)`).forEach((el) => {
      if (win.getComputedStyle(el).display === 'none') { return; }
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) { return; }
      shown += 1;
      const top = r.top + win.scrollY;
      const name = `${el.getAttribute('data-bx-placeholder') || el.textContent.trim()} (${el.getAttribute('data-bx-field') || el.className})`;
      if (r.left < -0.5 || r.right > width + 0.5 || top < -0.5) { out.push(`${name} leaves the canvas: ${Math.round(r.left)},${Math.round(top)} ${Math.round(r.width)}x${Math.round(r.height)}`); return; }
      // Cut by an element around it that clips what overflows it.
      for (let a = el.parentElement; a && a !== doc.body; a = a.parentElement) {
        const cs = win.getComputedStyle(a);
        if (cs.overflowX === 'visible' && cs.overflowY === 'visible' && cs.clipPath === 'none') { continue; }
        const b = a.getBoundingClientRect();
        if (r.left < b.left - 0.5 || r.right > b.right + 0.5 || r.top < b.top - 0.5 || r.bottom > b.bottom + 0.5) {
          out.push(`${name} cut by ${a.tagName.toLowerCase()}.${String(a.className).split(' ')[0]}`);
          break;
        }
      }
    });
    return { shown, out };
  }, key);
}

export default {
  name: 'placeholders',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('placeholders: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save_composition');
        await openBuilder(page, BASE, 1);
        const every = await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || '');
        const bad = [];
        let shown = 0;
        for (const pageId of [1, Number(every.slice(5))]) {
          await openBuilder(page, BASE, pageId);
          for (const device of ['desktop', 'tablet', 'phone']) {
            await page.click(`[data-device="${device}"]`);
            await wait(900);
            for (const key of await page.evaluate(() => window.pb.doc.blocks.map((b) => b.key))) {
              await page.evaluate((k) => window.pb.select('block', k), key);
              await wait(300);
              const c = await cut(page, key);
              shown += c.shown;
              bad.push(...c.out.map((o) => `page ${pageId} ${device} ${key}: ${o}`));
            }
          }
          await page.click('[data-device="desktop"]');
          await wait(600);
          if (character === 'brutalist' && pageId === 1) {
            const hero = await page.evaluate(() => window.pb.doc.blocks.find((b) => b.type === 'hero').key);
            await page.evaluate((k) => window.pb.select('block', k), hero);
            await page.evaluate(() => document.querySelector('[data-pb-canvas]').contentDocument.defaultView.scrollTo(0, 0));
            await wait(600);
            await report.shot(page, '01-brutalist-hero-selected', { fullPage: false });
          }
        }
        report.verdict(`${character}: every placeholder shown lies inside the canvas, cut by nothing (${shown} shown)`, shown > 0 && bad.length === 0, bad.slice(0, 8).join(' | ') || `${shown} placeholders`);
      }
    } finally {
      if (was !== '') { await applyCharacter(page, BASE, was, 'save_composition'); }
      report.verdict('placeholders: the copy\'s character is back', was !== '', was || 'could not read it');
    }
  },
};
