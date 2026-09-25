/*
 * THE FOCAL POINT, BACK ON A PICTURE'S PAGE (PLAN.md D-121).
 *
 * The owner had it taken away in D-038 without having seen what it was for, and asked for it
 * back once the cover hero showed him: a picture behind a hero's words is cut to a phone's
 * shape, and the point is what the cut keeps. Driven through real clicks on the picture, the
 * way it is used, and read back from the page after the save.
 *
 * ON THE COPY, AND IT CLEANS UP. It uploads a photograph of its own, moves that one's point,
 * and deletes it at the end (D-090); nothing of the owner's is touched.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN, PHOTOS } from '../config.mjs';
import { login, clickAndWait } from '../harness.mjs';
import { uploadPhoto, cardFor, attemptDelete } from '../media-helpers.mjs';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export default {
  name: 'focal',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('focal: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 1 });
    /* ITS OWN PHOTOGRAPH, AND ONLY IF IT IS NEW. An upload the library already holds is the
       SAME picture (sha1), so a photograph an earlier check left on a page would be moved and
       then, rightly, refused deletion — measured, the first run of this took the one on the
       copy's Blocks page. One nothing uses, and deleted only if this run brought it. */
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const had = await cardFor(page, 'desk-wood');
    await uploadPhoto(page, `${PHOTOS}/desk-wood.jpg`);
    const card = await cardFor(page, 'desk-wood');
    if (!card) {
      report.fail('focal: the photograph', 'the upload did not arrive in the library');
      return;
    }
    const url = card.href.startsWith('http') ? card.href : `${BASE}${card.href}`;

    try {
      await page.goto(url, { waitUntil: 'networkidle2' });
      const offered = await page.evaluate(() => ({
        form: !!document.querySelector('form[data-focal-form]'),
        x: (document.querySelector('#focal-x') || {}).value,
        y: (document.querySelector('#focal-y') || {}).value,
        samples: document.querySelectorAll('[data-focal-sample]').length,
      }));
      report.verdict('a picture\'s page offers the focal point, where it is, with the two cuts',
        offered.form && offered.x === '50' && offered.y === '50' && offered.samples === 2, JSON.stringify(offered));

      // A real click, a fifth of the way across and near the bottom.
      const frame = await page.$('[data-focal-frame]');
      const box = await frame.boundingBox();
      await page.mouse.click(box.x + box.width * 0.2, box.y + box.height * 0.8);
      await wait(300);
      const clicked = await page.evaluate(() => ({
        x: document.querySelector('#focal-x').value,
        y: document.querySelector('#focal-y').value,
        marker: [document.querySelector('[data-focal-marker]').style.insetInlineStart,
          document.querySelector('[data-focal-marker]').style.insetBlockStart],
        samples: [...document.querySelectorAll('[data-focal-sample]')].map((img) => img.style.objectPosition),
      }));
      report.verdict('a click on the picture moves the point, the marker and both cuts together',
        clicked.x === '20' && clicked.y === '80' && clicked.marker.join(' ') === '20% 80%'
          && clicked.samples.every((position) => position === '20% 80%'),
        JSON.stringify(clicked));
      await report.shot(page, '01-focal', { fullPage: false });

      await clickAndWait(page, 'form[data-focal-form] button[type="submit"]', 60000);
      const saved = await page.evaluate(() => ({
        x: (document.querySelector('#focal-x') || {}).value,
        y: (document.querySelector('#focal-y') || {}).value,
        said: (document.querySelector('.notice, [role="status"]') || {}).textContent || '',
      }));
      report.verdict('saving keeps the point, and says the cut sizes were made again',
        saved.x === '20' && saved.y === '80' && /focal point was moved/i.test(saved.said), JSON.stringify(saved));
    } finally {
      if (had === null) {
        await page.goto(url, { waitUntil: 'networkidle2' });
        const gone = await attemptDelete(page);
        report.verdict('the scenario removes the photograph it uploaded', gone !== null && /deleted/i.test(gone), String(gone));
      } else {
        report.skip('the scenario removes the photograph it uploaded', 'the copy already had it; left where it was');
      }
    }
  },
};
