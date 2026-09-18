/*
 * Addresses (SPEC §5.1).
 *
 *   - with primary en: /en/x returns 301 to /x, and / is the home page
 *   - a second locale: /hr/ is its home page, /hr redirects to /hr/, a disabled code 404s
 *   - the canonical link is present on pages and absent on error pages
 *
 * On the second locale: there is NO admin path for adding one. app/Modules/I18n holds a
 * .gitkeep and languages.php, app/Modules/Settings holds a .gitkeep, and adding a language
 * from the admin is Slice 6. So that part of the item is reported NOT CHECKABLE as an
 * admin action, and the ROUTING it would exercise — which lives in the Router today — is
 * checked by seeding the locale straight into this throwaway install's SQLite, which is
 * stated in the verdict rather than hidden.
 */
import { execFileSync } from 'node:child_process';
import { COPY_BASE as BASE, SITE_DIR } from '../config.mjs';

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
    report.skip('enable a second locale from the admin',
      'no admin path exists: app/Modules/I18n is a .gitkeep and languages.php, '
      + 'app/Modules/Settings is a .gitkeep, and adding a language from the admin is Slice 6. '
      + 'The routing below is checked by seeding the locale directly into this throwaway install.');

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
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const hrPageId = Number(sql(
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
        Number.isInteger(hrPageId) && hrPageId > 0
        && sql(`echo (string) $pdo->query("SELECT COUNT(*) FROM pages WHERE id = ${hrPageId}")->fetchColumn();`) === '0',
        `seeded page id ${hrPageId}`);
    }
  },
};
