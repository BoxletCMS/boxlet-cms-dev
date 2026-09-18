/*
 * The site's navigation on a narrow screen, and its submenus (PLAN.md D-032, D-036).
 *
 * The only script a visitor's page loads, and all of it optional. Without it the
 * navigation wraps openly under the logo and every submenu is listed under its parent:
 * nothing is out of reach, and no button is drawn that would do nothing. With it, the
 * buttons the header renders hidden are shown, the navigation folds under one Menu button
 * on a phone, and each submenu opens from its own button — never from hover alone.
 */
(function () {
  'use strict';

  var header = document.querySelector('[data-site-header]');
  if (!header) {
    return;
  }
  var toggle = header.querySelector('[data-site-nav-toggle]');
  var more = header.querySelectorAll('[data-site-nav-more]');
  if (!toggle && more.length === 0) {
    return;
  }

  header.classList.add('nav-ready');

  if (toggle) {
    toggle.hidden = false;
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(open));
      header.classList.toggle('nav-open', open);
    });
  }

  function close(button) {
    button.setAttribute('aria-expanded', 'false');
    button.parentElement.classList.remove('is-open');
  }

  Array.prototype.forEach.call(more, function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var open = button.getAttribute('aria-expanded') !== 'true';
      // One submenu at a time: two dropdowns over each other hide each other.
      Array.prototype.forEach.call(more, close);
      button.setAttribute('aria-expanded', String(open));
      button.parentElement.classList.toggle('is-open', open);
    });
  });

  // Escape closes whatever is open and returns focus to the button that opened it.
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    Array.prototype.forEach.call(more, function (button) {
      if (button.getAttribute('aria-expanded') === 'true') {
        close(button);
        button.focus();
      }
    });
    if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
      toggle.setAttribute('aria-expanded', 'false');
      header.classList.remove('nav-open');
      toggle.focus();
    }
  });

  // A click anywhere else closes an open submenu.
  document.addEventListener('click', function (event) {
    if (!event.target.closest || !event.target.closest('.site-nav li')) {
      Array.prototype.forEach.call(more, close);
    }
  });
})();
