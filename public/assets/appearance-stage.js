/*
 * The picture's own toolbar on the Appearance screen (PLAN.md D-060): three widths, a zoom,
 * Compare, and what state the screen is in.
 *
 * THE ZOOM SCALES THE STAGE, NEVER THE FRAME'S WIDTH. A page judged at 1280 has to lay
 * itself out at 1280. Shrinking the frame would hand the page a narrower window, and it
 * would answer with its phone layout — a different question from the one being asked.
 *
 * THE TOOLS ARE HIDDEN UNTIL THIS FILE RUNS. Without JavaScript there is no way to scale a
 * frame, so a row of controls that did nothing would be worse than none: the frame is then
 * the column's width at full size, which is what it always was.
 *
 * Styles are set through the CSSOM, one property at a time. The admin's policy refuses a
 * style ATTRIBUTE — setAttribute('style', …) is blocked, measured in this very screen — and
 * allows this. No rule is invented here that the stylesheet does not already own.
 */
(function () {
  'use strict';

  var stage = document.querySelector('[data-stage]');
  var frame = document.querySelector('iframe[data-design-preview]');
  var tools = document.querySelector('[data-preview-tools]');
  var state = document.querySelector('[data-state]');
  var revert = document.querySelector('[data-revert]');
  if (!stage || !frame || !tools) {
    return;
  }
  tools.hidden = false;

  /** Under a half the text stops being text, so the zoom does not go there. */
  var SMALLEST = 0.5;
  var width = 1280;
  var zoom = 'fit';

  function scale() {
    if (zoom !== 'fit') {
      return Math.max(SMALLEST, parseFloat(zoom));
    }
    // Fit means the whole width is on screen, and never magnified past its own size.
    var room = stage.clientWidth;
    return Math.max(SMALLEST, Math.min(1, room / width));
  }

  function draw() {
    var factor = scale();
    var tall = stage.clientHeight;
    frame.style.width = width + 'px';
    // The frame is laid out at full size and then scaled, so its height is divided by the
    // factor to land back on the stage exactly: at half zoom you see twice as much page,
    // which is what zooming out means.
    frame.style.height = (tall > 0 ? tall / factor : 0) + 'px';
    frame.style.transform = 'scale(' + factor + ')';
    // Centred while it fits, and hard against the left edge when it does not — a frame
    // centred by a margin it cannot give back is a frame whose left side cannot be scrolled
    // to.
    var spare = stage.clientWidth - width * factor;
    frame.style.marginInlineStart = (spare > 0 ? spare / 2 : 0) + 'px';
  }

  tools.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-viewport]') : null;
    if (!button) {
      return;
    }
    width = parseInt(button.getAttribute('data-viewport'), 10) || 1280;
    tools.querySelectorAll('[data-viewport]').forEach(function (other) {
      other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
    });
    draw();
  });

  var chooser = tools.querySelector('[data-zoom]');
  if (chooser) {
    chooser.addEventListener('change', function () {
      zoom = chooser.value;
      draw();
    });
  }

  /*
   * COMPARE IS HELD, NOT TOGGLED. A comparison you have to keep holding is one you cannot
   * walk away from and mistake for the site. The published state is the preview address with
   * no query at all: with nothing submitted, it draws what is stored.
   */
  var compare = tools.querySelector('[data-compare]');
  if (compare) {
    var mine = null;
    var hold = function () {
      if (mine === null) {
        mine = frame.getAttribute('src');
        frame.setAttribute('src', frame.getAttribute('src').split('?')[0]);
        compare.setAttribute('aria-pressed', 'true');
      }
    };
    var release = function () {
      if (mine !== null) {
        frame.setAttribute('src', mine);
        mine = null;
        compare.setAttribute('aria-pressed', 'false');
      }
    };
    compare.addEventListener('pointerdown', hold);
    ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(function (name) {
      compare.addEventListener(name, release);
    });
    // The keyboard holds it too: Space and Enter repeat while down and stop on release.
    compare.addEventListener('keydown', function (event) {
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        hold();
      }
    });
    compare.addEventListener('keyup', release);
  }

  /*
   * What the screen is: the site as published, work not published yet, or something that
   * cannot be published until it is fixed. appearance.js knows which, because it is the one
   * asking the server; it says so through an event rather than reaching in here.
   */
  if (state) {
    document.addEventListener('appearance:state', function (event) {
      var name = event.detail && event.detail.state;
      var words = state.getAttribute('data-' + name);
      if (words) {
        state.textContent = words;
        state.classList.toggle('state-unpublished', name !== 'published');
        state.classList.toggle('state-problem', name === 'problem');
      }
      if (revert) {
        revert.hidden = name === 'published';
      }
    });
  }

  window.addEventListener('resize', draw);
  draw();
})();
