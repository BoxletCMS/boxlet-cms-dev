/*
 * The visual editor shell, outside the canvas.
 *
 * It owns every change to the page, because it holds the form and the two have to stay
 * in step: a section in the canvas and its field group in the form are the same block
 * seen twice. Both documents are this origin, so it moves nodes directly rather than
 * describing them across a boundary.
 *
 * No block definition, no field markup and no validation lives here. A new block's HTML
 * is asked for from the server, which renders both halves of it.
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
  var target = null;
  var dirty = false;
  var keyCounter = 0;

  function groupNodes() {
    return Array.prototype.slice.call(groups.querySelectorAll('[data-block-group]'));
  }

  function canvasDoc() {
    return frame.contentDocument;
  }

  function sections() {
    var doc = canvasDoc();
    return doc ? Array.prototype.slice.call(doc.querySelectorAll('[data-bx-blocks] > section')) : [];
  }

  // Names and ids follow position, so the server receives blocks[0..n] in the order they
  // appear on the page. Same rule as the fallback editor.
  function renumber() {
    groupNodes().forEach(function (group, index) {
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
    dirty = true;
  }

  function show(index) {
    groupNodes().forEach(function (group) {
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
      var legend = group.querySelector('.block-editor-legend');
      selectedName.textContent = legend ? legend.textContent.trim() : '';
    }
    if (group) {
      var field = group.querySelector('input:not([type="hidden"]), textarea, select');
      if (field) {
        field.focus({ preventScroll: true });
      }
    }
  }

  function tellCanvas(name, detail) {
    if (frame.contentWindow) {
      frame.contentWindow.postMessage(
        Object.assign({ source: 'boxlet-builder', type: name }, detail || {}),
        window.location.origin,
      );
    }
  }

  function say(message) {
    if (status) {
      status.textContent = message || '';
    }
  }

  // Keys pair a section with its field group, and survive reordering.
  function pairKeys() {
    var list = sections();
    groupNodes().forEach(function (group, index) {
      var section = list[index];
      if (section && !group.getAttribute('data-block-key')) {
        group.setAttribute('data-block-key', section.getAttribute('data-bx-key'));
      }
    });
  }

  function reorderTo(keys) {
    keys.forEach(function (key) {
      var group = groups.querySelector('[data-block-key="' + key + '"]');
      if (group) {
        groups.appendChild(group);
      }
    });
    renumber();
    show(-1);
    tellCanvas('select', { index: -1 });
  }

  function insertBlock(type, button) {
    var doc = canvasDoc();
    if (!doc) {
      return;
    }
    var at = target === null ? sections().length : target;
    button.setAttribute('aria-busy', 'true');
    say(panel.getAttribute('data-text-inserting'));

    var body = new URLSearchParams();
    body.set('_csrf', form.querySelector('input[name="_csrf"]').value);
    body.set('type', type);
    body.set('index', String(at));

    fetch(panel.getAttribute('data-insert-url'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error(String(response.status));
        }
        return response.text();
      })
      .then(function (html) {
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var canvasPart = holder.querySelector('template[data-block-canvas]');
        var fieldsPart = holder.querySelector('template[data-block-fields]');
        if (!canvasPart || !fieldsPart) {
          throw new Error('malformed');
        }
        var key = 'n' + keyCounter++;

        var fragment = doc.importNode(canvasPart.content, true);
        var section = fragment.querySelector('section');
        section.setAttribute('data-bx-key', key);
        var list = sections();
        var main = doc.querySelector('[data-bx-blocks]');
        if (at >= list.length) {
          main.appendChild(fragment);
        } else {
          main.insertBefore(fragment, list[at]);
        }

        var group = fieldsPart.content.firstElementChild.cloneNode(true);
        group.setAttribute('data-block-key', key);
        group.hidden = true;
        var existing = groupNodes();
        if (at >= existing.length) {
          groups.appendChild(group);
        } else {
          groups.insertBefore(group, existing[at]);
        }

        renumber();
        say('');
        target = null;
        tellCanvas('refresh', {});
        show(at);
        tellCanvas('select', { index: at });
      })
      .catch(function () {
        say(panel.getAttribute('data-text-failed'));
      })
      .then(function () {
        button.removeAttribute('aria-busy');
      });
  }

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-canvas') {
      return;
    }
    if (event.data.type === 'ready') {
      pairKeys();
      show(openFailedGroup());
    } else if (event.data.type === 'select') {
      target = null;
      show(event.data.index);
    } else if (event.data.type === 'insert') {
      target = event.data.index;
      show(-1);
      tellCanvas('select', { index: -1 });
      if (library) {
        library.scrollIntoView({ block: 'nearest' });
      }
    } else if (event.data.type === 'reorder') {
      reorderTo(event.data.keys);
    }
  });

  // A group carrying a validation error opens itself, so a rejected save does not hide
  // the reason behind a selection nobody has made yet.
  function openFailedGroup() {
    var failed = groups.querySelector('[data-block-group] .field-error');
    var group = failed && failed.closest('[data-block-group]');
    return group ? Number(group.getAttribute('data-block-group')) : -1;
  }

  form.addEventListener('click', function (event) {
    var add = event.target.closest && event.target.closest('[data-add-type]');
    if (add) {
      event.preventDefault();
      insertBlock(add.getAttribute('data-add-type'), add);
      return;
    }
    if (event.target.closest && event.target.closest('[data-deselect]')) {
      event.preventDefault();
      show(-1);
      tellCanvas('select', { index: -1 });
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

  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('change', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  show(openFailedGroup());
})();
