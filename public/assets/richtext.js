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

  /*
   * An id Trix binds to must never encode the block's position.
   *
   * Trix resolves its input by id on every access, and a trix-toolbar finds its editors
   * with querySelectorAll('trix-editor[toolbar="<my id>"]'). Both editors renumber ids
   * matching block-<n>- when a block is added, moved or dragged, so an id in that shape
   * is silently re-pointed at whichever block now sits in that position — and the editor
   * then writes into another block's field. This counter belongs to the editor itself,
   * so moving a block cannot change what it is bound to. The textarea keeps its
   * block-<n>-field id, which is what label[for] follows.
   */
  var seq = 0;

  function setup(textarea) {
    if (textarea.hasAttribute('data-richtext-ready') || !window.Trix) {
      return;
    }
    textarea.setAttribute('data-richtext-ready', '');

    var field = textarea.closest('[data-richtext]');
    var uid = 'richtext-' + seq++;
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = textarea.name;
    hidden.value = textarea.value;
    hidden.id = uid + '-value';
    textarea.removeAttribute('name');
    textarea.insertAdjacentElement('afterend', hidden);

    var editor = document.createElement('trix-editor');
    editor.setAttribute('input', hidden.id);
    // The toolbar is found by structure, not by a name built from the textarea's id, so
    // this holds however the group is renumbered. Without one Trix makes its own.
    var toolbar = field.querySelector('trix-toolbar');
    if (toolbar) {
      toolbar.id = uid + '-toolbar';
      editor.setAttribute('toolbar', toolbar.id);
    }
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
