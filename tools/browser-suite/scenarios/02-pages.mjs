/*
 * Slice 3: pages and blocks.
 *
 *   - create a page from each of the three templates (landing, article, feature)
 *   - with JavaScript disabled, the plain editor can add, move and remove blocks and save
 *   - the front end renders the page; an unknown address renders the 404 page
 *   - an ISO 639-1 code as a top-level slug is rejected on save
 *
 * The no-JavaScript path is a different set of controls, not the same ones without their
 * script: Move up and Move down are real submits (action=up-N / down-N), and removal is a
 * `no-js-only` checkbox blocks[n][_delete] applied on save, while the Remove button beside
 * it is `js-only`. So this drives the checkbox, which is what a browser without JavaScript
 * would actually use.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, alerts, heading, SLOW } from '../harness.mjs';

const blockCount = (page) => page.$$eval('[data-block]', (els) => els.length);
const blockTypes = (page) => page.$$eval('[data-block] input[name$="[type]"]', (els) => els.map((e) => e.value));

/**
 * Each block as type:id, with 'new' for one not yet saved.
 *
 * Types alone cannot see a move: this page ends with two `text` blocks, so swapping them
 * leaves the type sequence identical and a working move reads as a failure. Measured — the
 * ids went 22,23,24 -> 22,24,23 while the types did not move at all.
 */
const fingerprints = (page) => page.$$eval('[data-block]', (els) => els.map((el) => {
  const type = el.querySelector('input[name$="[type]"]');
  const id = el.querySelector('input[name$="[id]"]');
  return `${type ? type.value : '?'}:${id ? id.value : 'new'}`;
}));

export default {
  name: 'pages',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('pages: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- a page from each template ---------------------------------------------------
    const templates = await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' })
      .then(() => page.$$eval('select[name="template"] option',
        (els) => els.map((e) => ({ value: e.value, label: e.textContent.trim() })).filter((o) => o.value)));

    report.verdict('the new-page form offers the three templates', templates.length === 3,
      templates.map((t) => t.label.split(':')[0]).join(', ') || 'none offered');

    // A slug unique to this run. Without it the scenario passes once and then fails for
    // ever against its own leftovers: the second run is correctly refused with "Another
    // page in this language already has the address …", which reads as a product failure
    // and is not one. A suite that only passes on a virgin database is not reusable.
    const run = Date.now().toString(36).slice(-5);

    const made = [];
    for (const template of templates) {
      const name = template.label.split(':')[0].trim();
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('input[name="title"]', `Zz ${name} ${run}`, { delay: SLOW });
      await page.select('select[name="template"]', template.value);
      const slug = `zz-${run}-${name.toLowerCase().replace(/\W+/g, '-')}`;
      await page.type('input[name="slug"]', slug, { delay: SLOW });
      await clickAndWait(page, 'form.panel button[type="submit"]');

      const id = Number((page.url().match(/\/admin\/pages\/(\d+)/) || [])[1]);
      if (!Number.isInteger(id)) {
        report.fail(`create a page from the ${name} template`, `did not land on a page: ${page.url()}`);
        continue;
      }
      made.push({ id, name, slug });
      await page.goto(`${BASE}/admin/pages/${id}/form`, { waitUntil: 'networkidle2' });
      const types = await blockTypes(page);
      report.verdict(`create a page from the ${name} template`, types.length > 0,
        `page ${id}, blocks pre-filled: ${types.join(', ') || 'NONE'}`);
    }
    await report.shot(page, '01-template-page');

    if (made.length === 0) {
      report.skip('the plain editor without JavaScript', 'no page was created to edit');
      return;
    }

    // ---- the plain editor with JavaScript disabled -------------------------------------
    const subject = made[0].id;
    await page.setJavaScriptEnabled(false);
    await page.goto(`${BASE}/admin/pages/${subject}/form`, { waitUntil: 'networkidle2' });
    const started = await blockCount(page);
    await report.shot(page, '02-plain-editor-no-js');

    // Add.
    await page.select('select[name="add_type"]', 'text');
    await clickAndWait(page, 'button[name="action"][value="add"]');
    const afterAdd = await blockCount(page);
    report.verdict('without JavaScript: a block can be added', afterAdd === started + 1,
      `${started} blocks before, ${afterAdd} after`);

    // Move: the last block up, read as type:id so a swap between two blocks of the same
    // type is still visible.
    const beforeMove = await fingerprints(page);
    // Since D-094 the action names the BLOCK, not the slot, so the value is read off the
    // last group rather than counted. Written this way it goes on working whatever the
    // key scheme is, which is the point of not putting a literal in a test.
    const lastUp = await page.$$eval('[data-block] button[data-editor-action="up"]',
      (els) => els[els.length - 1].value);
    await clickAndWait(page, `button[name="action"][value="${lastUp}"]`);
    const afterMove = await fingerprints(page);
    report.verdict('without JavaScript: a block can be moved',
      JSON.stringify(beforeMove) !== JSON.stringify(afterMove),
      `${beforeMove.join(', ')} -> ${afterMove.join(', ')}`);

    // Remove: the no-js checkbox, applied on save.
    //
    // Every required field has to be filled first. A template's blocks are created empty
    // (hero.heading, image_text.body and text.body are all required), so a save with them
    // blank is REJECTED — correctly — and update() returns reject() without ever calling
    // Page::update(). The first version of this check counted blocks in the re-rendered
    // form, which drops the _delete'd one whether the save succeeded or not, so a refused
    // save read as a successful removal.
    // Only the required fields, by name. Filling every empty text input also filled the
    // hero's link URL boxes, and a link is validated: the save was then refused with
    // "Links must start with /, #, ?, https://…" — a refusal this scenario had
    // manufactured for itself.
    await page.$$eval('[data-block] [name$="[heading]"], [data-block] [name$="[body]"]', (els) => {
      for (const el of els) {
        if (el.value.trim() === '') el.value = 'Checklist text';
      }
    });

    // The checkbox of the first text block, found through its group rather than built from
    // a position: a field is named for its block since D-094.
    const removeField = await page.$$eval('[data-block]', (groups) => {
        const found = groups.find((group) => {
          const type = group.querySelector('input[type="hidden"][name$="[type]"]');

          return type !== null && type.value === 'text';
        });
        const box = found && found.querySelector('input[name$="[_delete]"]');

        return box ? box.name : null;
      });
    await page.click(`input[name="${removeField}"]`);
    await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]');

    const saveAlerts = await alerts(page);
    // Storage, not the form that was just re-rendered: reload the editor and count again.
    await page.goto(`${BASE}/admin/pages/${subject}/form`, { waitUntil: 'networkidle2' });
    const afterSave = await blockCount(page);

    report.verdict('without JavaScript: a block can be removed and the page saved',
      saveAlerts.length === 0 && afterSave === afterMove.length - 1,
      `${afterMove.length} blocks before save, ${afterSave} stored after`
      + (saveAlerts.length ? `; the save was REFUSED: ${JSON.stringify(saveAlerts)}` : '; saved with no errors'));
    await report.shot(page, '03-after-no-js-save');
    await page.setJavaScriptEnabled(true);

    // ---- what the page says about itself in <head> (D-004) -------------------------------
    // Two fields on the plain editor's own panel, and the page's title standing in while the
    // meta title is empty. Checked here rather than in a probe of its own: this scenario
    // already owns the plain editor and a page whose address it knows.
    await page.goto(`${BASE}/admin/pages/${subject}/form`, { waitUntil: 'networkidle2' });
    const emptyAtFirst = await page.$$eval(
      'input[name="seo_title"], textarea[name="seo_description"]',
      (els) => els.map((el) => el.value),
    );
    await report.shot(page, '07-seo-fields');
    report.verdict('the editor offers a meta title and description, both empty until set',
      emptyAtFirst.length === 2 && emptyAtFirst.every((value) => value === ''),
      emptyAtFirst.length === 2
        ? `both on the form, holding ${JSON.stringify(emptyAtFirst)}`
        : `${emptyAtFirst.length} of the 2 fields are on the form`);

    if (emptyAtFirst.length === 2) {
      const metaTitle = `Zz meta ${run}`;
      const metaDescription = `What this page is for, said once — ${run}.`;
      await page.type('input[name="seo_title"]', metaTitle, { delay: SLOW });
      await page.type('textarea[name="seo_description"]', metaDescription, { delay: SLOW });
      await clickAndWait(page, 'div.editor-actions button[name="action"][value="save"]');
      const metaAlerts = await alerts(page);

      await page.goto(`${BASE}/admin/pages/${subject}/form`, { waitUntil: 'networkidle2' });
      const kept = await page.$$eval(
        'input[name="seo_title"], textarea[name="seo_description"]',
        (els) => els.map((el) => el.value),
      );
      report.verdict('the two fields survive a save and come back to the editor',
        kept[0] === metaTitle && kept[1] === metaDescription,
        `the editor now holds ${JSON.stringify(kept)}`
        + (metaAlerts.length ? `; the save was REFUSED: ${JSON.stringify(metaAlerts)}` : ''));

      // A draft answers 404, so the page is published through the listing's own control —
      // by exact id, and the same button a person would press.
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const publish = `form[action$="/admin/pages/${subject}/status"] button[type="submit"]`;
      if (await page.$(publish) === null) {
        report.skip('the page carries the two fields into <head>',
          `no publish control on the listing for page ${subject}`);
      } else {
        await clickAndWait(page, publish);
        await page.goto(`${BASE}/${made[0].slug}`, { waitUntil: 'networkidle2' });
        const head = await page.evaluate(() => ({
          title: document.title,
          description: document.querySelector('meta[name="description"]')?.content ?? null,
        }));
        await report.shot(page, '08-seo-front-end');
        report.verdict('the page carries the two fields into <head>',
          head.title === metaTitle && head.description === metaDescription,
          `<title> ${JSON.stringify(head.title)}, meta description ${JSON.stringify(head.description)}`);
      }
    }

    // ---- the front end ----------------------------------------------------------------
    await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' });
    const aboutHeading = await heading(page);
    const aboutSections = await page.$$eval('main section, body section', (e) => e.length).catch(() => 0);
    await report.shot(page, '04-front-end');
    report.verdict('the front end renders a page', aboutHeading.length > 0 && aboutSections > 0,
      `/about: h1 "${aboutHeading}", ${aboutSections} sections`);

    const missing = await page.goto(`${BASE}/no-such-address-here`, { waitUntil: 'networkidle2' });
    const missingStatus = missing.status();
    const missingBody = await page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim().slice(0, 120));
    await report.shot(page, '05-404');
    report.verdict('an unknown address renders the 404 page',
      missingStatus === 404 && missingBody.length > 0,
      `status ${missingStatus}, page says "${missingBody.slice(0, 80)}"`);

    // ---- a language code as a top-level slug -------------------------------------------
    await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
    await page.type('input[name="title"]', `Zz German ${run}`, { delay: SLOW });
    await page.type('input[name="slug"]', 'de', { delay: SLOW });
    await clickAndWait(page, 'form.panel button[type="submit"]');
    const slugErrors = await alerts(page);
    await report.shot(page, '06-language-code-slug');
    report.verdict('an ISO 639-1 code as a top-level slug is rejected',
      slugErrors.length > 0 && page.url().includes('/admin/pages'),
      slugErrors.length > 0 ? `refused with: "${slugErrors.join(' | ')}"` : 'ACCEPTED with no error');

    // ---- clean up what this scenario created -------------------------------------------
    // By exact id, captured at creation — never by a pattern over a title, which is
    // something a person can be halfway through typing.
    let removed = 0;
    for (const { id } of made) {
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const deleted = await page.evaluate((pageId) => {
        const form = document.querySelector(`form[action$="/admin/pages/${pageId}/delete"]`);
        if (!form) return false;
        const button = form.querySelector('[data-confirm]');
        if (button) button.removeAttribute('data-confirm');
        form.submit();
        return true;
      }, id);
      if (deleted) {
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => {});
        removed += 1;
      }
    }
    report.verdict('the scenario removes the pages it created', removed === made.length,
      `${removed} of ${made.length} deleted by exact id`);
  },
};
