/*
 * The Email panel on the Settings screen (PLAN.md D-045).
 *
 * What a person does: choose a way of sending and see only its fields, on a desktop and a
 * phone. Nothing is saved; the one request it makes is the test button while no way of
 * sending is chosen, which only answers that one must be chosen first — and is skipped when
 * the owner has already set one up, so it never sends mail on their behalf.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Which of the panel's groups are showing. */
const showing = (page) => page.$$eval('[data-mail-group]', (groups) => groups
  .filter((g) => !g.hidden && g.getBoundingClientRect().height > 0)
  .map((g) => g.getAttribute('data-mail-group')));

export default {
  name: 'mail',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('mail: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.goto(`${BASE}/admin/settings#mail`, { waitUntil: 'networkidle2' });
    const saved = await page.$eval('#mail_transport', (s) => s.value);

    const seen = {};
    for (const way of ['smtp', 'resend', 'sendmail', '']) {
      await page.select('#mail_transport', way);
      await wait(100);
      seen[way || 'none'] = await showing(page);
      if (way === 'smtp') {
        await page.$eval('#mail', (el) => el.scrollIntoView({ block: 'start' }));
        await report.shot(page, '01-smtp', { fullPage: false });
      }
    }
    report.verdict('each way of sending shows its own fields and no others',
      JSON.stringify(seen) === JSON.stringify({ smtp: ['smtp'], resend: ['resend'], sendmail: ['sendmail'], none: [] }), JSON.stringify(seen));

    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/settings#mail`, { waitUntil: 'networkidle2' });
    await page.select('#mail_transport', 'smtp');
    await page.$eval('#mail', (el) => el.scrollIntoView({ block: 'start' }));
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await report.shot(page, '02-phone', { fullPage: false });
    report.verdict('on a phone the panel does not push the page sideways', !sideways, `scrollWidth > width: ${sideways}`);
    await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 2 });

    if (saved !== '') {
      report.skip('the test button without a way of sending', `the site sends through ${saved}; not sending a test on the owner's behalf`);
      return;
    }
    await page.goto(`${BASE}/admin/settings#mail`, { waitUntil: 'networkidle2' });
    await clickAndWait(page, 'form.mail-test button');
    const said = await page.$$eval('[role="alert"], [role="status"], .flash, .notice', (els) => els.map((e) => e.textContent.trim()).join(' | '));
    report.verdict('the test button says a way of sending must be chosen first', /Choose a way of sending/.test(said), said);
  },
};
