/*
 * A ROW NEVER LEAVES ONE ALONE (PLAN.md D-188, the owner): Numbers "Four across" broke three
 * and one. A number now gives way to its column before the row breaks, and the row breaks
 * evenly: four across are four, two and two, or one under another — never three and one;
 * three across are three or one under another. A Logos grid takes as many across as leave no
 * mark by itself in the last row, and where none can, the last stands centred across the
 * row. A Gallery "four" is four or two and two.
 *
 * Measured on the page a visitor gets (the draft's preview), under each of the five
 * characters, from a wide screen to a phone: how many stand in each row, and whether a
 * number's digits stay inside their column.
 *
 * On the copy, in a draft that is discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const WIDTHS = [1440, 1280, 1024, 768, 600, 390];
/** Each pass: the Numbers' layout and count, the Logos grid's count, the Gallery four's. */
const PASSES = [
  { stats: ['four', 4], logos: 6, gallery: 4 },
  { stats: ['three', 3], logos: 5, gallery: 4 },
  { stats: ['four', 4], logos: 7, gallery: 4 },
  { stats: ['three', 3], logos: 13, gallery: 4 },
];

/** How many stand in each row of every grid the pass set, and whether a number spills. */
function rows(page) {
  return page.evaluate(() => {
    const counted = (grid) => {
      const kids = [...grid.children].filter((n) => n.getBoundingClientRect().width > 0);
      const tops = [];
      kids.forEach((n) => {
        const top = n.getBoundingClientRect().top;
        const row = tops.find((r) => Math.abs(r.top - top) < 3);
        if (row) { row.n += 1; row.last = n; } else { tops.push({ top, n: 1, last: n }); }
      });
      const last = tops.length ? tops[tops.length - 1] : null;
      // A last one alone that stands across the whole row, centred: what a count no number
      // across divides is given.
      const across = last && last.n === 1 && tops.length > 1 && Math.abs(last.last.getBoundingClientRect().width - grid.getBoundingClientRect().width) < 2;
      return { rows: tops.map((r) => r.n), across };
    };
    const pick = (sel) => { const g = document.querySelector(sel); return g ? counted(g) : null; };
    const values = [...document.querySelectorAll('.block-stats .stats-value')];
    const spill = values.filter((v) => {
      const range = document.createRange();
      range.selectNodeContents(v);
      return range.getBoundingClientRect().width > v.getBoundingClientRect().width + 1;
    }).map((v) => v.textContent.trim());
    const size = values.length ? Math.round(parseFloat(getComputedStyle(values[0]).fontSize)) : 0;
    return {
      stats: pick('.block-stats .stats-grid'),
      logos: pick('.block-logos.layout-grid .logos-row'),
      gallery: pick('.block-gallery.layout-four .gallery-grid'),
      spill,
      size,
    };
  });
}

/** What is wrong with these rows of `count`, `across` at most, if anything. */
function uneven(seen, count, across) {
  if (!seen) { return 'not drawn'; }
  const r = seen.rows;
  const total = r.reduce((a, b) => a + b, 0);
  if (total !== count) { return `${total} drawn of ${count}`; }
  if (r.some((n) => n > across)) { return `${r.join('+')}: more than ${across} across`; }
  // Every row but the last as full as the first; the last never one alone among more.
  if (r.slice(0, -1).some((n) => n !== r[0])) { return `${r.join('+')}: uneven rows`; }
  if (r.length > 1 && r[r.length - 1] === 1 && r[0] > 1 && !seen.across) { return `${r.join('+')}: one alone in the last row`; }
  return '';
}

export default {
  name: 'even-rows',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('even rows: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    await openBuilder(page, BASE, 1);
    const id = Number((await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || 'page:0')).slice(5));
    try {
      for (const character of CHARACTERS) {
        // The design alone: the blocks keep the layouts the draft gives them.
        await applyCharacter(page, BASE, character, 'save');
        const bad = [];
        const seen = [];
        for (const pass of PASSES) {
          await openBuilder(page, BASE, id);
          const set = await page.evaluate((p) => {
            const blocks = window.pb.doc.blocks;
            const stats = blocks.find((b) => b.type === 'stats');
            const logos = blocks.find((b) => b.type === 'logos' && b.layout === 'grid') || blocks.filter((b) => b.type === 'logos')[1];
            const gallery = blocks.find((b) => b.type === 'gallery' && b.layout === 'four') || blocks.filter((b) => b.type === 'gallery')[2];
            if (!stats || !logos || !gallery) { return false; }
            const sized = (items, n) => Array.from({ length: n }, (_, i) => window.pb.copy(items[i % items.length]));
            window.pb.change(() => {
              stats.layout = p.stats[0];
              stats.content.items = sized(stats.content.items, p.stats[1]);
              logos.layout = 'grid';
              logos.content.items = sized(logos.content.items, p.logos);
              gallery.layout = 'four';
              gallery.content.items = sized(gallery.content.items, p.gallery);
            }, { sections: [stats.section, logos.section, gallery.section] });
            return true;
          }, pass);
          if (!set) { report.fail('even rows: the page of every block', 'it has no Numbers, Logos or Gallery'); return; }
          await settle(page);
          for (const width of WIDTHS) {
            await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
            await page.goto(`${BASE}/admin/pages/${id}/preview`, { waitUntil: 'networkidle2' });
            const r = await rows(page);
            const at = `${pass.stats[0]}, ${width}px`;
            const stats = uneven(r.stats, pass.stats[1], pass.stats[0] === 'four' ? 4 : 3);
            if (stats) { bad.push(`Numbers ${at}: ${stats}`); }
            // Four across: four, two and two, or one under another.
            if (!stats && pass.stats[0] === 'four' && ![[4], [2, 2], [1, 1, 1, 1]].some((ok) => ok.join() === r.stats.rows.join())) { bad.push(`Numbers ${at}: ${r.stats.rows.join('+')}`); }
            if (!stats && pass.stats[0] === 'three' && ![[3], [1, 1, 1]].some((ok) => ok.join() === r.stats.rows.join())) { bad.push(`Numbers ${at}: ${r.stats.rows.join('+')}`); }
            if (r.spill.length) { bad.push(`Numbers ${at}: ${r.spill.join(', ')} wider than its column`); }
            const logos = uneven(r.logos, pass.logos, 6);
            if (logos) { bad.push(`Logos grid of ${pass.logos}, ${width}px: ${logos}`); }
            const gallery = uneven(r.gallery, pass.gallery, 4);
            if (gallery) { bad.push(`Gallery four, ${width}px: ${gallery}`); }
            seen.push(`${at} ${r.stats ? r.stats.rows.join('+') : '-'} @${r.size}px · logos ${pass.logos} ${r.logos ? r.logos.rows.join('+') : '-'}`);
            if (character === 'bold' && pass === PASSES[0] && width === 1440) {
              // THE CONTROL: the instrument must see three and one, and five and one, when the
              // page has them — the four Numbers in three columns, the six logos in five, as
              // auto-fit drew them in the canvas.
              await page.addStyleTag({ content: '.block-stats .stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; } .block-logos.layout-grid .logos-row { grid-template-columns: repeat(5, minmax(0, 1fr)) !important; }' });
              const forced = await rows(page);
              const caught = [uneven(forced.stats, 4, 4), uneven(forced.logos, 6, 6)];
              report.verdict('the control: three and one, and five and one, forced onto the page, are caught', caught.every((c) => c !== ''), caught.join(' | ') || JSON.stringify(forced));
              await page.goto(`${BASE}/admin/pages/${id}/preview`, { waitUntil: 'networkidle2' });
            }
            if (character === 'bold' && pass === PASSES[0] && (width === 1440 || width === 1024)) {
              await page.evaluate(() => document.querySelector('.block-stats').scrollIntoView({ block: 'center' }));
              await wait(300);
              await report.shot(page, `bold-numbers-${width}`, { fullPage: false });
            }
          }
          await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        }
        report.verdict(`${character}: Numbers, a Logos grid and a Gallery "four" break their rows evenly at every width, and no number spills from its column`, bad.length === 0, bad.slice(0, 8).join(' | ') || seen.join('; '));
      }
    } finally {
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
      await openBuilder(page, BASE, id);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('even rows: the draft discarded and the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
