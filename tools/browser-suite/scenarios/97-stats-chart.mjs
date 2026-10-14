/*
 * THE TREND CHART, READ POINT BY POINT (PLAN.md D-266, the owner).
 *
 * Over the last 30 days' chart: the pointer at a third of the way across brings a line, a dot on
 * each line and a window naming that day with its visitors and views, the same figures the table
 * under the chart has for that day; leaving the chart puts them away. From the keyboard, the
 * chart takes focus and the right arrow shows the first day.
 *
 * ON THE COPY, which prepare-copy.php gives visits in the last month. It only reads.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'stats-chart',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('stats-chart: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/statistics?period=30d`, { waitUntil: 'networkidle2' });
    const area = await page.$('.stats-chart-area');
    if (!area) {
      report.fail('stats-chart: the chart', 'no chart on the statistics screen');
      return;
    }
    await page.$eval('.stats-trend', (el) => el.scrollIntoView({ block: 'center' }));
    const box = await area.boundingBox();
    await page.mouse.move(box.x + box.width / 3, box.y + box.height / 2, { steps: 5 });
    const shown = await page.evaluate(() => {
      const tip = document.querySelector('.stats-chart-tip');
      const cursor = document.querySelector('.stats-chart-cursor');
      return {
        cursor: cursor && !cursor.hidden,
        dots: [...document.querySelectorAll('.stats-chart-dot')].every((d) => !d.hidden),
        day: tip && !tip.hidden ? tip.querySelector('.stats-chart-tip-day')?.textContent : null,
        figures: tip ? [...tip.querySelectorAll('strong')].map((s) => s.textContent) : [],
        titles: document.querySelectorAll('.stats-chart-area title').length,
      };
    });
    // The table's row for the same day.
    const row = await page.evaluate((day) => {
      const found = [...document.querySelectorAll('.stats-numbers tbody tr')].find((tr) => tr.querySelector('th').textContent.trim() === day);
      return found ? [...found.querySelectorAll('td')].map((td) => td.textContent.trim()) : null;
    }, shown.day || '');
    await report.shot(page, '01-hover', { fullPage: false });
    report.verdict('the pointer brings a line, two dots and a window with the day\'s figures, the table\'s own',
      shown.cursor && shown.dots && shown.day !== null && row !== null && JSON.stringify(row) === JSON.stringify(shown.figures) && shown.titles === 0,
      JSON.stringify({ shown, row }));

    await page.mouse.move(box.x + box.width / 2, box.y - 120);
    const gone = await page.$eval('.stats-chart-tip', (tip) => tip.hidden);
    report.verdict('leaving the chart puts them away', gone === true, '');

    await page.focus('.stats-chart-area');
    await page.keyboard.press('ArrowRight');
    const first = await page.evaluate(() => ({
      day: document.querySelector('.stats-chart-tip-day')?.textContent,
      table: document.querySelector('.stats-numbers tbody tr th')?.textContent.trim(),
      hidden: document.querySelector('.stats-chart-tip').hidden,
    }));
    await report.shot(page, '02-keyboard', { fullPage: false });
    report.verdict('focused, the right arrow shows the first day', !first.hidden && first.day === first.table, JSON.stringify(first));
  },
};
