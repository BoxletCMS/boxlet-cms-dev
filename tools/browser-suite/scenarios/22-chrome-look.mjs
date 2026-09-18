/*
 * The chrome's look under every character, the mobile menu and a submenu (PLAN.md D-032,
 * D-036).
 *
 * The PHP tests prove the classes reach the page. Only a browser shows whether a sticky
 * header stays up, a transparent one is readable over the hero it lies on, and the menu
 * folds under one button on a phone and opens again — so this photographs each character
 * at two widths and drives the buttons with real clicks.
 *
 * COPY ONLY: applying a character rewrites the site's whole design, which on the
 * development site nobody could put back (the owner's own colours are not a preset).
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, applyCharacter, clickAndWait } from '../harness.mjs';

const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const CHILD = 'Zz child';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The first menu the chrome uses gets one child item, so there is a submenu to open. */
async function ensureChild(page, report) {
  await page.goto(`${BASE}/admin/chrome`, { waitUntil: 'networkidle2' });
  const menuName = await page.$eval('#header_menu', (select) => select.value).catch(() => '');
  if (menuName === '') {
    report.fail('chrome look: its test data', 'the header shows no menu, so there is nothing to fold');
    return false;
  }
  await page.goto(`${BASE}/admin/menus`, { waitUntil: 'networkidle2' });
  const href = await page.evaluate((name) => {
    const link = Array.from(document.querySelectorAll('.row-title a')).find((a) => a.textContent.trim() === name);
    return link ? link.getAttribute('href') : null;
  }, menuName);
  if (href === null) {
    report.fail('chrome look: its test data', `no menu called ${menuName}`);
    return false;
  }
  await page.goto(`${BASE}${href}`, { waitUntil: 'networkidle2' });
  const has = await page.evaluate((label) => document.body.textContent.includes(label), CHILD);
  if (!has) {
    const parent = await page.$$eval('#item-parent option', (options) => options.map((o) => o.value).find((v) => v !== ''));
    await page.type('#item-url', '/#child');
    await page.type('#item-label', CHILD);
    await page.select('#item-parent', parent);
    await clickAndWait(page, 'form[action$="/items"] button[type="submit"]');
  }
  return true;
}

export default {
  name: 'chrome-look',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('chrome look: log in', `could not log in; at ${page.url()}`);
      return;
    }
    if (!await ensureChild(page, report)) {
      return;
    }

    for (const character of CHARACTERS) {
      await applyCharacter(page, character);

      await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      await report.shot(page, `${character}-desktop`, { fullPage: false });

      // A submenu opens from its own button, and only then.
      const more = await page.$('[data-site-nav-more]');
      if (more === null) {
        report.fail(`${character}: a submenu has a button`, 'no submenu button on a menu with a child');
      } else {
        const before = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        await more.click();
        await wait(150);
        const after = await page.$eval('.site-nav-children', (list) => getComputedStyle(list).display);
        const expanded = await page.$eval('[data-site-nav-more]', (button) => button.getAttribute('aria-expanded'));
        await report.shot(page, `${character}-submenu`, { fullPage: false });
        report.verdict(`${character}: a submenu opens from its button`,
          before === 'none' && after !== 'none' && expanded === 'true',
          `before ${before}, after ${after}, aria-expanded ${expanded}`);
        await page.keyboard.press('Escape');
      }

      // A sticky header is still on screen after scrolling.
      if (character === 'soft') {
        await page.evaluate(() => window.scrollTo(0, 1200));
        await wait(150);
        const top = await page.$eval('header.block-header', (header) => header.getBoundingClientRect().top);
        await report.shot(page, 'soft-scrolled', { fullPage: false });
        report.verdict('soft: the sticky header stays at the top', Math.abs(top) < 2, `header top at ${top}px`);
      }

      // On a phone the navigation folds under one button and opens from it.
      await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const folded = await page.$eval('.site-nav', (nav) => getComputedStyle(nav).display);
      // A boxed page's frame once took 144px of this screen and left the header 150px.
      const room = await page.$eval('.site-header', (header) => header.getBoundingClientRect().width);
      report.verdict(`${character}: on a phone the header has the width of the screen to use`,
        room >= 390 * 0.65, `${Math.round(room)}px of 390`);
      await report.shot(page, `${character}-phone`, { fullPage: false });
      const toggle = await page.$('[data-site-nav-toggle]');
      const shown = toggle ? await toggle.boundingBox() : null;
      if (toggle === null || shown === null) {
        report.fail(`${character}: the phone menu has a button`, 'no visible Menu button');
        continue;
      }
      await toggle.click();
      await wait(150);
      const opened = await page.$eval('.site-nav', (nav) => getComputedStyle(nav).display);
      await report.shot(page, `${character}-phone-open`, { fullPage: false });
      report.verdict(`${character}: on a phone the menu folds under a button and opens from it`,
        folded === 'none' && opened !== 'none',
        `folded ${folded}, opened ${opened}`);
    }
  },
};
