/*
 * HINTS ON DEMAND (PLAN.md D-078).
 *
 * A line of explanation under every control is what teaches this screen, and it is also
 * what fills a 312px column. The owner asked for the space back, so they are off until
 * asked for.
 *
 * OPTIONAL, LIKE EVERY SCRIPT HERE. Without it the hints show and the button is not there:
 * the state that explains itself is the safe one, and a toggle that cannot toggle is worse
 * than no toggle at all.
 *
 * REMEMBERED IN THIS BROWSER AND NOWHERE ELSE. It is how one person likes to work, not a
 * decision about the site, so it is not a setting and it never reaches the database. Every
 * read and write is wrapped, because storage throws in a private window rather than
 * returning nothing.
 *
 * Its own file rather than a corner of appearance-tabs.js: that one turns a row of links
 * into a tablist and knows nothing about what is inside a panel.
 */
(function () {
  'use strict';

  var KEY = 'boxlet.appearance.hints';
  var inspector = document.querySelector('[data-inspector]');
  var button = document.querySelector('[data-hints-toggle]');
  if (!inspector || !button) {
    return;
  }

  function remembered() {
    try {
      return window.localStorage.getItem(KEY);
    } catch (e) {
      return null;
    }
  }

  function remember(value) {
    try {
      window.localStorage.setItem(KEY, value);
    } catch (e) {
      // A browser that will not store it still gets the screen it asked for, for this visit.
    }
  }

  function draw(showing) {
    inspector.setAttribute('data-hints', showing ? 'on' : 'off');
    button.setAttribute('aria-pressed', showing ? 'true' : 'false');
    button.textContent = button.getAttribute(showing ? 'data-hide' : 'data-show');
  }

  // OFF is the default, which is what the owner asked for; anything stored wins over it.
  var showing = remembered() === 'on';
  button.hidden = false;
  draw(showing);

  button.addEventListener('click', function () {
    showing = !showing;
    remember(showing ? 'on' : 'off');
    draw(showing);
  });
})();
