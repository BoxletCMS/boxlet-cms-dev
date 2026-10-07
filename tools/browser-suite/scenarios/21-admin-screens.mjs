/*
 * Every admin screen, photographed at desktop and phone width (PLAN.md step 6b).
 *
 * The admin's look is the owner's to judge, from pictures. This is the camera: one run
 * before a change and one after gives the pairs they compare, screen by screen, and it
 * stays in the suite so the next change to the admin's design system is shown the same
 * way. Verdicts are only that each screen answers, and that at phone width it fits the
 * window without scrolling sideways, with no control past its edge (O-43).
 *
 * Read-only: it opens screens and never submits a form.
 *
 * Screenshots go to SHOTS/admin-screens/{label}/, where the label is the environment
 * variable BOXLET_SHOTS_LABEL (for example "before" or "after"), "latest" when unset.
 */
import { mkdirSync } from 'node:fs';
import { BASE, ADMIN, SHOTS } from '../config.mjs';
import { login } from '../harness.mjs';

const LABEL = process.env.BOXLET_SHOTS_LABEL ?? 'latest';

/**
 * Path, and a short name for the file. The page is the demo's home; a picture and a menu
 * are whichever the list screen links to first, since their ids differ from site to site.
 */
const SCREENS = [
  ['/admin', 'dashboard'],
  ['/admin/pages', 'pages'],
  ['/admin/pages/new', 'page-new'],
  ['/admin/pages/1', 'page-builder'],
  ['/admin/pages/1/form', 'page-form'],
  ['/admin/media', 'media'],
  ['first:/admin/media', 'media-item'],
  ['/admin/appearance', 'appearance'],
  ['/admin/navigation', 'navigation'],
  ['first:/admin/menus', 'menu'],
  ['/admin/settings', 'settings'],
  ['/admin/update', 'update'],
];

const WIDTHS = [['desktop', 1400, 1000], ['phone', 390, 844]];

export default {
  name: 'admin-screens',

  async run({ page, report }) {
    mkdirSync(`${SHOTS}/admin-screens/${LABEL}`, { recursive: true });
    // The login screen first, before there is a session to skip it.
    for (const [width, w, h] of WIDTHS) {
      await page.setViewport({ width: w, height: h, deviceScaleFactor: 2 });
      await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle2' });
      await report.shot(page, `${LABEL}/login-${width}`);
    }

    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('admin screens: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }

    for (let [path, name] of SCREENS) {
      if (path.startsWith('first:')) {
        const list = path.slice('first:'.length);
        await page.goto(`${BASE}${list}`, { waitUntil: 'networkidle2' });
        const href = await page.evaluate((prefix) => {
          const link = Array.from(document.querySelectorAll('a[href]'))
            .find((a) => new RegExp(`^${prefix}/\\d+$`).test(new URL(a.href).pathname));
          return link ? new URL(link.href).pathname : null;
        }, list);
        if (href === null) {
          report.fail(`${list}: something to open`, 'the list links to nothing');
          continue;
        }
        path = href;
      }
      for (const [width, w, h] of WIDTHS) {
        await page.setViewport({ width: w, height: h, deviceScaleFactor: 2 });
        const response = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle2' });
        const status = response ? response.status() : 0;
        await report.shot(page, `${LABEL}/${name}-${width}`);
        if (width === 'desktop') {
          report.verdict(`${path} answers`, status === 200, `status ${status}`);
        } else {
          // A phone never scrolls sideways. Measured once at 704px of document on a 390px
          // screen: labels hidden for screen readers had escaped a table's scroll box.
          const [scroll, view] = await page.evaluate(() => [
            document.documentElement.scrollWidth,
            document.documentElement.clientWidth,
          ]);
          report.verdict(`${path} fits a phone without scrolling sideways`, scroll <= view,
            `document ${scroll}px wide in a ${view}px window`);
          // AND NO CONTROL PAST THE WINDOW'S EDGE (O-43): the languages table scrolled inside
          // its own box, so the document fitted while its switch was cut at the edge and its
          // delete button stood beyond it. A row of links built to scroll (a nav, a tab list)
          // is the one exception; the canvas's own document is not this one's.
          const past = await page.evaluate(() => {
            const width = document.documentElement.clientWidth;
            return [...document.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, [role="switch"]')]
              .filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden')
              .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 2 && r.height > 2 && r.right > width + 0.5; })
              .filter((el) => !el.closest('nav, [role="tablist"], .visually-hidden'))
              // What a closed <details> holds is not drawn, though Chrome still gives it a box
              // (measured: the builder's language list, closed, at 403px; open, inside).
              .filter((el) => { const d = el.closest('details:not([open])'); const sum = el.closest('summary'); return !d || (sum !== null && sum.parentElement === d); })
              .map((el) => `${el.tagName.toLowerCase()} "${(el.textContent || el.getAttribute('title') || el.name || '').trim().slice(0, 30)}" to ${Math.round(el.getBoundingClientRect().right)}px`);
          });
          report.verdict(`${path}: on a phone every control is within the window`, past.length === 0,
            past.length ? past.slice(0, 6).join('; ') : 'none past the edge');
        }
      }
    }
  },
};
