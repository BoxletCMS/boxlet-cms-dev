/*
 * The visual editor shell, outside the canvas.
 *
 * It owns selection and the device width, and nothing else: the field groups it shows
 * were rendered by the server and are already in the form. No block definition, no
 * validation and no field markup exists in this file.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-builder]');
  var frame = document.querySelector('iframe[data-canvas]');
  if (!form || !frame) {
    return;
  }

  var groups = form.querySelector('[data-block-groups]');
  var empty = form.querySelector('[data-panel-empty]');
  var selected = -1;
  var dirty = false;

  function groupNodes() {
    return Array.prototype.slice.call(groups.querySelectorAll('[data-block-group]'));
  }

  function show(index) {
    selected = index;
    groupNodes().forEach(function (group) {
      group.hidden = Number(group.getAttribute('data-block-group')) !== index;
    });
    if (empty) {
      empty.hidden = index >= 0;
    }
    if (index >= 0) {
      var field = groups.querySelector('[data-block-group="' + index + '"] input:not([type="hidden"]), [data-block-group="' + index + '"] textarea, [data-block-group="' + index + '"] select');
      if (field) {
        field.focus({ preventScroll: true });
      }
    }
  }

  function tellCanvas(name, detail) {
    if (frame.contentWindow) {
      frame.contentWindow.postMessage(Object.assign({ source: 'boxlet-builder', type: name }, detail || {}), window.location.origin);
    }
  }

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-canvas') {
      return;
    }
    if (event.data.type === 'select') {
      show(event.data.index);
    }
  });

  // A field group that already carries a validation error opens itself, so a rejected
  // save does not hide the reason behind a selection the user has not made yet.
  var failed = groups.querySelector('[data-block-group] .field-error');
  var failedGroup = failed && failed.closest('[data-block-group]');
  if (failedGroup) {
    var index = Number(failedGroup.getAttribute('data-block-group'));
    show(index);
    tellCanvas('select', { index: index });
  } else {
    show(-1);
  }

  form.addEventListener('click', function (event) {
    var device = event.target.closest && event.target.closest('[data-device]');
    if (!device) {
      return;
    }
    event.preventDefault();
    form.querySelectorAll('[data-device]').forEach(function (button) {
      button.setAttribute('aria-pressed', String(button === device));
    });
    frame.style.width = device.getAttribute('data-width');
  });

  function markDirty() {
    dirty = true;
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', function () {
    dirty = false;
  });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
