/*
 * Slice 2: a fresh install, the lock, and login.
 *
 * Runs against a copy that has never been installed, and installs it with the demo
 * option — which is what gives every later scenario a home page, four demo pages and a
 * contrast surface to work against.
 *
 * Order matters inside this file. Login and logout are checked BEFORE the lockout,
 * because after five failures the account is locked and everything afterwards would fail
 * for the wrong reason. LoginThrottle: 5 failures per 900s, counted on the IP hash or the
 * email hash, and attempts made while already locked are not recorded, so the lock always
 * expires rather than extending itself.
 *
 * auth.failed is deliberately the same message for a wrong password and a locked account,
 * so the form never reveals whether an account exists. Reading the message therefore
 * cannot tell the two apart: the only honest test is to fail five times and then try the
 * CORRECT password.
 */
import { existsSync, readFileSync } from 'node:fs';
import { BASE, SITE_DIR, ADMIN, SITE_NAME } from '../config.mjs';
import { submitVia, alerts, heading, resetForInstall, SLOW } from '../harness.mjs';

export default {
  name: 'install',

  async run({ page, report }) {
    // ---- this scenario's own precondition ----------------------------------------------
    // It installs from nothing, so it needs a copy that has never been installed. It met an
    // installed one instead: install.php had deleted itself, exactly as it must, so the
    // first verdict read "Page not found, 0 checks listed" and the next blamed a missing
    // token file. Neither named the real reason, and both looked like the installer being
    // broken. A scenario must never fail because an earlier run left state behind — it sets
    // up what it needs, or it says plainly that it cannot (PLAN.md D-029).
    // It MAKES its precondition rather than complaining about it. Saying "this copy is
    // already installed" was honest but useless: the check could only ever fail, and a
    // colour that never changes stops being read. resetForInstall() refuses loudly if the
    // directory is the checkout, is under htdocs, or does not look like a Boxlet copy — it
    // deletes a database and a .env, so it asks those questions before acting, not after.
    let reset;
    try {
      reset = resetForInstall();
    } catch (error) {
      report.fail('install: a copy it can install into', error.message);
      return;
    }
    report.pass('install: a copy it can install into', reset);

    // ---- the installer, step by step -------------------------------------------------
    await page.goto(`${BASE}/install.php`, { waitUntil: 'networkidle2' });
    const first = await heading(page);
    await report.shot(page, '01-requirements');

    const checks = await page.$$eval('li, tr', (els) => els
      .map((el) => el.textContent.replace(/\s+/g, ' ').trim())
      .filter((t) => /\b(OK|Missing|Optional)\b/.test(t)).slice(0, 25));
    const blocked = await page.$$eval('[role="alert"]',
      (els) => els.some((e) => /will not install/i.test(e.textContent))).catch(() => false);

    report.verdict('requirements step lists the checks and does not block',
      /requirement/i.test(first) && checks.length > 0 && !blocked,
      `"${first}", ${checks.length} checks listed, blocked=${blocked}`);

    // The token proves filesystem access; it is written on the first visit.
    const tokenFile = `${SITE_DIR}/storage/install-token.txt`;
    if (!existsSync(tokenFile)) {
      report.fail('the install token is written on the first visit', `${tokenFile} does not exist`);
      return;
    }
    await page.type('input[name="token"]', readFileSync(tokenFile, 'utf8').trim(), { delay: SLOW });
    await submitVia(page, 'input[name="token"]');
    report.verdict('the install token is required and accepted',
      (await alerts(page)).length === 0, `now at "${await heading(page)}"`);

    // Database: SQLite.
    await page.click('input[name="driver"][value="sqlite"]');
    await report.shot(page, '02-database');
    await submitVia(page, 'input[name="driver"][value="sqlite"]');
    report.verdict('the database step accepts SQLite',
      (await alerts(page)).length === 0,
      `now at "${await heading(page)}", alerts: ${JSON.stringify(await alerts(page))}`);

    // Admin account.
    await page.type('input[name="email"]', ADMIN.email, { delay: SLOW });
    await page.type('input[name="password"]', ADMIN.password, { delay: SLOW });
    await page.type('input[name="password_confirm"]', ADMIN.password, { delay: SLOW });
    await report.shot(page, '03-admin');
    await submitVia(page, 'input[name="email"]');
    report.verdict('the admin step accepts an account',
      (await alerts(page)).length === 0,
      `now at "${await heading(page)}", alerts: ${JSON.stringify(await alerts(page))}`);

    // Site, with the demo option.
    await page.type('input[name="name"]', SITE_NAME, { delay: SLOW });
    const demoDefault = await page.$eval('input[name="demo"]', (el) => el.checked).catch(() => null);
    if (demoDefault === false) await page.click('input[name="demo"]');
    await report.shot(page, '04-site');
    await submitVia(page, 'input[name="name"]');
    await report.shot(page, '05-done');
    report.verdict('the site step installs, with the demo option',
      (await alerts(page)).length === 0,
      `demo checkbox default=${demoDefault}, now at "${await heading(page)}"`);

    // What the install produced on disk.
    const lock = existsSync(`${SITE_DIR}/storage/install.lock`);
    const env = existsSync(`${SITE_DIR}/.env`);
    const installerGone = !existsSync(`${SITE_DIR}/public/install.php`);
    report.verdict('the install writes .env and install.lock', lock && env,
      `.env=${env}, install.lock=${lock}, install.php removed itself=${installerGone}`);

    // The demo site on the front end.
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const home = await heading(page);
    const sections = await page.$$eval('main section, [data-bx-blocks] > section', (e) => e.length)
      .catch(() => 0);
    await report.shot(page, '06-front');
    report.verdict('the demo site renders at /', home.length > 0 && sections > 0,
      `home page h1 "${home}", ${sections} sections`);

    // ---- the second run is refused ---------------------------------------------------
    await page.goto(`${BASE}/install.php`, { waitUntil: 'networkidle2' });
    const body = await page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
    await report.shot(page, '07-second-run');
    report.verdict('running the installer a second time is refused',
      installerGone || !/Server requirements/i.test(body),
      installerGone ? 'install.php deleted itself' : `page says: "${body.slice(0, 140)}"`);

    // ---- login, logout, then the lockout ----------------------------------------------
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="email"]', ADMIN.email, { delay: SLOW });
    await page.type('input[name="password"]', ADMIN.password, { delay: SLOW });
    await submitVia(page, 'input[name="password"]');
    const loggedIn = !page.url().includes('/login');
    await report.shot(page, '08-logged-in');
    report.verdict('login works', loggedIn, `landed at ${page.url()}`);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }),
      page.evaluate(() => Array.from(document.querySelectorAll('button, a'))
        .find((el) => /log ?out/i.test(el.textContent)).click()),
    ]);
    const atLogin = page.url().includes('/login');
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const stillIn = !page.url().includes('/login');
    report.verdict('logout works', atLogin && !stillIn,
      `after logout at ${atLogin ? 'the login page' : page.url()}; /admin afterwards ${stillIn ? 'STILL SERVED' : 'sent back to login'}`);

    // The lockout is checked in 99-lockout, which runs last: it locks the account for
    // fifteen minutes (LoginThrottle: 5 failures per 900s), and every scenario in between
    // has to be able to log in.
  },
};
