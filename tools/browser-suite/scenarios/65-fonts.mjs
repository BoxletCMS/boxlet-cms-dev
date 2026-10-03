/*
 * THE FONT LIBRARY (PLAN.md D-185), measured on the copy:
 *
 *   - every family's latin-ext file carries č ć đ š ž Č Ć Đ Š Ž: each letter drawn in the face
 *     with two different fallbacks behind it is as wide either way only when the face has it.
 *     The instrument is checked first on a face that lacks č and đ (Fredoka, which is why it
 *     is not in the library);
 *   - Appearance's two pickers: the search finds a family, choosing one sets the preview's
 *     headings in it and moves the sliders still following the family, and a pairing sets
 *     both families.
 *
 * Nothing is published: the screen is left without saving.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openSection } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const FONTS = new URL('../../../public/assets/fonts/', import.meta.url).pathname;
const GLYPHS = 'čćđšžČĆĐŠŽ';

/** The letters of GLYPHS a woff2 does not have, measured in the page. */
function missing(page, file) {
  const data = readFileSync(file).toString('base64');
  return page.evaluate(async (b64, glyphs) => {
    const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const face = new FontFace('Probe' + Math.random().toString(36).slice(2), bytes.buffer);
    await face.load();
    document.fonts.add(face);
    const c = document.createElement('canvas').getContext('2d');
    return glyphs.split('').filter((g) => {
      c.font = `40px "${face.family}", monospace`;
      const a = c.measureText(g).width;
      c.font = `40px "${face.family}", serif`;
      return Math.abs(a - c.measureText(g).width) > 0.01;
    }).join('');
  }, data, GLYPHS);
}

export default {
  name: 'fonts',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('fonts: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    // ---- the letters, in every family ----------------------------------------------------
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const control = await missing(page, new URL('../fixtures/fredoka-latin-ext-wght.woff2', import.meta.url).pathname).catch((e) => `could not load: ${e.message}`);
    report.verdict('the instrument: a face without č and đ is found out', /č/.test(control) && /đ/.test(control), `Fredoka lacks "${control}"`);
    const families = readdirSync(FONTS).filter((d) => !d.startsWith('.'));
    const bad = [];
    let files = 0;
    for (const family of families) {
      for (const file of readdirSync(`${FONTS}${family}`).filter((f) => f.includes('latin-ext') && f.endsWith('.woff2'))) {
        files += 1;
        const lacks = await missing(page, `${FONTS}${family}/${file}`);
        if (lacks !== '') { bad.push(`${file}: ${lacks}`); }
      }
    }
    report.verdict(`every family's latin-ext file has ${GLYPHS} (${families.length} families, ${files} files)`, families.length >= 27 && bad.length === 0, bad.join('; ') || 'all ten in each');

    // ---- the pickers -----------------------------------------------------------------------
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openSection(page, 'typography');
    await wait(500);
    const heading = '[data-control="heading_font"]';
    await page.click(`${heading} summary`);
    await page.type(`${heading} [data-font-search]`, 'gar', { delay: 30 });
    await wait(300);
    const found = await page.$$eval(`${heading} .font-option`, (all) => all.filter((o) => !o.hidden).map((o) => o.textContent.trim()));
    report.verdict('the search finds families by name', found.length === 2 && found.every((n) => /Garamond/.test(n)), JSON.stringify(found));
    await report.shot(page, '01-heading-picker', { fullPage: false });

    const before = await page.evaluate(() => ({ weight: document.querySelector('input[name="heading_weight"]').value, weightDefault: document.querySelector('[data-control="heading_weight"]').getAttribute('data-default') }));
    await page.click(`${heading} input[value="cormorant-garamond"]`);
    await wait(2500);
    const after = await page.evaluate(() => {
      const frame = document.querySelector('iframe[name="design-preview"]');
      const h1 = frame && frame.contentDocument ? frame.contentDocument.querySelector('h1') : null;
      return {
        summary: document.querySelector('[data-control="heading_font"] summary .font-sample').textContent,
        weight: document.querySelector('input[name="heading_weight"]').value,
        weightDefault: document.querySelector('[data-control="heading_weight"]').getAttribute('data-default'),
        changed: document.querySelector('[data-control="heading_weight"]').classList.contains('is-changed'),
        preview: h1 ? getComputedStyle(h1).fontFamily : null,
      };
    });
    report.verdict('a family chosen: its name in the picker, the preview\'s headings in it, and the weight still following it moved with it',
      after.summary === 'Cormorant Garamond' && /Cormorant Garamond/.test(after.preview || '') && after.weight === '600' && after.weightDefault === '600' && !after.changed,
      JSON.stringify({ before, after }));

    await page.$eval('[data-view="typography"] .pairing-tile[data-pairing*="space-grotesk"]', (b) => b.scrollIntoView({ block: 'center' }));
    await page.click('[data-view="typography"] .pairing-tile[data-pairing*="space-grotesk"]');
    await wait(2500);
    const paired = await page.evaluate(() => ({
      heading: document.querySelector('input[name="heading_font"]:checked').value,
      body: document.querySelector('input[name="body_font"]:checked').value,
      line: document.querySelector('input[name="line_height"]').value,
      pressed: document.querySelector('[data-view="typography"] .pairing-tile[aria-pressed="true"] .pairing-name').firstChild.textContent,
    }));
    await report.shot(page, '02-pairing', { fullPage: false });
    report.verdict('a pairing sets both families and its own line height', paired.heading === 'space-grotesk' && paired.body === 'inter' && paired.line === '1.55' && /Grotesk/.test(paired.pressed), JSON.stringify(paired));

    // Left without saving.
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
  },
};
