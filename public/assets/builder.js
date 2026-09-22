/*
 * The visual editor shell: selection, the panel's two modes, the device width and the
 * unsaved-changes guard.
 *
 * Changes to the page itself live in builder-blocks.js, which attaches to the object
 * this file exposes. Both are deferred, so this one runs first.
 *
 * No block definition, no field markup and no validation lives in either: a block's HTML
 * is always asked for from the server, which renders it from the definition it owns.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-builder]');
  var frame = document.querySelector('iframe[data-canvas]');
  if (!form || !frame) {
    return;
  }

  var panel = form.querySelector('[data-insert-url]');
  var groups = form.querySelector('[data-block-groups]');
  var library = form.querySelector('[data-library]');
  var selectedPane = form.querySelector('[data-panel-selected]');
  var selectedName = form.querySelector('[data-selected-name]');
  var status = form.querySelector('[data-insert-status]');
  var selected = -1;

  var api = {
    form: form,
    frame: frame,
    panel: panel,
    groups: groups,
    // Where a "+" on the page aimed; null means the end.
    target: null,
    dirty: false,
  };

  /*
   * Record the page before a structural change, so it can be put back (D-079).
   *
   * A stub here and the real one in builder-undo.js, which loads last: every caller then
   * says what it means — "this is a change worth remembering" — without asking whether
   * the file that remembers is present. Without it the editor works and forgets, which is
   * exactly where it stood before.
   */
  api.commit = function () {};

  api.groupNodes = function () {
    return Array.prototype.slice.call(groups.querySelectorAll('[data-block-group]'));
  };

  api.sections = function () {
    var doc = frame.contentDocument;
    return doc ? Array.prototype.slice.call(doc.querySelectorAll('[data-bx-blocks] > section')) : [];
  };

  api.selected = function () {
    return selected;
  };

  api.say = function (message) {
    if (status) {
      status.textContent = message || '';
    }
  };

  api.tellCanvas = function (name, detail) {
    if (frame.contentWindow) {
      frame.contentWindow.postMessage(
        Object.assign({ source: 'boxlet-builder', type: name }, detail || {}),
        window.location.origin,
      );
    }
  };

  // Names and ids follow position, so the server receives blocks[0..n] in the order they
  // appear on the page. The same rule the fallback editor follows.
  api.renumber = function () {
    api.groupNodes().forEach(function (group, index) {
      group.setAttribute('data-block-group', String(index));
      group.querySelectorAll('[name]').forEach(function (element) {
        element.name = element.name.replace(/^blocks\[[^\]]*\]/, 'blocks[' + index + ']');
      });
      group.querySelectorAll('[id]').forEach(function (element) {
        element.id = element.id.replace(/^block-[^-]+-/, 'block-' + index + '-');
      });
      group.querySelectorAll('label[for]').forEach(function (label) {
        label.htmlFor = label.htmlFor.replace(/^block-[^-]+-/, 'block-' + index + '-');
      });
    });
    api.dirty = true;
  };

  /*
   * CONTENT AND SECTION (PLAN.md D-086).
   *
   * The section's style sat at the foot of the group's scroll, behind every content field:
   * measured on the demo page, with the panel's first field on screen at y=287, the first
   * style control was at y=1308 on a Hero and y=4296 on a Columns block, in a window 1000px
   * tall. Four screens down, past twelve repeater items, for the thing most often changed
   * while looking at the page.
   *
   * The split is a class on the panel and two rules in the stylesheet, not a rearrangement
   * of the group: block.php is the PLAIN editor's view too, and there the group stays one
   * scroll with the style folded at its foot. Moving markup around would have meant two
   * shapes of one thing, and the plain editor is the fallback that has to keep working.
   *
   * Which tab is open is remembered for the session, not per block: somebody adjusting how
   * a page looks moves from block to block doing the same thing, and being thrown back to
   * Content on every selection would undo that.
   */
  var tabs = form.querySelector('[data-panel-tabs]');

  function showTab(name) {
    form.setAttribute('data-panel-tab', name);
    /* The style is a <details> because the plain editor folds it; behind a tab it must be
       open, since the tab is what unfolded it and its own summary is hidden. Without this
       the Section tab was EMPTY — and a check that read getBoundingClientRect() on a field
       inside the closed <details> reported a box 40px tall at y=340, because the browser
       lays out what it does not paint. The screenshot was right and the measurement was
       wrong; what is asserted now is the height of the <details> itself. */
    form.querySelectorAll('[data-panel-part="section"]').forEach(function (part) {
      part.open = true;
    });
    if (!tabs) {
      return;
    }
    tabs.querySelectorAll('[data-panel-tab]').forEach(function (tab) {
      tab.setAttribute('aria-selected', tab.getAttribute('data-panel-tab') === name ? 'true' : 'false');
    });
  }

  if (tabs) {
    tabs.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-panel-tab]');
      if (tab) {
        showTab(tab.getAttribute('data-panel-tab'));
      }
    });
    showTab('content');
  }

  api.show = function (index) {
    selected = index;
    /* Re-applied on every selection because a field group can arrive as FRESH MARKUP — an
       undo puts all of them back, an insert brings a new one from the server — and such a
       group's <details> is open only when block.php happened to render it open, which is
       when its style differs from the character's. On the Section tab a closed one shows
       nothing. It survived the first check by that accident; this is the rule. */
    if (tabs) {
      showTab(form.getAttribute('data-panel-tab') || 'content');
    }
    api.groupNodes().forEach(function (group) {
      group.hidden = Number(group.getAttribute('data-block-group')) !== index;
    });
    if (library) {
      library.hidden = index >= 0;
    }
    if (selectedPane) {
      selectedPane.hidden = index < 0;
    }
    var group = index >= 0 ? groups.querySelector('[data-block-group="' + index + '"]') : null;
    if (group && selectedName) {
      var labelled = group.querySelector('[data-block-label]');
      selectedName.textContent = labelled ? labelled.getAttribute('data-block-label') : '';
    }
    /*
     * PUT THE CURSOR IN THE BLOCK'S FIRST FIELD — UNLESS THE PERSON IS IN THE CANVAS.
     *
     * Selecting a block from the library should leave you ready to type, which is what this
     * is for. But selection also happens as a CONSEQUENCE of something done in the canvas —
     * a click on a section, a press of Move down — and there this reached into the panel and
     * took the keyboard out of the canvas. Measured: focus a tool button, press it, and the
     * next press needs the mouse, because focus had left the iframe altogether. It is also
     * what made ⌘Z after a duplicate do nothing (D-079): the cursor was in a field the
     * author had never asked for.
     *
     * When focus is inside the canvas the parent's activeElement IS the iframe, so the rule
     * costs one comparison: the editor never takes the keyboard away from where the person
     * is working.
     */
    if (group && document.activeElement !== api.frame) {
      var field = group.querySelector('input:not([type="hidden"]), textarea, select');
      if (field) {
        field.focus({ preventScroll: true });
      }
    }
  };

  // Keys pair a section with its field group and survive reordering, so a drag in the
  // canvas can be replayed on the form without either side guessing.
  api.pairKeys = function () {
    var list = api.sections();
    api.groupNodes().forEach(function (group, index) {
      var section = list[index];
      if (section && !group.getAttribute('data-block-key')) {
        group.setAttribute('data-block-key', section.getAttribute('data-bx-key'));
      }
    });
  };

  function reorderTo(keys) {
    keys.forEach(function (key) {
      var group = groups.querySelector('[data-block-key="' + key + '"]');
      if (group) {
        groups.appendChild(group);
      }
    });
    api.renumber();
    api.show(-1);
    api.tellCanvas('select', { index: -1 });
  }

  // A group carrying a validation error opens itself, so a rejected save does not hide
  // the reason behind a selection nobody has made yet.
  function failedGroup() {
    var failed = groups.querySelector('[data-block-group] .field-error');
    var group = failed && failed.closest('[data-block-group]');
    return group ? Number(group.getAttribute('data-block-group')) : -1;
  }

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-canvas') {
      return;
    }
    if (event.data.type === 'ready') {
      api.pairKeys();
      api.show(failedGroup());
    } else if (event.data.type === 'select') {
      api.target = null;
      api.show(event.data.index);
    } else if (event.data.type === 'insert') {
      api.target = event.data.index;
      api.show(-1);
      api.tellCanvas('select', { index: -1 });
      if (library) {
        library.scrollIntoView({ block: 'nearest' });
      }
    } else if (event.data.type === 'reorder') {
      reorderTo(event.data.keys);
    } else if (event.data.type === 'action' && api.act) {
      // The selected block's controls in the canvas (D-040): the same act() the panel's
      // buttons used to call, so there is one way to move, copy or remove a block.
      api.act(event.data.action);
    }
  });

  form.addEventListener('click', function (event) {
    if (event.target.closest && event.target.closest('[data-deselect]')) {
      event.preventDefault();
      api.show(-1);
      api.tellCanvas('select', { index: -1 });
      return;
    }
    var device = event.target.closest && event.target.closest('[data-device]');
    if (device) {
      event.preventDefault();
      form.querySelectorAll('[data-device]').forEach(function (button) {
        button.setAttribute('aria-pressed', String(button === device));
      });
      frame.style.width = device.getAttribute('data-width');
    }
  });

  // The toolbar shows the page's name; the panel owns the field.
  var titleField = form.querySelector('#page-title');
  var titleEcho = form.querySelector('[data-title-echo]');
  var slugField = form.querySelector('[data-slug-field]');
  var statusField = form.querySelector('[data-status-field]');
  var slugEdited = false;

  if (slugField) {
    slugField.addEventListener('input', function () { slugEdited = true; });
  }

  /**
   * The address follows the title, but only while the page is a draft and only until the
   * address is touched. Two deliberate refusals: a published page's address never changes
   * behind its author, and an empty address is never generated over — empty means "the
   * home page of this language", so filling it in would move the site's root.
   *
   * The server's Slug is authoritative; this is a convenience that it validates.
   */
  function followTitle() {
    if (!slugField || !titleField || slugEdited) {
      return;
    }
    if (slugField.value === '' || (statusField && statusField.value !== 'draft')) {
      return;
    }
    slugField.value = titleField.value
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 90);
  }

  if (titleField) {
    titleField.addEventListener('input', function () {
      if (titleEcho) {
        titleEcho.textContent = titleField.value;
      }
      followTitle();
    });
  }

  form.addEventListener('input', function () { api.dirty = true; });
  form.addEventListener('change', function () { api.dirty = true; });
  form.addEventListener('submit', function () { api.dirty = false; });
  window.addEventListener('beforeunload', function (event) {
    if (api.dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  window.boxletBuilder = api;
  api.show(failedGroup());
})();
