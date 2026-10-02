/*
 * THE QUICK INSERTER (PLAN.md D-175, README 4.3): the "+" on a boundary between bands opens it,
 * there, over the page — every block, then the design set's patterns — and what is chosen goes
 * in at that boundary. Drawn in builder-overlay.js's layer, with its pieces.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.overlay) {
    return;
  }
  var o = pb.overlay;
  var inserter = null;

  function item(kind, value, iconName, label) {
    var b = o.el('button', 'bx-inserter-item' + (kind === 'pattern' ? ' bx-inserter-pattern' : ''));
    b.type = 'button';
    b.setAttribute(kind === 'block' ? 'data-bx-add-block' : 'data-bx-add-pattern', value);
    b.appendChild(o.icon(iconName));
    b.appendChild(o.el('span', '', label));
    return b;
  }

  function close() {
    if (inserter) {
      inserter.remove();
      inserter = null;
    }
  }

  function open(index, anchor) {
    close();
    inserter = o.el('div', 'bx-inserter');
    inserter.setAttribute('role', 'dialog');
    inserter.setAttribute('aria-label', pb.t('canvas.insert_title'));
    var head = o.el('div', 'bx-inserter-head', pb.t('canvas.insert_title'));
    head.appendChild(o.button('close-inserter', 'x', pb.t('canvas.close')));
    inserter.appendChild(head);
    var blocks = o.el('div', 'bx-inserter-grid');
    pb.data.library.forEach(function (entry) { blocks.appendChild(item('block', entry.type, entry.icon, entry.label)); });
    inserter.appendChild(blocks);
    var patterns = pb.data.setPatterns || [];
    if (patterns.length) {
      inserter.appendChild(o.el('div', 'bx-inserter-group', pb.t('add.from_set', { set: pb.data.setName })));
      var grid = o.el('div', 'bx-inserter-grid');
      patterns.forEach(function (p) { grid.appendChild(item('pattern', 'set:' + p.id, 'square-plus', p.name)); });
      inserter.appendChild(grid);
    }
    inserter.setAttribute('data-bx-at', String(index));
    var a = o.box(anchor);
    o.at(inserter, { top: a.top + a.height + o.px(6), left: a.left + a.width / 2 });
    o.layer().appendChild(inserter);
  }

  /** What was chosen, in at the boundary the inserter was opened on. */
  function choose(kind, value) {
    var at = Number(inserter ? inserter.getAttribute('data-bx-at') : pb.doc.sections.length);
    close();
    if (kind === 'block') { pb.addBlock(value, at); } else { pb.addPattern(value, at); }
  }

  // Escape closes it, from the page or from the shell around it.
  function escape(event) {
    if (event.key === 'Escape' && inserter) {
      close();
    }
  }
  document.addEventListener('keydown', escape);

  pb.inserter = { open: open, close: close, choose: choose, escape: escape };
})();
