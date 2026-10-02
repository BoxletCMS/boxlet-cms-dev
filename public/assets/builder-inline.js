/*
 * TYPING ON THE PAGE (PLAN.md D-178, README 4.4). The canvas marks the element that shows each
 * field (`data-bx-field`, edit_attr()); pressing one edits it where it stands:
 *
 *   - a line or a paragraph: plain text in place (`contenteditable="plaintext-only"`); Enter
 *     leaves a one-line field, Escape leaves any, a paste arrives as plain text;
 *   - rich text: builder-inline-rich.js; a link's words: here, its address in the popover of
 *     builder-inline-link.js;
 *   - a picture: the inspector's own picker, opened;
 *   - a repeater: "+" at its end adds one, and a pointed-at item has its own tools.
 *
 * Every keystroke writes the document — an undo step per field, folded while typing — and
 * nothing is drawn again while typing: the page already shows what was typed. Leaving a field
 * asks the server what is wrong with the block, and an error is shown on the element and in
 * the inspector.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.overlay || !pb.data.inline) {
    return;
  }
  var o = pb.overlay;
  var inline = pb.data.inline;
  var editing = null;

  /** What a field path names in a block of this type: `heading`, `items.2.heading`. */
  function spec(type, path) {
    var parts = path.split('.');
    var field = (inline.fields[type] || {})[parts[0]];
    return parts.length === 3 ? (field && field.fields ? field.fields[parts[2]] : null) : field;
  }
  function get(block, path) {
    var parts = path.split('.');
    var value = block.content[parts[0]];
    return parts.length === 3 ? ((value || [])[Number(parts[1])] || {})[parts[2]] : value;
  }
  function set(block, path, value) {
    var parts = path.split('.');
    if (parts.length === 3) {
      block.content[parts[0]][Number(parts[1])][parts[2]] = value;
    } else {
      block.content[parts[0]] = value;
    }
  }
  function blockOf(el) {
    var host = el.closest('[data-bx-key]');
    return host ? pb.block(host.getAttribute('data-bx-key')) : null;
  }
  function write(block, path, value) {
    pb.change(function () { set(block, path, value); }, { coalesce: 'inline:' + block.key + ':' + path, quiet: true });
  }

  // ---- a line or a paragraph ---------------------------------------------------------------------
  function words(el, kind) {
    var text = el.innerText.replace(/ /g, ' ');
    return kind === 'textarea' ? text.replace(/\n$/, '') : text.replace(/\s*\n\s*/g, ' ');
  }

  function startText(el, block, path, field) {
    el.setAttribute('contenteditable', 'plaintext-only');
    if (el.contentEditable !== 'plaintext-only') {
      el.setAttribute('contenteditable', 'true');
    }
    editing = { el: el, key: block.key, path: path, kind: field.type, stop: null };
    var link = field.type === 'link';
    function input() {
      var now = pb.block(block.key);
      if (!now) { return; }
      var value = words(el, field.type);
      if (link) {
        var current = pb.copy(get(now, path) || { label: '', url: '' });
        current.label = value;
        write(now, path, current);
      } else {
        write(now, path, value);
      }
    }
    function keys(event) {
      if (event.key === 'Escape' || (event.key === 'Enter' && field.type !== 'textarea')) {
        event.preventDefault();
        if (event.key === 'Escape' && pb.inline.closeLink) { pb.inline.closeLink(); }
        el.blur();
      }
    }
    function paste(event) {
      // A paste is plain text, whatever the browser offers (README 4.4).
      event.preventDefault();
      var text = (event.clipboardData || window.clipboardData).getData('text/plain');
      el.ownerDocument.execCommand('insertText', false, field.type === 'textarea' ? text : text.replace(/\s*\n\s*/g, ' '));
    }
    function leave() {
      // Emptied, it is empty again — a <br> the browser leaves would hide its placeholder.
      if (words(el, field.type).trim() === '') { el.innerHTML = ''; }
      el.removeEventListener('input', input);
      el.removeEventListener('keydown', keys);
      el.removeEventListener('paste', paste);
      el.removeAttribute('contenteditable');
      if (editing && editing.el === el) { editing = null; }
      check(block.key);
    }
    el.addEventListener('input', input);
    el.addEventListener('keydown', keys);
    el.addEventListener('paste', paste);
    el.addEventListener('blur', leave, { once: true });
    el.focus();
    if (link && pb.inline.link) {
      pb.inline.link(el, block, path);
    }
  }

  // ---- a picture: the inspector's picker -------------------------------------------------------------
  function picture(block, path) {
    var parts = path.split('.');
    var name = 'blocks[' + block.key + '][' + parts[0] + ']' + (parts.length === 3 ? '[' + parts[1] + '][' + parts[2] + ']' : '');
    function open() {
      var select = document.querySelector('[data-pb-inspector] select[name="' + name + '"]');
      if (!select) { return false; }
      var holder = select.closest('.field, .media-picker') || select.parentElement;
      var button = holder.querySelector('.media-picker-current') || select.parentElement.querySelector('.media-picker-current');
      if (button) { button.click(); } else { select.focus(); }
      return true;
    }
    if (!open()) {
      // The inspector is being drawn for the block just pressed: open it once it is there.
      var once = function (sel) {
        if (sel && sel.key === block.key) {
          pb.off('inspected', once);
          open();
        }
      };
      pb.on('inspected', once);
    }
  }

  // ---- a repeater's items ---------------------------------------------------------------------------
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
  var pointed = null;
  function itemTools(itemEl) {
    if (!itemEl) {
      return;
    }
    var block = blockOf(itemEl);
    var sel = pb.selection;
    if (!block || !sel || sel.kind !== 'block' || sel.key !== block.key) {
      return;
    }
    var bar = o.el('div', 'bx-item-tools');
    bar.setAttribute('data-bx-item-tools', itemEl.getAttribute('data-bx-item'));
    bar.appendChild(o.button('item-before', 'arrow-left', pb.t('inline.item_before')));
    bar.appendChild(o.button('item-after', 'arrow-right', pb.t('inline.item_after')));
    bar.appendChild(o.button('item-remove', 'x', pb.t('inline.remove_item')));
    var b = o.box(itemEl);
    o.at(bar, { top: b.top + o.px(6), left: b.left + b.width - o.px(6) });
    o.layer().appendChild(bar);
  }

  // ---- what the server says is wrong ------------------------------------------------------------------
  function check(key) {
    var block = pb.block(key);
    if (!block) {
      return;
    }
    pb.api(pb.data.endpoints.fields, { block: { key: key, type: block.type, content: block.content, layout: block.layout, options: block.options || {} } }).then(function (answer) {
      if (answer.status !== 200) { return; }
      pb.errors[key] = answer.json.errors || {};
      pb.emit('errors', key);
      // The inspector's All content shows what was typed here, unless it is being typed in.
      var sel = pb.selection;
      if (sel && sel.kind === 'block' && sel.key === key && !document.querySelector('[data-pb-inspector]').contains(document.activeElement)) {
        pb.emit('inspect');
      }
    });
  }

  /** Each error on the element it is about: an outline at rest and the words under it. */
  function marks(layer) {
    var doc = pb.canvas.doc();
    if (!doc || !layer) { return; }
    Array.prototype.forEach.call(doc.querySelectorAll('[data-bx-error]'), function (n) { n.removeAttribute('data-bx-error'); });
    Object.keys(pb.errors).forEach(function (key) {
      var host = pb.canvas.blockEl(key);
      Object.keys(pb.errors[key] || {}).forEach(function (path) {
        if (!host) { return; }
        var el = host.querySelector('[data-bx-field="' + path + '"]') || host.querySelector('[data-bx-field^="' + path + '."]') || host;
        el.setAttribute('data-bx-error', '');
        var note = o.el('p', 'bx-error', pb.errors[key][path]);
        note.setAttribute('role', 'alert');
        var b = o.box(el);
        o.at(note, { top: b.top + b.height + o.px(4), left: b.left });
        layer.appendChild(note);
      });
    });
    if (pointed && pointed.isConnected) { itemTools(pointed); }
  }
  pb.on('painted', marks);
  pb.on('errors', function () { marks(o.layer()); });

  // ---- the presses ------------------------------------------------------------------------------------
  function onDown(event) {
    var t = event.target;
    if (!t.closest || t.closest('.bx-layer')) {
      return;
    }
    var el = t.closest('[data-bx-field]');
    var block = el ? blockOf(el) : null;
    if (!el || !block || (editing && editing.el === el)) {
      return;
    }
    var path = el.getAttribute('data-bx-field');
    var field = spec(block.type, path);
    if (!field) {
      return;
    }
    // Its block selected now: the press may land on an element a rich editor replaces.
    pb.select('block', block.key);
    if (field.type === 'text' || field.type === 'textarea' || field.type === 'link') {
      startText(el, block, path, field);
    } else if (field.type === 'richtext' && pb.inline.rich) {
      event.preventDefault();
      pb.inline.rich(el, block, path, field, { x: event.clientX, y: event.clientY });
    }
  }
  function onClick(event) {
    var t = event.target;
    if (!t.closest) { return; }
    var tool = t.closest('[data-bx-action^="item-"]');
    if (tool) {
      var bar = tool.closest('[data-bx-item-tools]');
      var parts = bar.getAttribute('data-bx-item-tools').split('.');
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
    if (add) {
      var owner = blockOf(add);
      if (owner) { addItem(owner, add.getAttribute('data-bx-add-item')); }
      return;
    }
    var el = t.closest('[data-bx-field]');
    var block2 = el ? blockOf(el) : null;
    if (el && block2) {
      var field = spec(block2.type, el.getAttribute('data-bx-field'));
      if (field && field.type === 'media') { picture(block2, el.getAttribute('data-bx-field')); }
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

  pb.on('canvas', function () {
    var doc = pb.canvas.doc();
    if (!doc || doc.__bxInline) { return; }
    doc.__bxInline = true;
    doc.addEventListener('mousedown', onDown, true);
    doc.addEventListener('click', onClick, true);
    doc.addEventListener('mousemove', onMove);
  });

  pb.inline = { spec: spec, get: get, set: set, write: write, check: check, blockOf: blockOf, editing: function () { return editing; } };
})();
