/*
 * THE SETTINGS LIST MARKS THE SECTION A LINK OPENED (PLAN.md D-227): each of its links pressed
 * in turn, the one marked must be the one pressed. The first rule marked the section above it
 * ("Your login" on Statistics, "AI tools" on Languages), seen on the pictures for
 * boxletcms.org. Then each section scrolled to by hand, with no address to name it, its top
 * an eighth of the way down the screen and a strip of the section above still showing under
 * the bar, as on the picture: it is the one marked. Then back to the top, where the first is.
 *
 * ON THE COPY; it only reads.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const marked = (page) => page.$eval('[data-settings-nav]', (nav) => {
  const on = nav.querySelectorAll('a[aria-current="true"]');
  return on.length === 1 ? on[0].getAttribute('href') : `${on.length} marked`;
});

export default {
  name: 'settings-nav',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('settings-nav: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    for (const size of [{ width: 1440, height: 900 }, { width: 2560, height: 1600 }]) {
      await page.setViewport({ ...size, deviceScaleFactor: 1 });
      await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
      const top = await marked(page);
      const hrefs = await page.$$eval('[data-settings-nav] a[href^="#"]', (links) => links.map((a) => a.getAttribute('href')));
      const wrong = [];
      for (const href of hrefs) {
        await page.click(`[data-settings-nav] a[href="${href}"]`);
        await wait(700);
        const now = await marked(page);
        if (now !== href) {
          wrong.push(`${href} → ${now}`);
        }
      }
      if (size.width === 1440) {
        await report.shot(page, '01-last-pressed', { fullPage: false });
      }
      // By hand: no #name in the address, each section's top an eighth of the way down.
      await page.evaluate(() => history.replaceState(null, '', location.pathname));
      const byHand = [];
      for (const href of hrefs) {
        const placed = await page.evaluate((id) => {
          const section = document.querySelector(id);
          const want = window.innerHeight / 8;
          window.scrollBy(0, section.getBoundingClientRect().top - want);
          return Math.abs(section.getBoundingClientRect().top - want) < 2;
        }, href);
        await wait(300);
        if (!placed) {
          continue; // the foot of the page: it cannot rise that far
        }
        const now = await marked(page);
        byHand.push(now === href ? href : `${href} → ${now}`);
      }
      if (size.width === 1440) {
        await report.shot(page, '02-by-hand', { fullPage: false });
      }
      await page.evaluate(() => window.scrollTo(0, 0));
      await wait(500);
      const back = await marked(page);
      report.verdict(`at ${size.width}×${size.height}, each link pressed marks its own section, and the top marks the first`,
        hrefs.length > 5 && wrong.length === 0 && top === hrefs[0] && back === hrefs[0],
        JSON.stringify({ links: hrefs.length, wrong, top, back }));
      report.verdict(`at ${size.width}×${size.height}, a section scrolled to by hand is marked, not the one whose foot shows above it`,
        byHand.length > 5 && byHand.every((h) => !h.includes('→')), JSON.stringify(byHand));
    }
  },
};
