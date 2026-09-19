/*
 * The Languages panel on the Settings screen (PLAN.md D-043).
 *
 * What a person does: add a language, see it in the list, switch it off and on, and remove
 * it again. Real clicks on the panel's own buttons.
 *
 * LEAVES THE SITE AS FOUND: the language it adds is one the site does not have (Italian,
 * or the first other free one), and it is removed at the end — which the panel allows
 * because nothing was written in it. If the site already has Italian, a different code is
 * used rather than touching the owner's.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';

const WANTED = ['it', 'pt', 'nl', 'sv'];

/** The rows of the language table: code, name and status words. */
const rows = (page) => page.$$eval('#languages tbody tr', (trs) => trs.map((tr) => ({
  code: (tr.querySelector('code') || {}).textContent || '',
  name: ((tr.querySelector('.language-name') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
  status: ((tr.querySelector('.status-toggle, .hint') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
})));

const flash = (page) => page.$$eval('[role="status"], [role="alert"], .flash, .notice', (els) => els.map((e) => e.textContent.trim()).filter(Boolean).join(' | ')).catch(() => '');

export default {
  name: 'languages',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('languages: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    const before = await rows(page);
    const offered = await page.$$eval('#language-code option', (options) => options.map((o) => o.value));
    const code = WANTED.find((c) => offered.includes(c));
    report.verdict('the panel lists the site\'s languages, the main one first', before.length > 0 && /Main language/.test(before[0].name),
      JSON.stringify(before));
    if (code === undefined) {
      report.fail('languages: a free language to try', `none of ${WANTED.join(', ')} is free`);
      return;
    }

    // ---- add --------------------------------------------------------------------------------
    await page.select('#language-code', code);
    await clickAndWait(page, 'form.language-add button[type="submit"]');
    const added = await rows(page);
    const row = added.find((r) => r.code === code);
    await page.$eval('#languages', (el) => el.scrollIntoView({ block: 'start' }));
    await report.shot(page, '01-added', { fullPage: false });
    report.verdict('an added language appears last, switched on', row !== undefined && added[added.length - 1].code === code && /^On/.test(row.status),
      `${await flash(page)} — ${JSON.stringify(row)}`);

    // ---- off and on again ---------------------------------------------------------------------
    await clickAndWait(page, `form[action$="/languages/${code}/enabled"] button`);
    const off = (await rows(page)).find((r) => r.code === code);
    await clickAndWait(page, `form[action$="/languages/${code}/enabled"] button`);
    const on = (await rows(page)).find((r) => r.code === code);
    report.verdict('its status switches it off and on again', off !== undefined && /^Off/.test(off.status) && on !== undefined && /^On/.test(on.status),
      `off: ${off && off.status}; on: ${on && on.status}`);

    // ---- on a phone ---------------------------------------------------------------------------
    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2 });
    await page.goto(`${BASE}/admin/settings#languages`, { waitUntil: 'networkidle2' });
    await page.$eval('#languages', (el) => el.scrollIntoView({ block: 'start' }));
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    await report.shot(page, '02-phone', { fullPage: false });
    report.verdict('on a phone the panel does not push the page sideways', !sideways, `scrollWidth > width: ${sideways}`);
    await page.setViewport({ width: 1400, height: 1000, deviceScaleFactor: 2 });

    // ---- remove: the site is left as it was ---------------------------------------------------
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    await page.$eval(`form[action$="/languages/${code}/delete"] button`, (b) => b.removeAttribute('data-confirm'));
    await clickAndWait(page, `form[action$="/languages/${code}/delete"] button`);
    const after = await rows(page);
    report.verdict('a language nothing is written in can be removed, leaving the list as it was',
      JSON.stringify(after) === JSON.stringify(before), `${await flash(page)} — ${after.map((r) => r.code).join(', ')}`);
  },
};
