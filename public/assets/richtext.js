/*
 * Rich text fields.
 *
 * The textarea in the HTML is the real field: it carries the name, and without JavaScript
 * it is a perfectly good way to edit HTML. This takes the name off it, puts it on a hidden
 * input, and puts Trix above. The plain toggle then shows the textarea again — it is what
 * was underneath all along, not a second editor kept in step with the first.
 *
 * Trix is a convenience. The server's whitelist decides what is stored, whatever arrives.
 */
(function () {
  'use strict';

  // Attachments are Trix's one proprietary format: a figure carrying JSON in an
  // attribute. Refuse every file, by any route. The sanitiser strips the markup too, so a
  // later version of Trix cannot reintroduce them silently.
  document.addEventListener('trix-file-accept', function (event) {
    event.preventDefault();
  });

  function setup(textarea) {
    if (textarea.hasAttribute('data-richtext-ready') || !window.Trix) {
      return;
    }
    textarea.setAttribute('data-richtext-ready', '');

    var field = textarea.closest('[data-richtext]');
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = textarea.name;
    hidden.value = textarea.value;
    hidden.id = textarea.id + '-value';
    textarea.removeAttribute('name');
    textarea.insertAdjacentElement('afterend', hidden);

    var editor = document.createElement('trix-editor');
    editor.setAttribute('input', hidden.id);
    editor.setAttribute('toolbar', textarea.id + '-toolbar');
    editor.className = 'richtext-editor';
    hidden.insertAdjacentElement('afterend', editor);
    field.classList.add('richtext-rich');

    // Ctrl+Shift+V: paste with everything stripped to text. Pasting from a word processor
    // is a mess in every editor, and someone who knows the escape hatch is not stuck.
    var plainNext = false;
    editor.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && String(event.key).toLowerCase() === 'v') {
        plainNext = true;
      }
    });
    editor.addEventListener('paste', function (event) {
      if (!plainNext) {
        return;
      }
      plainNext = false;
      event.preventDefault();
      event.stopPropagation();
      var text = (event.clipboardData || window.clipboardData).getData('text/plain');
      if (text && editor.editor) {
        editor.editor.insertString(text);
      }
    }, true);

    var toggle = field.querySelector('[data-richtext-toggle]');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var plain = field.classList.toggle('richtext-plain');
        field.classList.toggle('richtext-rich', !plain);
        if (plain) {
          textarea.value = hidden.value;
          textarea.focus();
          toggle.textContent = toggle.getAttribute('data-label-rich');
        } else {
          if (editor.editor) {
            editor.editor.loadHTML(textarea.value);
          }
          toggle.textContent = toggle.getAttribute('data-label-plain');
        }
      });
    }

    // In plain mode the textarea has no name, so what it holds has to reach the field
    // that does.
    textarea.addEventListener('input', function () {
      if (field.classList.contains('richtext-plain')) {
        hidden.value = textarea.value;
      }
    });
  }

  function scan(root) {
    (root || document).querySelectorAll('textarea[data-richtext-source]').forEach(setup);
  }

  // Blocks added to the canvas arrive as HTML from the server and need the same
  // treatment; builder-blocks.js calls this after inserting one.
  window.boxletRichText = { scan: scan };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); });
  } else {
    scan(document);
  }
})();
