/*
 * Design screen. Optional: without JavaScript, "Update preview" submits the separate
 * preview form into the preview frame and Save posts normally. With it, the preview,
 * the colour readouts and the inline contrast messages follow every change. All
 * derivation and validation stays on the server; this only asks for it.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-design-form]');
  var preview = document.querySelector('iframe[data-design-preview]');
  if (!form || !preview) {
    return;
  }
  var timer = null;
  var dirty = false;

  function query() {
    var params = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      if (key !== '_csrf' && key !== 'action') {
        params.append(key, value);
      }
    });
    return params.toString();
  }

  function showErrors(errors) {
    form.querySelectorAll('[data-error-for]').forEach(function (element) {
      var message = errors[element.getAttribute('data-error-for')];
      element.textContent = message || '';
      element.hidden = !message;
    });
  }

  function showColors(colors) {
    Object.keys(colors).forEach(function (name) {
      form.querySelectorAll('[data-swatch="' + name + '"]').forEach(function (rect) {
        rect.setAttribute('fill', colors[name]);
      });
      form.querySelectorAll('[data-swatch-value="' + name + '"]').forEach(function (code) {
        code.textContent = colors[name];
      });
    });
  }

  // The hex next to each colour input, so the value is readable and not only visible.
  function showColourValues() {
    form.querySelectorAll('[data-colour-for]').forEach(function (output) {
      var input = document.getElementById(output.getAttribute('data-colour-for'));
      if (input) {
        output.textContent = input.value;
      }
    });
  }

  function refresh() {
    var params = query();
    preview.src = form.getAttribute('data-preview-url') + '?' + params;
    fetch(form.getAttribute('data-check-url') + '?' + params, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (result) {
        if (result) {
          showErrors(result.errors);
          showColors(result.colors);
        }
      })
      .catch(function () {
        // The preview frame still updates; the server re-checks on Save.
      });
  }

  function changed() {
    dirty = true;
    showColourValues();
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, 250);
  }

  form.addEventListener('input', changed);
  form.addEventListener('change', changed);
  form.addEventListener('submit', function () {
    dirty = false;
  });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // With JavaScript the preview button just refreshes the frame in place.
  var previewButton = document.querySelector('[data-preview-button]');
  if (previewButton) {
    previewButton.addEventListener('click', function (event) {
      event.preventDefault();
      refresh();
    });
  }
})();
