/*
 * Slice 5a: the site settings screen (PLAN.md D-028).
 *
 * What only a browser can answer: that the screen is reachable from the navigation rather
 * than by typing its address, that the three picture pickers are VISIBLE AT REST rather
 * than a hidden select with nothing in its place (CLAUDE.md: no control is ever invisible
 * at rest — media-picker.js replaces the select, and if that fails the field is gone), and
 * that saving the site name changes the admin bar, which is the one effect the owner sees
 * immediately.
 *
 * IT PUTS BOTH BACK. The maintenance switch closes this copy to visitors and the site name
 * shows in every later screenshot, so the scenario restores each one — the maintenance one
 * in a finally, because a scenario that throws halfway must not leave the copy shut for
 * every check that runs after it.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, submitVia, alerts, controlsOnPanels, retype } from '../harness.mjs';

const field = (page, selector) => page.$eval(selector, (el) => el.value).catch(() => null);

/*
 * retype() came from here. This scenario learned the triple-click lesson the hard way — the
 * marker was appended and the site saved as "Checklist SiteZz Settings z5byo" — and wrote
 * the fix down locally. Two other scenarios then wrote their own copies of the same fix,
 * and 04-builder made the original mistake again anyway. Three copies of one lesson is how
 * it comes back, so it is one function in the harness now (D-029).
 */

export default {
  name: 'settings',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('settings: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- reachable from the navigation, not only by its address ---------------------------
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const link = await page.$('.admin-nav a[href$="/admin/settings"]');
    report.verdict('the navigation offers Settings', link !== null,
      link === null ? 'no link to /admin/settings in the admin bar' : 'the admin bar links to it');

    if (link === null) { return; }
    await clickAndWait(page, '.admin-nav a[href$="/admin/settings"]');
    report.verdict('the link opens the settings screen', page.url().includes('/admin/settings'),
      `landed at ${page.url()}`);

    // ---- the pickers are controls, not hidden selects --------------------------------------
    // media-picker.js replaces each <select data-media-field> with a thumbnail, a name and a
    // verb. If it does not run, the select stays — which is also a control. What must never
    // happen is neither: a hidden select with no replacement is a field nobody can see.
    const pickers = await page.$$eval('[data-media-field]', (els) => els.map((el) => {
      const box = el.getBoundingClientRect();
      const replaced = el.parentElement?.querySelector('.media-picker-name, .media-picker-empty');
      const shown = replaced ? replaced.getBoundingClientRect() : null;
      return {
        name: el.name,
        selectVisible: box.width > 0 && box.height > 0,
        replacementVisible: shown !== null && shown.width > 0 && shown.height > 0,
      };
    }));

    const invisible = pickers.filter((p) => !p.selectVisible && !p.replacementVisible);
    report.verdict('every picture picker is visible at rest', pickers.length === 3 && invisible.length === 0,
      `${pickers.length} pickers (${pickers.map((p) => p.name).join(', ')})`
      + (invisible.length ? `; INVISIBLE: ${invisible.map((p) => p.name).join(', ')}` : '; all shown'));

    // Judged AFTER the pickers have been replaced: media-picker.js swaps each select for a
    // thumbnail, so a guard that ran first would grade a half-drawn screen.
    await controlsOnPanels(page, report, 'settings');

    await report.shot(page, '01-settings');

    // ---- saving the name changes the admin bar ---------------------------------------------
    const was = await field(page, 'input[name="site_name"]');
    const marker = `Zz Settings ${Date.now().toString(36).slice(-5)}`;
    await retype(page, 'input[name="site_name"]', marker);
    await submitVia(page, 'input[name="site_name"]', 40000);

    const said = await alerts(page);
    const brand = await page.$eval('.admin-brand', (el) => el.textContent.trim()).catch(() => '(none)');
    report.verdict('saving the site name changes the admin bar', brand === marker,
      `the bar says ${JSON.stringify(brand)}`
      + (said.length ? `; the save was REFUSED: ${JSON.stringify(said)}` : ''));
    await report.shot(page, '02-saved');

    // Put the name back, so later scenarios and screenshots do not carry this one's marker.
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    await retype(page, 'input[name="site_name"]', was ?? '');
    await submitVia(page, 'input[name="site_name"]', 40000);

    // EQUALS what it was, not merely "different from the marker". The weaker version
    // passed while the name was left as the old one with the marker stuck on the end:
    // a restore check that cannot see a mangled value is worse than none, because it
    // reports the copy as clean.
    const restored = await page.$eval('.admin-brand', (el) => el.textContent.trim()).catch(() => '');
    report.verdict('the scenario puts the site name back', restored === (was ?? ''),
      `the bar says ${JSON.stringify(restored)}, it was ${JSON.stringify(was)}`);

    // ---- the maintenance switch, which moved here from the dashboard ------------------------
    try {
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      const before = await page.$eval('form[action$="/admin/maintenance"] button', (b) => b.textContent.trim());
      await clickAndWait(page, 'form[action$="/admin/maintenance"] button', 40000);

      const back = page.url().includes('/admin/settings');
      const after = await page.$eval('form[action$="/admin/maintenance"] button', (b) => b.textContent.trim());
      report.verdict('the maintenance switch is here and comes back here', back && after !== before,
        `landed at ${page.url()}; the button went from ${JSON.stringify(before)} to ${JSON.stringify(after)}`);

      // And the dashboard no longer offers it: the point of the move was one place, not two.
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
      const onDashboard = await page.$$eval('form[action$="/admin/maintenance"]', (els) => els.length);
      report.verdict('the dashboard no longer carries the switch', onDashboard === 0,
        `${onDashboard} maintenance forms on the dashboard`);
    } finally {
      // Whatever happened above, this copy must be open when the scenario ends.
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      const button = await page.$eval('form[action$="/admin/maintenance"] input[name="state"]', (i) => i.value)
        .catch(() => null);
      if (button === 'off') {
        await clickAndWait(page, 'form[action$="/admin/maintenance"] button', 40000);
      }
      const state = await page.$eval('form[action$="/admin/maintenance"] input[name="state"]', (i) => i.value)
        .catch(() => '(unknown)');
      report.verdict('the scenario leaves the site open', state === 'on',
        `the switch now offers to turn maintenance ${state}`);
    }
  },
};
