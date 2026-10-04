/*
 * NO TEXT ON THE PAGE UNDER 13PX (PLAN.md D-187, the owner): the smallest size a site draws is
 * --text-small, ≈14px at a 16px body. A number's label, a picture's caption and a logo's name
 * stood at the type scale's `sm` step, which a wide scale took down to 10.7px.
 *
 * Every visible piece of text — every element holding words of its own — on the demo's home
 * page and its page of every block, as a visitor gets them, under each of the five
 * characters, its computed size against 13px. The copy's character is put back at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, applyCharacter } from '../harness.mjs';

const FLOOR = 13;
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];

/** Each element holding words of its own, seen, under the floor: what it is and its size. */
function small(page) {
  return page.evaluate((floor) => {
    const out = [];
    let least = Infinity;
    let counted = 0;
    document.querySelectorAll('body *').forEach((el) => {
      const words = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
      if (!words) { return; }
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      // Seen: laid out, not clipped to a pixel for screen readers only, not hidden.
      if (r.width <= 1 || r.height <= 1 || cs.visibility !== 'visible' || cs.display === 'none' || el.closest('[hidden], [aria-hidden="true"]')) { return; }
      const size = parseFloat(cs.fontSize);
      counted += 1;
      least = Math.min(least, size);
      if (size < floor) {
        const name = el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : '');
        out.push(`${name} "${el.textContent.trim().slice(0, 20)}" ${size.toFixed(1)}px`);
      }
    });
    return { counted, least: Math.round(least * 10) / 10, under: [...new Set(out)] };
  }, FLOOR);
}

export default {
  name: 'text-size',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('text size: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    const was = await page.$eval('.character-tile.is-current .tile-use', (b) => b.value.replace('preset:', '')).catch(() => '');
    try {
      for (const character of CHARACTERS) {
        await applyCharacter(page, BASE, character, 'save_composition');
        for (const path of ['/', '/blocks']) {
          await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
          const seen = await small(page);
          report.verdict(`${character}, ${path}: no text under ${FLOOR}px (${seen.counted} pieces, the least ${seen.least}px)`,
            seen.counted > 20 && seen.under.length === 0, seen.under.slice(0, 12).join('; ') || `the least ${seen.least}px`);
        }
      }
    } finally {
      if (was !== '') { await applyCharacter(page, BASE, was, 'save_composition'); }
      report.verdict('text size: the copy\'s character is back', was !== '', was || 'could not read it');
    }
  },
};
