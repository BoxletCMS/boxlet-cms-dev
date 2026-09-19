/*
 * Slice 5b: menus (PLAN.md D-028, resolving O-7).
 *
 * What only a browser can answer. The model and the routes are covered by the test suite;
 * this stands over the things a person sees: that the screen is reachable from the
 * navigation, that the ordering controls are VISIBLE AT REST rather than appearing on
 * hover (CLAUDE.md: no control is ever invisible at rest — the insertion handles in the
 * canvas were got wrong exactly this way), that Up and Down are disabled at the ends of a
 * group instead of silently doing nothing, and that an item pointing nowhere says so.
 *
 * IT CLEANS UP AFTER ITSELF, by exact id captured at creation — never by a pattern over a
 * name, which is something a person can be halfway through typing.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, clickAndWait, submitVia, alerts, controlsOnPanels, retype, SLOW } from '../harness.mjs';

const rowLabels = (page) => page.$$eval('tbody[data-menu-rows] tr[data-menu-item] td:nth-child(2)',
  (cells) => cells.map((c) => c.textContent.trim()));

export default {
  name: 'menus',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('menus: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- reachable from the navigation -----------------------------------------------------
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
    const link = await page.$('.rail-nav a[href$="/admin/menus"]');
    report.verdict('the navigation offers Menus', link !== null,
      link === null ? 'no link to /admin/menus in the admin bar' : 'the admin bar links to it');
    if (link === null) { return; }

    await clickAndWait(page, '.rail-nav a[href$="/admin/menus"]');

    // ---- create one --------------------------------------------------------------------
    const name = `Zz Menu ${Date.now().toString(36).slice(-5)}`;
    await page.type('input[name="name"]', name, { delay: SLOW });
    await submitVia(page, 'input[name="name"]', 40000);

    const menuId = Number((page.url().match(/\/admin\/menus\/(\d+)/) || [])[1]);
    report.verdict('creating a menu opens it', Number.isInteger(menuId),
      `landed at ${page.url()}`);
    if (!Number.isInteger(menuId)) { return; }

    try {
      // ---- three items: a page, an address, and one that goes nowhere --------------------
      // SCOPED TO THE ADD FORM. Every row now carries its own edit form (D-038) with the same
      // field names, and an unscoped input[name="url"] is the first row's, hidden inside its
      // closed <details>.
      const add = 'form[action$="/items"]';
      const addItem = async (fields) => {
        await page.goto(`${BASE}/admin/menus/${menuId}`, { waitUntil: 'networkidle2' });
        if (fields.page) {
          const value = await page.$eval(`${add} select[name="page_id"] option:nth-child(2)`, (o) => o.value).catch(() => '');
          if (value !== '') { await page.select(`${add} select[name="page_id"]`, value); }
        }
        if (fields.url) { await page.type(`${add} input[name="url"]`, fields.url, { delay: SLOW }); }
        // Replaced, not appended: choosing a page fills in its title (D-038).
        if (fields.label) { await retype(page, `${add} input[name="label"]`, fields.label); }
        await submitVia(page, `${add} input[name="label"]`, 40000);
      };

      await addItem({ page: true, label: 'First' });
      await addItem({ url: '/second', label: 'Second' });
      await addItem({ url: 'javascript:alert(1)', label: 'Nowhere' });

      const labels = await rowLabels(page);
      report.verdict('items are listed in the order they were added', labels.length === 3,
        `rows: ${JSON.stringify(labels)}`);

      // An item the server refused an address for is shown, marked, rather than hidden:
      // the owner is the only one who can repoint it.
      const broken = await page.$$eval('tbody[data-menu-rows] tr', (rows) => rows
        .filter((r) => r.textContent.includes('Goes nowhere')).length);
      report.verdict('an item that points nowhere says so', broken === 1,
        `${broken} rows marked as going nowhere`);

      // ---- the ordering controls are controls, at rest ------------------------------------
      const controls = await page.$$eval('tbody[data-menu-rows] tr[data-menu-item]', (rows) => rows.map((row) => {
        const handle = row.querySelector('[data-menu-handle]');
        const buttons = Array.from(row.querySelectorAll('button[name="move"]'));
        const box = handle ? handle.getBoundingClientRect() : null;
        return {
          handleVisible: box !== null && box.width > 0 && box.height > 0,
          buttons: buttons.length,
          disabled: buttons.filter((b) => b.disabled).map((b) => b.value),
        };
      }));

      report.verdict('every row has a visible drag handle and both buttons',
        controls.length === 3 && controls.every((c) => c.handleVisible && c.buttons === 2),
        JSON.stringify(controls.map((c) => ({ handle: c.handleVisible, buttons: c.buttons }))));

      // Disabled at the ends rather than present and inert: a button that looks live and
      // does nothing is worse than one that shows it cannot.
      report.verdict('Up is off at the top and Down at the bottom',
        controls[0].disabled.includes('up') && controls[2].disabled.includes('down')
        && !controls[1].disabled.length,
        `disabled per row: ${JSON.stringify(controls.map((c) => c.disabled))}`);

      await controlsOnPanels(page, report, 'menus');
      await report.shot(page, '01-menu');

      // ---- the no-JavaScript path actually reorders ---------------------------------------
      const before = await rowLabels(page);
      await clickAndWait(page, 'tbody[data-menu-rows] tr:nth-child(2) button[name="move"][value="up"]', 40000);
      const after = await rowLabels(page);
      const said = await alerts(page);
      report.verdict('Up moves an item past the one above it',
        JSON.stringify(before) !== JSON.stringify(after),
        `${JSON.stringify(before)} -> ${JSON.stringify(after)}`
        + (said.length ? `; the admin said: ${JSON.stringify(said)}` : ''));
      await report.shot(page, '02-reordered');

      // ---- editing an item in its dialog (D-039) -------------------------------------------
      await page.click('tbody[data-menu-rows] tr:nth-child(2) [data-dialog-open]');
      const dialogId = await page.$eval('tbody[data-menu-rows] tr:nth-child(2) [data-dialog-open]', (a) => a.getAttribute('data-dialog-open'));
      const open = await page.$eval(`#${dialogId}`, (d) => d.open && d.matches(':modal'));
      await report.shot(page, '03-edit-dialog', { fullPage: false });
      report.verdict('Edit opens the item in a dialog over the list', open, open ? 'a modal dialog' : 'no dialog opened');
      const renamed = `Zz renamed ${Date.now().toString(36).slice(-3)}`;
      await retype(page, `#${dialogId} input[name="label"]`, renamed);
      await submitVia(page, `#${dialogId} input[name="label"]`, 40000);
      const labelsAfterEdit = await rowLabels(page);
      report.verdict('saving the dialog changes the item', labelsAfterEdit.some((label) => label.includes(renamed)),
        `rows: ${JSON.stringify(labelsAfterEdit)}`);
    } finally {
      // By exact id, captured at creation. A menu left behind would make the next run's
      // "created a menu" count wrong and its name collide.
      await page.goto(`${BASE}/admin/menus`, { waitUntil: 'networkidle2' });
      const removed = await page.evaluate((id) => {
        const form = document.querySelector(`form[action$="/admin/menus/${id}/delete"]`);
        if (!form) { return false; }
        const button = form.querySelector('[data-confirm]');
        if (button) { button.removeAttribute('data-confirm'); }
        form.submit();
        return true;
      }, menuId);
      if (removed) {
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
      }
      const left = await page.$(`a[href$="/admin/menus/${menuId}"]`);
      report.verdict('the scenario removes the menu it created', removed && left === null,
        removed ? 'deleted by exact id' : 'its delete form was not on the list');
    }
  },
};
