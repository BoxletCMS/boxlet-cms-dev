/*
 * Making every picture's sizes again from the Media screen (PLAN.md O-13, D-048).
 *
 * What a person does: press "Make every size again", confirm, and watch it carry on by
 * itself until it says every picture is done — then see the library still showing every
 * picture, each at a new address, so no browser keeps an old file.
 *
 * COPY ONLY: it rewrites every variant file of the library it runs on. That is safe on a
 * live site by design (each file is replaced whole), but a suite run must not spend the
 * development site's server on it each time.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, PHOTOS } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';
import { uploadPhoto } from '../media-helpers.mjs';

const thumbs = (page) => page.$$eval('li.media-card img.media-thumb', (imgs) => imgs.map((i) => ({ src: i.getAttribute('src'), ok: i.naturalWidth > 0 })));

export default {
  name: 'remake',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('remake: log in', `could not log in; at ${page.url()}`);
      return;
    }
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    if ((await thumbs(page)).length < 2) {
      await uploadPhoto(page, `${PHOTOS}/tools.jpg`);
      await uploadPhoto(page, `${PHOTOS}/atelier.jpg`);
      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    }
    const before = await thumbs(page);

    await clickAndWait(page, 'form[action$="/admin/media/remake"] button');
    // media-remake.js presses Continue by itself; wait for the page that says it is done.
    await page.waitForFunction(() => !document.querySelector('[data-remake-continue]'), { timeout: 300000, polling: 1000 }).catch(() => {});
    await page.waitForNetworkIdle({ idleTime: 500 }).catch(() => {});
    const said = await page.$$eval('[role="status"], .flash, .notice', (els) => els.map((e) => e.textContent.trim()).join(' | '));
    const after = await thumbs(page);
    await page.$eval('#remake', (el) => el.scrollIntoView({ block: 'center' }));
    await report.shot(page, '01-done', { fullPage: false });

    report.verdict('the pass runs by itself to the end', /Every picture/.test(said), said);
    report.verdict('every picture still shows, each at a new address',
      after.length === before.length && after.every((t) => t.ok) && after.every((t, i) => t.src !== before[i].src),
      `${before.map((t) => t.src).join(', ')} → ${after.map((t) => t.src).join(', ')}`);
  },
};
