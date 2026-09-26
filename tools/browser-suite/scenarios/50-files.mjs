/*
 * FILES FOR VISITORS TO DOWNLOAD, in the library beside the pictures (PLAN.md O-17, D-126).
 *
 * Uploaded through the library's own file chooser, found by the Files filter, opened on its
 * own page, and downloaded from its address as a visitor would — then deleted.
 *
 * ON THE COPY, AND IT CLEANS UP: it makes its own PDF, with a name no earlier run used, and
 * deletes what it uploaded (D-090).
 */
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';
import { attemptDelete } from '../media-helpers.mjs';

export default {
  name: 'files',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('files: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    const marker = `price-list-${Date.now()}`;
    const pdf = `${tmpdir()}/${marker}.pdf`;
    writeFileSync(pdf, `%PDF-1.4\n% ${marker}\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n`);

    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const accepts = await page.$eval('input[data-media-input]', (input) => input.getAttribute('accept'));
    report.verdict('the file chooser offers documents as well as pictures',
      accepts.includes('.pdf') && accepts.includes('.docx') && accepts.includes('.jpg') && !accepts.includes('.html'), accepts);

    const input = await page.$('input[data-media-input]');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 60000 }),
      input.uploadFile(pdf),
    ]);

    await page.goto(`${BASE}/admin/media?kind=files`, { waitUntil: 'networkidle2' });
    const row = await page.$$eval('tr.media-row', (rows, wanted) => {
      const found = rows.find((r) => r.textContent.includes(wanted));
      return found ? {
        href: found.querySelector('a.media-link').getAttribute('href'),
        facts: found.querySelector('.media-facts').textContent.trim(),
        tile: !!found.querySelector('.media-row-file'),
      } : null;
    }, marker);
    report.verdict('the Files view lists it, with its type and downloads where a picture has its size',
      row !== null && row.tile && /PDF · 0 downloads/.test(row.facts), JSON.stringify(row));
    if (row === null) {
      return;
    }
    const url = row.href.startsWith('http') ? row.href : `${BASE}${row.href}`;

    try {
      await page.goto(`${BASE}/admin/media?kind=pictures`, { waitUntil: 'networkidle2' });
      const inPictures = await page.evaluate((wanted) => document.body.textContent.includes(wanted), marker);
      report.verdict('the Pictures view does not', !inPictures, inPictures ? 'listed among pictures' : 'not there');

      await page.goto(url, { waitUntil: 'networkidle2' });
      const own = await page.evaluate(() => ({
        address: (document.querySelector('.media-facts-list a') || {}).href || '',
        focal: !!document.querySelector('[data-focal-form]'),
      }));
      await report.shot(page, '01-file-page', { fullPage: false });
      report.verdict('its page gives the address it is downloaded from, and no picture controls',
        own.address.includes(`/download/`) && own.address.endsWith(`/${marker}`) && !own.focal, JSON.stringify(own));

      // As a visitor would: a fresh context with no admin cookie, reading what came back.
      const visitor = await page.browser().createBrowserContext();
      const tab = await visitor.newPage();
      // On the site first: from a blank tab the fetch is cross-origin, and was refused as
      // such — a "Failed to fetch" that was the instrument, not the download.
      await tab.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const got = await tab.evaluate(async (address) => {
        const response = await fetch(address);
        const text = await response.text();
        return {
          status: response.status,
          type: response.headers.get('content-type'),
          disposition: response.headers.get('content-disposition'),
          nosniff: response.headers.get('x-content-type-options'),
          pdf: text.startsWith('%PDF'),
        };
      }, own.address).catch((error) => ({ error: String(error) }));
      await visitor.close();
      report.verdict('a visitor gets the file itself, as an attachment of its own type and name',
        got.status === 200 && got.type === 'application/pdf' && (got.disposition || '').includes(`filename="${marker}.pdf"`)
          && got.nosniff === 'nosniff' && got.pdf, JSON.stringify(got));

      // Counted: the visitor's download, not the admin's visits to the library (D-126). The
      // visitor went through the site's home page first, as a real one does, and so this also
      // holds the site to giving a visitor no session cookie (D-128).
      await page.goto(`${BASE}/admin/media?kind=files`, { waitUntil: 'networkidle2' });
      const counted = await page.$$eval('tr.media-row', (rows, wanted) => {
        const found = rows.find((r) => r.textContent.includes(wanted));
        return found ? found.querySelector('.media-facts').textContent.trim() : null;
      }, marker);
      report.verdict('the library counts the visitor\'s download', /PDF · 1 download\b/.test(counted || ''), String(counted));
    } finally {
      await page.goto(url, { waitUntil: 'networkidle2' });
      const gone = await attemptDelete(page);
      report.verdict('the scenario removes the file it uploaded', gone !== null && /deleted/i.test(gone), String(gone));
    }
  },
};
