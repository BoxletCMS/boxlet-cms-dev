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

  api.show = function (index) {
    selected = index;
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
    if (group) {
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
