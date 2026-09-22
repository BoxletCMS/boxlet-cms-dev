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
    drawTools();
  }

  /**
   * @param announce false when the parent asked for this selection. Echoing it back
   *                 would make the parent treat it as a fresh click from the page, which
   *                 clears the position a "+" had just aimed at — so every insert landed
   *                 at the end of the page instead of at the boundary that was clicked.
   */
  function select(index, announce) {
    blocks().forEach(function (section, i) {
      section.classList.toggle('bx-selected', i === index);
    });
    drawTools();
    if (announce !== false) {
      tell('select', { index: index });
    }
  }

  /*
   * THE SELECTED BLOCK'S OWN CONTROLS (D-040): move up, move down, duplicate and remove,
   * as icons on the block's top right corner rather than a row of buttons in the panel, so
   * the block's fields start higher. Drawn in the overlay like the insertion controls, for
   * the same reason: nothing may sit between the sections. The action itself is the
   * builder's — this only says which one was pressed.
   */
  var ACTIONS = [['up', 'arrow-up'], ['down', 'arrow-down'], ['duplicate', 'copy'], ['remove', 'trash-2']];

  function drawTools() {
    var old = overlay.querySelector('.bx-tools');
    if (old) {
      old.remove();
    }
    var list = blocks();
    var index = list.findIndex(function (section) { return section.classList.contains('bx-selected'); });
    if (index < 0) {
      return;
    }
    var section = list[index];
    var labels = (document.body.getAttribute('data-block-labels') || 'Move up|Move down|Duplicate|Remove').split('|');
    var sprite = document.body.getAttribute('data-icons') || '';
    var tools = document.createElement('div');
    tools.className = 'bx-tools';
    tools.setAttribute('role', 'toolbar');
    ACTIONS.forEach(function (pair, i) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'bx-tool' + (pair[0] === 'remove' ? ' bx-tool-danger' : '');
      button.setAttribute('data-block-action', pair[0]);
      button.setAttribute('aria-label', labels[i]);
      button.title = labels[i];
      // At the ends there is nowhere to move to: shown, and shown as unavailable.
      if ((pair[0] === 'up' && index === 0) || (pair[0] === 'down' && index === list.length - 1)) {
        button.disabled = true;
      }
      var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('aria-hidden', 'true');
      svg.setAttribute('focusable', 'false');
      var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', sprite + '#i-' + pair[1]);
      svg.appendChild(use);
      button.appendChild(svg);
      tools.appendChild(button);
    });
    tools.style.top = Math.round(section.offsetTop + 12) + 'px';
    tools.style.left = Math.round(section.offsetLeft + section.offsetWidth - 12) + 'px';
    overlay.appendChild(tools);
  }

  function refresh() {
    renumber();
    drawInserts();
    tell('size', { height: document.documentElement.scrollHeight });
  }

  document.addEventListener('click', function (event) {
    var action = event.target.closest && event.target.closest('[data-block-action]');
    if (action) {
      event.preventDefault();
      tell('action', { action: action.getAttribute('data-block-action') });
      return;
    }
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
    /* Escape and the undo shortcut are the editor's, not the page's, and the page is what
       has focus whenever the pointer is in here. Nothing in this document is editable —
       the fields are all in the parent — so neither can be taken from anything (D-079). */
    if (event.key === 'Escape') {
      event.preventDefault();
      select(-1);

      return;
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      tell('undo', {});

      return;
    }
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
      select(event.data.index, false);
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
      /* A drag is reported when it ends, and by then this document has already moved:
         a snapshot taken then would record the result, not what to go back to. So the
         start is announced too, and the builder holds that state until it knows the
         drag changed something (D-079). */
      onStart: function () {
        tell('drag-start', {});
      },
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
