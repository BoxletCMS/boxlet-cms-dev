/*
 * Inside the canvas iframe.
 *
 * It knows nothing about block types or fields — the server owns those. All it does is
 * number the sections the server rendered, report which one was clicked, and stop links
 * from navigating away from the editor.
 */
(function () {
  'use strict';

  var main = document.querySelector('[data-bx-blocks]');
  if (!main) {
    return;
  }

  function blocks() {
    return Array.prototype.filter.call(main.children, function (node) {
      return node.tagName === 'SECTION';
    });
  }

  // Numbering is positional and re-derived after every change, so it always matches the
  // order of the field groups in the parent's form.
  function renumber() {
    blocks().forEach(function (section, index) {
      section.setAttribute('data-bx-index', String(index));
      section.setAttribute('tabindex', '0');
      section.setAttribute('role', 'button');
    });
  }

  function tell(name, detail) {
    window.parent.postMessage(Object.assign({ source: 'boxlet-canvas', type: name }, detail || {}), window.location.origin);
  }

  function select(index) {
    blocks().forEach(function (section, i) {
      section.classList.toggle('bx-selected', i === index);
    });
    tell('select', { index: index });
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a, button');
    if (link) {
      event.preventDefault();
    }
    var section = event.target.closest && event.target.closest('[data-bx-index]');
    if (!section) {
      select(-1);
      return;
    }
    select(Number(section.getAttribute('data-bx-index')));
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }
    var section = event.target.closest && event.target.closest('[data-bx-index]');
    if (section) {
      event.preventDefault();
      select(Number(section.getAttribute('data-bx-index')));
    }
  });

  // The parent drives selection when the panel changes it.
  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-builder') {
      return;
    }
    if (event.data.type === 'select') {
      select(event.data.index);
    }
  });

  function report() {
    tell('size', { height: document.documentElement.scrollHeight });
  }

  renumber();
  report();
  window.addEventListener('resize', report);
  if (window.ResizeObserver) {
    new window.ResizeObserver(report).observe(document.body);
  }
  tell('ready', { count: blocks().length });
})();
