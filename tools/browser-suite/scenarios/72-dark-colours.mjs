/*
 * THE OWNER'S COLOURS IN DARK MODE (PLAN.md D-187, the owner's model). On Appearance › Colours,
 * with Mode on Dark, each palette role shows its dark row: a colour picked there is for dark
 * mode only, says "only in dark", and the preview draws it; light mode keeps its own; "Use light
 * value" gives it up.
 *
 * On the copy. Nothing is published: the screen is put back with Reset all at the end.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, openSection, clickAndWait } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const PICKED = '#f5e9d0';

/** What the rows show and what the preview draws. */
function seen(page) {
  return page.evaluate(() => {
    const shown = (sel) => { const el = document.querySelector(sel); return !!el && getComputedStyle(el).display !== 'none'; };
    const frame = document.querySelector('iframe[data-design-preview]');
    const inside = frame ? frame.contentDocument : null;
    const darkRow = document.querySelector('.role-dark[data-control="dark_color_text"]');
    return {
      mode: (document.querySelector('input[name="mode"]:checked') || {}).value,
      darkRow: shown('.role-dark[data-control="dark_color_text"]'),
      lightRow: shown('.roles-palette > .role[data-control="color_text"]'),
      only: !!darkRow && getComputedStyle(darkRow.querySelector('.role-only-dark')).display !== 'none',
      darkSwitch: (document.querySelector('[data-by-hand-switch="dark_color_text"]') || {}).checked,
      lightSwitch: (document.querySelector('[data-by-hand-switch="color_text"]') || {}).checked,
      light: (document.querySelector('#design-color_text') || {}).value,
      text: inside ? getComputedStyle(inside.documentElement).getPropertyValue('--color-text').trim().toLowerCase() : '',
    };
  });
}

async function setMode(page, mode) {
  await page.evaluate((m) => {
    const radio = document.querySelector(`#design-form input[name="mode"][value="${m}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
  }, mode);
  await wait(2500);
}

export default {
  name: 'dark-colours',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('dark colours: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
    await openSection(page, 'colours');
    try {
      await setMode(page, 'dark');
      const dark = await seen(page);
      report.verdict('Mode on Dark: each palette role shows its dark row instead of its light one', dark.mode === 'dark' && dark.darkRow && !dark.lightRow, JSON.stringify(dark));

      await page.$eval('#design-dark_color_text', (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }, PICKED);
      await page.waitForFunction((v) => {
        const inside = document.querySelector('iframe[data-design-preview]').contentDocument;
        return inside && getComputedStyle(inside.documentElement).getPropertyValue('--color-text').trim().toLowerCase() === v;
      }, { timeout: 15000 }, PICKED).catch(() => {});
      const picked = await seen(page);
      await report.shot(page, '01-dark-text-picked', { fullPage: false });
      report.verdict('a colour picked in Dark is the owner\'s for dark only, says so, and the preview draws it',
        picked.darkSwitch && picked.only && picked.text === PICKED && !picked.lightSwitch, JSON.stringify(picked));

      await setMode(page, 'light');
      const light = await seen(page);
      await report.shot(page, '02-light-keeps-its-own', { fullPage: false });
      report.verdict('back in Light, the light row is shown, untouched, and the preview draws no dark colour',
        light.lightRow && !light.darkRow && !light.lightSwitch && light.text !== PICKED && light.light !== PICKED, JSON.stringify(light));

      await setMode(page, 'dark');
      await clickAndWait(page, 'button[form="design-form"][name="action"][value="colour:light:text"]');
      await openSection(page, 'colours');
      await wait(1500);
      const given = await seen(page);
      report.verdict('"Use light value" gives the dark colour up: dark mode draws without it',
        given.mode === 'dark' && given.darkSwitch === false && !given.only && given.text !== PICKED, JSON.stringify(given));
    } finally {
      // Nothing published: the screen put back as the site has it.
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' }).catch(() => {});
    }
  },
};
