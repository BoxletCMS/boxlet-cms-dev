/*
 * 3a: updating an existing install (PLAN.md D-019).
 *
 * A site that has taken new code carrying a migration its database has not applied. The
 * admin shows one screen with one button; the public site answers 503 until the button
 * is pressed.
 *
 * The pending state is created the way a real update arrives: a migration file is written
 * into the copy's migrations/ directory. It is removed again at the end, whatever
 * happened, so the copy is left as the suite found it — and the file is named with this
 * run's own number so two runs cannot collide.
 *
 * Nothing here presses anything on the live site. This is the throwaway copy.
 */
import { writeFileSync, unlinkSync, existsSync, rmSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { COPY_BASE as BASE, SITE_DIR, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login, clickAndWait, heading, SLOW } from '../harness.mjs';

const MIGRATION = '9001_checklist_update.sql';
const SQL = 'CREATE TABLE checklist_update_thing (\n    id {{pk}},\n    note VARCHAR(50) NULL\n);\n';

/**
 * Whether the database RECORDS the migration as applied.
 *
 * The verdict below used to read the screen, and the screen said what it was asked: while
 * the press was hitting the wrong button, "pressing the button applies the migration"
 * passed and nothing had been applied at all. Text on a page is not evidence that a
 * migration ran — the migrations table is.
 *
 * Node has no SQLite reader, so this asks PHP, which the copy under test has by definition.
 */
function recordedAsApplied(name) {
  const php = `$d = new PDO("sqlite:${SITE_DIR}/storage/database.sqlite"); `
    + `echo (string) $d->query("SELECT COUNT(*) FROM migrations WHERE filename = " . $d->quote("${name}"))->fetchColumn();`;
  try {
    return execSync(`php -r '${php}'`).toString().trim() === '1';
  } catch {
    return false;
  }
}

/** Removes what this scenario's migration created, by exact name, in the throwaway copy. */
function forgetMigration(name) {
  const php = `$d = new PDO("sqlite:${SITE_DIR}/storage/database.sqlite"); `
    + `$d->exec("DROP TABLE IF EXISTS checklist_update_thing"); `
    + `$s = $d->prepare("DELETE FROM migrations WHERE filename = ?"); $s->execute([${JSON.stringify(name)}]);`;
  try {
    execSync(`php -r '${php}'`);
  } catch {
    // Reported by the verdicts; a copy left with one stray row is not worth a throw here.
  }
}

export default {
  name: 'update',
  // Runs against the throwaway copy, never the development site (config.mjs).
  copy: true,

  async run({ page, report }) {
    const file = `${SITE_DIR}/migrations/${MIGRATION}`;
    const marker = `${SITE_DIR}/storage/migrations.state`;

    try {
      // ---- a new version arrives, carrying a migration --------------------------------
      writeFileSync(file, SQL);
      if (existsSync(marker)) {
        // The marker is what makes detection cheap; a real deploy replaces the whole
        // tree and never carries one, so remove it as an upload would.
        rmSync(marker);
      }

      // ---- the public site waits ------------------------------------------------------
      const publicResponse = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const retryAfter = publicResponse.headers()['retry-after'];
      const body = await page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
      await report.shot(page, '01-public-503');

      report.verdict('while an update is pending the public site answers 503',
        publicResponse.status() === 503 && retryAfter !== undefined,
        `status ${publicResponse.status()}, Retry-After ${retryAfter ?? 'ABSENT'}, page says "${body.slice(0, 80)}"`);

      // ---- the admin shows the update screen -------------------------------------------
      if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
        report.fail('update: log in', `could not log in; at ${page.url()}`);
        return;
      }

      await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      const redirected = page.url().endsWith('/admin/update');
      await report.shot(page, '02-admin-update-screen');

      const screen = await page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
      report.verdict('every admin screen becomes the update screen', redirected,
        `/admin/pages landed at ${page.url()}`);
      report.verdict('the screen names the pending migration and offers one button',
        screen.includes(MIGRATION) && (await page.$('form button[type="submit"]')) !== null,
        `the screen ${screen.includes(MIGRATION) ? 'lists' : 'DOES NOT list'} ${MIGRATION}`);

      // On a SQLite copy Boxlet takes its own backup and says so.
      report.verdict('the screen says what happens about a backup',
        /storage\/backups|backup/i.test(screen),
        screen.includes('storage/backups') ? 'it names storage/backups' : 'no backup sentence found');

      // ---- press it -------------------------------------------------------------------
      // SCOPED TO THE UPDATE FORM. `form button[type="submit"]` matches the admin shell's
      // Log out button first — it is the first form in the document, exactly as harness.mjs
      // warns — so this pressed Log out, nothing was applied, and the three verdicts after
      // it failed while the product was behaving correctly.
      await clickAndWait(page, 'form[action$="/admin/update"] button[type="submit"]');
      const after = await page.evaluate(() => document.body.textContent.replace(/\s+/g, ' ').trim());
      await report.shot(page, '03-after-pressing');

      // APPLIED MEANS RECORDED, not that the screen said so.
      const recorded = recordedAsApplied(MIGRATION);
      report.verdict('pressing the button applies the migration', recorded,
        `migrations ${recorded ? 'records' : 'DOES NOT record'} ${MIGRATION}; `
        + `the screen says "${after.slice(0, 120)}"`);

      const backups = existsSync(`${SITE_DIR}/storage/backups`);
      report.verdict('a SQLite database is copied into storage/backups first', backups,
        backups ? 'storage/backups exists' : 'no backup directory was written');

      // ---- and the site is back --------------------------------------------------------
      const live = await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' });
      const liveHeading = await heading(page);
      await report.shot(page, '04-site-back');
      report.verdict('the site works again once the update has run',
        live.status() === 200 && liveHeading.length > 0,
        `status ${live.status()}, home page h1 "${liveHeading}"`);

      const admin = await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle2' });
      report.verdict('the admin is reachable again',
        admin.status() === 200 && page.url().endsWith('/admin/pages'),
        `status ${admin.status()}, at ${page.url()}`);
    } finally {
      // The copy is left as it was found, whatever happened above — including the database.
      // Removing only the FILE left the applied row and the table behind, so the next run
      // met a half-applied world it had not made.
      if (existsSync(file)) {
        unlinkSync(file);
      }
      forgetMigration(MIGRATION);
      if (existsSync(marker)) {
        rmSync(marker);
      }
    }
  },
};
