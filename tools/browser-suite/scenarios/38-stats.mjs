/*
 * Visit statistics (PLAN.md D-051): a visitor's view reaching the Statistics screen, the
 * screen and the dashboard card on a desktop and a phone, and the panel in Settings.
 *
 * It writes one thing, the way a visitor would: one view of the home page, from a browser
 * no one else uses, so it is a new visitor. That adds one to today's counts, which is what
 * the check reads. Nothing is saved in Settings and "Delete all statistics" is never
 * pressed — the counts on the development site are real views, and removing them is the
 * owner's choice.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'stats',

  async run({ page, report, browser }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('stats: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    // Today's visitors, as the screen shows them, before and after one visitor's view.
    const todayVisitors = async () => {
      await page.goto(`${BASE}/admin/statistics?period=today`, { waitUntil: 'networkidle2' });
      return page.$eval('.stats-figure .stat-value', (el) => Number(el.textContent.replace(/[^0-9]/g, '')));
    };
    const before = await todayVisitors();
    const visitor = await browser.createBrowserContext();
    const tab = await visitor.newPage();
    // A real browser's User-Agent (a headless one is a program and not counted), made
    // unique so the view is a new visitor's.
    await tab.setUserAgent(`Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0 suite-${Date.now()}`);
    const visit = await tab.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const cookies = await tab.cookies();
    await visitor.close();
    report.verdict('a visitor gets the page and no cookie', visit.status() === 200 && cookies.length === 0,
      `status ${visit.status()}, cookies ${JSON.stringify(cookies.map((c) => c.name))}`);
    const after = await todayVisitors();
    report.verdict('one visitor\'s view adds one visitor to today', after === before + 1, `before ${before}, after ${after}`);
    const own = await todayVisitors();
    report.verdict('the admin looking at the screen is not counted', own === after, `after ${after}, then ${own}`);

    for (const [name, viewport] of [['03-screen-desktop', { width: 1400, height: 1000 }], ['04-screen-phone', { width: 390, height: 844 }]]) {
      await page.setViewport({ ...viewport, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/admin/statistics?period=30d`, { waitUntil: 'networkidle2' });
      await report.shot(page, name);
      const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      report.verdict(`${name}: the screen does not push the page sideways`, !sideways, `scrollWidth > width: ${sideways}`);
    }
    await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const card = await page.$('.stats-card');
    if (card) {
      await card.scrollIntoView();
    }
    await report.shot(page, '05-dashboard', { fullPage: false });
    report.verdict('the dashboard has the statistics card, and the bar a Statistics link',
      card !== null && await page.$('.admin-nav a[href$="/admin/statistics"]') !== null, `card ${card !== null}`);

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
