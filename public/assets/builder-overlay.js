/*
 * WHAT THE EDITOR DRAWS OVER THE PAGE (PLAN.md D-175, README 4.3), from the parent into the
 * canvas's document (same origin): the outline of what is hovered and what is selected, the
 * selected block's toolbar and the selected band's, a "+" on every boundary between bands (its
 * quick inserter is builder-inserter.js), the badges a band wears (#anchor, animation,
 * hidden-on), and the stripes over a band hidden on the device being looked at. None of them
 * lies over another (builder-overlay-apart.js).
 *
 * ALL OF IT IN ONE LAYER laid over the page, never between the bands: sections.css styles a
 * section by its place among its siblings, so anything put between them would change the page
 * being edited. Positions are measured from the drawn page whenever it may have moved.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb) {
    return;
  }
  var icons = document.querySelector('[data-pb]').getAttribute('data-icons');
  var layer = null;
  var HIDE = { desktop: 'hide_desktop', tablet: 'hide_tablet', phone: 'hide_mobile' };

  function cdoc() { return pb.canvas.doc(); }
  /** A distance on screen, in the canvas's own pixels: the page is shown scaled. */
  function px(n) { return n / (pb.canvas.scale || 1); }
  function el(tag, cls, text) {
    var node = cdoc().createElement(tag);
    if (cls) { node.className = cls; }
    if (text) { node.textContent = text; }
    return node;
  }
  function icon(name) {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = cdoc().createElementNS(ns, 'svg');
    svg.setAttribute('class', 'bx-icon');
    svg.setAttribute('aria-hidden', 'true');
    var use = cdoc().createElementNS(ns, 'use');
    use.setAttribute('href', icons + '#i-' + name);
    svg.appendChild(use);
    return svg;
  }
  function button(action, iconName, title) {
    var b = el('button', 'bx-tool');
    b.type = 'button';
    b.setAttribute('data-bx-action', action);
    b.title = title;
    b.setAttribute('aria-label', title);
    b.appendChild(icon(iconName));
    return b;
  }
  function box(node) {
    var r = node.getBoundingClientRect();
    var win = cdoc().defaultView;
    return { top: r.top + win.scrollY, left: r.left + win.scrollX, width: r.width, height: r.height };
  }
  function at(node, b) {
    node.style.top = b.top + 'px';
    node.style.left = b.left + 'px';
    if (b.width !== undefined) { node.style.width = b.width + 'px'; }
    if (b.height !== undefined) { node.style.height = b.height + 'px'; }
  }

  /** What builder-inserter.js draws with: the same layer, the same pieces. */
  pb.overlay = { el: el, icon: icon, button: button, box: box, at: at, px: px, layer: function () { return layer; } };

  /** Every mark drawn again from the document and the page as it stands. */
  function paint() {
    var doc = cdoc();
    if (!doc || !pb.canvas.main()) {
      return;
    }
    if (!layer || !layer.isConnected) {
      layer = el('div', 'bx-layer');
      doc.body.appendChild(layer);
    }
    // The quick inserter stays open through a repaint (an image loading, a band redrawn).
    var open = layer.querySelector('.bx-inserter');
    layer.textContent = '';
    // What is selected is marked BEFORE anything is measured: a selected block shows more of
    // itself (its placeholders, its "+ Card"), and a "+" placed for the page without them stood
    // a card's height above its boundary (D-179).
    marked();

    // A block that draws nothing yet is given a size and a word (canvas-marks.css, D-117):
    // a new Text or Form measures 0px tall, and what cannot be seen cannot be pressed.
    Array.prototype.forEach.call(pb.canvas.main().querySelectorAll('[data-bx-key]'), function (n) {
      if (!n.hasAttribute('data-bx-empty') && n.getBoundingClientRect().height < 2) {
        n.setAttribute('data-bx-empty', pb.t('canvas.empty'));
      }
    });

    var sections = pb.doc.sections;
    var main = box(pb.canvas.main());
    sections.forEach(function (section, index) {
      var element = pb.canvas.sectionEl(section.key);
      if (!element) {
        return;
      }
      var b = box(element);
      var style = section.style || {};
      // The badges: what a band is that the page itself does not show.
      var badges = el('div', 'bx-badges');
      if (style.anchor) { badges.appendChild(el('span', 'bx-badge', '#' + style.anchor)); }
      if (style.animation && style.animation !== 'none') { var a = el('span', 'bx-badge'); a.appendChild(icon('sparkles')); a.appendChild(doc.createTextNode(style.animation)); badges.appendChild(a); }
      ['hide_desktop', 'hide_tablet', 'hide_mobile'].forEach(function (k) {
        if (style[k] === 'yes') { var h = el('span', 'bx-badge'); h.appendChild(icon('eye-off')); h.appendChild(doc.createTextNode(pb.t('device.' + (k === 'hide_mobile' ? 'phone' : k.slice(5))))); badges.appendChild(h); }
      });
      if (badges.childNodes.length) { badges.setAttribute('data-bx-badges', section.key); at(badges, { top: b.top + px(8), left: b.left + px(8) }); layer.appendChild(badges); }
      // Hidden on the device being looked at: still here to be edited, striped over.
      if (style[HIDE[pb.device]] === 'yes') {
        var stripes = el('div', 'bx-hidden-here', pb.t('canvas.hidden_here', { device: pb.t('device.' + pb.device) }));
        at(stripes, b);
        layer.appendChild(stripes);
      }
      layer.appendChild(plus(index, b.top, main));
    });
    // Below the last band: one to add at the end, said in words.
    var end = el('button', 'bx-add-end');
    end.type = 'button';
    end.setAttribute('data-bx-insert', String(sections.length));
    end.appendChild(icon('plus'));
    end.appendChild(doc.createTextNode(pb.t('canvas.add_end')));
    var last = sections.length ? pb.canvas.sectionEl(sections[sections.length - 1].key) : null;
    at(end, { top: (last ? box(last).top + box(last).height : main.top) + px(16), left: main.left + main.width / 2 });
    layer.appendChild(end);

    selection();
    if (open) {
      layer.appendChild(open);
    }
    // What typing on the page draws in the layer (its errors) is drawn again after this.
    pb.emit('painted', layer);
  }

  function plus(index, top, main) {
    var p = el('button', 'bx-plus');
    p.type = 'button';
    p.setAttribute('data-bx-insert', String(index));
    p.title = pb.t('canvas.add_here');
    p.setAttribute('aria-label', pb.t('canvas.add_here'));
    p.appendChild(icon('plus'));
    at(p, { top: top, left: main.left + main.width / 2 });
    return p;
  }

  /** The selected block or band marked, and nothing else. */
  function marked() {
    var sel = pb.selection;
    var node = !sel ? null : sel.kind === 'block' ? pb.canvas.blockEl(sel.key) : pb.canvas.sectionEl(sel.key);
    Array.prototype.forEach.call(cdoc().querySelectorAll('[data-bx-selected]'), function (n) {
      if (n !== node) { n.removeAttribute('data-bx-selected'); }
    });
    if (node) {
      node.setAttribute('data-bx-selected', sel.kind);
    }
  }

  /**
   * THE BLOCK'S TOOLBAR NEVER COVERS WORDS (D-182): outside the block, above it, clear of its
   * band's "+" and edge and of every field's words; where that is not to be had, under it;
   * where neither is, above it all the same, which at least never covers its own block.
   */
  function placeBar(bar, node, block) {
    bar.style.transform = 'none';
    var b = box(node);
    var h = bar.offsetHeight;
    var gap = px(4);
    var band = box(pb.canvas.sectionEl(block.section) || node);
    var edge = layer.querySelector('[data-bx-insert="' + pb.sectionIndex(block.section) + '"]');
    var floor = Math.max(band.top, edge ? box(edge).top + box(edge).height : band.top) + gap;
    var fields = Array.prototype.map.call(pb.canvas.main().querySelectorAll('[data-bx-field]'), box).filter(function (f) { return f.width > 0 && f.height > 0; });
    var w = bar.offsetWidth;
    function clear(top) {
      return !fields.some(function (f) {
        return b.left < f.left + f.width && f.left < b.left + w && top < f.top + f.height && f.top < top + h;
      });
    }
    var above = b.top - gap - h;
    var below = b.top + b.height + gap;
    var top = above >= floor && clear(above) ? above : (clear(below) ? below : above);
    at(bar, { top: top, left: b.left });
    bar.setAttribute('data-bx-side', top === below ? 'below' : 'above');
  }

  /** The selected thing's toolbar. */
  function selection() {
    var sel = pb.selection;
    if (!sel) {
      return;
    }
    if (sel.kind === 'block') {
      var block = pb.block(sel.key);
      var node = pb.canvas.blockEl(sel.key);
      if (!block || !node) { return; }
      var item = pb.libraryItem(block.type) || { label: block.type, icon: 'file-text' };
      var bar = el('div', 'bx-toolbar bx-toolbar-block');
      var name = el('span', 'bx-toolbar-name');
      name.appendChild(icon(item.icon));
      name.appendChild(cdoc().createTextNode(item.label));
      bar.appendChild(name);
      var layouts = (pb.data.layouts || {})[block.type] || [];
      if (layouts.length > 1) {
        var select = el('select', 'bx-toolbar-layout');
        select.title = pb.t('canvas.layout');
        select.setAttribute('data-bx-layout', sel.key);
        layouts.forEach(function (l) {
          var o = el('option', '', (l.value === block.layout ? '✓ ' : '') + l.label);
          o.value = l.value;
          o.selected = l.value === block.layout;
          select.appendChild(o);
        });
        bar.appendChild(select);
      }
      bar.appendChild(button('block-up', 'arrow-up', pb.t('canvas.move_up')));
      bar.appendChild(button('block-down', 'arrow-down', pb.t('canvas.move_down')));
      bar.appendChild(button('block-copy', 'copy', pb.t('canvas.copy')));
      bar.appendChild(button('block-delete', 'trash-2', pb.t('canvas.delete')));
      layer.appendChild(bar);
      placeBar(bar, node, block);
    } else if (sel.kind === 'section') {
      var element = pb.canvas.sectionEl(sel.key);
      if (!element) { return; }
      var tools = el('div', 'bx-toolbar bx-toolbar-section');
      tools.appendChild(el('span', 'bx-toolbar-name', pb.sectionName(sel.key)));
      tools.appendChild(button('section-up', 'arrow-up', pb.t('canvas.move_up')));
      tools.appendChild(button('section-down', 'arrow-down', pb.t('canvas.move_down')));
      tools.appendChild(button('section-copy', 'copy', pb.t('canvas.copy')));
      tools.appendChild(button('section-pattern', 'bookmark-plus', pb.t('pattern_name')));
      tools.appendChild(button('section-delete', 'trash-2', pb.t('canvas.delete')));
      var s = box(element);
      at(tools, { top: s.top + px(8), left: s.left + s.width - px(8) });
      layer.appendChild(tools);
    }
  }

  function attach() {
    var doc = cdoc();
    if (!doc || doc.__bxAttached) {
      paint();
      return;
    }
    doc.__bxAttached = true;
    // What the presses on the page do is builder-overlay-press.js's.
    if (doc.defaultView.ResizeObserver) {
      new doc.defaultView.ResizeObserver(function () { paint(); }).observe(pb.canvas.main());
    }
    paint();
  }

  pb.on('canvas', attach);
  pb.on('drawn', paint);
  pb.on('select', paint);
  pb.on('device', paint);
  pb.on('change', function () { setTimeout(paint, 0); });
})();
