/*
 * A HEADING NO LARGER THAN ITS COLUMN (PLAN.md O-71, the owner: "B, naslov se smanji na širinu
 * stupca: riječi ostaju cijele"). The demo's About has four values in quarters; in Couture at
 * 1440 "AFFORDABLE" was 493px in a column of 308 and ran off the page.
 *
 *   - at 1440, each value's heading stays inside its own column, whole, on one line, in English
 *     and in the demo's own Croatian words, which are longer;
 *   - its size is the column's cap (11cqi), below the character's --text-2xl;
 *   - a heading in a section of one column on the same page keeps --text-2xl;
 *   - at 390, stacked, each heading still inside its column.
 *
 * The Croatian words are put into the drawn page, not saved: the copy's demo is English only,
 * and what is measured is layout, which is the same for words typed and words written in.
 * A visitor's page: nothing is logged into and nothing is changed.
 */
import { COPY_BASE as BASE } from '../config.mjs';

const VALUES = ['Open', 'Hands-on', 'Local', 'Affordable'];
const CROATIAN = ['Otvoreno', 'Rukom', 'Lokalno', 'Pristupačno'];

function measure(page, words) {
  return page.evaluate((VALUES, words) => {
    const heads = [...document.querySelectorAll('h2')].filter((h) => VALUES.includes(h.textContent.trim()));
    if (words) heads.forEach((h, i) => { h.textContent = words[i]; });
    const probe = document.createElement('h2');
    probe.className = 'text-heading';
    document.querySelector('main').append(probe);
    const full = parseFloat(getComputedStyle(probe).fontSize);
    probe.remove();
    const one = [...document.querySelectorAll('.image-text-heading')].find((h) => !h.closest('.section-cols:not(.cols-one)'));
    heads[0]?.closest('section')?.scrollIntoView();
    return {
      full,
      one: one ? parseFloat(getComputedStyle(one).fontSize) : null,
      heads: heads.map((h) => {
        const column = h.closest('.section-column').getBoundingClientRect();
        const r = h.getBoundingClientRect();
        const size = parseFloat(getComputedStyle(h).fontSize);
        return {
          words: h.textContent,
          size,
          column: Math.round(column.width),
          inside: r.left >= column.left - 1 && r.right <= column.right + 1 && h.scrollWidth <= h.clientWidth,
          lines: Math.round(r.height / parseFloat(getComputedStyle(h).lineHeight)),
        };
      }),
    };
  }, VALUES, words);
}

export default {
  name: 'column-headings',
  copy: true,

  async run({ page, report }) {
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    for (const [language, words] of [['English', null], ['Croatian', CROATIAN]]) {
      await page.goto(`${BASE}/about`, { waitUntil: 'networkidle0' });
      const m = await measure(page, words);
      if (m.heads.length !== 4) {
        report.fail('column-headings: the demo\'s four values', `found ${m.heads.length} on /about`);
        return;
      }
      await report.shot(page, `1440-${language.toLowerCase()}`, { fullPage: false });
      report.verdict(`1440, ${language}: every value's heading inside its own column, whole, on one line`,
        m.heads.every((h) => h.inside && h.lines === 1), JSON.stringify(m.heads));
      report.verdict(`1440, ${language}: the heading is the column's cap, 11cqi, below the character's size`,
        m.heads.every((h) => Math.abs(h.size - 0.11 * h.column) < 0.6 && h.size < m.full), JSON.stringify({ full: m.full, sizes: m.heads.map((h) => [h.size, h.column]) }));
      if (words === null) {
        report.verdict('1440: a heading in a section of one column keeps the character\'s size',
          m.one !== null && Math.abs(m.one - m.full) < 0.1, JSON.stringify({ one: m.one, full: m.full }));
      }
    }

    await page.setViewport({ width: 390, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/about`, { waitUntil: 'networkidle0' });
    const phone = await measure(page, CROATIAN);
    await report.shot(page, '390-croatian', { fullPage: false });
    report.verdict('390, Croatian: stacked, every heading still inside its column, whole',
      phone.heads.length === 4 && phone.heads.every((h) => h.inside && h.lines === 1), JSON.stringify(phone.heads));
  },
};
