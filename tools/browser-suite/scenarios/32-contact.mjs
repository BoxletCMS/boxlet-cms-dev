/*
 * Slice 7's acceptance, in the browser (SPEC §8, PLAN.md D-046): build a contact form, put
 * it on a page, send it as a visitor, and see the message in the admin.
 *
 * "RECEIVE BOTH EMAILS" is not checked here: the development site has no way of sending
 * chosen, and choosing one would send real mail. That the owner is notified and the sender
 * answered is asserted by tests/form_submit_test.php through a capturing transport; the
 * delivery itself waits for the owner's own mail account.
 *
 * LEAVES THE SITE AS FOUND: the form and the page are made for this run and deleted at
 * the end, the form's message with it.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, SLOW } from '../harness.mjs';

const STAMP = Date.now();
const FORM = `Zz contact check ${STAMP}`;
const PAGE = `Zz contact page ${STAMP}`;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'contact',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('contact: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    let formId = null;
    let pageId = null;
    try {
      // ---- the form -------------------------------------------------------------------------
      await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
      await page.type('#form-name', FORM);
      await clickAndWait(page, 'form[action$="/admin/forms"] button[type="submit"]');
      formId = Number((page.url().match(/\/admin\/forms\/(\d+)$/) || [])[1]) || null;

      // ---- a page with the Form block --------------------------------------------------------
      await page.goto(`${BASE}/admin/pages/new`, { waitUntil: 'networkidle2' });
      await page.type('#page-title', PAGE);
      await page.select('#page-template', '');
      await clickAndWait(page, 'form.panel button[type="submit"]');
      pageId = Number((page.url().match(/\/admin\/pages\/(\d+)$/) || [])[1]) || null;
      await page.click('[data-add-type="form"]');
      await wait(1500);
      const group = await page.evaluate(() => {
        const shown = Array.from(document.querySelectorAll('[data-block-group]')).find((g) => !g.hidden);
        return shown ? shown.getAttribute('data-block-group') : null;
      });
      await page.type(`[data-block-group="${group}"] input[name$="[heading]"]`, 'Write to us', { delay: SLOW });
      await page.select(`[data-block-group="${group}"] select[name$="[form]"]`, String(formId));
      await wait(1500);
      const onCanvas = await page.evaluate(() => {
        const frame = document.querySelector('iframe[data-canvas]');
        return frame.contentDocument.querySelector('form.site-form') !== null;
      });
      await report.shot(page, '01-editor', { fullPage: false });
      report.verdict('the Form block shows the chosen form on the canvas', onCanvas, `form drawn: ${onCanvas}`);
      await page.evaluate(() => { window.onbeforeunload = null; });
      await clickAndWait(page, '.builder-bar button[value="save"]');
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      await clickAndWait(page, `form[action$="/pages/${pageId}/status"] button`);
      // The address cell is the path in words since D-052: opening the page on the site
      // moved into the row's menu, so there is no link in the cell to read a href from.
      const address = await page.evaluate((id) => {
        const cell = document.querySelector(`tr[data-page-id="${id}"] .address`);
        return cell ? cell.textContent.trim() : null;
      }, pageId);

      // ---- a visitor sends it ---------------------------------------------------------------
      const visitor = await page.browser().createBrowserContext();
      const tab = await visitor.newPage();
      await tab.setViewport({ width: 1400, height: 900, deviceScaleFactor: 2 });
      await tab.goto(`${BASE}${address}`, { waitUntil: 'networkidle2' });
      await tab.type('form.site-form input[name="f[name]"]', 'Ana Horvat', { delay: SLOW });
      await tab.type('form.site-form input[name="f[email]"]', 'ana@example.org', { delay: SLOW });
      await tab.type('form.site-form textarea[name="f[message]"]', 'A browser check, sent as a visitor.', { delay: SLOW });
      await tab.$eval('form.site-form', (f) => f.scrollIntoView({ block: 'center' }));
      await report.shot(tab, '02-filled', { fullPage: false });
      await wait(3500);
      await Promise.all([tab.waitForNavigation({ waitUntil: 'networkidle2' }), tab.click('form.site-form button[type="submit"]')]);
      const thanked = await tab.$eval('.form-block-sent', (p) => p.textContent.trim()).catch(() => null);
      await report.shot(tab, '03-thanks', { fullPage: false });
      report.verdict('the visitor lands back on the page, thanked', thanked !== null, `${tab.url()} — ${thanked}`);
      await visitor.close();

      // ---- the owner reads it ----------------------------------------------------------------
      await page.goto(`${BASE}/admin/forms/${formId}/messages`, { waitUntil: 'networkidle2' });
      const listed = await page.$eval('.messages-table tbody', (t) => t.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      await report.shot(page, '04-messages', { fullPage: false });
      report.verdict('the message is in the admin, marked new', /Ana Horvat/.test(listed) && /New/.test(listed), listed.slice(0, 120));
    } finally {
      await page.evaluate(() => { window.onbeforeunload = null; }).catch(() => {});
      if (pageId !== null) {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
        // Deleting moved into the row's menu (D-052), which is a closed <details> until
        // something opens it — a click on a button inside one does nothing.
        await page.$eval(`tr[data-page-id="${pageId}"] details.row-menu`, (d) => { d.open = true; }).catch(() => {});
        await page.$eval(`form[action$="/pages/${pageId}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/pages/${pageId}/delete"] button`).catch(() => {});
      }
      if (formId !== null) {
        await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
        await page.$eval(`form[action$="/forms/${formId}/delete"] button`, (b) => b.removeAttribute('data-confirm')).catch(() => {});
        await clickAndWait(page, `form[action$="/forms/${formId}/delete"] button`).catch(() => {});
      }
      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const strayPage = await page.evaluate((t) => document.body.textContent.includes(t), PAGE);
      await page.goto(`${BASE}/admin/forms`, { waitUntil: 'networkidle2' });
      const strayForm = await page.evaluate((t) => document.body.textContent.includes(t), FORM);
      report.verdict('the page and the form are gone again', !strayPage && !strayForm, `page left: ${strayPage}, form left: ${strayForm}`);
    }
  },
};
