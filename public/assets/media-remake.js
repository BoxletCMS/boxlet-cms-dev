/*
 * Making every picture's sizes again (PLAN.md D-048): while pictures are still owed a
 * remake, press Continue by itself, so the whole pass runs without the owner clicking
 * through it. Each press is one ordinary request that does what fits in its time; leaving
 * the page simply stops, and coming back carries on.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-remake-continue]');
  if (!form) {
    return;
  }
  var button = form.querySelector('button');
  window.setTimeout(function () {
    if (button) {
      button.disabled = true;
    }
    form.submit();
  }, 600);
})();
