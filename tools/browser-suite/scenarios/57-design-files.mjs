/*
 * A DESIGN AS A FILE ON THE APPEARANCE SCREEN (PLAN.md D-152, step 5).
 *
 * Out and in, as the owner does it: the Export button in the bar and in each character's ⋯
 * menu, a file chosen under Your designs and imported, the question that follows at the top of
 * the inspector, Add as character, the tile it becomes with its Custom label, and its delete
 * (PLAN.md D-157 moved all of it out of the rail).
 *
 * ON THE DEVELOPMENT SITE. The one character it adds is deleted again through its own card,
 * and, should a step fail before that, by the same post at the end. Nothing is published.
 */
import { writeFileSync } from 'node:fs';
import { BASE, ADMIN, SHOTS } from '../config.mjs';
import { login } from '../harness.mjs';

const SLUG = 'scenario-harbour';
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const shot = (report, page, name) => report.shot(page, name, { fullPage: false });

/** A character as a file, made here so the scenario owns its test data (CLAUDE.md, rule 9). */
const SET = {
  format: 'boxlet-design-set',
  version: 1,
  id: SLUG,
  name: { en: 'Scenario Harbour' },
  description: { en: 'Navy and sand, imported by a browser check.' },
  decisions: {
    seed: '#1d3557', secondary: '#f1e3c6', typography: 'classic', text_size: 'normal', scale: '1.25',
    spacing: 'roomy', radius: 'subtle', shadow: 'soft', container: '60', surface_contrast: 'medium',
    header_width: 'content', boxed: 'no', page_background: 'surface',
  },
  look: {
    header_arrangement: 'left', header_behaviour: 'sticky', footer_layout: 'columns', footer_edge: 'line',
    small_print_row: 'split', header_surface: 'plain', footer_surface: 'tinted', density: 'normal',
    header_edge: 'line', logo_size: 'medium', brand: 'both', nav_style: 'bar', nav_ink: 'ink',
    header_button: 'outline', footer_columns: '3', footer_links: 'auto',
  },
  composition: {
    section: { surface: 'plain', rhythm: 'normal', width: 'normal', align: 'left', divider: 'none' },
    surfaces: { image_text: 'tinted' },
    dividers: { text: 'line' },
    layouts: { hero: 'split', carousel: 'big' },
  },
};

export default {
  name: 'design-files',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('design-files: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1500, height: 1000, deviceScaleFactor: 1 });
    const cleanup = () => page.evaluate(async (base, slug) => {
      const token = document.querySelector('input[name="_csrf"]');
      if (!token || !document.querySelector(`button[value="character:delete:${slug}"]`)) return 'nothing to clean';
      const form = new FormData(document.getElementById('design-form'));
      form.set('action', `character:delete:${slug}`);
      return (await fetch(`${base}/admin/appearance`, { method: 'POST', body: form, credentials: 'same-origin' })).status;
    }, BASE, SLUG);

    try {
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      const atRest = await page.evaluate(() => ({
        export: !!document.querySelector('button[form="design-form"][value="export"]'),
        input: !!document.querySelector('#design-file[form="design-import"]'),
        // The control at rest is the label in the admin's words; the browser's input is hidden.
        button: !!document.querySelector('.design-import label[for="design-file"].button'),
        cardExports: document.querySelectorAll('.character-tile .tile-menu a[href*="/admin/appearance/export/character/"]').length,
      }));
      const designs = await page.$('.design-import');
      if (designs) await designs.scrollIntoView();
      await shot(report, page, '01-designs');
      report.verdict('Export in the bar, Import under Your designs in the admin\'s words, an export in every character\'s menu',
        atRest.export && atRest.input && atRest.button && atRest.cardExports >= 5, JSON.stringify(atRest));

      // ---- in: the file, the question ---------------------------------------------------------
      const file = `${SHOTS}/${SLUG}.json`;
      writeFileSync(file, JSON.stringify(SET, null, 2));
      // Choosing the file sends it (file-sends.js): no second press.
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.$('#design-file').then((input) => input.uploadFile(file)),
      ]);
      await shot(report, page, '02-question');
      const question = await page.evaluate(() => {
        const panel = document.querySelector('.import-confirm');
        const stage = document.querySelector('.appearance-stage-column').getBoundingClientRect();
        return panel ? {
          // In the inspector, not a band across the screen pushing the picture down (D-157).
          inInspector: !!panel.closest('.appearance-inspector'),
          pictureAtTop: Math.round(stage.top) <= Math.round(document.querySelector('.appearance-bar').getBoundingClientRect().bottom) + 1,
          text: panel.innerText.replace(/\s+/g, ' ').slice(0, 300),
          swatches: panel.querySelectorAll('[data-swatch]').length,
          add: !!panel.querySelector('button[form="design-import-add"]'),
          load: !!panel.querySelector('button[value="import:load"]'),
        } : null;
      });
      report.verdict('the import asks, with the design shown, what was left out, and the three answers',
        question !== null && question.inInspector && question.pictureAtTop
          && /Import “Scenario Harbour”\?/.test(question.text) && question.swatches === 3
          && question.add && question.load && /carousel/.test(question.text),
        JSON.stringify(question));

      // ---- kept as a character ----------------------------------------------------------------
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('button[form="design-import-add"]'),
      ]);
      const card = `button[value="preset:${SLUG}"]`;
      await page.waitForSelector(card, { timeout: 10000 }).catch(() => {});
      const added = await page.evaluate((selector, slug) => {
        const use = document.querySelector(selector);
        const box = use && use.closest('.character-tile');
        const name = box && box.querySelector('.tile-name');
        return {
          notice: (document.querySelector('.notice, [role="status"]') || {}).textContent || '',
          card: !!box,
          custom: box ? !!box.querySelector('.tile-custom') : false,
          remove: !!document.querySelector(`.tile-menu button[value="character:delete:${slug}"]`),
          // A long name is cut inside its tile and whole in its title; the inspector never
          // scrolls sideways for it (measured once at 304px in a rail of 195).
          titled: !!name && name.getAttribute('title') === 'Scenario Harbour',
          inspector: (() => { const r = document.querySelector('.appearance-inspector'); return { client: r.clientWidth, scroll: r.scrollWidth }; })(),
        };
      }, card, SLUG);
      const box = await page.$(card);
      if (box) {
        await box.evaluate((el) => el.closest('.character-tile').scrollIntoView({ block: 'center' }));
        // Its ⋯ menu open, so the shot shows Export and Delete.
        await box.evaluate((el) => { el.closest('.character-tile').querySelector('.tile-menu').open = true; });
      }
      await wait(300);
      await shot(report, page, '03-added');
      report.verdict('Add as character makes it a tile, marked Custom, with Delete in its menu',
        added.card && added.custom && added.remove && added.titled, JSON.stringify(added));
      report.verdict('its tile fits the inspector: nothing scrolls sideways',
        added.inspector.scroll <= added.inspector.client, JSON.stringify(added.inspector));

      // ---- out: the file it is ------------------------------------------------------------------
      const out = await page.evaluate(async (base, slug) => {
        const response = await fetch(`${base}/admin/appearance/export/character/${slug}`, { credentials: 'same-origin' });
        return { status: response.status, disposition: response.headers.get('content-disposition'), body: await response.text() };
      }, BASE, SLUG);
      const read = (() => { try { return JSON.parse(out.body); } catch { return null; } })();
      report.verdict('its export is the set again, without the block this site lacks',
        out.status === 200 && out.disposition === `attachment; filename="${SLUG}.json"` && read && read.decisions.seed === '#1d3557'
          && read.composition.layouts.hero === 'split' && !('carousel' in read.composition.layouts),
        `${out.status} ${out.disposition}`);

      // ---- deleted from its card ------------------------------------------------------------------
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click(`button[value="character:delete:${SLUG}"]`),
      ]);
      const gone = await page.evaluate((selector) => !document.querySelector(selector), card);
      report.verdict('the delete button removes it', gone, gone ? 'gone' : 'still on the rail');
    } finally {
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      const left = await cleanup();
      report.verdict('cleanup: no imported character left behind', left === 'nothing to clean', String(left));
    }
  },
};
