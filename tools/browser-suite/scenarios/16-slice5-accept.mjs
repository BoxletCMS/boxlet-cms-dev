/*
 * Slice 5's acceptance criterion, word for word from docs/SPEC.md §8:
 *
 *   "upload a 4 MB photo, place it in a hero, confirm the served file is WebP and under
 *    200 KB, confirm a second request does not hit PHP."
 *
 * Two notes on reading it honestly.
 *
 * WEBP OR AVIF. The encoder generates both (MediaEncoder::FORMATS) and a browser that
 * accepts AVIF is served AVIF, which §5.1 intends. §8's wording predates that. So this
 * records what is ACTUALLY served and reports it against both readings rather than
 * quietly calling AVIF a pass.
 *
 * "DOES NOT HIT PHP" IS NOT TESTABLE HERE, and saying otherwise would be the easiest
 * false pass in this suite. The copy runs under `php -S`, where every request is PHP by
 * definition — dev-router.php only returns false so the same PHP process serves the file.
 * How a real server does it on nginx and Apache is PLAN.md O-2, still open. What can be
 * shown is that the variant is a real file on disk under public/m/, which is what a web
 * server would serve without PHP. That is reported as NOT CHECKABLE with the reason, not
 * as a pass.
 */
import { BASE, ADMIN } from '../config.mjs';
import { login, submitVia, alerts } from '../harness.mjs';

const PHOTO = '/tmp/claude-1018/-home-svejedobro-boxlet-htdocs-boxlet-svejedobro-hr/146e0567-aeae-48ea-b763-f667b4ed0792/scratchpad/big-photo.jpg';
const NAME = 'big-photo';
const LIMIT = 200 * 1024;

const human = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(2)} MB` : `${Math.round(n / 1024)} KB`);

export default {
  name: 'slice5-accept',

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('slice5: log in', `could not log in; at ${page.url()}`);
      return;
    }

    // ---- upload the photograph -----------------------------------------------------------
    await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
    const already = await page.$$eval('li.media-card .media-name', (els) => els.map((e) => e.textContent.trim()));
    if (!already.includes(NAME)) {
      const input = await page.$('input[name="files[]"]');
      if (input === null) { report.fail('slice5: upload a 4 MB photo', 'no file input on the library'); return; }
      // Choosing a file uploads it at once (D-038): the navigation is the upload.
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 120000 }),
        input.uploadFile(PHOTO),
      ]);
    }

    const card = await page.$$eval('li.media-card', (els, wanted) => {
      const found = els.find((el) => el.querySelector('.media-name')?.textContent.trim() === wanted);
      if (!found) return null;
      const href = found.querySelector('a.media-card-link')?.getAttribute('href') || '';
      return { id: Number((href.match(/\/(\d+)$/) || [])[1]), facts: found.textContent.replace(/\s+/g, ' ').trim() };
    }, NAME);

    if (card === null || !Number.isInteger(card.id)) {
      report.fail('slice5: upload a 4 MB photo', `no library card named ${NAME} after uploading`);
      return;
    }
    report.pass('upload a 4 MB photo', `media ${card.id}; the library says: ${card.facts.slice(0, 110)}`);

    // ---- place it in a hero ---------------------------------------------------------------
    await page.goto(`${BASE}/admin/pages/1/form`, { waitUntil: 'networkidle2' });
    const types = await page.$$eval('[data-block] input[name$="[type]"]', (els) => els.map((e) => e.value));
    const index = types.indexOf('hero');
    if (index === -1) { report.fail('place it in a hero', `page 1 has no hero block: ${types.join(', ')}`); return; }

    await page.select(`select[name="blocks[${index}][image]"]`, String(card.id));
    await submitVia(page, `select[name="blocks[${index}][image]"]`, 60000);
    const refused = await alerts(page);

    await page.goto(`${BASE}/admin/pages/1/form`, { waitUntil: 'networkidle2' });
    const stored = await page.$eval(`select[name="blocks[${index}][image]"]`,
      (s) => s.options[s.selectedIndex]?.textContent.trim() || '(none)');
    report.verdict('place it in a hero', stored === NAME,
      `hero is block ${index}; stored picture is ${stored}`
      + (refused.length ? `; the save was REFUSED: ${JSON.stringify(refused)}` : ''));
    if (stored !== NAME) { return; }

    // ---- what the front end actually serves -----------------------------------------------
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const chosen = await page.$eval(`img[src*="${NAME}"], picture img`, (img) => ({
      current: img.currentSrc || img.src,
      width: img.naturalWidth,
      height: img.naturalHeight,
    })).catch(() => null);

    if (chosen === null) { report.fail('the hero renders the picture', 'no <img> for it on the home page'); return; }

    const measured = await page.evaluate(async (url) => {
      const response = await fetch(url, { cache: 'reload' });
      const blob = await response.blob();
      return { status: response.status, type: response.headers.get('content-type'), bytes: blob.size, server: response.headers.get('server') };
    }, chosen.current);

    const preset = (chosen.current.match(/\/m\/([a-z]+)\//) || [])[1] || '?';
    const isWebp = (measured.type || '').includes('webp');
    const isAvif = (measured.type || '').includes('avif');

    report.verdict('the served file is WebP (SPEC §8) or AVIF (§5.1)', isWebp || isAvif,
      `preset ${preset}, ${chosen.width}x${chosen.height}, ${measured.type}, ${human(measured.bytes)}`
      + (isAvif && !isWebp ? ' — AVIF, which §8 does not name but §5.1 intends' : ''));

    report.verdict('the served file is under 200 KB', measured.bytes < LIMIT,
      `${human(measured.bytes)} of the 4 MB original (${Math.round((measured.bytes / (4.16 * 1048576)) * 100)}% of it)`);

    // ---- the half this server cannot answer ------------------------------------------------
    const second = await page.evaluate(async (url) => {
      const response = await fetch(url, { cache: 'reload' });
      return { status: response.status, server: response.headers.get('server'), powered: response.headers.get('x-powered-by') };
    }, chosen.current);

    report.skip('a second request does not hit PHP',
      `not answerable here: this copy runs under \`php -S\`, where every request is PHP by `
      + `definition — the second request answered ${second.status} with Server: `
      + `${second.server || '(none)'}${second.powered ? `, X-Powered-By: ${second.powered}` : ''}, `
      + `and dev-router.php only returns false so the same PHP process serves the file. `
      + `Measured against the live demo instead, where nginx serves it: the variant comes back `
      + `with etag and last-modified, which the PHP-rendered pages there do not carry. That is `
      + `consistent with a file served from disk but is NOT proof — the same nginx sends no `
      + `X-Powered-By for either, so the absence of a PHP header distinguishes nothing. Proving `
      + `it needs the server config, which is PLAN.md O-2 and still open.`);

    await report.shot(page, '01-hero-with-4mb-photo');
  },
};
