/*
 * THE INSTALLER'S LAST STEP AT WORK (PLAN.md D-212): once the form is sent, its button says
 * so and stops taking presses, "Start over" too (it would throw away an install half done),
 * and a moving bar with a sentence shows under it until the next page arrives. Without
 * script the form posts as before.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-install-busy]');
  if (!form) {
    return;
  }
  var button = form.querySelector('button[type="submit"]');
  var busy = form.querySelector('.install-busy');
  var label = button ? button.textContent : '';
  var restart = document.querySelectorAll('form.restart button');

  function lock(locked) {
    Array.prototype.forEach.call(restart, function (b) { b.disabled = locked; });
  }

  // 'submit' fires only once the browser's own checks have passed.
  form.addEventListener('submit', function () {
    form.setAttribute('aria-busy', 'true');
    if (button) {
      button.disabled = true;
      button.textContent = button.getAttribute('data-busy-label') || label;
    }
    if (busy) {
      busy.hidden = false;
    }
    lock(true);
  });

  // Back from the next page, the browser may show this one as it was left: ready again.
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) {
      return;
    }
    form.removeAttribute('aria-busy');
    if (button) {
      button.disabled = false;
      button.textContent = label;
    }
    if (busy) {
      busy.hidden = true;
    }
    lock(false);
  });
})();
