/*
 * Addresses (SPEC §5.1).
 *
 *   - with primary en: /en/x returns 301 to /x, and / is the home page
 *   - a second locale: /hr/ is its home page, /hr redirects to /hr/, a disabled code 404s
 *   - the canonical link is present on pages and absent on error pages
 *
 * A language is added from the admin (Settings → Languages, D-043) and removed again. The
 * ROUTING is checked with the second locale seeded straight into this throwaway install's
 * SQLite, as it always was: a language added from the admin has no home page to visit.
 */
import { execFileSync } from 'node:child_process';
import { COPY_BASE as BASE, SITE_DIR, ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

/** Runs a tiny PHP snippet against the throwaway copy's database. */
function sql(statement) {
  return execFileSync('php', ['-r', `$pdo = new PDO("sqlite:${SITE_DIR}/storage/database.sqlite"); ${statement}`], {
    encoding: 'utf8',
  }).trim();
}

/** Follows nothing: the status and Location of one request. */
async function raw(page, url) {
  return page.evaluate(async (target) => {
    const response = await fetch(target, { redirect: 'manual' });
    return { status: response.status, type: response.type };
  }, url);
}

export default {
  name: 'addresses',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

  async run({ page, report }) {
    // A page to aim at, and the home page.
    const home = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
    const homeCanonical = await page.$eval('link[rel="canonical"]', (el) => el.href).catch(() => null);
    await report.shot(page, '01-home');
    report.verdict('/ is the home page', home.status() === 200 && homeCanonical !== null,
      `status ${home.status()}, canonical ${homeCanonical ?? 'ABSENT'}`);

    // /en/about must redirect to /about, permanently.
    const prefixed = await page.goto(`${BASE}/en/about`, { waitUntil: 'networkidle2' });
    const chain = prefixed.request().redirectChain().map((r) => `${r.response()?.status()} ${new URL(r.url()).pathname}`);
    report.verdict('/en/x returns 301 to /x',
      chain.some((step) => step.startsWith('301')) && new URL(page.url()).pathname === '/about',
      `chain: ${JSON.stringify(chain)}, landed at ${new URL(page.url()).pathname}`);

    // The canonical link: present on a page, absent on an error page.
    await page.goto(`${BASE}/about`, { waitUntil: 'networkidle2' });
    const pageCanonical = await page.$eval('link[rel="canonical"]', (el) => el.href).catch(() => null);
    const notFound = await page.goto(`${BASE}/no-such-address-here`, { waitUntil: 'networkidle2' });
    const errorCanonical = await page.$eval('link[rel="canonical"]', (el) => el.href).catch(() => null);
    report.verdict('the canonical link is on pages and not on error pages',
      pageCanonical !== null && errorCanonical === null,
      `/about: ${pageCanonical ?? 'ABSENT'}; 404 (status ${notFound.status()}): ${errorCanonical ?? 'absent'}`);

    // ---- the second locale --------------------------------------------------------------
    // ADDED AND REMOVED FROM THE ADMIN (D-202): Settings → Languages, as the owner does it. It
    // reported NOT CHECKABLE, "no admin path exists", long after D-043 built one. A language
    // none of this scenario's checks use, so the routing below is untouched by it, and taken
    // away again through its own delete, which a language with no pages has.
    await login(page, BASE, ADMIN.email, ADMIN.password);
    await page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle2' });
    const added = await page.$$eval('form.language-add select[name="code"] option', (options) => {
      const codes = options.map((o) => o.value).filter((v) => v !== '');
      return ['it', 'fr', 'es', 'nl', 'pt'].find((c) => codes.includes(c)) || codes.find((c) => !['en', 'hr', 'de'].includes(c)) || null;
    }).catch(() => null);
    if (added === null) {
      report.fail('a language added from the admin is listed, and removed again', 'Settings offers no language to add');
    } else {
      await page.select('form.language-add select[name="code"]', added);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form.language-add button[type="submit"]')]);
      const listed = await page.$$eval('.languages-table td.language-code code', (els) => els.map((el) => el.textContent.trim()));
      const remove = `form[action$="/languages/${added}/delete"] button[type="submit"]`;
      const removable = (await page.$(remove)) !== null;
      if (removable) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(remove)]);
      }
      const after = await page.$$eval('.languages-table td.language-code code', (els) => els.map((el) => el.textContent.trim()));
      report.verdict('a language added from the admin is listed, and removed again',
        listed.includes(added) && removable && !after.includes(added),
        `added ${added}: listed ${JSON.stringify(listed)}, delete offered ${removable}, after it ${JSON.stringify(after)}`);
    }

    sql(`$pdo->exec("INSERT OR REPLACE INTO locales (code, label, is_primary, fallback, sort, enabled) `
      + `VALUES ('hr', 'Hrvatski', 0, 'en', 1, 1)");`);
    sql(`$pdo->exec("INSERT OR REPLACE INTO locales (code, label, is_primary, fallback, sort, enabled) `
      + `VALUES ('de', 'Deutsch', 0, 'en', 2, 0)");`);
    // A home page for the new locale: the empty slug is a locale's home (SPEC §5.1).
    //
    // IT IS TAKEN AWAY AGAIN, and the id is captured here rather than looked up later by
    // title — a title is something a person can be halfway through typing. Without this the
    // scenario worked exactly once: pages has UNIQUE (locale, slug), so every run after the
    // first died on the insert and reported "the scenario itself", which reads like the
    // product breaking. The same disease as the renamed photographs — state left behind.
    //
    // UNLESS THERE IS ONE: the demo has translated its home page into Croatian since D-167,
    // and seeding a second died on that same constraint. Then that page is the one checked,
    // and nothing is removed afterwards — it is the demo's, not this scenario's.
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const existing = Number(sql('echo (string) $pdo->query("SELECT id FROM pages WHERE locale = \'hr\' AND slug = \'\'")->fetchColumn();'));
    const hrPageId = existing > 0 ? 0 : Number(sql(
      `$pdo->exec("INSERT INTO pages (content_group_id, locale, slug, title, status, sort, translation_status, created_at, updated_at) `
      + `VALUES (NULL, 'hr', '', 'Naslovnica', 'published', 0, 'source', '${now}', '${now}')"); `
      + 'echo $pdo->lastInsertId();',
    ));

    try {
    const hrHome = await page.goto(`${BASE}/hr/`, { waitUntil: 'networkidle2' });
    await report.shot(page, '02-hr-home');
    report.verdict('/hr/ is the second locale\'s home page', hrHome.status() === 200,
      `status ${hrHome.status()}, h1 "${await page.$eval('h1', (e) => e.textContent.trim()).catch(() => '(none)')}"`);

    const hrBare = await page.goto(`${BASE}/hr`, { waitUntil: 'networkidle2' });
    const hrChain = hrBare.request().redirectChain().map((r) => `${r.response()?.status()} ${new URL(r.url()).pathname}`);
    report.verdict('/hr redirects to /hr/',
      hrChain.length > 0 && new URL(page.url()).pathname === '/hr/',
      `chain: ${JSON.stringify(hrChain)}, landed at ${new URL(page.url()).pathname}`);

    const disabled = await page.goto(`${BASE}/de/anything`, { waitUntil: 'networkidle2' });
    const disabledChain = disabled.request().redirectChain().length;
    report.verdict('a disabled locale code returns 404 without redirecting',
      disabled.status() === 404 && disabledChain === 0,
      `status ${disabled.status()}, ${disabledChain} redirects`);
    } finally {
      // By exact id, whatever happened above. The locales rows stay: they are written with
      // INSERT OR REPLACE, so they cost the next run nothing, and the verdicts above are
      // about them. The page is the row that cannot be written twice.
      if (Number.isInteger(hrPageId) && hrPageId > 0) {
        sql(`$pdo->exec("DELETE FROM page_blocks WHERE page_id = ${hrPageId}"); `
          + `$pdo->exec("DELETE FROM pages WHERE id = ${hrPageId}");`);
      }
      report.verdict('the scenario removes the page it seeded',
        existing > 0 || (Number.isInteger(hrPageId) && hrPageId > 0
        && sql(`echo (string) $pdo->query("SELECT COUNT(*) FROM pages WHERE id = ${hrPageId}")->fetchColumn();`) === '0'),
        existing > 0 ? `seeded none: the demo's own Croatian home, page ${existing}` : `seeded page id ${hrPageId}`);
    }
  },
};
