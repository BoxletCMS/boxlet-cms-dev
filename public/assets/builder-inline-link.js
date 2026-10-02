/*
 * WHERE A LINK LEADS, ON THE PAGE (PLAN.md D-178, README 4.4): pressing a link's words edits
 * them in place (builder-inline.js) and opens this under them — a page of the site, or another
 * address — and the rich text toolbar's link button opens the same. A page is stored as its
 * reference (`page:3`, D-034), so the link follows the page wherever it moves.
 *
 * In the admin's own document, over the canvas, and in the admin's own fields and buttons
 * (D-179): a form the inspector would draw, not a control of the page's. It is placed under
 * the words where the scaled canvas shows them, and placed again when the page scrolls or is
 * drawn again.
 */
(function () {
  'use strict';

  var pb = window.pb;
  if (!pb || !pb.inline || !pb.overlay) {
    return;
  }
  var pages = (pb.data.inline || {}).pages || [];
  var open = null;

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text) { node.textContent = text; }
    return node;
  }

  function close() {
    if (open) {
      open.panel.remove();
      open = null;
    }
  }

  /** Under the words, where the scaled canvas shows them; above them when there is no room. */
  function place() {
    if (!open) {
      return;
    }
    if (!open.anchor.isConnected) {
      close();
      return;
    }
    var frame = document.querySelector('[data-pb-canvas]').getBoundingClientRect();
    var scale = pb.canvas.scale || 1;
    var r = open.anchor.getBoundingClientRect();
    var panel = open.panel;
    var gap = 8;
    var top = frame.top + (r.top + r.height) * scale + gap;
    if (top + panel.offsetHeight > window.innerHeight - gap) {
      top = Math.max(gap, frame.top + r.top * scale - panel.offsetHeight - gap);
    }
    var left = Math.min(frame.left + r.left * scale, window.innerWidth - panel.offsetWidth - gap);
    panel.style.top = top + 'px';
    panel.style.left = Math.max(gap, left) + 'px';
  }

  /**
   * The popover under `anchor`, showing `url`. `onChange(url)` is told every change; `onDone`
   * when it closes. `onRemove`, when given, offers taking the link away.
   */
  function picker(anchor, url, onChange, onDone, onRemove) {
    close();
    var panel = el('div', 'pb-link');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', pb.t('inline.link_title'));
    panel.setAttribute('data-pb-link', '');
    panel.appendChild(el('p', 'pb-link-title', pb.t('inline.link_title')));

    var pageField = el('div', 'field');
    var pageLabel = el('label', '', pb.t('inline.link_page'));
    pageLabel.htmlFor = 'pb-link-page';
    var select = el('select');
    select.id = 'pb-link-page';
    var other = el('option', '', pb.t('inline.link_other'));
    other.value = '';
    select.appendChild(other);
    pages.forEach(function (page) {
      var option = el('option', '', new Array(page.depth + 1).join('— ') + page.title);
      option.value = page.ref;
      select.appendChild(option);
    });
    pageField.appendChild(pageLabel);
    pageField.appendChild(select);

    var urlField = el('div', 'field');
    var urlLabel = el('label', '', pb.t('inline.link_url'));
    urlLabel.htmlFor = 'pb-link-url';
    var input = el('input');
    input.id = 'pb-link-url';
    input.type = 'text';
    input.inputMode = 'url';
    urlField.appendChild(urlLabel);
    urlField.appendChild(input);

    var isPage = /^page:\d+$/.test(url || '');
    select.value = isPage ? url : '';
    input.value = isPage ? '' : (url || '');
    urlField.hidden = isPage;
    panel.appendChild(pageField);
    panel.appendChild(urlField);

    var row = el('div', 'pb-link-actions');
    var done = el('button', 'button button-secondary', pb.t('inline.link_done'));
    done.type = 'button';
    done.setAttribute('data-pb-link-done', '');
    row.appendChild(done);
    if (onRemove) {
      var remove = el('button', 'button button-ghost', pb.t('inline.link_remove'));
      remove.type = 'button';
      remove.setAttribute('data-pb-link-remove', '');
      remove.addEventListener('click', function () { close(); onRemove(); });
      row.appendChild(remove);
    }
    panel.appendChild(row);

    function value() { return select.value !== '' ? select.value : input.value.trim(); }
    select.addEventListener('change', function () {
      urlField.hidden = select.value !== '';
      onChange(value());
    });
    input.addEventListener('input', function () { onChange(value()); });
    function finish() { var v = value(); close(); if (onDone) { onDone(v); } }
    done.addEventListener('click', finish);
    panel.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && event.target === input) { event.preventDefault(); finish(); }
      if (event.key === 'Escape') { event.preventDefault(); finish(); }
    });

    document.querySelector('[data-pb]').appendChild(panel);
    open = { panel: panel, anchor: anchor };
    place();
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

  // Placed again whenever the page under it may have moved.
  pb.on('painted', place);
  pb.on('scale', place);
  window.addEventListener('resize', place);
  // Pressing anywhere else, on the page or in the admin, closes it.
  function outside(event) {
    if (open && !open.panel.contains(event.target) && !open.anchor.contains(event.target)) {
      close();
    }
  }
  document.addEventListener('mousedown', outside, true);
  pb.on('canvas', function () {
    var doc = pb.canvas.doc();
    if (!doc || doc.__bxLink) { return; }
    doc.__bxLink = true;
    doc.addEventListener('mousedown', outside, true);
    doc.defaultView.addEventListener('scroll', place, { passive: true });
  });
})();
