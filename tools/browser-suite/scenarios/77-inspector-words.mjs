/*
 * NOTHING IN THE INSPECTOR IS CUT SHORT, AND THE CHARACTER'S LAYOUT SAYS "DEFAULT" (PLAN.md
 * D-195, the owner). Under each of the five characters, every block of the home page and of
 * the page of every block selected in turn, in the builder's inspector at 1440:
 *
 *   - no words in it end in "…" or are clamped: a readout too long for its three fifths of
 *     the row runs onto a second line;
 *   - the tile of the layout the character composes for the block, and only that tile, says
 *     "Default", whether the block follows it or has a layout of its own;
 *   - the readout is the layout's name alone, on one line beside the name or, where it does
 *     not fit there, on a line of its own under it, from the left (D-196).
 *
 * Its control: the readout's old rule (one line, cut with an ellipsis) put back on the page is
 * caught on a cover hero's "Picture behind, words low on the left".
 *
 * Nothing is changed but the character, which is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openBuilder, applyCharacter } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

/** What the inspector shows for the block selected: words cut, the tagged tiles, the readout. */
function inspected(page) {
  return page.evaluate(() => {
    const ins = document.querySelector('[data-pb-inspector]');
    const cut = [];
    ins.querySelectorAll('*').forEach((n) => {
      if (n.getClientRects().length === 0) { return; }
      const cs = getComputedStyle(n);
      if ((cs.textOverflow === 'ellipsis' && n.scrollWidth > n.clientWidth + 1) || (cs.webkitLineClamp !== 'none' && n.scrollHeight > n.clientHeight + 1)) {
        cut.push((n.getAttribute('title') || n.textContent).trim().slice(0, 50));
      }
    });
    // Every readout: on one line beside its name, or on a line of its own under the name,
    // from the same left edge (D-196) — never two lines against the row's end.
    const misplaced = [];
    ins.querySelectorAll('.control-head > .readout').forEach((r) => {
      if (r.getClientRects().length === 0 || r.textContent.trim() === '') { return; }
      const label = r.parentElement.querySelector('.control-label').getBoundingClientRect();
      const range = document.createRange();
      range.selectNodeContents(r);
      const lines = new Set([...range.getClientRects()].map((x) => Math.round(x.top))).size;
      const box = r.getBoundingClientRect();
      const beside = box.top < label.bottom - 2;
      if (beside ? lines > 1 : Math.abs(box.left - label.left) > 1) { misplaced.push(`${r.textContent.trim()} (${beside ? `${lines} lines beside` : 'below, not from the name\'s edge'})`); }
    });
    const tiles = [...ins.querySelectorAll('.tile-choice input[data-tile]')].filter((i) => /\[layout\]$/.test(i.name));
    const tagged = tiles.filter((i) => i.closest('.tile-option').querySelector('.tile-tag')).map((i) => i.dataset.tile);
    const checked = tiles.find((i) => i.checked);
    const row = checked ? checked.closest('.control-row, .field-row') : null;
    const readout = row ? (row.querySelector('.readout') || {}).textContent || '' : '';
    return {
      cut,
      misplaced,
      tagged,
      tag: tagged.length ? ins.querySelector('.tile-tag').textContent.trim() : '',
      readout: readout.trim(),
      // Its full name: the tile's title where its label is a short one, else the label.
      name: checked ? (checked.closest('.tile-option').getAttribute('title') || checked.closest('.tile-option').querySelector('.tile-label').textContent).trim() : '',
      tiles: tiles.length,
    };
  });
}

export default {
  name: 'inspector-words',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('inspector words: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    await openBuilder(page, BASE, 1);
    const showroom = Number((await page.evaluate(() => (window.pb.data.inline.pages.find((p) => /\/blocks\/?$/.test(p.url)) || {}).ref || 'page:0')).slice(5));
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save');
        const bad = [];
        let seen = 0;
        for (const id of [1, showroom]) {
          await openBuilder(page, BASE, id);
          const blocks = await page.evaluate(() => window.pb.doc.blocks.map((b) => ({ key: b.key, type: b.type, layout: b.layout, composed: window.pb.data.composed[b.type] })));
          for (const b of blocks) {
            await page.evaluate((k) => window.pb.select('block', k), b.key);
            await page.waitForSelector(`[data-pb-inspector] [data-block-fields="${b.key}"]`, { timeout: 10000 }).catch(() => {});
            await wait(200);
            const r = await inspected(page);
            seen += 1;
            const at = `${b.type}/${b.layout || `'' (${b.composed})`}`;
            if (r.cut.length) { bad.push(`${at}: cut ${r.cut.join(', ')}`); }
            if (r.misplaced.length) { bad.push(`${at}: readout ${r.misplaced.join(', ')}`); }
            if (r.tiles > 1) {
              if (r.tagged.join() !== b.composed || r.tag.toLowerCase() !== 'default') { bad.push(`${at}: tagged ${JSON.stringify(r.tagged)} "${r.tag}"`); }
              if (r.readout === '' || r.readout.includes('·') || r.readout !== r.name) { bad.push(`${at}: readout "${r.readout}" for "${r.name}"`); }
            }
            if (character === 'soft' && id !== 1 && b.type === 'hero' && b.layout === 'cover-low') {
              await page.evaluate(() => document.querySelector('[data-pb-inspector] .tile-choice').scrollIntoView({ block: 'center' }));
              await report.shot(page, 'soft-cover-low-inspector', { fullPage: false });
              // THE CONTROL: the readout's old rule, one line cut with an ellipsis.
              // Through the CSSOM: the admin's policy refuses a <style> element.
              const rule = (on) => page.evaluate((o) => document.querySelectorAll('[data-pb-inspector] .readout').forEach((n) => {
                // Beside its name in three fifths of the row, as it stood then.
                if (o) { n.classList.remove('readout-below'); }
                ['white-space', 'overflow', 'text-overflow', 'max-inline-size'].forEach((p, i) => (o ? n.style.setProperty(p, ['nowrap', 'hidden', 'ellipsis', '60%'][i]) : n.style.removeProperty(p)));
              }), on);
              await rule(true);
              const forced = await inspected(page);
              report.verdict('the control: the readout\'s old rule is caught cutting "Picture behind, words low on the left"',
                forced.cut.some((c) => /words low on the left/.test(c)), JSON.stringify(forced.cut));
              await rule(false);
              // AND THE SECOND: the readout put back against the row's end, in its three
              // fifths, as D-195 had it, is caught running onto two lines there.
              const beside = (on) => page.evaluate((o) => document.querySelectorAll('[data-pb-inspector] .control-head > .readout').forEach((n) => {
                if (o) { n.classList.remove('readout-below'); n.style.setProperty('max-inline-size', '60%'); } else { n.style.removeProperty('max-inline-size'); }
              }), on);
              await beside(true);
              const against = await inspected(page);
              report.verdict('the control: a long readout kept beside its name, in three fifths of the row, is caught on two lines',
                against.misplaced.some((m) => /words low on the left.*2 lines beside/.test(m)), JSON.stringify(against.misplaced));
              await beside(false);
              await page.evaluate(() => window.dispatchEvent(new Event('resize')));
            }
          }
        }
        report.verdict(`${character}: in the inspector nothing is cut short, the character's layout alone says Default, and the readout is the layout's name, beside or under its own name`,
          bad.length === 0 && seen > 20, bad.slice(0, 6).join(' | ') || `${seen} blocks`);
      }
    } finally {
      if (was !== '') { await applyCharacter(page, BASE, was, 'save'); }
      report.verdict('inspector words: the copy\'s character back', was !== '', was || 'could not read it');
    }
  },
};
