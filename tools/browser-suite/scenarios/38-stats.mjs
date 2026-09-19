/*
 * Visit statistics (PLAN.md D-051): the Statistics panel on the Settings screen.
 *
 * Reads only: nothing is saved and "Delete all statistics" is never pressed — the counts on
 * the development site are real views, and removing them is the owner's choice.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'stats',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('stats: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    for (const [name, viewport] of [['01-desktop', { width: 1400, height: 1000 }], ['02-phone', { width: 390, height: 844 }]]) {
      await page.setViewport({ ...viewport, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/admin/settings#statistics`, { waitUntil: 'networkidle2' });
      const panel = await page.$eval('#statistics', (el) => ({
        text: el.textContent.replace(/\s+/g, ' ').trim(),
        boxes: [...el.querySelectorAll('input[type=checkbox]')].map((c) => c.name + '=' + c.checked),
        retention: el.querySelector('#stats_retention')?.value,
        erase: !!el.querySelector('button[data-confirm]'),
      }));
      await page.$eval('#statistics', (el) => el.scrollIntoView({ block: 'start' }));
      await report.shot(page, name, { fullPage: false });
      if (name === '01-desktop') {
        report.verdict('the panel says statistics are on, with its two switches, the retention and the delete button',
          /Statistics are on\./.test(panel.text) && panel.boxes.length === 2 && panel.retention !== undefined && panel.erase,
          JSON.stringify(panel));
      } else {
        const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        report.verdict('on a phone the panel does not push the page sideways', !sideways, `scrollWidth > width: ${sideways}`);
      }
    }
    await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 2 });
  },
};
