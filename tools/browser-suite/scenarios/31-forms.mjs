/*
 * Forms in the admin (PLAN.md D-046).
 *
 * What a person does: make a form, rename a field, add one and make it a list with choices,
 * move it up, and see the list's choices appear only for a list. Real clicks on the edit
 * screen's own buttons, every one of which saves.
 *
 * LEAVES THE SITE AS FOUND: the form is made for this run under a name no owner would use,
 * and deleted from the forms list at the end.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, retype } from '../harness.mjs';

const NAME = `Zz form check ${Date.now()}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const fields = (page) => page.$$eval('[data-form-field]', (items) => items.map((li) => ({
  label: li.querySelector('input[name$="[label]"]').value,
  type: li.querySelector('select[name$="[type]"]').value,
  options: !li.querySelector('[data-field-options]').hidden,
})));

export default {
  name: 'forms',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('forms: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    let id = null;
    try {
      await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
      await page.type('#form-name', NAME);
      await clickAndWait(page, 'form[action$="/admin/forms"] button[type="submit"]');
      id = Number((page.url().match(/\/admin\/forms\/(\d+)$/) || [])[1]) || null;
      const start = await fields(page);
      report.verdict('a new form starts with name, email and message, and shows no choices box',
        id !== null && start.map((f) => f.type).join() === 'text,email,textarea' && start.every((f) => !f.options), JSON.stringify(start));

      await retype(page, 'input[name="fields[0][label]"]', 'Your name');
      await clickAndWait(page, 'button[value="add"]');
      await page.select('select[name="fields[3][type]"]', 'select');
      await wait(100);
      const shown = (await fields(page))[3];
      await retype(page, 'input[name="fields[3][label]"]', 'Topic');
      await page.type('textarea[name="fields[3][options]"]', 'Design\nBuild\nCare');
      await clickAndWait(page, 'button[value="up-3"]');
      const after = await fields(page);
      await page.$eval('#fields', (el) => el.scrollIntoView({ block: 'start' }));
      await report.shot(page, '01-fields', { fullPage: false });
      report.verdict('choosing a list shows its choices box', shown !== undefined && shown.options, JSON.stringify(shown));
      report.verdict('rename, add and move each saved, in order',
        after.map((f) => f.label).join('|') === 'Your name|Email|Topic|Message' && after[2].type === 'select',
        after.map((f) => `${f.label}:${f.type}`).join(', '));

      await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
      await page.reload({ waitUntil: 'networkidle2' });
      const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      await page.$eval('#fields', (el) => el.scrollIntoView({ block: 'start' }));
      await report.shot(page, '02-phone', { fullPage: false });
      report.verdict('on a phone the edit screen does not push the page sideways', !sideways, `sideways: ${sideways}`);
      await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 2 });
    } finally {
      if (id !== null) {
        await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
        await page.$eval(`form[action$="/forms/${id}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/forms/${id}/delete"] button`).catch(() => {});
        const left = await page.evaluate((name) => document.body.textContent.includes(name), NAME);
        report.verdict('the form is gone again', !left, `still listed: ${left}`);
      }
    }
  },
};
