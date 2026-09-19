/*
 * The rail (PLAN.md D-052): folded to its icons and opened again with the toggle on its
 * edge, the choice remembered for the next page and drawn by the server before any script
 * runs; the page editor opening folded whatever was chosen; icons alone under 1000px; a
 * drawer on a phone.
 *
 * Changes nothing on the site: the choice is a cookie in this headless browser alone.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'rail',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('rail: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    const width = () => page.$eval('[data-admin-rail]', (r) => Math.round(r.getBoundingClientRect().width));

    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const open = await width();
    await page.click('[data-rail-toggle]');
    const folded = await width();
    report.verdict('the toggle folds the rail to its icons', open > 150 && folded < 70, `${open}px, then ${folded}px`);
    await report.shot(page, '01-folded', { fullPage: false });

    await page.goto(`${BASE}/admin/media`, { waitUntil: 'domcontentloaded' });
    const next = await width();
    report.verdict('the next page is drawn folded before any script runs', next < 70, `${next}px at DOMContentLoaded`);

    await page.click('[data-rail-toggle]');
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
    const reopened = await width();
    report.verdict('opened again, it stays open', reopened > 150, `${reopened}px`);

    // A page's own address, /admin/pages/{id}: not New page, which is /admin/pages/new.
    const home = await page.$$eval('a[href*="/admin/pages/"]', (links) => links.map((a) => a.getAttribute('href')).find((h) => /\/admin\/pages\/\d+$/.test(h)) ?? null);
    if (home === null) {
      report.fail('rail: its test data', 'no page to open in the editor');
      return;
    }
    await page.goto(`${BASE}${home}`, { waitUntil: 'networkidle2' });
    const editor = await width();
    report.verdict('the page editor opens with the rail folded', editor < 70, `${editor}px`);
    await report.shot(page, '02-editor', { fullPage: false });

    await page.setViewport({ width: 900, height: 700, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    await page.click('[data-rail-toggle]');
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const mid = await width();
    report.verdict('under 1000px it keeps its icons once folded there', mid < 70, `${mid}px`);

    await page.setViewport({ width: 390, height: 800, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const toggleShown = await page.$eval('[data-rail-toggle]', (b) => getComputedStyle(b).display !== 'none');
    await page.click('[data-admin-nav-toggle]');
    const drawer = await page.$eval('[data-admin-rail]', (r) => getComputedStyle(r).position + ' ' + Math.round(r.getBoundingClientRect().width));
    report.verdict('on a phone the rail is a drawer and the edge toggle is gone', !toggleShown && drawer.startsWith('fixed'), `edge toggle shown=${toggleShown}, rail ${drawer}`);
    await report.shot(page, '03-phone-drawer', { fullPage: false });

    // Left open, as it started.
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    if (await width() < 70) {
      await page.click('[data-rail-toggle]');
    }
  },
};
