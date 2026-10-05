/*
 * BROWSE LIBRARY (PLAN.md D-195, the owner): from Appearance, the sets Boxlet ships beside its
 * characters, as cards, each added as a character in one press — the same as Import → "Add as
 * character" — and then a tile among the characters, marked Custom, that the library no longer
 * offers twice.
 *
 * On the copy. The character it adds is deleted again, as the owner would from its ⋯ menu,
 * and the library offers it once more.
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

const SLUG = 'retreat';

export default {
  name: 'library',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('library: log in', `could not log in as ${ADMIN.email || '(no admin configured)'}`);
      return;
    }
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    const cleanup = () => page.evaluate(async (base, slug) => {
      if (!document.querySelector(`button[value="character:delete:${slug}"]`)) return 'nothing to clean';
      const form = new FormData(document.getElementById('design-form'));
      form.set('action', `character:delete:${slug}`);
      return (await fetch(`${base}/admin/appearance`, { method: 'POST', body: form, credentials: 'same-origin' })).status;
    }, BASE, SLUG);
    const cards = () => page.$$eval('.library-card', (els) => els.map((c) => ({
      name: (c.querySelector('.library-name') || {}).textContent?.trim() || '',
      chips: c.querySelectorAll('.library-chips svg').length,
      fonts: (c.querySelector('.library-shape') || {}).textContent?.trim() || '',
      add: (c.querySelector('form.library-add') || {}).getAttribute?.('action') || '',
      added: !!c.querySelector('.library-tag'),
    })));

    try {
      // A run cut short may have left it: put the library back as it ships.
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      await cleanup();
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      const before = await page.$(`button[value="preset:${SLUG}"]`) === null;

      // ---- Browse library, from Appearance --------------------------------------------------------
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('a.library-browse')]);
      const shown = await cards();
      await report.shot(page, 'library', { fullPage: true });
      const offered = shown.find((c) => c.add.endsWith(`/browse/${SLUG}`));
      report.verdict('Browse library shows the library\'s sets as cards: a name, three colours and two typefaces each, and Add on one not yet added',
        new URL(page.url()).pathname === '/admin/appearance/browse' && shown.length >= 7 && shown.every((c) => c.name !== '' && c.chips === 3 && / over /.test(c.fonts)) && !!offered && before,
        JSON.stringify(shown.map((c) => `${c.name}${c.added ? ' (added)' : ''}`)));

      // ---- one press ------------------------------------------------------------------------------
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`form.library-add[action$="/browse/${SLUG}"] button`)]);
      const after = await page.evaluate((slug) => ({
        path: location.pathname,
        notice: (document.querySelector('.notice, .flash, [role="status"]') || {}).textContent?.trim() || '',
        tile: !!document.querySelector(`button[value="preset:${slug}"]`),
        custom: !!document.querySelector(`button[value="preset:${slug}"]`)?.closest('.character-tile')?.querySelector('.tile-custom'),
      }), SLUG);
      report.verdict('one press adds it as a character: back on Appearance, said, a tile marked Custom',
        after.path === '/admin/appearance' && /Retreat.*added as a character/.test(after.notice) && after.tile && after.custom, JSON.stringify(after));

      await page.goto(`${BASE}/admin/appearance/browse`, { waitUntil: 'networkidle2' });
      const again = (await cards()).find((c) => c.name === 'Retreat');
      report.verdict('the library says it is added and no longer offers it', !!again && again.added && again.add === '', JSON.stringify(again));
    } finally {
      await page.goto(`${BASE}/admin/appearance`, { waitUntil: 'networkidle2' });
      const removed = await cleanup();
      await page.goto(`${BASE}/admin/appearance/browse`, { waitUntil: 'networkidle2' });
      const back = (await cards()).find((c) => c.name === 'Retreat');
      report.verdict('cleanup: the character deleted, and the library offers it again', removed !== 'nothing to clean' && !!back && back.add !== '', `${removed} ${JSON.stringify(back)}`);
    }
  },
};
