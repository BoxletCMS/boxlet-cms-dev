/*
 * A CARD'S PICTURE IS A SHAPE, AND NO CARD STANDS ALONE (PLAN.md D-194, the owner), under each
 * of the five characters, from a wide screen to a phone, on the page a visitor gets (the
 * draft's preview):
 *
 *   - a card's picture is 16:9 when wide and 1:1 when square, of the card's width, whatever
 *     the number to a row; round is a circle, 1:1;
 *   - the last card of an odd two, of three and one, of four and one, stands in the middle of
 *     its row at a card's width; an even row and a phone's single column are left as they are.
 *
 * Its control: the old fixed height and the lone card at the start of its row, forced onto the
 * page, are caught.
 *
 * A Cards block is added to the page of every block, which has none, in a draft that is
 * discarded; the copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter, settle } from '../harness.mjs';

const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const WIDTHS = [1440, 1024, 768, 390];
/** Each pass: cards to a row, how many, the pictures' shape. */
const PASSES = [
  { across: 2, count: 3, shape: 'wide' },
  { across: 2, count: 4, shape: 'square' },
  { across: 3, count: 4, shape: 'round' },
  { across: 4, count: 5, shape: 'wide' },
];
const RATIO = { wide: 16 / 9, square: 1, round: 1 };

/** The cards as drawn: each one's box, its picture's, and the grid's. */
function drawn(page) {
  return page.evaluate(() => {
    const grid = document.querySelector('.block-cards.layout-grid .cards-grid');
    if (!grid) { return null; }
    const g = grid.getBoundingClientRect();
    const cards = [...grid.children].filter((n) => n.classList.contains('cards-item')).map((n) => {
      const r = n.getBoundingClientRect();
      const media = n.querySelector('.cards-media img, .cards-media .media-placeholder');
      const m = media ? media.getBoundingClientRect() : null;
      return { top: Math.round(r.top), left: r.left, width: r.width, ratio: m && m.height ? m.width / m.height : 0, round: media ? getComputedStyle(media).borderRadius : '' };
    });
    return { left: g.left, width: g.width, cards };
  });
}

/** What is wrong with these cards, if anything. */
function wrong(seen, pass, width) {
  if (!seen) { return 'no Cards grid drawn'; }
  const { cards } = seen;
  if (cards.length !== pass.count) { return `${cards.length} cards of ${pass.count}`; }
  const bad = [];
  const want = RATIO[pass.shape];
  cards.forEach((c, i) => { if (Math.abs(c.ratio - want) > 0.03) { bad.push(`card ${i + 1} picture ${c.ratio.toFixed(2)}:1, not ${want.toFixed(2)}`); } });
  if (pass.shape === 'round' && !cards.every((c) => c.round === '50%')) { bad.push(`round drawn ${cards[0].round}`); }
  const rows = [];
  cards.forEach((c) => { const row = rows.find((r) => Math.abs(r.top - c.top) < 3); if (row) { row.cards.push(c); } else { rows.push({ top: c.top, cards: [c] }); } });
  const last = rows[rows.length - 1].cards;
  if (rows.length > 1 && last.length === 1) {
    const c = last[0];
    const centre = Math.abs((c.left + c.width / 2) - (seen.left + seen.width / 2));
    const one = rows[0].cards[0].width;
    if (rows[0].cards.length > 1 && (centre > 2 || Math.abs(c.width - one) > 2)) {
      bad.push(`${rows.map((r) => r.cards.length).join('+')}: the last alone ${Math.round(centre)} from the middle, ${Math.round(c.width)} wide beside ${Math.round(one)}`);
    }
  }
  // A phone: one card to a row, each as wide as the grid.
  if (width <= 640 && cards.some((c) => Math.abs(c.width - seen.width) > 2)) { bad.push('a card narrower than the phone\'s column'); }
  return bad.join('; ');
}

export default {
  name: 'cards',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('cards: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    await openBuilder(page, BASE, 1);
    const id = Number((await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || 'page:0')).slice(5));
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save');
        const bad = [];
        for (const pass of PASSES) {
          await openBuilder(page, BASE, id);
          await page.evaluate((p) => {
            let block = window.pb.doc.blocks.find((b) => b.type === 'cards');
            if (!block) {
              window.pb.addBlock('cards', 0);
              block = window.pb.doc.blocks.find((b) => b.type === 'cards');
            }
            const items = block.content.items;
            window.pb.change(() => {
              block.layout = 'grid';
              block.options = { per_row: p.across, image_shape: p.shape };
              block.content.items = Array.from({ length: p.count }, (_, i) => window.pb.copy(items[i % items.length]));
            }, { sections: [block.section] });
          }, pass);
          await settle(page);
          for (const width of WIDTHS) {
            await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
            await page.goto(`${BASE}/admin/pages/${id}/preview`, { waitUntil: 'networkidle2' });
            const problem = wrong(await drawn(page), pass, width);
            if (problem) { bad.push(`${pass.count} ${pass.shape} ${pass.across} across, ${width}px: ${problem}`); }
            if (character === 'bold' && pass === PASSES[0] && width === 1440) {
              await page.evaluate(() => document.querySelector('.block-cards').scrollIntoView({ block: 'center' }));
              await report.shot(page, 'bold-two-and-one', { fullPage: false });
              // THE CONTROL: the fixed height and the lone card at the start of its row.
              await page.addStyleTag({ content: '.cards-media .media-placeholder, .cards-media img { aspect-ratio: auto !important; block-size: calc(var(--space-m) * 6.875) !important; } .cards-item { grid-column: auto !important; justify-self: stretch !important; inline-size: auto !important; }' });
              const forced = wrong(await drawn(page), pass, width);
              report.verdict('the control: a fixed picture height and the last card alone at the start of its row, forced onto the page, are caught',
                forced.includes('picture') && forced.includes('the last alone'), forced);
            }
          }
          await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
        }
        report.verdict(`${character}: a card's picture is 16:9 or 1:1 of the card at every width, and the last card of an uneven row stands in its middle at a card's width`, bad.length === 0, bad.slice(0, 6).join(' | ') || 'all passes at every width');
      }
    } finally {
      await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
      await openBuilder(page, BASE, id);
      if (await page.$('[data-pb-discard]:not([hidden])')) {
        await page.click('[data-pb-discard]');
        await page.waitForFunction(() => document.querySelector('[data-pb-discard]').hidden, { timeout: 10000 }).catch(() => {});
      }
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('cards: the draft discarded and the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
