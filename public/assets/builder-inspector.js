/*
 * THE INSPECTOR (PLAN.md D-175, README 4.5): what is selected, drawn by the server with the
 * admin's controls (PageBuilderApi::inspect), and every control written back into the document.
 *
 * A BAND'S CONTROLS are named by where their value lives (`s.layout`, `s.style.surface`…) and
 * written straight into the document: closed sets and stepped numbers, which the server cleans
 * again when it draws and saves. A BLOCK'S FIELDS are its form, posted whole to /fields and
 * cleaned there by the parser every save has used; what comes back is the block's content.
 * Either way the band is drawn again, and a choice that changes what the inspector shows (a
 * layout, an option, a surface) draws the inspector again too — never on a keystroke, which
 * would take the field from under the hand typing in it.
 */
(function () {
  'use strict';

  var pb = window.pb;
  var panel = document.querySelector('[data-pb-inspector]');
  if (!pb || !panel) {
    return;
  }
  var nothing = panel.innerHTML;
  var ticket = 0;
  var COMPOSED = ['surface', 'pad_top', 'pad_bottom', 'min_height', 'v_align', 'width', 'align', 'divider', 'animation'];
  var LAYOUT_COLUMNS = { one: 1, halves: 2, thirds: 3, quarters: 4, 'wide-left': 2, 'wide-right': 2, sidebar: 2 };
  var drawTimers = {};

  function redraw(sectionKey, now) {
    clearTimeout(drawTimers[sectionKey]);
    drawTimers[sectionKey] = setTimeout(function () { pb.canvas.draw(sectionKey); }, now ? 0 : 150);
  }

  function inspect() {
    var sel = pb.selection;
    var mine = ++ticket;
    if (!sel) {
      panel.innerHTML = nothing;
      return;
    }
    var sectionKey = sel.kind === 'section' ? sel.key : (pb.block(sel.key) || {}).section;
    var section = pb.section(sectionKey);
    if (!section) {
      pb.select(null);
      return;
    }
    pb.api(pb.data.endpoints.inspect, { kind: sel.kind, key: sel.key, section: section, blocks: pb.blocksIn(sectionKey), number: pb.sectionIndex(sectionKey) + 1 }).then(function (answer) {
      if (mine !== ticket || answer.status !== 200) {
        return;
      }
      panel.innerHTML = answer.json.html;
      if (window.boxletRichText) { window.boxletRichText.scan(panel); }
      if (window.boxletPicker) { window.boxletPicker.scan(panel); }
      // An error found while typing on the page is said here too (README 4.4).
      var form = panel.querySelector('[data-block-fields]');
      if (form && pb.errors[sel.key]) { errors(form, pb.errors[sel.key]); }
      pb.emit('inspected', sel);
    });
  }

  function selectedSection() {
    var sel = pb.selection;
    return sel ? pb.section(sel.kind === 'section' ? sel.key : (pb.block(sel.key) || {}).section) : null;
  }

  /** A band's control, into the document. */
  function sectionValue(target, final) {
    var section = selectedSection();
    var path = target.name.split('.');
    if (!section || path[0] !== 's') {
      return;
    }
    var value = target.type === 'checkbox' ? (target.checked ? 'yes' : '') : target.value;
    pb.change(function () {
      if (path[1] === 'style') {
        section.style[path[2]] = value;
      } else {
        section[path[1]] = value;
      }
      if (path[1] === 'layout') {
        var most = (LAYOUT_COLUMNS[value] || 1) - 1;
        pb.doc.blocks.forEach(function (b) { if (b.section === section.key && b.column > most) { b.column = most; } });
      }
    }, { coalesce: section.key + target.name, quiet: path[2] !== 'name' && path[2] !== 'anchor' && path[1] !== 'layout' });
    redraw(section.key, final);
    if (final && target.type !== 'text') {
      inspect();
    }
  }

  // ---- a block's fields --------------------------------------------------------------------------
  var fieldTimer = null;
  function sendFields(form, rethink) {
    clearTimeout(fieldTimer);
    fieldTimer = setTimeout(function () {
      var key = form.getAttribute('data-block-fields');
      var body = new URLSearchParams(new FormData(form));
      fetch(pb.data.endpoints.fields, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': pb.csrf, Accept: 'application/json' }, body: body })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          var block = pb.block(key);
          if (!block || !json.block) {
            return;
          }
          pb.change(function () {
            block.content = json.block.content;
            block.options = json.block.options;
            block.layout = json.block.layout;
          }, { coalesce: 'fields:' + key, quiet: true });
          redraw(block.section, true);
          errors(form, json.errors || {});
          pb.errors[key] = json.errors || {};
          pb.emit('errors', key);
          if (rethink) { inspect(); }
        });
    }, rethink ? 0 : 400);
  }

  /**
   * Each error under its field, the field marked, and never out of sight (D-179): All content
   * opens on an error in it, and the first error is scrolled to when it is not in view — an
   * error found on the page, that is: typing in the inspector is never scrolled away from.
   */
  function errors(form, found) {
    Array.prototype.forEach.call(form.querySelectorAll('.field-error[data-pb-error]'), function (e) { e.remove(); });
    Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid="true"]'), function (e) { e.removeAttribute('aria-invalid'); });
    var first = null;
    Object.keys(found).forEach(function (name) {
      var input = form.querySelector('[name$="[' + name + ']"]');
      var holder = input ? input.closest('.field, .repeater') : null;
      if (holder) {
        var p = document.createElement('p');
        p.className = 'field-error';
        p.setAttribute('role', 'alert');
        p.setAttribute('data-pb-error', '');
        p.textContent = found[name];
        holder.appendChild(p);
        input.setAttribute('aria-invalid', 'true');
        var fold = holder.closest('details');
        if (fold) { fold.open = true; }
        first = first || holder;
      }
    });
    // Not while the inspector is being typed in: the hand stays where it is.
    if (first && !panel.contains(document.activeElement)) {
      var shown = first.getBoundingClientRect();
      var room = panel.getBoundingClientRect();
      if (shown.top < room.top || shown.bottom > room.bottom) {
        first.scrollIntoView({ block: 'center' });
      }
    }
  }

  panel.addEventListener('input', function (event) {
    var t = event.target;
    if (t.name && t.name.indexOf('s.') === 0) {
      sectionValue(t, false);
      return;
    }
    var form = t.closest('[data-block-fields]');
    if (form) { sendFields(form, false); }
  });
  panel.addEventListener('change', function (event) {
    var t = event.target;
    if (t.name && t.name.indexOf('s.') === 0) {
      sectionValue(t, true);
      return;
    }
    var form = t.closest('[data-block-fields]');
    if (form) { sendFields(form, /\[(layout|options)\]/.test(t.name || '')); }
  });
  panel.addEventListener('submit', function (event) { event.preventDefault(); });

  panel.addEventListener('click', function (event) {
    var reset = event.target.closest('button[name="reset"]');
    var all = event.target.closest('[data-reset-all]');
    var action = event.target.closest('[data-action]');
    var sel = pb.selection;
    if (!sel || !(reset || all || action)) {
      return;
    }
    event.preventDefault();
    var section = selectedSection();
    var block = sel.kind === 'block' ? pb.block(sel.key) : null;
    if (reset) {
      var path = reset.value.split(':')[0].split('.');
      pb.change(function () {
        if (path[0] === 's') { if (path[1] === 'style') { section.style[path[2]] = ''; } else { section[path[1]] = ''; } }
        if (path[0] === 'b' && block) { if (path[1] === 'options') { block.options[path[2]] = ''; } else { block.layout = reset.value.split(':')[1]; } }
      }, { sections: [section.key] });
      inspect();
    } else if (all) {
      pb.change(function () {
        if (all.getAttribute('data-reset-all') === 'section') {
          COMPOSED.forEach(function (k) { section.style[k] = ''; });
        } else if (block) {
          Object.keys(block.options || {}).forEach(function (k) { block.options[k] = ''; });
          block.layout = all.getAttribute('data-layout');
        }
      }, { sections: [section.key] });
      inspect();
    } else {
      var name = action.getAttribute('data-action');
      var acts = {
        'select-section': function () { pb.select('section', section.key); },
        duplicate: function () { if (block) { pb.duplicateBlock(block.key); } else { pb.duplicateSection(section.key); } },
        delete: function () { if (block) { pb.deleteBlock(block.key); } else { pb.deleteSection(section.key); } },
        pattern: function () { pb.savePattern(section.key); },
      };
      if (acts[name]) { acts[name](); }
    }
  });

  pb.on('select', inspect);
  pb.on('inspect', inspect);
  pb.on('replace', function () {
    var sel = pb.selection;
    if (sel && !(sel.kind === 'section' ? pb.section(sel.key) : pb.block(sel.key))) {
      pb.select(null);
    } else {
      inspect();
    }
  });
})();
