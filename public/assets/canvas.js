/*
 * Inside the canvas iframe.
 *
 * It knows nothing about block types or fields — the server owns those, and builder.js
 * owns every change to the page. This file does only what has to happen inside the
 * frame: report clicks, drag sections into a new order, and keep the insertion controls
 * sitting on the right boundaries.
 *
 * The insertion controls are an overlay, not elements between the sections: sections.css
 * styles a section by its position among its siblings, so anything placed between two of
 * them would change the page being edited.
 */
(function () {
  'use strict';

  var main = document.querySelector('[data-bx-blocks]');
  if (!main) {
    return;
  }

  var overlay = document.createElement('div');
  overlay.className = 'bx-overlay';
  document.body.appendChild(overlay);

  var counter = 0;

  function blocks() {
    return Array.prototype.filter.call(main.children, function (node) {
      return node.tagName === 'SECTION';
    });
  }

  function tell(name, detail) {
    window.parent.postMessage(
      Object.assign({ source: 'boxlet-canvas', type: name }, detail || {}),
      window.location.origin,
    );
  }

  // Positional numbering, re-derived after every change, so it always matches the order
  // of the field groups in the parent's form. The key is stable across reordering, which
  // is how the parent knows which group belongs to which section.
  function renumber() {
    blocks().forEach(function (section, index) {
      section.setAttribute('data-bx-index', String(index));
      section.setAttribute('tabindex', '0');
      section.setAttribute('role', 'button');
      if (!section.hasAttribute('data-bx-key')) {
        section.setAttribute('data-bx-key', 'k' + counter++);
      }
    });
  }

  function insertButton(index, top, label) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'bx-insert';
    button.setAttribute('data-insert-at', String(index));
    button.setAttribute('aria-label', label);
    button.title = label;
    button.textContent = '+';
    // Never flush with the top edge: the control is centred on the boundary, so at y=0
    // half of it would sit above the page. An empty page has only this one.
    button.style.top = Math.max(16, Math.round(top)) + 'px';
    return button;
  }

  function drawInserts() {
    var labels = document.body.getAttribute('data-insert-labels') || 'Add a block here|Add a block at the end';
    var parts = labels.split('|');
    overlay.textContent = '';
    var list = blocks();
    list.forEach(function (section, index) {
      overlay.appendChild(insertButton(index, section.offsetTop, parts[0]));
    });
    var last = list[list.length - 1];
    overlay.appendChild(insertButton(list.length, last ? last.offsetTop + last.offsetHeight : 0, parts[1]));
  }

  function select(index) {
    blocks().forEach(function (section, i) {
      section.classList.toggle('bx-selected', i === index);
    });
    tell('select', { index: index });
  }

  function refresh() {
    renumber();
    drawInserts();
    tell('size', { height: document.documentElement.scrollHeight });
  }

  document.addEventListener('click', function (event) {
    var insert = event.target.closest && event.target.closest('[data-insert-at]');
    if (insert) {
      event.preventDefault();
      tell('insert', { index: Number(insert.getAttribute('data-insert-at')) });
      return;
    }
    // Nothing in the canvas navigates: this is the page being edited, not browsed.
    if (event.target.closest && event.target.closest('a, button')) {
      event.preventDefault();
    }
    var section = event.target.closest && event.target.closest('[data-bx-index]');
    select(section ? Number(section.getAttribute('data-bx-index')) : -1);
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

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-builder') {
      return;
    }
    if (event.data.type === 'select') {
      select(event.data.index);
    } else if (event.data.type === 'refresh') {
      refresh();
    }
  });

  // Reordering happens here because drag events do not cross a document boundary.
  // SortableJS rather than native drag and drop: it handles touch, and a tablet is a
  // real case for this screen.
  if (window.Sortable) {
    window.Sortable.create(main, {
      draggable: 'section.block',
      animation: 120,
      ghostClass: 'bx-dragging',
      onEnd: function () {
        renumber();
        drawInserts();
        tell('reorder', {
          keys: blocks().map(function (section) {
            return section.getAttribute('data-bx-key');
          }),
        });
      },
    });
  }

  window.bxCanvas = { refresh: refresh, select: select };

  refresh();
  window.addEventListener('resize', drawInserts);
  if (window.ResizeObserver) {
    new window.ResizeObserver(drawInserts).observe(document.body);
  }
  tell('ready', { count: blocks().length });
})();
