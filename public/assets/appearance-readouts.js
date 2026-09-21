/*
 * What the Appearance screen SHOWS of the server's answer: the inline messages, the derived
 * palette, the contrast gauge, and what every control comes to in words.
 *
 * Split from appearance.js when that passed 300 lines, along the seam it already had: that
 * file ASKS the server and decides what the picture needs; this one only writes down what
 * came back. Nothing here decides anything, which is why none of it needs to know about the
 * preview, the debounce or the reload list.
 *
 * It listens rather than being called, because the two files cannot import each other — the
 * admin has no module loader and by D-013 never will. The same way appearance-stage.js is
 * told what state the screen is in.
 *
 * Optional, like every script here: without it the server's answer simply is not drawn, and
 * Publish re-checks everything anyway.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-design-form]');
  if (!form) {
    return;
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

    /*
     * A ROLE LEFT TO THE PALETTE SHOWS WHAT THE PALETTE NOW SAYS (D-063).
     *
     * Each of those inputs starts at the colour the server worked out, which stops being
     * true the moment anything it depends on moves: set a dark page by hand and the five
     * other swatches still showed the near-white palette they were rendered with, while the
     * preview beside them was dark. The same stale-dependent bug the palette itself was
     * rearranged to prevent, this time on the screen.
     *
     * A role the owner has taken over is never touched: that colour is theirs.
     */
    form.querySelectorAll('[data-by-hand]').forEach(function (input) {
      var field = input.getAttribute('data-by-hand');
      var role = field.replace(/^color_/, '');
      var mine = form.querySelector('[data-by-hand-switch="' + field + '"]');
      if (mine && !mine.checked && colors[role]) {
        input.value = colors[role];
      }
    });
    showColourValues();
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

  /*
   * WHAT EVERY CONTROL COMES TO, as the server says it (D-066). Rendered once into the
   * markup and written again on every answer, because a readout rendered once goes stale
   * the moment anything moves — and a readout that lies is worse than none, since it is the
   * thing being read.
   */
  function showReadouts(readouts) {
    Object.keys(readouts || {}).forEach(function (name) {
      document.querySelectorAll('[data-readout="' + name.replace(/"/g, '') + '"]').forEach(function (slot) {
        // A group still following the character says so; that is a state, not a value.
        if (!slot.classList.contains('readout-following')) {
          slot.textContent = readouts[name];
        }
      });
    });
  }

  document.addEventListener('appearance:answer', function (event) {
    var answer = event.detail || {};
    showErrors(answer.errors || {});
    showColors(answer.colors || {});
    showPairs(answer.pairs || []);
    showReadouts(answer.readouts);
  });

  // The hex beside a colour keeps up with the HAND, not with the server: a value that only
  // appears a quarter of a second after the picker closes reads as a screen that did not
  // hear the choice.
  form.addEventListener('input', showColourValues);
})();
