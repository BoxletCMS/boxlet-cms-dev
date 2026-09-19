/*
 * The rail on a phone (PLAN.md D-052): folded behind the strip's menu button, and opened as
 * a drawer over the page.
 *
 * Optional, like everything the admin's scripts do. Without it the rail is a plain list
 * above the content, and every screen is still one link away.
 */
(function () {
  'use strict';

  var frame = document.querySelector('[data-admin-frame]');
  var toggle = frame && frame.querySelector('[data-admin-nav-toggle]');
  if (!toggle) {
    return;
  }

  var rail = frame.querySelector('[data-admin-rail]');
  frame.classList.add('nav-ready');
  toggle.hidden = false;

  function set(open) {
    toggle.setAttribute('aria-expanded', String(open));
    frame.classList.toggle('nav-open', open);
  }

  toggle.addEventListener('click', function (event) {
    event.stopPropagation();
    set(toggle.getAttribute('aria-expanded') !== 'true');
  });

  // Closed by a click outside it, or by Escape, as a drawer is expected to be.
  document.addEventListener('click', function (event) {
    if (frame.classList.contains('nav-open') && rail && !rail.contains(event.target)) {
      set(false);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && frame.classList.contains('nav-open')) {
      set(false);
      toggle.focus();
    }
  });
})();
