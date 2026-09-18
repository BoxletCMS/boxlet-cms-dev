/*
 * The selected block's controls on the block itself, and Page settings & SEO folded
 * (PLAN.md D-040).
 *
 * What a person does: open a page, see the page settings folded, pick a block on the
 * canvas, and use the icons on its top right corner — duplicate it, remove the copy, move
 * a block down and back up. Real clicks inside the canvas frame.
 *
 * NOTHING IS SAVED: every change is made in the editor and left, so the site is as it was.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const PAGE = 1;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'block-tools',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('block tools: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    await page.goto(`${BASE}/admin/pages/${PAGE}`, { waitUntil: 'networkidle2' });
    await page.waitForFunction(() => {
      const frame = document.querySelector('iframe[data-canvas]');
      return frame && frame.contentDocument
        && frame.contentDocument.querySelectorAll('[data-bx-blocks] > section').length > 1;
    }, { timeout: 20000 });

    // ---- the page settings start folded, and open ------------------------------------------
    const folded = await page.$eval('[data-page-settings]', (d) => ({ open: d.open, summary: d.querySelector('summary').textContent.trim() }));
    report.verdict('Page settings & SEO starts folded', !folded.open && /SEO/.test(folded.summary), JSON.stringify(folded));
    await page.click('[data-page-settings] > summary');
    const opened = await page.$eval('[data-page-settings]', (d) => d.open && !!d.querySelector('#page-title').offsetParent);
    report.verdict('it opens to its fields', opened, opened ? 'the title field is shown' : 'still folded');
    await page.click('[data-page-settings] > summary');

    // ---- the selected block's own controls ---------------------------------------------------
    const frame = page.frames().find((f) => f.url().includes('/canvas'));
    const count = () => frame.$$eval('[data-bx-blocks] > section', (list) => list.length);
    const headingOf = (i) => frame.$eval(`[data-bx-blocks] > section:nth-of-type(${i + 1})`, (s) => (s.querySelector('h1, h2') || s).textContent.trim().slice(0, 40));

    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(200);
    const tools = await frame.evaluate(() => {
      const bar = document.querySelector('.bx-tools');
      const section = document.querySelector('[data-bx-index="0"]');
      if (!bar || !section) return null;
      const b = bar.getBoundingClientRect();
      const s = section.getBoundingClientRect();
      return {
        buttons: Array.from(bar.querySelectorAll('button')).map((button) => ({ label: button.getAttribute('aria-label'), disabled: button.disabled })),
        insideTopRight: b.right <= s.right + 1 && b.right > s.right - 60 && b.top >= s.top && b.top < s.top + 40,
      };
    });
    await report.shot(page, '01-selected-with-tools', { fullPage: false });
    report.verdict('a selected block carries four controls on its top right corner',
      tools !== null && tools.buttons.length === 4 && tools.insideTopRight,
      JSON.stringify(tools));
    report.verdict('at the top of the page, up is shown as unavailable',
      tools !== null && tools.buttons[0].disabled && !tools.buttons[1].disabled,
      JSON.stringify(tools && tools.buttons.map((b) => b.disabled)));

    const before = await count();
    await frame.click('.bx-tools [data-block-action="duplicate"]');
    await wait(400);
    const afterCopy = await count();
    report.verdict('duplicate adds a copy', afterCopy === before + 1, `${before} -> ${afterCopy} blocks`);

    // The copy is selected next to the original; remove it.
    await (await frame.$('[data-bx-index="1"]')).click();
    await wait(200);
    await frame.click('.bx-tools [data-block-action="remove"]');
    await wait(400);
    const afterRemove = await count();
    report.verdict('remove takes it away again', afterRemove === before, `${afterCopy} -> ${afterRemove} blocks`);

    const first = await headingOf(0);
    await (await frame.$('[data-bx-index="0"]')).click();
    await wait(200);
    await frame.click('.bx-tools [data-block-action="down"]');
    await wait(400);
    const movedTo = await headingOf(1);
    report.verdict('down moves the block one place down', movedTo === first, `"${first}" is now second: "${movedTo}"`);
    await frame.click('.bx-tools [data-block-action="up"]');
    await wait(400);
    report.verdict('up moves it back', (await headingOf(0)) === first, `first is "${await headingOf(0)}"`);

    // Leave without saving.
    await page.evaluate(() => { window.onbeforeunload = null; });
  },
};
