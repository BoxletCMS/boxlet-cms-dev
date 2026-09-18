/*
 * The admin bar on a phone, and the Design group's closing (PLAN.md D-038).
 *
 * Optional, like everything the admin's scripts do. Without it the navigation wraps under
 * the site's name and the Design group opens and closes as the <details> it is.
 */
(function () {
  'use strict';

  var bar = document.querySelector('[data-admin-bar]');
  if (!bar) {
    return;
  }

  var toggle = bar.querySelector('[data-admin-nav-toggle]');
  if (toggle) {
    bar.classList.add('nav-ready');
    toggle.hidden = false;
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      bar.classList.toggle('nav-open', open);
    });
  }

  // An open group closes on a click anywhere else, or on Escape, as a menu is expected to.
  var groups = bar.querySelectorAll('.admin-nav-group');
  document.addEventListener('click', function (event) {
    Array.prototype.forEach.call(groups, function (group) {
      if (group.open && !group.contains(event.target)) {
        group.open = false;
      }
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    Array.prototype.forEach.call(groups, function (group) {
      if (group.open) {
        group.open = false;
        group.querySelector('summary').focus();
      }
    });
  });
})();
