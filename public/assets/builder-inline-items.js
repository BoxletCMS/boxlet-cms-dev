/*
 * A REPEATER'S ITEMS ON THE PAGE (PLAN.md D-178, README 4.4): "+ Card" where the next item
 * would stand adds one, saying it is new (D-179), and the item pointed at in the selected
 * block has its own tools — before, after, remove. Moved by buttons rather than dragged (O-50).
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.overlay || !pb.inline || !pb.data.inline) {
    return;
  }
  var o = pb.overlay;
  var inline = pb.data.inline;
  var pointed = null;

  function addItem(block, field) {
    var declared = (inline.fields[block.type] || {})[field] || {};
    var items = block.content[field] || [];
    if (declared.max && items.length >= declared.max) {
      return;
    }
    pb.change(function () { block.content[field] = items.concat([pb.copy(inline.items[block.type][field])]); }, { sections: [block.section] });
  }
  function moveItem(block, field, at, by) {
    var items = block.content[field] || [];
    var to = at + by;
    if (to < 0 || to >= items.length) {
      return;
    }
    pb.change(function () {
      var copy = items.slice();
      var moved = copy.splice(at, 1)[0];
      copy.splice(to, 0, moved);
      block.content[field] = copy;
    }, { sections: [block.section] });
  }
  function removeItem(block, field, at) {
    pb.change(function () { block.content[field] = (block.content[field] || []).filter(function (_, i) { return i !== at; }); }, { sections: [block.section] });
  }

  /** The tools of the item pointed at, in the selected block: before, after, remove. */
  function itemTools(itemEl) {
    if (!itemEl || !o.layer()) {
      return;
    }
    var block = pb.inline.blockOf(itemEl);
    var sel = pb.selection;
    if (!block || !sel || sel.kind !== 'block' || sel.key !== block.key) {
      return;
    }
    var bar = o.el('div', 'bx-item-tools');
    bar.setAttribute('data-bx-item-tools', itemEl.getAttribute('data-bx-item'));
    bar.appendChild(o.button('item-before', 'arrow-left', pb.t('inline.item_before')));
    bar.appendChild(o.button('item-after', 'arrow-right', pb.t('inline.item_after')));
    bar.appendChild(o.button('item-remove', 'x', pb.t('inline.remove_item')));
    o.layer().appendChild(bar);
    placeTools(bar, itemEl, block);
    if (o.apart) { o.apart(); }
  }

  /**
   * Where the words of every field that shows words stand: the lines of its text, or the whole
   * element while it is empty and shows its placeholder. A heading's element is the width of
   * the page; its words are where a press lands.
   */
  function wordBoxes(block) {
    var doc = pb.canvas.doc();
    var win = doc.defaultView;
    var out = [];
    Array.prototype.forEach.call(pb.canvas.main().querySelectorAll('[data-bx-field], .bx-add-item-cell'), function (f) {
      var owner = f.hasAttribute('data-bx-field') ? pb.inline.blockOf(f) : null;
      var spec = owner ? pb.inline.spec(owner.type, f.getAttribute('data-bx-field')) : null;
      if (spec && spec.type === 'media') { return; }
      var range = doc.createRange();
      range.selectNodeContents(f);
      var lines = f.textContent.trim() === '' ? [] : Array.prototype.filter.call(range.getClientRects(), function (r) { return r.width > 0 && r.height > 0; });
      (lines.length ? lines : [f.getBoundingClientRect()]).forEach(function (r) {
        if (r.width > 0 && r.height > 0) { out.push({ top: r.top + win.scrollY, left: r.left + win.scrollX, width: r.width, height: r.height }); }
      });
    });
    return out;
  }

  /**
   * THE TOOLS NEVER LIE OVER WORDS (D-186): at the item's top right, inside it, as before — over
   * its picture, which a press does not edit — unless words are there. A logo's name or a
   * number fills its small item, and a press meant for the words removed the item instead.
   * Then the item's other corners, inside and then just outside it; and only where none is
   * clear, the place that lies on least.
   */
  function placeTools(bar, itemEl, block) {
    var b = o.box(itemEl);
    var w = bar.offsetWidth;
    var h = bar.offsetHeight;
    var gap = o.px(6);
    var words = wordBoxes(block);
    function on(p) {
      return words.reduce(function (sum, f) {
        return sum + Math.max(0, Math.min(p.left + w, f.left + f.width) - Math.max(p.left, f.left)) * Math.max(0, Math.min(p.top + h, f.top + f.height) - Math.max(p.top, f.top));
      }, 0);
    }
    var right = b.left + b.width - w - gap;
    var left = b.left + gap;
    var places = [];
    [b.top + gap, b.top + b.height - h - gap, b.top - h - gap, b.top + b.height + gap].forEach(function (top) {
      places.push({ top: top, left: right }, { top: top, left: left });
    });
    var chosen = places.filter(function (p) { return on(p) === 0; })[0]
      || places.slice().sort(function (x, y) { return on(x) - on(y); })[0];
    o.at(bar, chosen);
  }

  function onClick(event) {
    var t = event.target;
    if (!t.closest) { return; }
    var tool = t.closest('[data-bx-action^="item-"]');
    if (tool) {
      var parts = tool.closest('[data-bx-item-tools]').getAttribute('data-bx-item-tools').split('.');
      var block = pb.selection ? pb.block(pb.selection.key) : null;
      if (!block) { return; }
      var at = Number(parts[1]);
      var act = tool.getAttribute('data-bx-action');
      if (act === 'item-before') { moveItem(block, parts[0], at, -1); }
      if (act === 'item-after') { moveItem(block, parts[0], at, 1); }
      if (act === 'item-remove') { removeItem(block, parts[0], at); }
      return;
    }
    var add = t.closest('[data-bx-add-item]');
    var owner = add ? pb.inline.blockOf(add) : null;
    if (owner) {
      addItem(owner, add.getAttribute('data-bx-add-item'));
    }
  }
  function onMove(event) {
    var item = event.target.closest ? event.target.closest('[data-bx-item]') : null;
    if (item === pointed || (event.target.closest && event.target.closest('.bx-item-tools'))) {
      return;
    }
    pointed = item;
    var old = o.layer() ? o.layer().querySelector('[data-bx-item-tools]') : null;
    if (old) { old.remove(); }
    itemTools(item);
  }

  // A layer drawn again keeps the tools of the item still pointed at.
  pb.on('painted', function () { if (pointed && pointed.isConnected) { itemTools(pointed); } });
  pb.on('canvas', function () {
    var doc = pb.canvas.doc();
    if (!doc || doc.__bxItems) { return; }
    doc.__bxItems = true;
    doc.addEventListener('click', onClick, true);
    doc.addEventListener('mousemove', onMove);
  });
})();
