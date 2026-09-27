/*
 * A ROW'S MENU FLOATS OVER THE PAGE (the owner, 2026-09-27): the "…" at the end of a row in
 * Pages and Media opened inside its table, whose scroll box clipped it and grew a scrollbar.
 * row-menu.js places the list against the window: below its button, or above it when
 * there is no room below — the maintenance bar counting as the bottom where it lies there.
 *
 * READ ONLY, on the development site: it opens menus and presses nothing in them.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The open menu's list: where it is, whether every item in it is on top, and its table. */
const measure = (page) => page.evaluate(() => {
  const menu = document.querySelector('details[data-menu][open]');
  if (!menu) return null;
  const list = menu.querySelector('.row-menu-list');
  const button = menu.querySelector('summary').getBoundingClientRect();
  const box = list.getBoundingClientRect();
  const wrap = menu.closest('.table-wrap');
  const items = [...list.querySelectorAll('a, button')];
  return {
    onTop: items.length > 0 && items.every((item) => {
      const r = item.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return hit !== null && item.contains(hit);
    }),
    tableScrolls: wrap ? wrap.scrollHeight > wrap.clientHeight : false,
    above: box.bottom <= button.top,
    inWindow: box.top >= 0 && box.bottom <= innerHeight,
  };
});

export default {
  name: 'row-menu',

  async run({ page, report }) {
    await page.setViewport({ width: 1400, height: 640 });
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('row-menu: log in', `could not log in as ${ADMIN.email}`);
      return;
    }

    // The last row of the page list: the case the owner met.
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const rows = await page.$$('details[data-menu] > summary');
    const last = rows[rows.length - 1];
    await last.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await pause(200);
    await last.click();
    await pause(300);
    const pages = await measure(page);
    await report.shot(page, '01-pages-last-row', { fullPage: false });
    report.verdict('the last page\'s menu opens whole, over the page, and its table grows no scrollbar',
      pages !== null && pages.onTop && pages.inWindow && !pages.tableScrolls, JSON.stringify(pages));

    // A row near the bottom of the window opens its menu upward.
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const buttons = await page.$$('details[data-menu] > summary');
    const low = buttons[Math.min(6, buttons.length - 1)];
    await low.evaluate((el) => { const r = el.getBoundingClientRect(); window.scrollBy(0, r.bottom - (innerHeight - 90)); });
    await pause(300);
    await low.click();
    await pause(300);
    const media = await measure(page);
    await report.shot(page, '02-media-near-the-bottom', { fullPage: false });
    report.verdict('near the bottom it opens upward, whole', media !== null && media.onTop && media.above && media.inWindow, JSON.stringify(media));

    // It is left behind by nothing: scrolling closes it.
    await page.evaluate(() => window.scrollBy(0, -40));
    await pause(300);
    const open = await page.evaluate(() => document.querySelectorAll('details[data-menu][open]').length);
    report.verdict('scrolling the page closes it', open === 0, `${open} open`);
  },
};
