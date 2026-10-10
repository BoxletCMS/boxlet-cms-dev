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
import { execSync, execFileSync } from 'node:child_process';
import { COPY_BASE as BASE, SITE_DIR, COPY_ADMIN as ADMIN, SITE_NAME, CHECKOUT } from '../config.mjs';
import { submitVia, alerts, heading, resetForInstall, SLOW } from '../harness.mjs';

export default {
  name: 'install',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

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

    // Every stylesheet and script named with a hash of what it holds (D-215): the owner's host
    // kept the installer's plain /assets/install.js for ten years, and the second try ran the
    // first try's code.
    const assets = await page.$$eval('link[rel="stylesheet"][href^="/"], script[src^="/"]',
      (els) => els.map((el) => el.getAttribute('href') || el.getAttribute('src')));
    report.verdict('the installer names every stylesheet and script with its version',
      assets.length >= 5 && assets.every((a) => /[?&]v=[0-9a-f]{12}/.test(a)), JSON.stringify(assets));

    const checks = await page.$$eval('li, tr', (els) => els
      .map((el) => el.textContent.replace(/\s+/g, ' ').trim())
      .filter((t) => /\b(OK|Missing|Optional)\b/.test(t)).slice(0, 25));
    const blocked = await page.$$eval('[role="alert"]',
      (els) => els.some((e) => /will not install/i.test(e.textContent))).catch(() => false);

    report.verdict('requirements step lists the checks and does not block',
      /requirement/i.test(first) && checks.length > 0 && !blocked,
      `"${first}", ${checks.length} checks listed, blocked=${blocked}`);

    // Every check keeps its two columns, whatever it says. The rows that print a path are
    // long enough to wrap, and before this was a grid the second line went back under the
    // word "OK" — the owner saw it on his own install. Measured rather than eyeballed: the
    // words always start to the right of the verdict, and the card never pushes the page
    // sideways. Checked at a phone's width too, where every row wraps.
    const columns = async () => page.$$eval('.check', (rows) => rows.map((row) => {
      const verdict = row.querySelector('.check-status');
      const words = verdict?.nextElementSibling;
      return [Math.round(verdict?.getBoundingClientRect().left ?? 0), Math.round(words?.getBoundingClientRect().left ?? 0)];
    }));
    const straight = (pairs) => pairs.length > 0 && pairs.every(([verdict, words]) => words > verdict);
    const wide = await columns();
    await page.setViewport({ width: 400, height: 900, deviceScaleFactor: 1 });
    await page.reload({ waitUntil: 'networkidle2' });
    const narrow = await columns();
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.reload({ waitUntil: 'networkidle2' });
    report.verdict('a check that wraps keeps its column, on a wide screen and on a phone',
      straight(wide) && straight(narrow) && !sideways,
      `wide ${JSON.stringify(wide[0])}, phone ${JSON.stringify(narrow[0])}, sideways=${sideways}`);

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

    // THE STEP AT WORK (D-212). A real press on the button, the page's own handler answering
    // it; only the leaving is stopped, so the page is seen in the state it shows while the
    // server works, however fast this server is. A check between the click and the next page
    // would race the server (O-26), and holding the request back hung the driver instead.
    await page.evaluate(() => {
      document.querySelector('form[data-install-busy]').addEventListener('submit', (event) => event.preventDefault(), { once: true });
    });
    await page.click('form[data-install-busy] button[type="submit"]');
    const busy = await page.evaluate(() => {
      const button = document.querySelector('form[data-install-busy] button[type="submit"]');
      const status = document.querySelector('.install-busy');
      const bar = document.querySelector('.install-busy-bar');
      const box = bar ? bar.getBoundingClientRect() : null;
      return {
        disabled: button ? button.disabled : null,
        label: button ? button.textContent.trim() : null,
        wanted: button ? button.getAttribute('data-busy-label') : null,
        statusShown: status ? !status.hidden && status.getBoundingClientRect().height > 0 : false,
        bar: box ? [Math.round(box.width), Math.round(box.height)] : null,
        moving: bar ? getComputedStyle(bar, '::before').animationName : null,
        sentence: status ? status.textContent.trim() : '',
        restartLocked: Array.from(document.querySelectorAll('form.restart button')).every((b) => b.disabled),
      };
    });
    // Mid-way, so the shot shows the moving part on its track rather than at an edge.
    await new Promise((resolve) => setTimeout(resolve, 700));
    await report.shot(page, '04b-site-busy');
    report.verdict('once sent, the site step says it is at work: the button locked and renamed, "Start over" locked, a moving bar and a sentence',
      busy.disabled === true && busy.label === busy.wanted && busy.statusShown && busy.restartLocked
        && busy.bar !== null && busy.bar[0] > 0 && busy.bar[1] > 0 && busy.moving === 'install-busy' && busy.sentence.length > 0,
      JSON.stringify(busy));
    // Now sent for real; the locked button is not pressed again, the form goes as it is.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
      page.$eval('form[data-install-busy]', (form) => form.submit()),
    ]);

    // THE DEMO'S PICTURES, A FEW TO A REQUEST (D-214): the page goes on by itself until the
    // last brings the finished install. Each page of it stands for 300 ms before it sends the
    // next, and a read from the page while it is being replaced came back with nothing
    // (measured: no count seen in a whole install), so the counts are read from the pages the
    // server sends. Every request is timed too: none may come near Cloudflare's hundred seconds.
    const counts = [];
    const longest = { ms: 0 };
    const started = new Map();
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('install.php')) started.set(r, Date.now()); });
    page.on('requestfinished', (r) => { if (started.has(r)) longest.ms = Math.max(longest.ms, Date.now() - started.get(r)); });
    page.on('response', async (r) => {
      if (r.request().method() !== 'GET' || !r.url().includes('install.php')) return;
      const html = await r.text().catch(() => '');
      const value = html.match(/class="install-progress" value="(\d+)"/);
      if (value && counts[counts.length - 1] !== Number(value[1])) counts.push(Number(value[1]));
    });
    const deadline = Date.now() + 900000;
    let finished = false;
    let shot = false;
    while (Date.now() < deadline) {
      const h = await page.evaluate(() => (document.querySelector('h1') || {}).textContent || '').catch(() => '');
      if (/installed/i.test(h)) { finished = true; break; }
      if (counts.length === 2 && !shot) {
        shot = true;
        await report.shot(page, '04c-demo-pictures').catch(() => {});
      }
      await new Promise((r) => setTimeout(r, 1000));
    }
    // CHANGED DELIBERATELY (D-215): with every size made in the package, the pictures are in
    // within a request or two, so a count that rises over many requests is no longer what
    // shows the step works. What does: it finishes, no request runs long, and every picture
    // of the demo is in the library complete — read from the copy's own database.
    const count = "$db = new PDO('sqlite:' . $argv[1]); echo json_encode($db->query(\"SELECT COUNT(*) AS n, SUM(status = 'complete') AS c FROM media WHERE mime LIKE 'image/%'\")->fetch(PDO::FETCH_ASSOC));";
    const library = JSON.parse(execFileSync('php', ['-r', count, `${SITE_DIR}/storage/database.sqlite`], { encoding: 'utf8' }));
    report.verdict('the demo\'s pictures come in from its package, every one complete, and no request runs long',
      finished && Number(library.n) >= 49 && Number(library.n) === Number(library.c) && longest.ms < 60000,
      `pictures ${library.n}, complete ${library.c}, counts seen ${JSON.stringify(counts)}, longest request ${Math.round(longest.ms / 1000)} s`);
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

    // What later scenarios need of the copy, made again on the site just installed
    // (prepare-copy.php, PLAN.md D-174): sync-copy.sh made it on the one this replaced.
    const prepared = execSync(`php ${JSON.stringify(`${CHECKOUT}/tools/browser-suite/prepare-copy.php`)} ${JSON.stringify(SITE_DIR)} ${JSON.stringify(CHECKOUT)}`, { encoding: 'utf8' });
    report.verdict('the new copy is prepared for the scenarios after this one', /Pictures in the library: \d+/.test(prepared), prepared.trim().replace(/\n/g, ' '));
  },
};
