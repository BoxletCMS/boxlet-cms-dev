/*
 * A BLOCK'S LINE LENGTH (PLAN.md D-187, D-188, the owner): Width had no visible effect on a
 * Text block, its paragraphs held to 38em whatever the section; Questions' answers the same;
 * a Quote ran as wide as any section. Now each block's `measure` holds it, and for every Width ×
 * measure, under each of the five characters, the block is as wide as min(its column, the
 * measure): 65 or 85 of the body face's "0", or the column whole. Its words fill it, its heading
 * keeps their edge. And the section's Width says so, its link leading to the block's option.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const WIDTHS = ['narrow', 'normal', 'wide', 'full'];
const MEASURES = { comfortable: 65, wide: 85, full: 0 };

/** Each block, its root, the words that must fill it and the heading on their edge. */
const BLOCKS = {
  text: { root: '.text', words: '.richtext > p', heading: '.text-heading', layout: 'single' },
  accordion: { root: '.accordion', words: '.accordion-answer > p', heading: '.accordion-heading', layout: 'list' },
  quote: { root: '.quote', words: '.quote-words', heading: null, layout: 'plain' },
};
const LONG = 'A longer text runs as wide as its line length allows, and no wider, whatever the section around it. ';

function measured(page, key, type) {
  return page.evaluate((k, b) => {
    const doc = document.querySelector('[data-pb-canvas]').contentDocument;
    const host = doc.querySelector(`[data-bx-key="${k}"]`);
    const text = host.querySelector(b.root);
    const p = text.querySelector(b.words);
    const heading = b.heading ? text.querySelector(b.heading) : null;
    // The room the section gives: its column, not the block's own box, which a centred
    // section shrinks to the words (a short sentence passed for a held measure).
    const column = text.closest('.section-column') || text.parentElement;
    const cs = getComputedStyle(column);
    const room = column.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    // One "0" of the face the block is set in: what ch is.
    const zero = doc.createElement('span');
    zero.textContent = '0';
    zero.style.position = 'absolute';
    zero.style.visibility = 'hidden';
    text.appendChild(zero);
    const ch = zero.getBoundingClientRect().width;
    zero.remove();
    const t = text.getBoundingClientRect();
    const h = heading ? heading.getBoundingClientRect() : null;
    const w = p.getBoundingClientRect();
    // The page's own edge: where the words of a section in the character's width begin, as
    // every other heading on the page does (D-190).
    const probe = doc.createElement('div');
    probe.className = 'container';
    probe.style.blockSize = '0';
    // In the width the character composes its sections in: Bold's are wide.
    probe.style.maxWidth = 'var(--page-content-width, var(--container-width))';
    doc.querySelector('main').appendChild(probe);
    const pr = probe.getBoundingClientRect();
    const edge = pr.left + parseFloat(getComputedStyle(probe).paddingLeft);
    probe.remove();
    const section = host.closest('[data-bx-section]');
    return { room, ch, text: t.width, para: w.right - t.left, left: t.left, headingLeft: h ? h.left : t.left, headingRight: h ? h.right : t.right, right: t.right, edge, left_set: !section.matches('.align-center') };
  }, key, BLOCKS[type]);
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
        let textKey = null;
        for (const type of Object.keys(BLOCKS)) {
          // The block alone in its band, in one column, with words enough to fill any measure:
          // a sentence is narrower than all of them.
          const key = await page.evaluate((t, layout, words) => {
            const b = window.pb.doc.blocks.find((x) => x.type === t && window.pb.doc.blocks.filter((y) => y.section === x.section).length === 1);
            if (!b) { return null; }
            window.pb.change(() => {
              b.layout = layout;
              if (t === 'text') { b.content.body = '<p>' + words.repeat(8) + '</p>'; }
              if (t === 'accordion') { b.content.items[0].answer = '<p>' + words.repeat(8) + '</p>'; }
              if (t === 'quote') { b.content.quote = words.repeat(6); }
              window.pb.section(b.section).layout = 'one';
            }, { sections: [b.section] });
            return b.key;
          }, type, BLOCKS[type].layout, LONG);
          if (!key) { report.fail(`measure: a ${type} block alone in its band`, 'the demo has none'); continue; }
          if (type === 'text') { textKey = key; }
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
              const m = await measured(page, key, type);
              const expected = chars ? Math.min(m.room, chars * m.ch) : m.room;
              seen.push(`${width}/${measure} ${Math.round(m.text)}`);
              if (Math.abs(m.text - expected) > 1) { bad.push(`${width}/${measure}: ${Math.round(m.text)}px, expected ${Math.round(expected)}px (column ${Math.round(m.room)}, ${chars}ch = ${Math.round(chars * m.ch)})`); }
              // The words run to the block's far edge: no rule of their own holds them short.
              if (Math.abs(m.para - m.text) > 1) { bad.push(`${width}/${measure}: the words end ${Math.round(m.text - m.para)}px short of the block's edge`); }
              if (Math.abs(m.headingLeft - m.left) > 1 || m.headingRight > m.right + 1) { bad.push(`${width}/${measure}: the heading leaves the body's edge`); }
              // Set left, narrow or normal: on the page's edge, never centred (D-190).
              if (m.left_set && (width === 'narrow' || width === 'normal') && Math.abs(m.left - m.edge) > 1) { bad.push(`${width}/${measure}: starts ${Math.round(m.left - m.edge)}px in from the page's edge`); }
            }
          }
          report.verdict(`${character}, ${type}: for every Width × line length the block is min(its column, the measure), its words filling it${BLOCKS[type].heading ? ', its heading on their edge' : ''}, and set left it starts on the page's edge`, bad.length === 0, bad.slice(0, 6).join(' | ') || seen.join(', '));
        }
        const key = textKey;

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
