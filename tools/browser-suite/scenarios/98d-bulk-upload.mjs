/*
 * SEVERAL PICTURES DROPPED AT ONCE (PLAN.md D-295; the owner, 2026-10-24: seventeen dropped,
 * twelve left "Unfinished", each needing its own Finish processing, and nothing on screen but
 * "Uploading…").
 *
 *   - each file goes up in a request of its own, asking for JSON, not all in one post;
 *   - each has its row: the name, a bar seen part-way while it goes (on a slow line), its state;
 *   - every one ends Done, the library is drawn again, and each new picture is complete, with
 *     a thumbnail that loaded.
 *
 * What it does not reach: a picture LEFT unfinished. On the copy nothing runs out of time, so
 * none is; the finishing of one when the library opens is tested in media_admin_test (the
 * marks, the JSON Finish) and was measured on a copy served with an 8-second limit (D-295).
 *
 * Its pictures are the suite's photographs with bytes after their end, which a JPEG ignores, so
 * each is new to the library; deleted again through each picture's own Delete. On the copy.
 */
import { copyFileSync, appendFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BASE, ADMIN } from '../config.mjs';
import { login, fixtures } from '../harness.mjs';
import { PHOTOS, attemptDelete } from '../media-helpers.mjs';

const SOURCES = ['atelier.jpg', 'desk-wood.jpg', 'tools.jpg', 'workshop.jpg'].map((f) => `${PHOTOS}/${f}`);

export default {
  name: 'bulk-upload',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('bulk-upload: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    if (fixtures(report, 'bulk-upload', SOURCES, 'four photographs') === null) {
      return;
    }
    const stamp = Date.now();
    const dir = mkdtempSync(join(tmpdir(), 'bulk-'));
    const files = SOURCES.map((source, i) => {
      const file = join(dir, `bulk-${stamp}-${i + 1}.jpg`);
      copyFileSync(source, file);
      appendFileSync(file, `bulk ${stamp} ${i}`);
      return file;
    });
    const made = [];
    try {
      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      // The new pictures, by the library before and after: an answer's body cannot be read
      // once the page has been drawn again, which the last one is followed by at once.
      const ids = () => page.$$eval('tr.media-row', (trs) => trs.map((tr) => Number(tr.getAttribute('data-media-id'))));
      const before = new Set(await ids());
      const posts = [];
      page.on('request', (r) => {
        if (r.method() === 'POST' && new URL(r.url()).pathname.endsWith('/admin/media')) {
          posts.push(r.headers().accept || '');
        }
      });
      // A slow line out, so a file's bar can be seen part-way.
      const cdp = await page.target().createCDPSession();
      await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 20, downloadThroughput: -1, uploadThroughput: 300 * 1024 });

      const seen = { rows: 0, partial: false, states: new Set() };
      const reloaded = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 180000 });
      await (await page.$('#media-files')).uploadFile(...files);
      let shot = false;
      for (let i = 0; i < 600; i++) {
        await new Promise((r) => setTimeout(r, 200));
        const now = await page.evaluate(() => ({
          rows: document.querySelectorAll('.upload-item').length,
          partial: [...document.querySelectorAll('.upload-item progress')].some((b) => b.value > 0 && b.value < 100),
          states: [...document.querySelectorAll('.upload-item')].map((r) => r.getAttribute('data-state') || 'waiting'),
        })).catch(() => null);
        if (now === null) {
          break; // the library is being drawn again
        }
        seen.rows = Math.max(seen.rows, now.rows);
        seen.partial = seen.partial || now.partial;
        now.states.forEach((s) => seen.states.add(s));
        if (!shot && now.partial) {
          await page.$eval('[data-upload-progress]', (el) => el.scrollIntoView({ block: 'center' }));
          await report.shot(page, '01-sending', { fullPage: false });
          shot = true;
        }
      }
      await reloaded.catch(() => {});
      await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });

      report.verdict('each file goes up in a request of its own, asking for JSON',
        posts.length === files.length && posts.every((a) => a.includes('application/json')), JSON.stringify(posts));
      report.verdict('each has its row, its bar seen part-way, and it is sent, then Done',
        seen.rows === files.length && seen.partial && seen.states.has('sending') && seen.states.has('done') && !seen.states.has('refused'),
        JSON.stringify({ rows: seen.rows, partial: seen.partial, states: [...seen.states] }));

      await page.goto(`${BASE}/admin/media`, { waitUntil: 'networkidle2' });
      made.push(...(await ids()).filter((id) => !before.has(id)));
      const rows = await page.$$eval('tr.media-row', (trs, ids) => trs.filter((tr) => ids.includes(Number(tr.getAttribute('data-media-id')))).map((tr) => ({
        unfinished: tr.hasAttribute('data-media-unfinished'),
        thumb: (tr.querySelector('img.media-thumb') || {}).naturalWidth || 0,
      })), made);
      report.verdict('every new picture is in the library, complete, with a thumbnail that loaded',
        made.length === files.length && rows.length === files.length && rows.every((r) => !r.unfinished && r.thumb > 0), JSON.stringify({ made, rows }));
    } finally {
      for (const id of made) {
        await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' });
        await attemptDelete(page);
      }
      const left = [];
      for (const id of made) {
        const status = (await page.goto(`${BASE}/admin/media/${id}`, { waitUntil: 'networkidle2' })).status();
        if (status !== 404) {
          left.push(id);
        }
      }
      report.verdict('the scenario\'s pictures are gone again', left.length === 0, left.length ? `left: ${left}` : '');
      rmSync(dir, { recursive: true, force: true });
    }
  },
};
