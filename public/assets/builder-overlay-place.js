/*
 * WHERE THE SELECTED BLOCK'S BAR STANDS (PLAN.md D-182, D-185, D-189). Split from
 * builder-overlay.js, which draws the bar, when the rule that it keeps 4px clear of words
 * above and below took that file past 300 lines.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.overlay) {
    return;
  }
  var o = pb.overlay;

  /**
   * THE BLOCK'S TOOLBAR NEVER COVERS WORDS (D-182): outside the block, above it, clear of its
   * band's "+" and edge and of every field's words and every "+ Card"; where that is not to be
   * had, under it. Then (D-185: bands of one surface half the space apart left a gap no taller
   * than the toolbar) above or under it again, slid along to the first place clear of words,
   * "+ Card" and every "+" on a boundary; then the same places touching the block; then inside
   * it at its top, over nothing but its own picture; and only then the place that lies on least.
   * Never nearer any words than 4px on screen, above and below as at the sides (D-189).
   */
  pb.overlay.placeBar = function (bar, node, block) {
    bar.style.transform = 'none';
    var b = o.box(node);
    var h = bar.offsetHeight;
    var w = bar.offsetWidth;
    var gap = o.px(4);
    var band = o.box(pb.canvas.sectionEl(block.section) || node);
    var edge = o.layer().querySelector('[data-bx-insert="' + pb.sectionIndex(block.section) + '"]');
    var floor = Math.max(band.top, edge ? o.box(edge).top + o.box(edge).height : band.top) + gap;
    var main = o.box(pb.canvas.main());
    var fieldEls = Array.prototype.slice.call(pb.canvas.main().querySelectorAll('[data-bx-field], .bx-add-item-cell'));
    // Each with its air around it, above and below as at the sides: the bar stands 4px clear
    // of any words, the block's first line among them (D-189, the owner).
    var obstacles = fieldEls.map(o.box)
      .concat(Array.prototype.map.call(o.layer().querySelectorAll('.bx-plus, .bx-add-end'), o.box))
      .map(function (f) { return f.width > 0 && f.height > 0 ? { top: f.top - gap, left: f.left - gap, width: f.width + gap * 2, height: f.height + gap * 2 } : { top: -1e6, left: -1e6, width: 0, height: 0 }; });
    function clear(top, left) {
      return !obstacles.some(function (f) {
        return left < f.left + f.width && f.left < left + w && top < f.top + f.height && f.top < top + h;
      });
    }
    var above = b.top - gap - h;
    var below = b.top + b.height + gap;
    var places = [{ top: above, left: b.left, side: 'above', ok: above >= floor }, { top: below, left: b.left, side: 'below', ok: true }];
    // Slid along: to just past each obstacle, within the page.
    var lefts = obstacles.map(function (f) { return f.left + f.width + gap; })
      .filter(function (l) { return l > b.left && l + w <= main.left + main.width; })
      .sort(function (x, y) { return x - y; });
    [['above', above], ['below', below]].forEach(function (pair) {
      lefts.forEach(function (left) { places.push({ top: pair[1], left: left, side: pair[0], ok: true }); });
    });
    // Then the same places with no air between, the toolbar touching the block (D-185: two
    // bands of one surface half the space apart left Brutalist exactly a toolbar's height).
    var touching = [{ top: b.top - h, left: b.left, side: 'above' }, { top: b.top + b.height, left: b.left, side: 'below' }];
    [['above', b.top - h], ['below', b.top + b.height]].forEach(function (pair) {
      lefts.forEach(function (left) { touching.push({ top: pair[1], left: left, side: pair[0] }); });
    });
    // A field of the block's own that is a picture, which the last two places may lie on.
    function ownPicture(i) {
      var field = i < fieldEls.length ? fieldEls[i] : null;
      if (!field || !field.hasAttribute('data-bx-field') || !node.contains(field) || !pb.inline || !pb.inline.spec) { return false; }
      var spec = pb.inline.spec(block.type, field.getAttribute('data-bx-field'));
      return !!spec && spec.type === 'media';
    }
    function on(p, i) {
      var f = obstacles[i];
      return Math.max(0, Math.min(p.left + w, f.left + f.width) - Math.max(p.left, f.left)) * Math.max(0, Math.min(p.top + h, f.top + f.height) - Math.max(p.top, f.top));
    }
    function lies(p, picturesToo) {
      return obstacles.reduce(function (sum, f, i) { return sum + (picturesToo || !ownPicture(i) ? on(p, i) : 0); }, 0);
    }
    var inside = { top: b.top + gap, left: b.left + gap, side: 'inside' };
    // THE LAST RESORT: inside the block at its top, over nothing but its own picture; and only
    // where even that is not to be had, the place that lies on least.
    var chosen = places.filter(function (p) { return p.ok && clear(p.top, p.left); })[0]
      || touching.filter(function (p) { return lies(p, true) === 0; })[0]
      || (lies(inside, false) === 0 ? inside : null)
      || places.concat(touching, [inside]).sort(function (x, y) { return lies(x, true) - lies(y, true); })[0];
    o.at(bar, { top: chosen.top, left: chosen.left });
    bar.setAttribute('data-bx-side', chosen.side);
  };
})();
