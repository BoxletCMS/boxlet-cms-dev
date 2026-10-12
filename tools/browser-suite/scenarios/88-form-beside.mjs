/*
 * A FORM "BESIDE" ITS WORDS, STACKED IN A NARROW COLUMN, KEEPS ITS WORDS CLOSE (PLAN.md D-230,
 * the owner, on boxletcms.org's Contact): beside, the words and the fields share a row with
 * the wide gap between them; in a column too narrow for that (the words-beside container,
 * 47rem) they stand one under the other, and the wide gap stood between them as well, on top
 * of the words' own margin: 106 px from the intro to the first field on a 1440 screen.
 *
 *   - one form in a section of its own: words and fields side by side, tops level;
 *   - one in the first of two halves: stacked, and the words' margin is the only space under
 *     them, as in the form's other layout.
 *
 * ON THE COPY, on a page of its own, deleted at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, deletePage, clickAndWait, openBuilder, addBlock, settle } from '../harness.mjs';

const TITLE = `Zz form beside ${Date.now()}`;
const idFrom = (url) => Number((url.match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;

export default {
  name: 'form-beside',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('form-beside: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    let id = null;
    try {
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', TITLE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      id = idFrom(page.url());
      await openBuilder(page, BASE, id);
      const wide = await addBlock(page, 'form');
      const form = await page.$eval(`[data-pb-inspector] [data-block-fields="${wide}"] select[name$="[form]"]`, (s) => [...s.options].map((o) => o.value).find((v) => v !== '') || '');
      const narrow = await addBlock(page, 'form');
      if (form === '') {
        report.fail('form-beside: a form to show', 'the copy has no form to choose');
        return;
      }
      await page.evaluate((keys, chosen) => {
        const blocks = keys.map((k) => window.pb.doc.blocks.find((b) => b.key === k));
        window.pb.change((doc) => {
          blocks.forEach((b) => {
            b.layout = 'beside';
            b.content.form = Number(chosen);
            b.content.heading = 'Get in touch';
            b.content.intro = 'Words above a form, long enough to wrap onto a second line in a narrow column of a section in halves.';
          });
          doc.sections.find((s) => s.key === blocks[1].section).layout = 'halves';
        }, { structure: true, sections: blocks.map((b) => b.section) });
      }, [wide, narrow], form);
      await settle(page);
      await page.waitForFunction((k) => document.querySelector('[data-pb-canvas]').contentDocument.querySelectorAll('.form-block form.site-form').length === 2, { timeout: 15000 }, narrow).catch(() => {});
      const seen = await page.evaluate((keys) => {
        const frame = document.querySelector('[data-pb-canvas]').contentDocument;
        return keys.map((k) => {
          const block = frame.querySelector(`[data-bx-key="${k}"]`);
          const head = block?.querySelector('.form-block-head');
          const fields = block?.querySelector('form.site-form');
          if (!head || !fields) return null;
          const h = head.getBoundingClientRect();
          const f = fields.getBoundingClientRect();
          const css = getComputedStyle(head);
          return { side: f.left > h.right - 1, levels: Math.round(Math.abs(f.top - h.top)), gap: Math.round(f.top - h.bottom), margin: Math.round(parseFloat(css.marginBlockEnd)), columnGap: Math.round(parseFloat(getComputedStyle(block.querySelector('.form-block')).columnGap)) };
        });
      }, [wide, narrow]);
      await page.evaluate((k) => document.querySelector('[data-pb-canvas]').contentDocument.querySelector(`[data-bx-key="${k}"]`).scrollIntoView({ block: 'center' }), narrow);
      await report.shot(page, '01-stacked-in-a-half', { fullPage: false });
      const [a, b] = seen;
      report.verdict('a form beside in a section of its own: its words and fields side by side, tops level',
        a !== null && a.side && a.levels <= 2, JSON.stringify(a));
      // The words' own margin, within a pixel of rounding: no row gap added to it.
      report.verdict('a form beside, stacked in a half: only the words\' margin under them, not the wide gap as well',
        b !== null && !b.side && Math.abs(b.gap - b.margin) <= 2 && b.gap < b.columnGap, JSON.stringify(b));
    } finally {
      if (id !== null) {
        await settle(page).catch(() => {});
        await deletePage(page, BASE, id);
        const left = await page.$(`tr[data-page-id="${id}"]`) !== null;
        report.verdict('form-beside: its page is gone again', !left, `left: ${left}`);
      }
    }
  },
};
