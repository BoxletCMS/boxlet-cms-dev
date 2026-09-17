/*
 * 4b: the picture LIBRARY — the screen that lists pictures and the form that uploads them.
 * One picture on its own is 11-picture.mjs, the same seam the controllers take.
 *
 * The parts a test runner cannot judge: whether an uploaded photograph actually APPEARS,
 * whether the variant the screen shows is a real file the web server can hand over, and
 * whether the browser refuses a file that is too big before sending it.
 *
 * A thumbnail is checked by naturalWidth, never by the <img> being in the DOM. A broken
 * image is still an element with a src, so "the tag is there" is exactly the check that
 * would pass while the screen showed a broken-image icon.
 *
 * Uploading the same photograph twice is recognised by its sha1 and not stored twice. On a
 * re-run that is the correct answer, so both outcomes count as a successful upload.
 */
import { existsSync, writeFileSync, rmSync, statSync } from 'node:fs';
import { BASE, ADMIN } from '../config.mjs';
import { login, submitVia, heading, fixtures } from '../harness.mjs';
import {
  PHOTO, PHOTOS, notice, variantFiles, cardFor, claimants, clearReferences, attemptDelete, markerFor,
} from '../media-helpers.mjs';

const BIG = `${PHOTOS}/.oversized-fixture.jpg`;

/** What the library calls the photograph this scenario uploads, derived from the file. */
const MARKER = markerFor(PHOTO);

export default {
  name: 'media',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('media: log in', `could not log in; at ${page.url()}`);
      return;
    }
    // A FAILURE, not NOT CHECKABLE: missing data means this scenario measured nothing.
    if (fixtures(report, 'media', [PHOTO], 'one photograph to upload') === null) {
      return;
    }

    let mediaId = null;

    try {
      // ---- the library is reachable from the navigation --------------------------------
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle2' });
      // Found by WHERE IT GOES, not by what it is called. The library is named "Media"
      // because it will later hold documents to download (PLAN.md O-17), and a check that
      // matched the old word would have to be edited again for every rename — while a link
      // that stopped pointing at the library is the failure actually worth catching.
      const navLink = await page.$eval('.admin-nav', (nav) => {
        const link = Array.from(nav.querySelectorAll('a')).find((a) => /\/admin\/media$/.test(a.getAttribute('href') || ''));
        return link ? { label: link.textContent.trim(), href: link.getAttribute('href') } : null;
      }).catch(() => null);

      report.verdict('the admin navigation offers the library', navLink !== null,
        navLink ? `"${navLink.label}" -> ${navLink.href}` : 'NO link to /admin/media in the nav');

      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      await report.shot(page, '01-library');
      const title = await heading(page);
      report.verdict('the library screen renders', title !== '' && title !== '(no h1)', `h1 "${title}"`);

      // ---- uploading a real photograph ---------------------------------------------------
      const originalBytes = statSync(PHOTO).size;
      const input = await page.$('input[name="files[]"]');
      if (!input) {
        report.fail('the library offers a file input', 'no input[name="files[]"] on the screen');
        return;
      }
      await input.uploadFile(PHOTO);
      await submitVia(page, 'input[name="files[]"]', 60000);

      const said = await notice(page);
      await report.shot(page, '02-after-upload');
      report.verdict('uploading a photograph is confirmed',
        /Uploaded/i.test(said) || /already in the library/i.test(said),
        `the screen says "${said.slice(0, 140)}"`);

      const card = await cardFor(page, MARKER);
      report.verdict('the uploaded picture appears with a thumbnail that loaded',
        card !== null && card.naturalWidth > 0,
        card === null ? 'no card for the uploaded photograph'
          : `"${card.name}" ${card.facts}, thumb ${card.src} decoded at ${card.naturalWidth}x${card.naturalHeight}`);

      // D-025: a filename like "atelier.jpg" tidies into a description, so a NEW upload is given a
      // suggested alt, and the card has to say it is a guess until the owner confirms it.
      //
      // ONLY FOR A NEW UPLOAD. Identical bytes are recognised by their sha1 and the row is
      // returned as it stands, so nothing is suggested — and if an earlier run confirmed
      // that row's alt, the badge is correctly absent. This suite runs against a site that
      // persists between runs, so that is the ordinary case, not the exception: asserting
      // regardless would fail on a second run and look like the product breaking. The
      // picture screen proves the same feature through a different path (11-picture), so
      // skipping here loses no coverage.
      if (/already in the library/i.test(said)) {
        report.skip('a suggested alt is marked as a guess on the card (D-025)',
          'those bytes were already in the library, so this upload suggested nothing');
      } else {
        const badge = await page.$$eval('.media-card', (els, wanted) => {
          const el = els.find((c) => new RegExp(wanted).test((c.querySelector('.media-name') || {}).textContent || ''));
          const mark = el ? el.querySelector('.media-suggested') : null;
          return mark === null ? null : mark.textContent.replace(/\s+/g, ' ').trim();
        }, MARKER).catch(() => null);

        report.verdict('a suggested alt is marked as a guess on the card (D-025)',
          badge !== null && badge !== '',
          badge === null ? 'NO badge on a picture Boxlet named itself' : `the card says "${badge}"`);
      }

      mediaId = card && card.href ? Number((card.href.match(/\/admin\/media\/(\d+)/) || [])[1]) : null;

      // ---- the variants are real files, and much smaller than the original ---------------
      const thumbs = variantFiles('thumb');
      const cards = variantFiles('card');
      const smallest = cards.length ? Math.min(...cards.map((f) => f.bytes)) : 0;

      report.verdict('variants are written to public/m/ as real files',
        thumbs.length > 0 && cards.length > 0,
        `${thumbs.length} thumb, ${cards.length} card files: ${cards.map((f) => `${f.name} ${Math.round(f.bytes / 1024)}KB`).join(', ') || 'NONE'}`);

      // Generated on upload, never on demand (§5.1): the file exists before anyone asks
      // for it, which is what lets the web server answer without PHP.
      report.verdict('a variant is a fraction of the original',
        smallest > 0 && smallest < originalBytes / 2,
        `original ${Math.round(originalBytes / 1024)}KB, smallest card variant ${Math.round(smallest / 1024)}KB`);

      if (card && card.src) {
        const served = await page.evaluate(async (url) => {
          const response = await fetch(url);
          return { status: response.status, type: response.headers.get('content-type') };
        }, `${BASE}${card.src}`);
        report.verdict('the thumbnail URL is served from disk as an image',
          served.status === 200 && /^image\//.test(served.type || ''),
          `${card.src} -> ${served.status} ${served.type}`);
      }

      // ---- a file too big is refused in the browser, before it is sent ---------------------
      const limit = await page.$eval('[data-media-upload]', (el) => Number(el.getAttribute('data-max-file')));

      if (!Number.isFinite(limit) || limit <= 0 || limit > 32 * 1024 * 1024) {
        report.skip('a file over the server limit is refused before it is sent',
          `upload_max_filesize is ${limit} bytes — too large to build a fixture for without wasting disk`);
      } else {
        writeFileSync(BIG, Buffer.alloc(limit + 1024, 0x41));
        const bigInput = await page.$('input[name="files[]"]');
        await bigInput.uploadFile(BIG);
        // No submit: the check runs on change, which is the point — nothing is sent.
        await new Promise((resolve) => setTimeout(resolve, 200));

        const refusal = await page.evaluate(() => {
          const el = document.querySelector('[data-media-error]');
          return el && !el.hidden ? el.textContent.replace(/\s+/g, ' ').trim() : null;
        });
        await report.shot(page, '03-too-large');
        report.verdict('a file over the server limit is refused before it is sent',
          refusal !== null && /larger|more than/i.test(refusal),
          refusal === null ? 'NOTHING was said; the browser would have sent it'
            : `the screen says "${refusal}" (limit ${Math.round(limit / 1024 / 1024)}MB)`);
      }
    } finally {
      if (Number.isInteger(mediaId)) {
        try {
          await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
          await clearReferences(page, mediaId, await claimants(page));
          await page.goto(`${BASE}/admin/media/${mediaId}`, { waitUntil: 'networkidle2' });
          const gone = await attemptDelete(page);
          const left = await page.$$eval('.media-card .media-name',
            (els, wanted) => els.filter((el) => new RegExp(wanted).test(el.textContent)).length,
            MARKER).catch(() => -1);

          report.verdict('the scenario removes the picture it uploaded',
            gone !== null && /deleted/i.test(gone) && left === 0,
            `"${gone}"; ${left} ${MARKER} card(s) left`);
        } catch (error) {
          report.fail('the scenario removes the picture it uploaded', error.message.split('\n')[0]);
        }
      }
      if (existsSync(BIG)) rmSync(BIG);
    }
  },
};
