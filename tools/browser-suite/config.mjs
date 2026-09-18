/*
 * One place for what every scenario would otherwise copy.
 *
 * TWO TARGETS (PLAN.md D-033). Most scenarios run against the development site itself,
 * https://boxlet.svejedobro.hr, which is this checkout: what they check is the code as it
 * stands, with no copy to fall behind. A few cannot: 01-install wipes a site to install it
 * from nothing, 99-lockout locks the account for a quarter of an hour, and 06-addresses and
 * 08-update write into the site's SQLite database and migrations directory. Those declare
 * `copy: true` and run against the throwaway copy under ~/boxlet-browser, never the
 * development site.
 */
/*
 * EVERYTHING OUTSIDE THE REPOSITORY IS CONFIGURATION (PLAN.md D-029). The suite lives in
 * the repository; the browser, the site copy, the photographs and the screenshots do not,
 * and their places differ on every machine. Each is an environment variable with the
 * default this machine has always used, so a checkout elsewhere sets what it needs and
 * edits no code.
 */
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const outside = process.env.BOXLET_SUITE_HOME ?? `${process.env.HOME}/boxlet-browser`;

/** The development site: where every scenario runs unless it declares `copy: true`. */
export const BASE = process.env.BOXLET_SUITE_BASE ?? 'https://boxlet.svejedobro.hr';

/** The throwaway copy, served by `php -S 127.0.0.1:8100` from its public/ directory. */
export const COPY_BASE = process.env.BOXLET_SUITE_COPY_BASE ?? 'http://127.0.0.1:8100';

/** The copy scenario 01 installs into. Its storage is read directly for the install token. */
export const SITE_DIR = process.env.BOXLET_SUITE_SITE_DIR ?? `${outside}/fresh`;

/** Photographs the media scenarios upload: downloads, not fixtures we can commit. */
export const PHOTOS = process.env.BOXLET_SUITE_PHOTOS ?? `${outside}/photos`;

/** Where screenshots are written. Output, never input. */
export const SHOTS = process.env.BOXLET_SUITE_SHOTS ?? `${outside}/shots`;

/**
 * Where node_modules lives. The suite is in the repository now; Puppeteer is not, and by
 * D-013 it stays outside. Node resolves node_modules upward from the importing file, which
 * worked while the suite sat beside them and stopped the moment it moved — every scenario
 * failed with "Cannot find package 'puppeteer'". So the place is configuration too, and
 * harness.mjs resolves through it rather than relying on where the file happens to sit.
 */
export const MODULES = process.env.BOXLET_SUITE_MODULES ?? `${outside}/node_modules`;

/** Where puppeteer put chrome-headless-shell. */
export const CHROME = process.env.BOXLET_SUITE_CHROME
  ?? `${process.env.HOME}/.cache/puppeteer/chrome-headless-shell`;

/**
 * The checkout this suite belongs to — the one place that is NOT outside configuration,
 * because the suite now lives inside it: two levels up from tools/browser-suite/.
 *
 * 01-install restores public/install.php from here. The installer deletes itself after a
 * successful install, which is correct and is also why that scenario could only ever run
 * once against a copy.
 */
export const CHECKOUT = process.env.BOXLET_SUITE_CHECKOUT
  ?? resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * The development site's administrator. A real password on a public site, so it is never
 * in the repository: it comes from the environment, or from a JSON file outside it
 * ({"email": "...", "password": "..."}). Missing, the scenarios fail at login and say so.
 */
const adminFile = process.env.BOXLET_SUITE_ADMIN_FILE ?? `${outside}/dev-admin.json`;
const stored = existsSync(adminFile) ? JSON.parse(readFileSync(adminFile, 'utf8')) : {};
export const ADMIN = {
  email: process.env.BOXLET_SUITE_EMAIL ?? stored.email ?? '',
  password: process.env.BOXLET_SUITE_PASSWORD ?? stored.password ?? '',
};

/** The copy's administrator, created by 01-install. Throwaway, so it can live here. */
export const COPY_ADMIN = {
  email: 'owner@example.test',
  // The admin step requires at least 12 characters.
  password: 'correct horse battery staple',
};

export const SITE_NAME = 'Checklist Site';
