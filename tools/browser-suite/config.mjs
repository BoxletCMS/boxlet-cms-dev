/*
 * One place for what every scenario would otherwise copy.
 *
 * 2g runs against the FRESH copy, which scenario 01 installs from nothing with the demo
 * option. That gives a home page, four demo pages covering every block and section style
 * including a contrast surface, and a known starting state. The older site copy on 8099
 * holds one leftover page and no home page, so it cannot exercise most of the checklist.
 */
/*
 * EVERYTHING OUTSIDE THE REPOSITORY IS CONFIGURATION (PLAN.md D-029). The suite lives in
 * the repository; the browser, the site copy, the photographs and the screenshots do not,
 * and their places differ on every machine. Each is an environment variable with the
 * default this machine has always used, so a checkout elsewhere sets what it needs and
 * edits no code.
 */
const outside = process.env.BOXLET_SUITE_HOME ?? `${process.env.HOME}/boxlet-browser`;

export const BASE = process.env.BOXLET_SUITE_BASE ?? 'http://127.0.0.1:8100';

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

export const ADMIN = {
  email: 'owner@example.test',
  // The admin step requires at least 12 characters.
  password: 'correct horse battery staple',
};

export const SITE_NAME = 'Checklist Site';
