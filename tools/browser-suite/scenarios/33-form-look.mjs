/*
 * The Form block under every character, on a desktop and a phone (PLAN.md D-046).
 *
 * The PHP tests prove the fields are drawn and checked; only a browser shows whether a form
 * reads on a tinted section and a plain one under five designs, whether its fields are
 * visible at rest, and whether "beside" folds on a phone. The demo carries the form twice:
 * stacked on the home page, beside its heading on About.
 *
 * COPY ONLY: applying a character rewrites the site's whole design (D-042). Needs a copy
 * installed with the demo (01-install).
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, applyCharacter } from '../harness.mjs';

const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'form-look',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('form look: log in', `could not log in; at ${page.url()}`);
      return;
    }
    for (const character of CHARACTERS) {
      await applyCharacter(page, BASE, character);
      for (const slug of ['', 'about']) {
        for (const [device, width, height] of [['desktop', 1400, 900], ['phone', 390, 844]]) {
          await page.setViewport({ width, height, deviceScaleFactor: 2 });
          await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
          const found = await page.evaluate(() => {
            const form = document.querySelector('form.site-form');
            if (!form) return null;
            form.closest('section').scrollIntoView({ block: 'start' });
            const input = form.querySelector('input[type="text"]');
            const style = input ? getComputedStyle(input) : null;
            return {
              fields: form.querySelectorAll('.site-form-field').length,
              edge: style ? style.borderTopWidth + ' ' + style.borderTopStyle : '',
              overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
          });
          await wait(200);
          const name = `${character}-${slug || 'home'}-${device}`;
          await report.shot(page, name, { fullPage: false });
          report.verdict(`${name}: the form is drawn with visible fields, no sideways scroll`,
            found !== null && found.fields === 3 && !found.edge.startsWith('0px') && !found.overflow, JSON.stringify(found));
        }
      }
    }
  },
};
