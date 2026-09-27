/*
 * YOUR LOGIN, AND THE WAYS BACK IN (PLAN.md D-132).
 *
 * The password is changed in Settings through its own form, used to log in, and changed
 * back; the forgotten-password screen is reached from the login form; and the FTP way is
 * done as an owner would, a file dropped into the copy's storage/, read at the next visit
 * to the login form.
 *
 * ON THE COPY, AND IT PUTS THE PASSWORD BACK: every change ends at the copy's own password,
 * which the file sets too, so a run that stops half-way leaves a password this config knows
 * — and 01-install reinstalls the copy from nothing in any case.
 */
import { existsSync, writeFileSync } from 'node:fs';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, SITE_DIR } from '../config.mjs';
import { login } from '../harness.mjs';

const TEMPORARY = 'a temporary password for the scenario';

/** Fills Your login's password form, found inside the form that posts to it, and sends it. */
async function changePassword(page, from, to) {
  await page.goto(`${BASE}/admin/settings#account`, { waitUntil: 'networkidle2' });
  const form = 'form[action$="/admin/account/password"]';
  await page.type(`${form} [name="current_password"]`, from);
  await page.type(`${form} [name="new_password"]`, to);
  await page.type(`${form} [name="new_password_confirm"]`, to);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.$eval(form, (f) => f.submit())]);
  return page.$eval('[role="status"], .flash, .notice', (e) => e.textContent.trim()).catch(() => '');
}

async function logOut(page) {
  await page.evaluate(async () => {
    const form = document.querySelector('form[action$="/admin/logout"]');
    if (form) form.submit();
  });
  await page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {});
}

export default {
  name: 'your-login',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('your-login: log in', `could not log in as ${ADMIN.email}`);
      return;
    }

    try {
      const said = await changePassword(page, ADMIN.password, TEMPORARY);
      await report.shot(page, '01-password-changed', { fullPage: false });
      report.verdict('Your login changes the password with the current one', /password was changed/i.test(said), said);

      await logOut(page);
      report.verdict('the new password logs in', await login(page, BASE, ADMIN.email, TEMPORARY), 'with the temporary password');
    } finally {
      // Back to the copy's own password, whichever of the two it has now.
      const back = await changePassword(page, TEMPORARY, ADMIN.password);
      report.verdict('the scenario puts the password back', /password was changed/i.test(back), back);
    }

    // The forgotten-password screen, from the login form's own link.
    await logOut(page);
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
    const href = await page.$eval('a[href$="/admin/forgot"]', (a) => a.getAttribute('href')).catch(() => null);
    report.verdict('the login form offers a way back for a forgotten password', href !== null, String(href));
    await page.goto(`${BASE}/admin/forgot`, { waitUntil: 'networkidle2' });
    await report.shot(page, '02-forgot', { fullPage: false });
    const ftp = await page.evaluate(() => document.body.textContent.includes('storage/reset-password'));
    report.verdict('it says how to do it by FTP', ftp, ftp ? 'names storage/reset-password' : 'no FTP way');

    // The FTP way: the copy's own password in the file, so it changes nothing lasting.
    const file = `${SITE_DIR}/storage/reset-password`;
    writeFileSync(file, `${ADMIN.password}\n`);
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
    const notice = await page.$eval('.notice', (e) => e.textContent.trim()).catch(() => '');
    await report.shot(page, '03-password-from-file', { fullPage: false });
    report.verdict('a password put in a file by FTP is set at the next visit, and the file deleted',
      /is set, and the file was deleted/.test(notice) && !existsSync(file), JSON.stringify({ notice, fileLeft: existsSync(file) }));
    report.verdict('and it logs in', await login(page, BASE, ADMIN.email, ADMIN.password), 'with the password from the file');
  },
};
