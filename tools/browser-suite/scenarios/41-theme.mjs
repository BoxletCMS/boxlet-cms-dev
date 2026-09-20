/*
 * Light, dark, or the machine's own (PLAN.md D-054): the switch in the strip changes the
 * palette and the next page keeps it, the server draws the right one before any script
 * runs, and every screen is shot in both so the second palette cannot rot unseen.
 *
 * Changes nothing on the site: the choice is a cookie in this headless browser alone, and
 * the scenario puts it back to dark before it ends.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

/** The screens worth seeing in both palettes, and what each is called in a file name. */
const SCREENS = [
  ['', 'overview'],
  ['/pages', 'pages'],
  ['/media', 'media'],
  ['/settings', 'settings'],
  ['/statistics', 'statistics'],
  ['/design', 'design'],
];

/** The body's own ground and ink, as the browser resolves them. */
const painted = (page) => page.evaluate(() => {
  const style = getComputedStyle(document.body);
  return {
    theme: document.body.getAttribute('data-ui-theme'),
    background: style.backgroundColor,
    color: style.color,
    scheme: style.colorScheme,
  };
});

/** Every screen in SCREENS, plus the world map, which is below the fold on Statistics. */
async function screens(page, report, theme) {
  for (const [path, name] of SCREENS) {
    await page.goto(`${BASE}/admin${path}`, { waitUntil: 'networkidle2' });
    await report.shot(page, `${theme}-${name}`, { fullPage: false });
  }
  await page.goto(`${BASE}/admin/statistics`, { waitUntil: 'networkidle2' });
  const map = await page.$('.stats-map');
  if (map === null) {
    report.fail(`theme: the map in ${theme}`, 'the Statistics screen has no world map to look at');
    return;
  }
  await map.scrollIntoView();
  await report.shot(page, `${theme}-map`, { fullPage: false });
}

export default {
  name: 'theme',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('theme: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });

    const dark = await painted(page);
    report.verdict('the admin opens dark, as it shipped', dark.theme === 'dark' && dark.background === 'rgb(22, 24, 38)', JSON.stringify(dark));

    // Scoped to the switch's own form. An unscoped button[type=submit] in the admin header
    // is Log out, which ends the session and every later step then meets the login screen.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('.theme-switch button[value="light"]'),
    ]);
    const light = await painted(page);
    report.verdict('pressing Light repaints the admin on paper',
      light.theme === 'light' && light.background === 'rgb(243, 241, 236)' && light.scheme === 'light',
      JSON.stringify(light));

    const pressed = await page.$eval('.theme-switch button[aria-pressed="true"]', (b) => b.value);
    report.verdict('the switch says which palette is current', pressed === 'light', `aria-pressed="true" on ${pressed}`);

    await screens(page, report, 'light');

    // A phone: the strip now carries three more buttons, and the one thing that must not
    // happen is the page scrolling sideways because of them.
    await page.setViewport({ width: 390, height: 800, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const phone = await page.evaluate(() => ({
      wide: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      switchShown: getComputedStyle(document.querySelector('.theme-switch')).display !== 'none',
    }));
    report.verdict('on a phone the switch fits without pushing the page sideways',
      !phone.wide && phone.switchShown, JSON.stringify(phone));
    await report.shot(page, 'light-phone', { fullPage: false });
    await page.setViewport({ width: 1400, height: 900, deviceScaleFactor: 1 });

    // Drawn by the server: the palette is right at DOMContentLoaded, before any script.
    await page.goto(`${BASE}/admin/pages`, { waitUntil: 'domcontentloaded' });
    const early = await painted(page);
    report.verdict('the next page is already on paper before any script runs', early.theme === 'light', JSON.stringify(early));

    // The login screen keeps it too: being logged out is no reason for the colours to jump.
    // Really logged out, not merely asked for /admin/login — which, while the session is
    // alive, redirects to the dashboard and would have this verdict measure that instead.
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('.admin-logout button[type="submit"]'),
    ]);
    const loginScreen = await painted(page);
    const field = await page.$('input[name="email"]');
    report.verdict('the login screen keeps the chosen palette',
      loginScreen.theme === 'light' && field !== null,
      `${JSON.stringify(loginScreen)}, email field ${field === null ? 'missing' : 'present'}`);
    await report.shot(page, 'light-login', { fullPage: false });

    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('theme: log in again', 'the login screen did not let us back in');
      return;
    }

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('.theme-switch button[value="dark"]'),
    ]);
    const back = await painted(page);
    report.verdict('pressing Dark puts it back', back.theme === 'dark' && back.background === 'rgb(22, 24, 38)', JSON.stringify(back));

    await screens(page, report, 'dark');
  },
};
