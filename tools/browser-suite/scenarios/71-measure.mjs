/*
 * THE TEXT BLOCK'S LINE LENGTH (PLAN.md D-187, the owner): Width had no visible effect on a
 * Text block, its paragraphs held to 38em whatever the section. Now the block's `measure` holds
 * it, and for every Width × measure, under each of the five characters, the text is as wide as
 * min(its column, the measure): 65 or 85 of the body face's "0", or the column whole. Its
 * heading keeps the body's edge. And the section's Width says so, its link leading to the
 * block's option.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const WIDTHS = ['narrow', 'normal', 'wide', 'full'];
const MEASURES = { comfortable: 65, wide: 85, full: 0 };

function measured(page, key) {
  return page.evaluate((k) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const host = doc.querySelector(`[data-bx-key="${k}"]`);
    const text = host.querySelector('.text');
    const p = text.querySelector('.richtext > p');
    const heading = text.querySelector('.text-heading');
    // The room the section gives: its column, not the block's own box, which a centred
    // section shrinks to the words (a short sentence passed for a held measure).
    const column = text.closest('.section-column') || text.parentElement;
    const cs = getComputedStyle(column);
    const room = column.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    // One "0" of the face the text is set in: what ch is.
    const zero = doc.createElement('span');
    zero.textContent = '0';
    zero.style.position = 'absolute';
    zero.style.visibility = 'hidden';
    text.appendChild(zero);
    const ch = zero.getBoundingClientRect().width;
    zero.remove();
    const t = text.getBoundingClientRect();
    const h = heading ? heading.getBoundingClientRect() : null;
    return { room, ch, text: t.width, para: p.getBoundingClientRect().width, left: t.left, headingLeft: h ? h.left : t.left, headingRight: h ? h.right : t.right, right: t.right };
  }, key);
}

export default {
  name: 'measure',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('measure: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    await openBuilder(page, BASE, 1);
    const id = Number((await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || 'page:0')).slice(5));
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save_composition');
        await openBuilder(page, BASE, id);
        // A Text block alone in its band, in one column.
        const key = await page.evaluate(() => {
          const b = window.pb.doc.blocks.find((x) => x.type === 'text' && window.pb.doc.blocks.filter((y) => y.section === x.section).length === 1);
          // Words enough to fill any measure: a sentence is narrower than all of them.
          const words = 'A longer text runs as wide as its line length allows, and no wider, whatever the section around it. ';
          window.pb.change(() => { b.layout = 'single'; b.content.body = '<p>' + words.repeat(8) + '</p>'; window.pb.section(b.section).layout = 'one'; }, { sections: [b.section] });
          return b.key;
        });
        await wait(1500);
        const bad = [];
        const seen = [];
        for (const width of WIDTHS) {
          for (const [measure, chars] of Object.entries(MEASURES)) {
            await page.evaluate((k, w, m) => {
              const b = window.pb.block(k);
              const s = window.pb.section(b.section);
              window.pb.change(() => { s.style.width = w; b.options.measure = m; }, { sections: [s.key] });
            }, key, width, measure);
            await wait(1300);
            const m = await measured(page, key);
            const expected = chars ? Math.min(m.room, chars * m.ch) : m.room;
            seen.push(`${width}/${measure} ${Math.round(m.text)}`);
            if (Math.abs(m.text - expected) > 1) { bad.push(`${width}/${measure}: ${Math.round(m.text)}px, expected ${Math.round(expected)}px (column ${Math.round(m.room)}, ${chars}ch = ${Math.round(chars * m.ch)})`); }
            if (Math.abs(m.para - m.text) > 1) { bad.push(`${width}/${measure}: the paragraph ${Math.round(m.para)}px in a text of ${Math.round(m.text)}px`); }
            if (Math.abs(m.headingLeft - m.left) > 1 || m.headingRight > m.right + 1) { bad.push(`${width}/${measure}: the heading leaves the body's edge`); }
          }
        }
        report.verdict(`${character}: for every Width × line length the text is min(its column, the measure), its heading on the same edge`, bad.length === 0, bad.slice(0, 6).join(' | ') || seen.join(', '));

        if (character === 'editorial') {
          // The section's Width says it, and its link leads to the option.
          await page.evaluate((k) => { const b = window.pb.block(k); window.pb.change(() => { b.options.measure = ''; window.pb.section(b.section).style.width = 'wide'; }, { sections: [b.section] }); window.pb.select('section', b.section); }, key);
          await page.waitForSelector('[data-pb-inspector] [data-width-measure]', { timeout: 8000 }).catch(() => {});
          await wait(500);
          const hint = await page.$('[data-pb-inspector] [data-width-measure]');
          await report.shot(page, '01-width-says-measure', { fullPage: false });
          if (hint) {
            await page.click('[data-pb-inspector] [data-width-measure] [data-action="select-block"]');
            await wait(1500);
          }
          const led = await page.evaluate((k) => ({ selected: (window.pb.selection || {}).key, focused: (document.activeElement || {}).name || '' }), key);
          await report.shot(page, '02-led-to-measure', { fullPage: false });
          report.verdict('the section\'s Width says the text keeps its line length, and its link selects the block at that option',
            !!hint && led.selected === key && /\[options\]\[measure\]$/.test(led.focused), JSON.stringify({ hint: !!hint, ...led }));
        }
        await settle(page).catch(() => {});
        await openBuilder(page, BASE, id);
        if (await page.$('[data-pb-discard]:not([hidden])')) {
          await page.click('[data-pb-discard]');
          await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
        }
      }
    } finally {
      await settle(page).catch(() => {});
      await openBuilder(page, BASE, id);
      if (await page.$('[data-pb-discard]:not([hidden])')) { await page.click('[data-pb-discard]'); await wait(1500); }
      if (was !== '') { await applyCharacter(page, BASE, was, 'save_composition'); }
      report.verdict('measure: the copy\'s character is back', was !== '', was || 'could not read it');
    }
  },
};
