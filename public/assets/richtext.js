/*
 * Rich text fields, on TipTap (PLAN.md D-017 — spike).
 *
 * The contract is the one Trix had, because it is ours and not the editor's:
 *
 *   - The textarea in the HTML is the real field. It carries the name, and without
 *     JavaScript it is a perfectly good way to edit HTML. This takes the name off it, puts
 *     it on a hidden input, and puts the editor above.
 *   - The plain toggle shows the textarea again. It is what was underneath all along, not
 *     a second editor kept in step with the first.
 *   - Nothing is bound by an id that encodes a block's position. Both editors renumber
 *     block-<n>- ids when a block moves, and an editor bound to one would start writing
 *     into another block's field (the 2a bug).
 *
 * The editor is a convenience. The server's whitelist decides what is stored, whatever
 * arrives, and the schema below is that whitelist so the editor cannot even offer markup
 * the server would throw away.
 */
(function () {
  'use strict';

  var seq = 0;

  /*
   * The schema is exactly the storage whitelist (SPEC §5.3).
   *
   * Every option here was settled by measuring the editor's output, not by reading about
   * it:
   *   trailingNode: false — TipTap otherwise appends an empty paragraph to any content
   *     that does not end in one, so <ul>…</ul> came back as <ul>…</ul><p></p> and a field
   *     changed on its first save. The cost is that a list or quote at the very end has no
   *     paragraph after it to click into; Enter twice still leaves the list.
   *   HTMLAttributes on the link — TipTap adds target and rel by default, which the
   *     whitelist does not allow. Nulling them there works; setting target/rel at the top
   *     level does not.
   */
  function extensions(tiptap) {
    return [
      tiptap.StarterKit.configure({
        code: false,
        codeBlock: false,
        strike: false,
        underline: false,
        horizontalRule: false,
        link: false,
        trailingNode: false,
        heading: { levels: [2, 3, 4] },
      }),
      tiptap.Link.configure({
        openOnClick: false,
        autolink: false,
        HTMLAttributes: { target: null, rel: null },
      }),
    ];
  }

  /** What each toolbar button does, and when it shows as active. */
  var COMMANDS = {
    bold: { run: function (c) { return c.toggleBold(); }, active: 'bold' },
    italic: { run: function (c) { return c.toggleItalic(); }, active: 'italic' },
    h2: { run: function (c) { return c.toggleHeading({ level: 2 }); }, active: ['heading', { level: 2 }] },
    h3: { run: function (c) { return c.toggleHeading({ level: 3 }); }, active: ['heading', { level: 3 }] },
    h4: { run: function (c) { return c.toggleHeading({ level: 4 }); }, active: ['heading', { level: 4 }] },
    quote: { run: function (c) { return c.toggleBlockquote(); }, active: 'blockquote' },
    bullet: { run: function (c) { return c.toggleBulletList(); }, active: 'bulletList' },
    ordered: { run: function (c) { return c.toggleOrderedList(); }, active: 'orderedList' },
    undo: { run: function (c) { return c.undo(); } },
    redo: { run: function (c) { return c.redo(); } },
  };

  function setup(textarea) {
    var tiptap = window.BoxletTipTap;
    if (textarea.hasAttribute('data-richtext-ready') || !tiptap) {
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

    var host = document.createElement('div');
    host.className = 'richtext-editor';
    host.setAttribute('data-richtext-editor', '');
    hidden.insertAdjacentElement('afterend', host);

    var editor = new tiptap.Editor({
      element: host,
      extensions: extensions(tiptap),
      injectCSS: false,
      content: textarea.value,
      onUpdate: function () {
        hidden.value = editor.getHTML();
      },
    });

    var toolbar = field.querySelector('[data-richtext-toolbar]');
    var link = field.querySelector('[data-richtext-link]');
    field.classList.add('richtext-rich');

    /*
     * Which buttons are lit. ProseMirror keeps the selection in the editor's own state, so
     * this is asked of the document rather than of the browser's selection.
     */
    function refresh() {
      if (!toolbar) {
        return;
      }
      Object.keys(COMMANDS).forEach(function (name) {
        var button = toolbar.querySelector('[data-rt="' + name + '"]');
        var active = COMMANDS[name].active;
        if (!button || !active) {
          return;
        }
        var on = Array.isArray(active) ? editor.isActive(active[0], active[1]) : editor.isActive(active);
        button.setAttribute('aria-pressed', String(on));
      });
      var linkButton = toolbar.querySelector('[data-rt="link"]');
      if (linkButton) {
        linkButton.setAttribute('aria-pressed', String(editor.isActive('link')));
      }
    }
    editor.on('selectionUpdate', refresh);
    editor.on('transaction', refresh);

    if (toolbar) {
      toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('[data-rt]');
        if (!button) {
          return;
        }
        event.preventDefault();
        var name = button.getAttribute('data-rt');
        if (name === 'link') {
          openLink();
          return;
        }
        if (COMMANDS[name]) {
          COMMANDS[name].run(editor.chain().focus()).run();
          refresh();
        }
      });
    }

    /*
     * Editing a link without stealing the selection.
     *
     * The selection lives in the editor's state, not in the browser, so moving focus to a
     * text input does not disturb it: ProseMirror simply stops rendering the cursor. When
     * the address is applied, extendMarkRange('link') widens the stored selection to the
     * whole link so editing one applies to all of it, and .focus() hands the caret back.
     * Nothing has to be saved and restored by hand, which is what went wrong under Trix.
     */
    function openLink() {
      if (!link) {
        return;
      }
      var input = link.querySelector('input');
      link.hidden = false;
      input.value = editor.getAttributes('link').href || '';
      input.focus();
      input.select();
    }

    function closeLink(refocus) {
      if (!link) {
        return;
      }
      link.hidden = true;
      if (refocus) {
        editor.chain().focus().run();
      }
    }

    if (link) {
      link.addEventListener('click', function (event) {
        var action = event.target.closest('[data-rt-link]');
        if (!action) {
          return;
        }
        event.preventDefault();
        var href = link.querySelector('input').value.trim();
        var chain = editor.chain().focus().extendMarkRange('link');
        if (action.getAttribute('data-rt-link') === 'apply' && href !== '') {
          chain.setLink({ href: href }).run();
        } else {
          chain.unsetLink().run();
        }
        closeLink(true);
        refresh();
      });
      link.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeLink(true);
        } else if (event.key === 'Enter') {
          event.preventDefault();
          link.querySelector('[data-rt-link="apply"]').click();
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
          editor.commands.setContent(textarea.value, { emitUpdate: false });
          hidden.value = editor.getHTML();
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

    refresh();
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
