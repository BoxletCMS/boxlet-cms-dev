/*
 * THE OWNER'S SCRIPTS, AND THE BOX THAT ASKS (PLAN.md O-73).
 *
 *   - Scripts & cookies says no visitor is asked anything while no script waits;
 *   - a script pasted in that names googletagmanager.com/gtag/js is recognised as Google
 *     Analytics, a statistics script, with its cookies filled in;
 *   - Check finds what the site sets that no script lists, and leaves this browser as it was;
 *   - a visitor's first page: the box, and nothing run and nothing set;
 *   - only the necessary (Choose, statistics left off, Save): nothing runs, nothing is set, and the
 *     next page runs nothing either, though the code is in its source as on every page (the owner,
 *     2026-10-23, read it there as not blocked; it waits in a <template>, which never runs);
 *   - Accept all: the script runs and sets its cookie, the box goes, the next page runs it again;
 *   - Cookie settings, statistics taken back: the page reloads, the cookie is gone, nothing runs;
 *   - on a phone the box keeps inside the screen.
 *
 * The script is this scenario's own and sends nothing anywhere: it names the address only in a
 * comment, and sets its cookies itself. Deleted at the end, by its id. On the copy.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, openBrowser } from '../harness.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const CODE = "<script>/* googletagmanager.com/gtag/js */ window.__suiteStats = (window.__suiteStats || 0) + 1; document.cookie = '_ga=GA1.suite; path=/'; document.cookie = '_suite_unlisted=1; path=/';</script>";
const visitorState = (page) => page.evaluate(() => ({
  ran: window.__suiteStats || 0,
  cookies: document.cookie,
  box: !!document.querySelector('[data-consent]') && !document.querySelector('[data-consent]').hidden,
}));

export default {
  name: 'consent',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('consent: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    let id = null;
    try {
      await page.goto(`${BASE}/admin/scripts`, { waitUntil: 'networkidle2' });
      const before = await page.$eval('.notice[role=status]', (n) => n.textContent.trim()).catch(() => '');
      report.verdict('with no script waiting, no visitor is asked anything, and the screen says so', /asked nothing/.test(before), before);

      await page.type('#script-name', 'Suite analytics');
      await page.$eval('#script-code', (t, code) => { t.value = code; }, CODE);
      await clickAndWait(page, 'form[action$="/admin/scripts"] button[type=submit]');
      id = Number((page.url().match(/scripts\/(\d+)/) || [])[1]) || null;
      const made = await page.evaluate(() => ({
        kind: document.querySelector('#script-kind')?.value,
        rows: [...document.querySelectorAll('[data-scripts-cookie] input[name$="[name]"]')].map((i) => i.value).filter(Boolean),
      }));
      report.verdict('pasted, it is recognised as Google Analytics: statistics, with its cookies',
        id !== null && made.kind === 'statistics' && made.rows.join() === '_ga,_ga_*', JSON.stringify(made));

      await page.click('[data-scripts-check-run]');
      await page.waitForFunction(() => !document.querySelector('[data-scripts-check-run]').disabled, { timeout: 30000 });
      const checked = await page.evaluate(() => ({
        found: [...document.querySelectorAll('.scripts-check-list code')].map((c) => c.textContent),
        cookies: document.cookie,
        answer: localStorage.getItem('boxlet-consent'),
      }));
      await page.$eval('#cookies', (el) => el.scrollIntoView());
      await report.shot(page, '01-check', { fullPage: false });
      report.verdict('Check finds what no script lists, and leaves this browser as it was',
        checked.found.includes('_suite_unlisted') && !checked.found.includes('_ga') && !/_ga|_suite/.test(checked.cookies) && checked.answer === null, JSON.stringify(checked));

      // A visitor: a browser of their own, nothing remembered.
      const visitor = await openBrowser({ width: 1280, height: 900, scale: 1 });
      try {
        const v = visitor.page;
        await v.goto(`${BASE}/about`, { waitUntil: 'networkidle0' });
        const first = await visitorState(v);
        await report.shot(v, '02-first-visit', { fullPage: false });
        report.verdict('a first visit: the box, and nothing run, nothing set', first.box && first.ran === 0 && first.cookies === '', JSON.stringify(first));

        await v.click('[data-consent-more]');
        await sleep(200);
        const statsOn = await v.$eval('[data-consent-choice="statistics"]', (i) => i.checked);
        if (statsOn) {
          await v.click('[data-consent-choice="statistics"]');
        }
        await v.click('[data-consent-save]');
        await sleep(300);
        const necessary = await visitorState(v);
        await v.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
        const necessaryNext = { ...await visitorState(v), inSource: await v.evaluate(() => !!document.querySelector('template[data-consent-kind="statistics"]')) };
        report.verdict('only the necessary: nothing runs or is set, on this page or the next, though the code waits in the source',
          necessary.ran === 0 && necessary.cookies === '' && !necessary.box && necessaryNext.ran === 0 && necessaryNext.cookies === '' && !necessaryNext.box && necessaryNext.inSource,
          JSON.stringify({ necessary, necessaryNext }));

        // The same visitor changes their mind: the box again, from the footer, and Accept all.
        await v.$eval('[data-consent-open]', (b) => b.scrollIntoView());
        await v.click('[data-consent-open]');
        await sleep(200);
        await v.click('[data-consent-all="yes"]');
        await sleep(300);
        const accepted = await visitorState(v);
        await v.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
        const next = await visitorState(v);
        report.verdict('Accept all: it runs and sets its cookie, the box goes, and the next page runs it with no question',
          accepted.ran === 1 && /_ga=/.test(accepted.cookies) && !accepted.box && next.ran === 1 && !next.box, JSON.stringify({ accepted, next }));

        await v.$eval('[data-consent-open]', (b) => b.scrollIntoView());
        await v.click('[data-consent-open]');
        await sleep(200);
        await v.click('[data-consent-choice="statistics"]');
        await Promise.all([v.waitForNavigation({ waitUntil: 'networkidle0' }), v.click('[data-consent-save]')]);
        const back = await visitorState(v);
        report.verdict('taken back in Cookie settings: the page reloads, its cookie is gone, and it no longer runs',
          back.ran === 0 && !/_ga=/.test(back.cookies) && !back.box, JSON.stringify(back));

        await v.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
        await v.evaluate(() => localStorage.removeItem('boxlet-consent'));
        await v.goto(`${BASE}/about`, { waitUntil: 'networkidle0' });
        const phone = await v.evaluate(() => {
          const r = document.querySelector('[data-consent]').getBoundingClientRect();
          return { left: Math.round(r.left), right: Math.round(r.right), width: window.innerWidth, sideways: document.documentElement.scrollWidth > window.innerWidth };
        });
        await report.shot(v, '03-phone', { fullPage: false });
        report.verdict('on a phone the box keeps inside the screen', phone.left >= 0 && phone.right <= phone.width && !phone.sideways, JSON.stringify(phone));
      } finally {
        await visitor.browser.close();
      }
    } finally {
      if (id !== null) {
        await page.goto(`${BASE}/admin/scripts`, { waitUntil: 'networkidle2' });
        await page.$eval(`form[action$="/scripts/${id}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/scripts/${id}/delete"] button`).catch(() => {});
        const left = await page.$(`form[action$="/scripts/${id}/delete"]`);
        report.verdict('the scenario\'s script is gone again', left === null, left === null ? '' : `script ${id} still listed`);
      }
    }
  },
};
