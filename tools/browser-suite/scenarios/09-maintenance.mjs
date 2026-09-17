/*
 * D-021: maintenance mode.
 *
 * One gate, two triggers. This is the owner's own switch: visitors get the 503 page while
 * a logged-in admin still sees the real site, with a bar saying it is hidden.
 *
 * The visitor is a SEPARATE browser context, not the admin's page with its cookies
 * cleared. A context has its own cookie jar and its own session, so "a visitor" here is
 * really a visitor — clearing cookies on the admin's page would still share everything
 * else the browser has cached about that origin.
 *
 * The flag is switched through the admin's own toggle, not by writing the file, so this
 * exercises the control the owner actually uses. It is switched off again in a finally,
 * because leaving it on would break every scenario that runs after this one.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, heading, SHOTS } from '../harness.mjs';

const text = (page) => page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());

export default {
  name: 'maintenance',

  async run({ page, report, browser }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('maintenance: log in', `could not log in; at ${page.url()}`);
      return;
    }

    let visitor = null;
    let context = null;

    try {
      // ---- the settings screen says which way round the site is -----------------------
      // The switch moved here from the dashboard in 5a, and 17-settings asserts the
      // dashboard no longer carries it. This scenario kept clicking the old place and
      // threw — a check left looking where the thing used to be, the same rot that hid
      // six media scenarios when the photographs were renamed.
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      const before = await text(page);
      await report.shot(page, '01-settings-off');
      report.verdict('the settings screen says the site is visible and offers to hide it',
        /visible to everyone/i.test(before) && /Turn on maintenance mode/i.test(before),
        `the settings screen says "${before.slice(before.indexOf('Maintenance'), before.indexOf('Maintenance') + 120)}"`);

      // ---- switch it on ----------------------------------------------------------------
      await clickAndWait(page, 'form[action$="/admin/maintenance"] button[type="submit"]');
      const afterOn = await text(page);
      await report.shot(page, '02-settings-on');
      report.verdict('switching it on is confirmed on the settings screen',
        /Maintenance mode is on/i.test(afterOn) && /Turn off maintenance mode/i.test(afterOn),
        `the screen now says "${afterOn.slice(0, 120)}"`);

      // ---- what a visitor sees ---------------------------------------------------------
      context = await browser.createBrowserContext();
      visitor = await context.newPage();
      const visitorResponse = await visitor.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const visitorText = await visitor.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
      await visitor.screenshot({ path: `${SHOTS}/maintenance/03-visitor.png`, fullPage: true });

      report.verdict('a visitor gets 503 and the maintenance page',
        visitorResponse.status() === 503
        && visitorResponse.headers()['retry-after'] !== undefined
        && /back in a moment|briefly unavailable/i.test(visitorText),
        `status ${visitorResponse.status()}, Retry-After ${visitorResponse.headers()['retry-after'] ?? 'ABSENT'}, `
        + `page says "${visitorText.slice(0, 80)}"`);

      report.verdict('the visitor does not get the site itself',
        !/Northwind|Small studio/i.test(visitorText),
        visitorText.includes('Northwind') ? 'THE SITE LEAKED to a visitor' : 'only the maintenance page');

      // ---- what the admin sees -----------------------------------------------------------
      const adminResponse = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const adminHeading = await heading(page);
      const bar = await page.evaluate(() => {
        const el = document.querySelector('.boxlet-maintenance-bar');
        if (!el) return null;
        const style = getComputedStyle(el);
        const box = el.getBoundingClientRect();
        return {
          text: el.textContent.replace(/\s+/g, ' ').trim(),
          background: style.backgroundColor,
          color: style.color,
          position: style.position,
          height: Math.round(box.height),
          hasLink: !!el.querySelector('a'),
        };
      });
      await report.shot(page, '04-admin-sees-site');

      report.verdict('the admin still sees the real site', adminResponse.status() === 200 && adminHeading.length > 0,
        `status ${adminResponse.status()}, home page h1 "${adminHeading}"`);
      report.verdict('the bar tells the admin the site is hidden, and offers a way out',
        bar !== null && bar.hasLink && /cannot see/i.test(bar.text),
        bar === null ? 'NO BAR on the page' : `"${bar.text}" — ${bar.background} on ${bar.position}, ${bar.height}px`);

      // The bar must not push the page it sits over (D-021, and the D-012 note about not
      // changing the page being judged).
      report.verdict('the bar overlays the page rather than moving it',
        bar !== null && bar.position === 'fixed',
        bar === null ? 'no bar' : `position: ${bar.position}`);

      // ---- switch it off ------------------------------------------------------------------
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, 'form[action$="/admin/maintenance"] button[type="submit"]');
      const afterOff = await text(page);
      report.verdict('switching it off is confirmed', /Maintenance mode is off|visible to everyone/i.test(afterOff),
        `the screen now says "${afterOff.slice(0, 120)}"`);

      const back = await visitor.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const backText = await visitor.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
      await report.shot(page, '05-after-off');
      report.verdict('the site is visible to visitors again',
        back.status() === 200 && !/back in a moment/i.test(backText),
        `status ${back.status()}, page says "${backText.slice(0, 60)}"`);
    } finally {
      // Never leave the site closed: every scenario after this one would fail.
      try {
        await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
        const stillOn = await page.evaluate(() => /Turn off maintenance mode/i.test(document.body.textContent));
        if (stillOn) {
          await clickAndWait(page, 'form[action$="/admin/maintenance"] button[type="submit"]');
        }
      } catch {
        // Reported by the verdicts above; nothing useful to add here.
      }
      if (context) await context.close();
    }
  },
};
