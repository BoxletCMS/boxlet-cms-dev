/*
 * THE AI TRANSLATION PANEL (PLAN.md D-248, D-255).
 *
 * The field for another model's ID is shown only while "Another model…" is chosen: the owner
 * asked what it was for, standing empty under the model list. Chosen, it appears; another
 * model chosen, it goes again. Nothing is saved.
 *
 * ON THE COPY. It only reads and changes a select it never submits.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

export default {
  name: 'ai-settings',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('ai-settings: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1280, height: 900, deviceScaleFactor: 1 });
    await page.goto(`${BASE}/admin/settings#ai`, { waitUntil: 'networkidle2' });
    const shown = () => page.$eval('#ai_model_other', (input) => input.offsetParent !== null).catch(() => null);

    const first = await page.$eval('#ai_model', (s) => s.value).catch(() => null);
    if (first === null) {
      report.fail('ai-settings: the panel', 'no model select on the settings screen');
      return;
    }
    const atRest = await shown();
    await page.select('#ai_model', 'other');
    const chosen = await shown();
    await page.$eval('#ai', (panel) => panel.scrollIntoView({ block: 'start' }));
    await report.shot(page, '01-other-model', { fullPage: false });
    const listed = await page.$eval('#ai_model', (s) => [...s.options].map((o) => o.value).find((v) => v !== 'other'));
    await page.select('#ai_model', listed);
    const again = await shown();
    await page.select('#ai_model', first);
    report.verdict('another model\'s ID shows only while "Another model…" is chosen',
      atRest === (first === 'other') && chosen === true && again === false, `at rest ${atRest} (${first}), chosen ${chosen}, after ${again}`);
    await page.$eval('#ai', (panel) => panel.scrollIntoView({ block: 'start' }));
    await report.shot(page, '02-panel', { fullPage: false });
  },
};
