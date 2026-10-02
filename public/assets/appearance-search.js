/*
 * SEARCH ON APPEARANCE (PLAN.md D-181, README 5.5): what is typed is looked for in each
 * control's name, its section's and its group's, the words of its choices, and a few more it
 * is known by (`appearance.keywords.*`: "dark", "sticky", "corners"). The results ARE the
 * controls: every section stands open at once, each with only its controls that match, under
 * its own title, and a control is used where it is found. Pressing a section's title opens
 * that section and ends the search; emptying the box does too.
 */
(function () {
  'use strict';

  var inspector = document.querySelector('[data-inspector]');
  var box = document.querySelector('[data-search]');
  if (!inspector || !box) {
    return;
  }
  var input = box.querySelector('input');
  var none = box.querySelector('[data-search-none]');
  var keywords = {};
  try {
    keywords = JSON.parse(input.getAttribute('data-keywords') || '{}');
  } catch (e) {
    keywords = {};
  }
  var sections = [].slice.call(inspector.querySelectorAll('.inspector-section[data-view]'));
  var home = inspector.querySelector('[data-view="home"]');
  var searching = false;
  box.hidden = false;

  function words(el) {
    return (el ? el.textContent : '').replace(/\s+/g, ' ').toLowerCase();
  }
  /** What a row is found by: its label and choices, not its readout, and its keywords. */
  function said(row) {
    var copy = row.cloneNode(true);
    [].forEach.call(copy.querySelectorAll('.readout, .control-reset, .visually-hidden'), function (n) { n.remove(); });
    return words(copy) + ' ' + (keywords[row.getAttribute('data-control')] || '').toLowerCase();
  }

  function search(query) {
    var q = query.trim().toLowerCase();
    if (q === '') {
      stop();
      return;
    }
    searching = true;
    inspector.classList.add('is-searching');
    if (home) { home.hidden = true; }
    var found = 0;
    sections.forEach(function (section) {
      var title = words(section.querySelector('.section-title'));
      var inSection = 0;
      [].forEach.call(section.querySelectorAll('.control-group'), function (group) {
        var heading = words(group.querySelector('.control-group-title'));
        var inGroup = 0;
        [].forEach.call(group.querySelectorAll('.control-row'), function (row) {
          var hit = (said(row) + ' ' + heading + ' ' + title).indexOf(q) >= 0;
          row.toggleAttribute('data-search-miss', !hit);
          inGroup += hit ? 1 : 0;
        });
        group.toggleAttribute('data-search-miss', inGroup === 0);
        if (inGroup > 0) { group.open = true; }
        inSection += inGroup;
      });
      section.hidden = inSection === 0;
      found += inSection;
    });
    none.hidden = found > 0;
  }

  function stop() {
    if (!searching) {
      return;
    }
    searching = false;
    inspector.classList.remove('is-searching');
    [].forEach.call(inspector.querySelectorAll('[data-search-miss]'), function (n) { n.removeAttribute('data-search-miss'); });
    none.hidden = true;
    if (window.boxletAppearanceViews) { window.boxletAppearanceViews.restore(); }
  }

  // The box is not one of the design's fields: what is typed in it is nobody's change.
  ['input', 'change'].forEach(function (type) {
    input.addEventListener(type, function (event) {
      event.stopPropagation();
      if (type === 'input') { search(input.value); }
    });
  });
  input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); }
    if (event.key === 'Escape') { input.value = ''; stop(); }
  });
  // A section's title, while searching, opens it.
  inspector.addEventListener('click', function (event) {
    var title = searching && event.target.closest ? event.target.closest('.section-title') : null;
    var section = title ? title.closest('[data-view]') : null;
    if (section) {
      input.value = '';
      stop();
      if (window.boxletAppearanceViews) { window.boxletAppearanceViews.open(section.getAttribute('data-view')); }
    }
  });
  // Opening a section another way ends the search too.
  document.addEventListener('appearance:section', function () {
    if (searching && input.value.trim() !== '') {
      return;
    }
    stop();
  });
})();
