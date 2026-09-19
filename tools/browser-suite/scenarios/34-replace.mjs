/*
 * Replacing a picture (the owner's report, 2026-09-19).
 *
 * What a person does: open a picture, open Replace, drop a new file — and is asked first,
 * naming the file; declining changes nothing, accepting puts the new picture in its place,
 * which the library then SHOWS. It did not: the replaced picture kept its address, and the
 * browser kept showing the old one from its cache, even after a reload. Colours are read
 * off the thumbnails themselves, so a stale cache cannot pass for a new picture.
 *
 * Two plain photographs in PHOTOS, red and blue, made for this. LEAVES THE SITE AS FOUND:
 * the picture is uploaded for this run and deleted at the end.
 */
import { BASE, ADMIN, PHOTOS } from '../config.mjs';
import { login } from '../harness.mjs';

/** The colour at the middle of an image, as the browser drew it: 'red', 'blue' or what it is. */
const colourOf = (page, selector) => page.evaluate(async (s) => {
  const img = document.querySelector(s);
  if (!img) return 'no picture';
  await img.decode().catch(() => {});
  const canvas = document.createElement('canvas');
  canvas.width = 20; canvas.height = 20;
  const context = canvas.getContext('2d');
  context.drawImage(img, 0, 0, 20, 20);
  const [r, , b] = context.getImageData(10, 10, 1, 1).data;
  return r > b + 60 ? 'red' : b > r + 60 ? 'blue' : `rgb ${r}/${b}`;
}, selector);

export default {
  name: 'replace',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('replace: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    let id = null;
    let asked = null;
    let answer = 'dismiss';
    // This scenario answers the question itself — once no, once yes — so the harness's
    // accept-everything handler steps aside for it.
    page.removeAllListeners('dialog');
    page.on('dialog', async (dialog) => {
      asked = dialog.message();
      await (answer === 'accept' ? dialog.accept() : dialog.dismiss());
    });
    try {
      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      const input = await page.$('input[name="files[]"]');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 180000 }), input.uploadFile(`${PHOTOS}/replace-red.jpg`)]);
      id = await page.$$eval('tr.media-row', (cards) => {
        const card = cards.find((c) => /replace-red/.test(c.textContent));
        return card ? Number(card.querySelector('a.media-link').getAttribute('href').match(/(\d+)$/)[1]) : null;
      });
      const thumb = `tr.media-row[data-media-id="${id}"] img.media-thumb`;
      report.verdict('the picture is uploaded and shown red in the library', id !== null && await colourOf(page, thumb) === 'red', `media ${id}`);

      // ---- declined -----------------------------------------------------------------------
      await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' });
      await page.click('[data-replace-toggle]');
      const field = await page.$('#media-replace input[type="file"]');
      await field.uploadFile(`${PHOTOS}/replace-blue.jpg`);
      await new Promise((resolve) => setTimeout(resolve, 1500));
      const stayed = page.url().endsWith(`/admin/media/${id}`);
      report.verdict('dropping a file asks first, naming it, and declining changes nothing',
        asked !== null && asked.includes('replace-blue.jpg') && stayed && await colourOf(page, '[data-crop-image]') === 'red',
        `asked: ${asked}`);

      // ---- accepted -----------------------------------------------------------------------
      answer = 'accept';
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 180000 }), field.uploadFile(`${PHOTOS}/replace-blue.jpg`)]);
      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      const after = await colourOf(page, thumb);
      await page.reload({ waitUntil: 'networkidle2' });
      const reloaded = await colourOf(page, thumb);
      report.verdict('accepted, the library shows the new picture at once, and after a reload', after === 'blue' && reloaded === 'blue', `${after}, then ${reloaded}`);
    } finally {
      if (id !== null) {
        await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' });
        await page.evaluate(() => {
          const form = document.querySelector('form.media-delete');
          form.querySelector('[data-confirm]')?.removeAttribute('data-confirm');
          form.submit();
        });
        await page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {});
        await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
        const left = await page.$(`a.media-link[href$="/${id}"]`);
        report.verdict('the picture is gone again', left === null, `media ${id}`);
      }
    }
  },
};
