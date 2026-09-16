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
   * A second heading level, emitting the h3 we already store (PLAN.md D-015).
   *
   * Trix ships one heading, h1. The whitelist allows h2 and h3, and h3 had no way through:
   * Trix did not recognise the element, loaded it as bold text, and the first save stored
   * it as bold text — the author's subheading gone, permanently, without anyone touching
   * it. Registering the block attribute gives Trix both a way to write one and a way to
   * read one back.
   *
   * Set before any editor is created below, because Trix builds its parser from this.
   */
  if (window.Trix) {
    [['heading2', 'h3'], ['heading3', 'h4']].forEach(function (level) {
      if (!window.Trix.config.blockAttributes[level[0]]) {
        window.Trix.config.blockAttributes[level[0]] = {
          tagName: level[1],
          terminal: true,
          breakOnReturn: true,
          group: false,
        };
      }
    });
  }

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

  /*
   * The stored HTML in the shapes Trix owns.
   *
   * Trix's block element is <div>, and it offers one heading level, which it emits as
   * <h1>. Handed a <p> or an <h2> it does not recognise the block: it keeps the words but
   * marks the boundaries with <br>, and renders a heading as <strong>. Measured in a
   * browser over three open-and-save cycles, <h2>Heading</h2> became
   * <p><strong><br><br>Heading<br><br><br></strong></p> — the heading lost on the first
   * cycle and a break added at each end on every one after, for ever, in every field on
   * any page that was merely opened and saved.
   *
   * Sanitising on save maps div back to p and h1 back to h2, so this is that rule's exact
   * inverse and nothing about what is stored changes.
   *
   * h3 needs no mapping: the heading2 block attribute registered above gives Trix an h3
   * of its own, so it parses and re-emits one unchanged.
   */
  function forEditor(html) {
    var holder = document.createElement('div');
    holder.innerHTML = html;
    holder.querySelectorAll('p, h2').forEach(function (block) {
      var replacement = document.createElement(block.tagName === 'P' ? 'div' : 'h1');
      while (block.firstChild) {
        replacement.appendChild(block.firstChild);
      }
      block.replaceWith(replacement);
    });

    return holder.innerHTML;
  }

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
    hidden.value = forEditor(textarea.value);
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

    /*
     * Close the heading menu on a choice and on Escape.
     *
     * Trix opens the dialog for us and marks the active level, but it closes a dialog only
     * for its own dialog methods; an attribute button inside one leaves it open. Closing is
     * what Trix's hideDialog does — drop the active attribute and class — and focus goes
     * back to the button that opened it, so the keyboard does not end up nowhere.
     */
    var menu = field.querySelector('[data-trix-dialog="heading"]');
    var menuButton = field.querySelector('[data-trix-action="heading"]');
    if (menu && menuButton) {
      var closeMenu = function (refocus) {
        menu.removeAttribute('data-trix-active');
        menu.classList.remove('trix-active');
        if (refocus) {
          menuButton.focus();
        }
      };
      menu.addEventListener('click', function (event) {
        if (event.target.closest('[data-trix-attribute]')) {
          closeMenu(true);
        }
      });
      field.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.hasAttribute('data-trix-active')) {
          event.preventDefault();
          closeMenu(true);
        }
      });
    }

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
            editor.editor.loadHTML(forEditor(textarea.value));
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
