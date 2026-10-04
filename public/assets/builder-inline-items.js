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
      outline(null);
      return;
    }
    var block = pb.inline.blockOf(itemEl);
    var sel = pb.selection;
    if (!block || !sel || sel.kind !== 'block' || sel.key !== block.key) {
      outline(null);
      return;
    }
    var bar = o.el('div', 'bx-item-tools');
    bar.setAttribute('data-bx-item-tools', itemEl.getAttribute('data-bx-item'));
    bar.appendChild(o.button('item-before', 'arrow-left', pb.t('inline.item_before')));
    bar.appendChild(o.button('item-after', 'arrow-right', pb.t('inline.item_after')));
    bar.appendChild(o.button('item-remove', 'x', pb.t('inline.remove_item')));
    o.layer().appendChild(bar);
    placeTools(bar, itemEl);
    outline(itemEl);
  }

  /**
   * THE TOOLS ARE THE ITEM'S (D-187, the owner): the item they act on is outlined, and they
   * stand in its top right corner, inside it, over what it shows — never between two items,
   * never outside the block. An item smaller than the tools (a logo's name alone measures
   * 31 × 27 in the canvas) cannot hold them: there they sit on that corner as a tab over its
   * outline, kept within the block.
   */
  function placeTools(bar, itemEl) {
    var b = o.box(itemEl);
    var w = bar.offsetWidth;
    var h = bar.offsetHeight;
    var gap = o.px(4);
    var at = { top: b.top + gap, left: b.left + b.width - w - gap };
    if (w + gap * 2 > b.width || h + gap * 2 > b.height) {
      var host = itemEl.closest('[data-bx-key]');
      var block = host ? o.box(host) : b;
      at = {
        top: Math.max(block.top, b.top - h),
        left: Math.min(Math.max(block.left, b.left + b.width - w), block.left + block.width - w),
      };
      bar.setAttribute('data-bx-side', 'tab');
    }
    o.at(bar, at);
  }

  /** The item the tools act on, outlined; one at a time. */
  function outline(itemEl) {
    var doc = pb.canvas.doc();
    Array.prototype.forEach.call(doc ? doc.querySelectorAll('[data-bx-pointed]') : [], function (n) {
      if (n !== itemEl) { n.removeAttribute('data-bx-pointed'); }
    });
    if (itemEl) { itemEl.setAttribute('data-bx-pointed', ''); }
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
