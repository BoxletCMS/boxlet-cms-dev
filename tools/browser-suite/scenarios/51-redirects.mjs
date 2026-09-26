/*
 * ADDRESSES THAT LEAD ELSEWHERE (PLAN.md D-129).
 *
 * A rule is made on the Redirects screen through its own form, followed as a visitor would
 * follow it — a request with no cookie, read before the redirect is taken — and deleted
 * from the same screen, after which the address answers 404. Then a page is placed under
 * another and visited at its nested address, with its trail in the head (step 3).
 *
 * ON THE COPY, AND IT CLEANS UP: the rule's address carries a marker no earlier run used,
 * and the scenario deletes that exact rule, found by its address (D-090).
 */
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from '../config.mjs';
import { login } from '../harness.mjs';

/** What the server answers, without following it, and with no admin cookie. */
async function visit(path) {
  const response = await fetch(`${BASE}${path}`, {
    redirect: 'manual',
    headers: { 'user-agent': 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0 Safari/537.36' },
  });
  return { status: response.status, location: response.headers.get('location') };
}

/** Submits the form of the row whose first cell is exactly `address`; true if one was found. */
async function deleteRow(page, address) {
  const submitted = await page.evaluate((wanted) => {
    const row = [...document.querySelectorAll('.redirects-table tbody tr')]
      .find((r) => r.querySelector('td')?.textContent.trim() === wanted);
    const form = row?.querySelector('form');
    if (!form) return false;
    form.querySelector('[data-confirm]')?.removeAttribute('data-confirm');
    form.submit();
    return true;
  }, address);
  if (submitted) {
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
  }
  return submitted;
}

export default {
  name: 'redirects',
  copy: true,

  async run({ page, report }) {
    if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
      report.fail('redirects: log in', `could not log in as ${ADMIN.email}`);
      return;
    }
    const marker = `/old-site-${Date.now()}.html`;

    await page.goto(`${BASE}/admin/redirects`, { waitUntil: 'networkidle2' });
    const railed = await page.$eval('a[aria-current="page"]', (a) => a.textContent.trim()).catch(() => '');
    report.verdict('the rail has Redirects, and it is the current screen', railed === 'Redirects', railed);

    // The form's own controls, found inside the form that posts to this screen — never an
    // unscoped submit button, which is the header's Log out.
    const form = 'form[action$="/admin/redirects"]';
    const target = await page.$eval(`${form} #redirect-page`, (select) => {
      const option = [...select.options].find((o) => o.value !== '' && !o.textContent.includes('draft'));
      return option ? { id: option.value, address: option.textContent.split(' — ')[1] } : null;
    });
    if (target === null) {
      report.fail('redirects: a published page to lead to', 'the copy offers none');
      return;
    }
    await page.type(`${form} #redirect-from`, `https://old.example.com${marker.toUpperCase()}`);
    await page.select(`${form} #redirect-page`, target.id);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.$eval(form, (f) => f.submit()),
    ]);

    try {
      const listed = await page.evaluate((wanted) => [...document.querySelectorAll('.redirects-table tbody tr')]
        .some((r) => r.querySelector('td')?.textContent.trim() === wanted), marker);
      await report.shot(page, '01-rule-added', { fullPage: false });
      report.verdict('the whole old address, typed in capitals, is listed as its path in lower case', listed, marker);

      const followed = await visit(marker);
      report.verdict('a visitor asking for it is sent on, permanently, to the page',
        followed.status === 301 && (followed.location || '').endsWith(target.address), JSON.stringify({ ...followed, expected: target.address }));
    } finally {
      await page.goto(`${BASE}/admin/redirects`, { waitUntil: 'networkidle2' });
      const removed = await deleteRow(page, marker);
      const after = await visit(marker);
      report.verdict('the scenario deletes its rule, and the address then answers 404', removed && after.status === 404, JSON.stringify({ removed, ...after }));
    }

    await nested(page, report);
  },
};

/**
 * A page placed under another through the plain editor's own form, then visited: its nested
 * address, the old one sent on, and the trail in its head. The page is put back at the top
 * level in the same run, whatever happened (D-090).
 */
async function nested(page, report) {
  await page.goto(`${BASE}/admin/redirects`, { waitUntil: 'networkidle2' });
  const [parent, child] = await page.$$eval('#redirect-page option', (options) => options
    .filter((o) => o.value !== '' && !o.textContent.includes('draft') && !o.textContent.endsWith('— /'))
    .map((o) => ({ id: o.value, title: o.textContent.split(' — ')[0], address: o.textContent.split(' — ')[1] }))
    .filter((o) => /^\/[a-z0-9-]+$/.test(o.address))
    .slice(0, 2));
  if (!parent || !child) {
    report.fail('nested: two published top-level pages', 'the copy has fewer than two');
    return;
  }

  const place = async (parentId) => {
    await page.goto(`${BASE}/admin/pages/${child.id}/form`, { waitUntil: 'networkidle2' });
    await page.select('form.editor-form #page-parent', parentId);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.$eval('form.editor-form', (form) => form.requestSubmit(form.querySelector('button[value="save"]'))),
    ]);
  };

  const address = `${parent.address}${child.address}`;
  try {
    await place(parent.id);
    // A save lands in the visual editor, not the form it came from; both carry the line.
    const shown = await page.$$eval('#page-slug ~ .hint', (hints) => hints.map((e) => e.textContent.trim()).join(' | '));
    report.verdict('the editor shows the whole address under the slug', shown.includes(address), `${page.url()}: ${shown}`);

    const visited = await fetch(`${BASE}${address}`, { headers: { 'user-agent': 'Mozilla/5.0 Chrome/126.0' } });
    const html = await visited.text();
    const json = (html.match(/<script type="application\/ld\+json">(.*?)<\/script>/s) || [])[1];
    const names = json ? JSON.parse(json).itemListElement.map((i) => i.name) : [];
    report.verdict('the page answers at its nested address', visited.status === 200, `${address}: ${visited.status}`);
    report.verdict('its head names the trail to it for search engines',
      names.length >= 2 && names[names.length - 2] === parent.title && names[names.length - 1] === child.title, JSON.stringify(names));

    const old = await visit(child.address);
    report.verdict('its address from before is sent on to the nested one',
      old.status === 301 && (old.location || '').endsWith(address), JSON.stringify(old));
  } finally {
    await place('');
    const back = await visit(child.address);
    report.verdict('the scenario puts the page back at the top level', back.status === 200, JSON.stringify(back));
  }
}
