/*
 * THE MAPS' DOWNLOAD SHOWS HOW FAR IT IS, WHERE ITS BUTTONS ARE (PLAN.md D-237, the owner): the
 * city database fetched from DB-IP, as the owner fetches it, through a real press.
 *
 *   - no page is loaded while it comes down (the owner counted several refreshes);
 *   - the bar and the words beside the buttons move: "Fetching the city database: x of y";
 *   - Continue is not offered while the script carries on by itself;
 *   - at the end the panel says it is in use, there, and the sentence above changes;
 *   - then the country database the same way, with its steps cut off on the way: tried again,
 *     then said, Continue offered, and Continue finishes it. That leaves the copy as the suite
 *     expects it.
 *
 * ON THE COPY. Reaches DB-IP (57 MB): NOT CHECKABLE where the server cannot.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'geo-progress',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('geo-progress: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/statistics/settings#geo`, { waitUntil: 'networkidle2' });
    let loads = 0;
    page.on('load', () => { loads += 1; });

    const panel = () => page.evaluate(() => {
      const root = document.querySelector('[data-geo]');
      const bar = root.querySelector('[data-geo-bar]');
      const notice = root.querySelector('[data-geo-notice]');
      return {
        shown: !root.querySelector('[data-geo-progress]').hidden,
        value: bar.hasAttribute('value') ? Number(bar.value) : null,
        max: Number(bar.max),
        text: root.querySelector('[data-geo-text]').textContent.trim(),
        continueShown: !root.querySelector('[data-geo-busy]').hidden && !root.querySelector('[data-geo-continue]').hidden,
        inUse: root.querySelector('[data-geo-in-use]').textContent.trim(),
        notice: notice && !notice.hidden ? { error: notice.classList.contains('notice-error'), text: notice.textContent.trim() } : null,
      };
    });

    await page.$eval('form[action$="/statistics/cities"]', (f) => f.scrollIntoView({ block: 'center' }));
    await page.click('form[action$="/statistics/cities"] button');
    const seen = [];
    const texts = [];
    const offered = [];
    let shot = false;
    for (let i = 0; i < 600; i++) {
      await wait(500);
      const now = await panel();
      if (now.shown && now.value !== null) {
        seen.push(now.value);
        texts.push(now.text);
        offered.push(now.continueShown);
        if (!shot && now.value > 0) {
          await report.shot(page, '01-downloading', { fullPage: false });
          shot = true;
        }
      }
      if (now.notice) {
        break;
      }
    }
    const city = await panel();
    await report.shot(page, '02-installed', { fullPage: false });
    if (city.notice && city.notice.error && /could not be downloaded|HTTP|resolve|connect/i.test(city.notice.text)) {
      report.skip('the city database from DB-IP', city.notice.text);
      return;
    }
    const rising = seen.length > 1 && seen.some((v, i) => i > 0 && v > seen[i - 1]);
    report.verdict('it comes down without a page loaded, the bar rising, the words saying how far',
      loads === 0 && rising && texts.some((t) => /Fetching the city database: .+ of .+/.test(t)),
      JSON.stringify({ loads, steps: seen.length, first: seen[0], last: seen[seen.length - 1], words: texts.slice(-2) }));
    report.verdict('Continue is not offered while it carries on by itself', offered.length > 0 && offered.every((o) => !o), JSON.stringify(offered));
    report.verdict('at the end it says so beside the buttons, and the sentence above changes',
      !!city.notice && !city.notice.error && /City database in use|It knows cities/.test(city.inUse) && !city.shown,
      JSON.stringify(city));

    // The country database, its steps cut off on the way: what a dropped line does.
    await page.setRequestInterception(true);
    let cut = true;
    const onRequest = (request) => {
      if (cut && request.method() === 'POST' && request.url().endsWith('/statistics/cities/step')) {
        request.abort('connectionreset');
      } else {
        request.continue();
      }
    };
    page.on('request', onRequest);
    await page.click('form[action$="/statistics/countries"] button');
    await page.waitForFunction(() => {
      const notice = document.querySelector('[data-geo-notice]');
      return notice && !notice.hidden && notice.classList.contains('notice-error');
    }, { timeout: 60000 }).catch(() => {});
    const lost = await panel();
    await report.shot(page, '03-connection-lost', { fullPage: false });
    report.verdict('a line that drops is tried again, then said, and Continue offered', !!lost.notice && lost.notice.error && /connection/i.test(lost.notice.text) && lost.continueShown, JSON.stringify(lost));

    cut = false;
    await page.click('[data-geo-continue] button');
    await page.waitForFunction(() => /It knows countries/.test(document.querySelector('[data-geo-in-use]').textContent), { timeout: 180000 }).catch(() => {});
    page.off('request', onRequest);
    await page.setRequestInterception(false);
    const country = await panel();
    report.verdict('Continue carries on from there and the country database is in use again', loads === 0 && /It knows countries/.test(country.inUse) && !!country.notice && !country.notice.error, JSON.stringify({ loads, country }));
  },
};
