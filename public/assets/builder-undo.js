/*
 * UNDO FOR THE PAGE EDITOR (PLAN.md D-079).
 *
 * Removing a block dropped its field group from the form, and the only way back was to
 * leave without saving — which took every other change on the page with it. A Columns
 * block with twelve filled items was one click from gone. An editor whose destructive
 * action cannot be taken back is not solid, however good the rest of it is.
 *
 * ONE PATH FOR EVERY STRUCTURAL CHANGE. Insert, remove, duplicate, move and drag all go
 * through api.commit(), which pushes the state before the change. An undo that covered
 * removal but not a move would be worse than none, because nobody could predict it.
 *
 * ⌘Z BELONGS TO A FIELD THE AUTHOR HAS TYPED IN. TipTap has its own undo and so does every
 * text input, and taking the shortcut off them would make typing unpredictable. But focus
 * being in a field does not mean the author put it there: api.show() focuses the first
 * field of whatever block is selected, so after every insert, duplicate and move the
 * cursor is already sitting in one. Ignoring the shortcut on focus alone would therefore
 * have meant it never worked for those three — measured: duplicate, ⌘Z, nothing happened.
 * A field that has not been typed in has an empty undo stack of its own, so it loses
 * nothing by letting the page have the keystroke; from the first keystroke in it, it keeps
 * it. Typing is tracked from the `input` event and forgotten on every focus change.
 *
 * THE STATE IS ALREADY IN ONE PLACE, which is what makes this small: the field groups in
 * the form and the sections in the canvas are the same page seen twice. A snapshot is the
 * inner HTML of both, and a restore puts both back and re-raises the editors inside.
 *
 * NO REDO. Twenty steps back covers the mistake this exists for; a redo stack is a second
 * thing to reason about for a case nobody has asked for.
 *
 * Its own file rather than a corner of builder.js: that one is the shell — selection, the
 * panel's modes, the device width — and this is the page's history.
 */
(function () {
  'use strict';

  var api = window.boxletBuilder;
  if (!api) {
    return;
  }

  var DEPTH = 20;
  var SHOWN = 6000;

  var history = [];
  /* The control that is there whether or not anything has happened (D-092). The strip below
     only appears after a removal, which meant somebody who had removed nothing never learnt
     that undo existed — reported by the owner, who asked whether it was keyboard-only. */
  var button = document.querySelector('[data-undo-button]');
  var strip = document.querySelector('[data-undo-strip]');
  var stripText = strip && strip.querySelector('[data-undo-text]');
  var hiding = null;
  /* A drag is reported when it ENDS, by which time the canvas has already moved. The
     canvas says when it starts too, and that snapshot is held here until the move is
     known to have changed something — a drag that ends where it began must not leave an
     undo step that appears to do nothing. */
  var beforeDrag = null;
  /* Whether the field that has focus has been typed in since it got it — which is what
     tells a cursor the author placed from one api.show() left behind. */
  var typed = false;

  /* Disabled rather than hidden when the stack is empty: a control that disappears teaches
     nobody that it is there, and the point of this one is that it can be found. */
  function offer() {
    if (button) {
      button.disabled = history.length === 0;
    }
  }

  function main() {
    var doc = api.frame.contentDocument;
    return doc ? doc.querySelector('[data-bx-blocks]') : null;
  }

  /**
   * Write what the author has typed into the markup, so that reading innerHTML reads it.
   *
   * A snapshot is HTML, and HTML carries a field's value in an ATTRIBUTE while typing
   * changes a PROPERTY. Without this the snapshot holds what the server rendered, and an
   * undo after ten minutes of writing would restore the removed block and throw the ten
   * minutes away — the precise loss this file exists to prevent. Rich text is covered by
   * the same pass: richtext.js keeps its hidden input current on every keystroke, and the
   * hidden input is an input like any other.
   */
  function sync(root) {
    root.querySelectorAll('input, textarea, select').forEach(function (field) {
      if (field.tagName === 'TEXTAREA') {
        field.textContent = field.value;
      } else if (field.tagName === 'SELECT') {
        Array.prototype.forEach.call(field.options, function (option) {
          option.toggleAttribute('selected', option.selected);
        });
      } else if (field.type === 'checkbox' || field.type === 'radio') {
        field.toggleAttribute('checked', field.checked);
      } else {
        field.setAttribute('value', field.value);
      }
    });
  }

  function snapshot() {
    var page = main();
    sync(api.groups);

    return page === null ? null : {
      groups: api.groups.innerHTML,
      canvas: page.innerHTML,
      selected: api.selected(),
    };
  }

  /**
   * The page's arrangement as the FORM holds it, said the same way the canvas says it, so
   * the two can be compared to decide whether a drag moved anything (PLAN.md D-103).
   */
  function order(kind) {
    if (kind === 'bands') {
      var seen = [];
      api.groupNodes().forEach(function (group) {
        var key = group.getAttribute('data-section-key');
        if (key && seen.indexOf(key) < 0) {
          seen.push(key);
        }
      });

      return seen.join('|');
    }

    return api.groupNodes().map(function (group) {
      return group.getAttribute('data-block-key') + '@'
        + group.getAttribute('data-section-key') + ':'
        + ((group.querySelector('[data-block-column]') || {}).value || '0');
    }).join('|');
  }

  function show(message) {
    if (!strip || !stripText || !message) {
      return;
    }
    stripText.textContent = message;
    strip.hidden = false;
    window.clearTimeout(hiding);
    hiding = window.setTimeout(hide, SHOWN);
  }

  function hide() {
    window.clearTimeout(hiding);
    if (strip) {
      strip.hidden = true;
    }
  }

  /**
   * Record the page as it is, before the change about to be made.
   *
   * @param message shown in the strip with a way back, for a change that destroys work.
   *                A move or an insert needs none: what happened is on the screen.
   */
  api.commit = function (message) {
    var state = snapshot();
    if (state === null) {
      return;
    }
    history.push(state);
    if (history.length > DEPTH) {
      history.shift();
    }
    if (message) {
      show(message);
    } else {
      hide();
    }
    offer();
  };

  api.undo = function () {
    var state = history.pop();
    var page = main();
    if (!state || page === null) {
      return;
    }
    hide();

    api.groups.innerHTML = state.groups;
    page.innerHTML = state.canvas;

    /* A restored group's rich text and picker are dead markup for exactly the reason a
       clone's are: the editor host and the picker panel are still bound to the elements
       they were raised on, which this has just replaced. So they are taken back down to
       the plain textarea and select the server sent, and raised again. */
    api.unsetLive(api.groups);
    if (window.boxletRichText) {
      window.boxletRichText.scan(api.groups);
    }
    if (window.boxletPicker) {
      window.boxletPicker.scan(api.groups);
    }

    api.renumber();
    // An answer already in flight was asked for by a page that no longer exists.
    api.forget();
    api.tellCanvas('refresh', {});
    api.show(state.selected);
    api.tellCanvas('select', { index: state.selected });
    offer();
  };

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.source !== 'boxlet-canvas') {
      return;
    }
    if (event.data.type === 'drag-start') {
      beforeDrag = snapshot();
    } else if (event.data.type === 'placed' || event.data.type === 'bands') {
      /* The form still holds the old arrangement at this moment, so comparing the two says
         whether the drag moved anything at all. PLACES and not an order since D-103: a
         block that crossed into another column can leave the reading order untouched, and
         comparing orders called that "nothing happened" — an un-undoable move. */
      var now = event.data.type === 'bands'
        ? event.data.keys.join('|')
        : event.data.at.map(function (where) {
          return where.key + '@' + where.section + ':' + where.column;
        }).join('|');
      var moved = beforeDrag !== null && now !== order(event.data.type);
      if (moved) {
        history.push(beforeDrag);
        if (history.length > DEPTH) {
          history.shift();
        }
        offer();
      }
      beforeDrag = null;
    } else if (event.data.type === 'undo') {
      api.undo();
    }
  });

  document.addEventListener('focusin', function () {
    typed = false;
  });
  document.addEventListener('input', function () {
    typed = true;
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      api.show(-1);
      api.tellCanvas('select', { index: -1 });

      return;
    }
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'z') {
      return;
    }
    // A field the author has written in keeps its own undo (see the head of this file).
    var inField = event.target.closest && event.target.closest('input, textarea, select, [contenteditable="true"]');
    if (inField && typed) {
      return;
    }
    event.preventDefault();
    api.undo();
  });

  if (button) {
    button.addEventListener('click', function () {
      api.undo();
    });
  }

  if (strip) {
    strip.addEventListener('click', function (event) {
      if (event.target.closest('[data-undo-now]')) {
        event.preventDefault();
        api.undo();
      }
    });
  }
})();
