/*
 * PROVING THAT A REFACTOR CHANGED NOTHING ON THE SCREEN.
 *
 *   node compare.mjs capture <label>      write one capture of the whole demo site
 *   node compare.mjs diff <before> <after>  compare two captures, property by property
 *
 * D-077 was proved this way — every computed property of every element, 8,699,295
 * comparisons, 0 differing — and the script that did it was thrown away the same day. This
 * is that script, kept, because D-093 needs it three more times: when a page becomes
 * sections (step 2), when sections gain columns (step 3), and when blocks move between
 * columns (step 4).
 *
 * WHY COMPUTED STYLE AND NOT A SCREENSHOT. A screenshot answers "does it look the same to
 * me"; this answers "is any value the browser resolved different". It catches a rule that
 * stopped matching because an element moved in the tree, which is exactly the risk when a
 * wrapper is introduced — and it names the element and the property, which a picture cannot.
 *
 * WHY THE HTML TOO. A computed-style capture is keyed by a path through the tree, so it can
 * only compare elements that both captures have. The HTML is what shows an element that
 * appeared or vanished.
 *
 * ON THE COPY, ALWAYS. It applies all five characters in turn, and applying one rewrites the
 * site's design — never the development site the owner looks at (CLAUDE.md).
 *
 * Captures are gzipped because the raw text is around 8KB per element.
 */
import { gzipSync, gunzipSync } from 'node:zlib';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { COPY_BASE as BASE, COPY_ADMIN as ADMIN } from './config.mjs';
import { login, openBrowser, applyCharacter } from './harness.mjs';

const HOME = process.env.HOME ?? '';
const ROOT = process.env.BOXLET_COMPARE_DIR ?? `${HOME}/boxlet-browser/compare`;

const PAGES = ['', 'about', 'services', 'style-guide'];
const CHARACTERS = ['editorial', 'minimal', 'bold', 'soft', 'brutalist'];
const WIDTHS = [[1400, 900], [900, 900], [390, 844]];

/**
 * Every element in document order, each with a path that does not depend on ids or classes
 * — those are exactly what a refactor may legitimately change — and the whole of its
 * computed style as one string.
 */
const CAPTURE = () => {
  const out = [];
  const walk = (node, path) => {
    const style = window.getComputedStyle(node);
    const properties = [];
    for (let i = 0; i < style.length; i += 1) {
      const name = style.item(i);
      properties.push(name + ':' + style.getPropertyValue(name));
    }
    out.push({
      path,
      tag: node.tagName.toLowerCase(),
      cls: node.getAttribute('class') || '',
      n: properties.length,
      style: properties.join(';'),
    });
    let index = 0;
    for (const child of node.children) {
      index += 1;
      walk(child, path + '/' + child.tagName.toLowerCase() + '[' + index + ']');
    }
  };
  walk(document.documentElement, 'html');

  return { html: document.documentElement.outerHTML, elements: out };
};

async function capture(label) {
  const directory = `${ROOT}/${label}`;
  mkdirSync(directory, { recursive: true });

  const { browser, page, errors } = await openBrowser({ width: 1400, height: 900, scale: 1 });
  if (!await login(page, BASE, ADMIN.email, ADMIN.password)) {
    throw new Error(`could not log in to the copy at ${BASE}`);
  }

  let elements = 0;
  let properties = 0;
  for (const character of CHARACTERS) {
    await applyCharacter(page, BASE, character);
    for (const slug of PAGES) {
      for (const [width, height] of WIDTHS) {
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.goto(`${BASE}/${slug}`, { waitUntil: 'networkidle2' });
        // Webfonts change metrics, and a metric is a computed value.
        await page.evaluate(() => document.fonts.ready);
        const shot = await page.evaluate(CAPTURE);
        const name = `${character}__${slug === '' ? 'home' : slug}__${width}`;
        writeFileSync(`${directory}/${name}.json.gz`, gzipSync(JSON.stringify(shot)));
        elements += shot.elements.length;
        properties += shot.elements.reduce((sum, element) => sum + element.n, 0);
        process.stdout.write(`  ${name}: ${shot.elements.length} elements\n`);
      }
    }
  }
  await browser.close();

  writeFileSync(`${directory}/index.json`, JSON.stringify({
    label, base: BASE, pages: PAGES, characters: CHARACTERS, widths: WIDTHS,
    elements, properties, at: new Date().toISOString(),
  }, null, 1));
  console.log(`\n${label}: ${elements} elements, ${properties} properties captured`);
  if (errors.length > 0) {
    console.log(`page errors during capture: ${JSON.stringify(errors)}`);
  }
}

function load(label, name) {
  const file = `${ROOT}/${label}/${name}.json.gz`;

  return existsSync(file) ? JSON.parse(gunzipSync(readFileSync(file)).toString()) : null;
}

function diff(before, after) {
  const index = JSON.parse(readFileSync(`${ROOT}/${before}/index.json`).toString());
  let compared = 0;
  let differing = 0;
  const reported = [];

  for (const character of index.characters) {
    for (const slug of index.pages) {
      for (const [width] of index.widths) {
        const name = `${character}__${slug === '' ? 'home' : slug}__${width}`;
        const a = load(before, name);
        const b = load(after, name);
        if (a === null || b === null) {
          reported.push(`${name}: missing from ${a === null ? before : after}`);
          continue;
        }
        // An element that appeared or vanished is a structural change, which the property
        // comparison below cannot see because it can only compare paths both captures have.
        if (a.elements.length !== b.elements.length) {
          reported.push(`${name}: ${a.elements.length} elements before, ${b.elements.length} after`);
        }
        const byPath = new Map(b.elements.map((element) => [element.path, element]));
        for (const element of a.elements) {
          const other = byPath.get(element.path);
          if (other === undefined) {
            reported.push(`${name}: ${element.path} <${element.tag} class="${element.cls}"> is gone`);
            continue;
          }
          compared += element.n;
          if (element.style === other.style) {
            continue;
          }
          const was = new Map(element.style.split(';').map((pair) => {
            const at = pair.indexOf(':');

            return [pair.slice(0, at), pair.slice(at + 1)];
          }));
          for (const pair of other.style.split(';')) {
            const at = pair.indexOf(':');
            const property = pair.slice(0, at);
            const value = pair.slice(at + 1);
            if (was.get(property) !== value) {
              differing += 1;
              if (reported.length < 40) {
                reported.push(`${name}: ${element.path} <${element.tag} class="${element.cls}"> `
                  + `${property}: ${JSON.stringify(was.get(property))} -> ${JSON.stringify(value)}`);
              }
            }
          }
        }
      }
    }
  }

  console.log(`${compared} property comparisons, ${differing} differing`);
  reported.forEach((line) => console.log('  ' + line));
  process.exit(differing === 0 && reported.length === 0 ? 0 : 1);
}

const [command, a, b] = process.argv.slice(2);
if (command === 'capture' && a) {
  await capture(a);
} else if (command === 'diff' && a && b) {
  diff(a, b);
} else {
  console.error('usage: node compare.mjs capture <label> | node compare.mjs diff <before> <after>');
  process.exit(2);
}
