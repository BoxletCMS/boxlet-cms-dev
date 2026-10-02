/*
 * One place for what every scenario would otherwise copy.
 *
 * ONE TARGET: THE COPY (PLAN.md D-033, D-174). Every scenario runs against the throwaway copy
 * under ~/boxlet-browser, never the development site — the owner's rule of 2026-10-02: "Dev
 * site je za mene; testovi ga ne pišu." The development site is the owner's to work in, and
 * a check that writes there writes over their work. sync-copy.sh brings the copy to this
 * checkout's code and prepares what scenarios need of it (prepare-copy.php, copy-router.php).
 * `BASE` and `ADMIN` are the copy's; `copy: true` on a scenario is kept as the older marking
 * and means nothing more.
 */
/*
 * EVERYTHING OUTSIDE THE REPOSITORY IS CONFIGURATION (PLAN.md D-029). The suite lives in
 * the repository; the browser, the site copy, the photographs and the screenshots do not,
 * and their places differ on every machine. Each is an environment variable with the
 * default this machine has always used, so a checkout elsewhere sets what it needs and
 * edits no code.
 */
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const outside = process.env.BOXLET_SUITE_HOME ?? `${process.env.HOME}/boxlet-browser`;

/**
 * The throwaway copy, served from ~/boxlet-browser by
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8100 -t fresh/public dev-router.php
 * More than one worker, because the installer checks URL rewriting by asking the server
 * from inside a request, and a single-process server cannot answer itself (D-042). The router
 * is sync-copy.sh's, from copy-router.php: it answers a file on disk as nginx would.
 */
export const COPY_BASE = process.env.BOXLET_SUITE_COPY_BASE ?? 'http://127.0.0.1:8100';

/** Where every scenario runs: the copy (D-174). Never the development site. */
export const BASE = COPY_BASE;

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

/** The copy's administrator, created by 01-install. Throwaway, so it can live here. */
export const COPY_ADMIN = {
  email: 'owner@example.test',
  // The admin step requires at least 12 characters.
  password: 'correct horse battery staple',
};

/** Who every scenario logs in as: the copy's administrator (D-174). */
export const ADMIN = COPY_ADMIN;

export const SITE_NAME = 'Checklist Site';
