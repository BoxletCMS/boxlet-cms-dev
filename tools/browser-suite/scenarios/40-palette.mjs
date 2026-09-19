/*
 * The ⌘K palette (PLAN.md D-052): Ctrl+K opens it with the field focused, typing finds a
 * setting by its name, the arrows and Enter take you to its field, Escape closes; the rail's
 * Search opens it too, and without a script is a link to the Search screen.
 *
 * Reads only: nothing is saved.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'palette',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('palette: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });

    await page.keyboard.down('Control');
    await page.keyboard.press('k');
    await page.keyboard.up('Control');
    await wait(150);
    const opened = await page.evaluate(() => ({
      open: document.querySelector('[data-palette]').open,
      focused: document.activeElement && document.activeElement.hasAttribute('data-palette-input'),
    }));
    report.verdict('Ctrl+K opens the palette with its field focused', opened.open && opened.focused, JSON.stringify(opened));

    // Typed, not set: through real key events, as a person does.
    await page.keyboard.type('time zone', { delay: 40 });
    await page.waitForSelector('[data-palette-results] .search-result', { timeout: 5000 }).catch(() => null);
    await wait(300);
    const first = await page.$eval('[data-palette-results] .search-result', (a) => ({
      text: a.textContent.replace(/\s+/g, ' ').trim(),
      selected: a.getAttribute('aria-selected'),
    })).catch(() => null);
    await report.shot(page, '01-time-zone', { fullPage: false });
    report.verdict('typing "time zone" finds the setting first, chosen', first !== null && /Time zone/.test(first.text) && first.selected === 'true',
      JSON.stringify(first));

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowUp');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => null), page.keyboard.press('Enter')]);
    report.verdict('Enter goes to the field', page.url().endsWith('/admin/settings#timezone'), page.url());

    await page.click('[data-palette-open]');
    await wait(150);
    const byRail = await page.$eval('[data-palette]', (d) => d.open);
    await page.keyboard.press('Escape');
    await wait(150);
    const closed = await page.$eval('[data-palette]', (d) => !d.open);
    report.verdict('the rail\'s Search opens it and Escape closes it', byRail && closed, `opened ${byRail}, closed ${closed}`);

    const href = await page.$eval('[data-palette-open]', (a) => a.getAttribute('href'));
    report.verdict('without a script the rail\'s Search is a link to the Search screen', /\/admin\/search$/.test(href), href);
  },
};
