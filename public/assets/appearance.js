/*
 * The Appearance screen's form. Optional: without JavaScript, "Update preview" submits the
 * separate preview form into the preview frame and Publish posts normally. With it, the preview,
 * the colour readouts, the contrast gauge and the inline messages follow every change.
 * All derivation and validation stays on the server; this only asks for it.
 *
 * TWO SPEEDS, because one was wrong for both (PLAN.md D-058). A select, a checkbox or a
 * radio is a DECISION — the person has already chosen, and a quarter-second of nothing
 * reads as a screen that did not hear them. A colour input and anything dragged fire
 * continuously, so those wait until the hand stops. Choosing is immediate; dragging is
 * settled.
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

  // The gauge, measured on the server: this only writes the numbers it is handed. A row
  // that fails keeps its place in the list rather than jumping to the top — a list that
  // reorders under the reader is a list nobody can follow.
  function showPairs(pairs) {
    pairs.forEach(function (pair) {
      var row = document.querySelector('[data-pair="' + pair.pair + '"]');
      if (!row) {
        return;
      }
      row.classList.toggle('gauge-fails', !pair.passes);
      var ratio = row.querySelector('[data-pair-ratio]');
      var verdict = row.querySelector('[data-pair-verdict]');
      var background = row.querySelector('[data-pair-background]');
      var foreground = row.querySelector('[data-pair-foreground]');
      if (ratio) ratio.textContent = pair.ratio.toFixed(2);
      // The two words come from the markup, because they are translated and this file is not.
      if (verdict) verdict.textContent = verdict.getAttribute(pair.passes ? 'data-pass' : 'data-fail') || verdict.textContent;
      if (background) background.setAttribute('fill', pair.background);
      if (foreground) foreground.setAttribute('fill', pair.foreground);
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

  /**
   * What the screen is in one word, for the toolbar over the picture (D-060). An event
   * rather than a reach into another file's elements: this one knows, because it is the one
   * asking the server.
   */
  function announce(name) {
    document.dispatchEvent(new CustomEvent('appearance:state', { detail: { state: name } }));
  }

  function refresh() {
    window.clearTimeout(timer);
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
          showPairs(result.pairs || []);
          // A palette that would be refused is not "not published yet" — it is something to
          // fix, and the screen says which of the two it is.
          announce(Object.keys(result.errors || {}).length > 0 ? 'problem' : 'unpublished');
        }
      })
      .catch(function () {
        // The preview frame still updates; the server re-checks on Save.
      });
  }

  /** A control the person has finished with: a choice, rather than a value being dragged. */
  function isDiscrete(target) {
    if (!target || !target.tagName) {
      return false;
    }
    var tag = target.tagName.toLowerCase();
    return tag === 'select' || (tag === 'input' && /^(checkbox|radio)$/.test(target.type));
  }

  function changed(event) {
    dirty = true;
    showColourValues();
    announce('unpublished');
    if (event.type === 'change' && isDiscrete(event.target)) {
      refresh();
      return;
    }
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, 250);
  }

  form.addEventListener('input', changed);
  form.addEventListener('change', changed);
  // Still the form's own event, although the buttons now sit beside the preview: a button
  // with form="design-form" submits that form, wherever it stands.
  form.addEventListener('submit', function () {
    dirty = false;
  });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // With JavaScript the preview follows every change, so the button has nothing left to do
  // and goes — a control that repeats what already happened is a control that makes the
  // person doubt whether it did.
  var previewButton = document.querySelector('[data-preview-button]');
  if (previewButton) {
    previewButton.remove();
  }
})();
