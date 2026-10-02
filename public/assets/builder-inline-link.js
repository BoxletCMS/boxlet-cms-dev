/*
 * WHERE A LINK LEADS, ON THE PAGE (PLAN.md D-178, README 4.4): pressing a link's words edits
 * them in place (builder-inline.js) and opens this under them — a page of the site, or another
 * address — and the rich text toolbar's link button opens the same. A page is stored as its
 * reference (`page:3`, D-034), so the link follows the page wherever it moves.
 *
 * Drawn in the canvas's layer, and put back there when the layer is drawn again.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.inline || !pb.overlay) {
    return;
  }
  var o = pb.overlay;
  var pages = (pb.data.inline || {}).pages || [];
  var open = null;

  function close() {
    if (open) {
      open.panel.remove();
      open = null;
    }
  }

  /**
   * The popover under `anchor`, showing `url`. `onChange(url)` is told every change; `onDone`
   * when it closes. `onRemove`, when given, offers taking the link away.
   */
  function picker(anchor, url, onChange, onDone, onRemove) {
    close();
    var panel = o.el('div', 'bx-link');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', pb.t('inline.link_title'));
    panel.appendChild(o.el('p', 'bx-link-title', pb.t('inline.link_title')));
    var select = o.el('select', 'bx-link-page');
    select.setAttribute('aria-label', pb.t('inline.link_page'));
    var other = o.el('option', '', pb.t('inline.link_other'));
    other.value = '';
    select.appendChild(other);
    pages.forEach(function (page) {
      var option = o.el('option', '', new Array(page.depth + 1).join('— ') + page.title);
      option.value = page.ref;
      select.appendChild(option);
    });
    var input = o.el('input', 'bx-link-url');
    input.type = 'text';
    input.setAttribute('aria-label', pb.t('inline.link_url'));
    input.placeholder = pb.t('inline.link_url');
    var isPage = /^page:\d+$/.test(url || '');
    select.value = isPage ? url : '';
    input.value = isPage ? '' : (url || '');
    input.hidden = isPage;
    panel.appendChild(select);
    panel.appendChild(input);
    var row = o.el('div', 'bx-link-actions');
    var done = o.el('button', 'bx-link-done', pb.t('inline.link_done'));
    done.type = 'button';
    row.appendChild(done);
    if (onRemove) {
      var remove = o.el('button', 'bx-link-remove', pb.t('inline.link_remove'));
      remove.type = 'button';
      remove.addEventListener('click', function () { close(); onRemove(); });
      row.appendChild(remove);
    }
    panel.appendChild(row);

    function value() { return select.value !== '' ? select.value : input.value.trim(); }
    select.addEventListener('change', function () {
      input.hidden = select.value !== '';
      onChange(value());
    });
    input.addEventListener('input', function () { onChange(value()); });
    function finish() { var v = value(); close(); if (onDone) { onDone(v); } }
    done.addEventListener('click', finish);
    panel.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target === input) { event.preventDefault(); finish(); }
      if (event.key === 'Escape') { event.preventDefault(); finish(); }
    });

    var b = o.box(anchor);
    o.at(panel, { top: b.top + b.height + o.px(8), left: b.left });
    o.layer().appendChild(panel);
    open = { panel: panel, anchor: anchor };
    return panel;
  }

  /** A link field's address, from its words on the page. */
  pb.inline.link = function (el, block, path) {
    var now = pb.inline.get(block, path) || { label: '', url: '' };
    picker(el, now.url || '', function (url) {
      var b = pb.block(block.key);
      if (!b) { return; }
      var link = pb.copy(pb.inline.get(b, path) || { label: '', url: '' });
      link.url = url;
      pb.inline.write(b, path, link);
    }, function () {
      pb.inline.check(block.key);
    }, function () {
      var b = pb.block(block.key);
      if (!b) { return; }
      pb.change(function () { pb.inline.set(b, path, { label: '', url: '' }); }, { sections: [b.section] });
      pb.inline.check(block.key);
    });
  };

  pb.inline.picker = picker;
  pb.inline.closeLink = close;

  // A layer drawn again keeps the popover open on it.
  pb.on('painted', function (layer) {
    if (open && !open.panel.isConnected && layer) {
      layer.appendChild(open.panel);
    }
  });
  // Pressing the page anywhere else closes it.
  pb.on('canvas', function () {
    var doc = pb.canvas.doc();
    if (!doc || doc.__bxLink) { return; }
    doc.__bxLink = true;
    doc.addEventListener('mousedown', function (event) {
      if (open && !open.panel.contains(event.target) && !open.anchor.contains(event.target)) {
        close();
      }
    }, true);
  });
})();
