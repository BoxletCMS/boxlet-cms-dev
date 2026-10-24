/*
 * SETTINGS IN TABS (PLAN.md D-294; the owner, 2026-10-24: one long page of anchors was
 * confusing, the AI settings in three places). It replaces 87-settings-nav, which checked that
 * the list of anchors marked the section a link opened or the hand scrolled to (D-227): there is
 * no scrolling to mark now, each section being a page. What it checked in kind, here:
 *
 *   - each tab pressed opens its own address and is the one marked, by the press, not the scroll;
 *   - AI's three, the same;
 *   - an address from the one long page (#mail, #mcp, #geo…) lands on its tab, and Back from it
 *     goes where the owner was before, not to General;
 *   - Statistics' settings, now its tab, stand as wide as a settings tab's column;
 *   - on a phone the tabs are a row, and the page does not scroll sideways.
 *
 * ON THE COPY; it only reads.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const marked = (page, nav) => page.$eval(nav, (n) => {
  const on = n.querySelectorAll('a[aria-current="page"]');
  return on.length === 1 ? new URL(on[0].href).pathname : `${on.length} marked`;
});

export default {
  name: 'settings-tabs',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('settings-tabs: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    const tabs = await page.$$eval('[data-settings-nav] a', (links) => links.map((a) => new URL(a.href).pathname));
    const wrong = [];
    for (const path of tabs) {
      await clickAndWait(page, `[data-settings-nav] a[href$="${path}"]`);
      const at = new URL(page.url()).pathname;
      const now = await marked(page, '[data-settings-nav]');
      if (at !== path || now !== path) {
        wrong.push(`${path} → at ${at}, marked ${now}`);
      }
    }
    report.verdict('each tab pressed opens its own address and is the one marked',
      tabs.length === 6 && wrong.length === 0, JSON.stringify({ tabs, wrong }));

    await page.goto(`${BASE}/admin/settings/ai`, { waitUntil: 'networkidle2' });
    const ai = [];
    for (const path of ['/admin/settings/ai/access', '/admin/settings/ai/search', '/admin/settings/ai']) {
      await clickAndWait(page, `.settings-sections .tabs a[href$="${path}"]`);
      ai.push([new URL(page.url()).pathname, await marked(page, '.settings-sections .tabs'), await marked(page, '[data-settings-nav]')]);
    }
    await page.goto(`${BASE}/admin/settings/ai/access`, { waitUntil: 'networkidle2' });
    await report.shot(page, '01-ai-access', { fullPage: false });
    report.verdict('AI\'s three: each its address, marked, with AI marked in the list',
      ai.every(([at, sub, tab], i) => at === ['/admin/settings/ai/access', '/admin/settings/ai/search', '/admin/settings/ai'][i] && sub === at && tab === '/admin/settings/ai'),
      JSON.stringify(ai));

    const old = { mail: '/admin/settings/mail', mcp: '/admin/settings/ai/access', llms: '/admin/settings/ai/search', two_step: '/admin/settings/account', statistics: '/admin/statistics/settings', geo: '/admin/statistics/settings' };
    const landed = {};
    for (const [anchor, path] of Object.entries(old)) {
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      await page.goto(`${BASE}/admin/settings#${anchor.replace('_', '-')}`, { waitUntil: 'networkidle2' });
      await page.waitForFunction((p) => location.pathname === p, { timeout: 5000 }, path).catch(() => {});
      const at = new URL(page.url()).pathname;
      await page.goBack({ waitUntil: 'networkidle2' });
      landed[anchor] = [at === path, new URL(page.url()).pathname];
    }
    report.verdict('an address from the one long page lands on its tab, and Back goes to where the owner was, not General',
      Object.values(landed).every(([ok, back]) => ok && back === '/admin/pages'), JSON.stringify(landed));

    const widths = {};
    for (const path of ['/admin/settings/mail', '/admin/statistics/settings']) {
      await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
      widths[path] = await page.$eval('.settings-sections .panel', (p) => Math.round(p.getBoundingClientRect().width));
    }
    await page.goto(`${BASE}/admin/statistics/settings`, { waitUntil: 'networkidle2' });
    await report.shot(page, '02-statistics-settings', { fullPage: false });
    const [a, b] = Object.values(widths);
    report.verdict('Statistics\' settings stand as wide as a settings tab\'s column, within 2%',
      Math.abs(a - b) / a <= 0.02, JSON.stringify(widths));

    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/settings/ai/access`, { waitUntil: 'networkidle2' });
    const phone = await page.evaluate(() => {
      const nav = document.querySelector('[data-settings-nav]');
      return { sideways: document.documentElement.scrollWidth > innerWidth, row: getComputedStyle(nav).display, scrolls: nav.scrollWidth > nav.clientWidth };
    });
    await report.shot(page, '03-phone', { fullPage: false });
    report.verdict('on a phone the tabs are a row, and the page does not scroll sideways', !phone.sideways && phone.row === 'flex', JSON.stringify(phone));
  },
};
