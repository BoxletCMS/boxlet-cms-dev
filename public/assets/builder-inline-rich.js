/*
 * RICH TEXT ON THE PAGE (PLAN.md D-178, README 4.4): the inspector's editor — the same TipTap,
 * the same schema, the same commands (richtext.js) — mounted where the words are shown, with a
 * small toolbar over them offering only what the field allows (its `allow`, the list the
 * sanitiser keeps). TipTap is the page's, loaded once with the builder; it is started on the
 * element the first time it is pressed, and put away when the writing leaves it, when the band
 * is drawn again from the document as a visitor will get it.
 *
 * Measured before it was built: an editor made in the builder's window edits an element of the
 * canvas's document, typing and bold included (D-178).
 */
(function () {
  'use strict';

  var pb = window.pb;
  var rt = window.boxletRichText;
  if (!pb || !pb.inline || !pb.overlay || !window.BoxletTipTap || !rt || !rt.extensions) {
    return;
  }
  var o = pb.overlay;
  // Each button: the feature that allows it, the command richtext.js runs, an icon, its words.
  var BUTTONS = [
    ['bold', 'bold', 'bold', 'rt.bold'],
    ['italic', 'italic', 'italic', 'rt.italic'],
    ['heading', 'h2', 'heading-2', 'rt.heading_2'],
    ['heading', 'h3', 'heading-3', 'rt.heading_3'],
    ['list', 'bullet', 'list', 'rt.bullets'],
    ['list', 'ordered', 'list-ordered', 'rt.numbers'],
    ['quote', 'quote', 'text-quote', 'rt.quote'],
    ['link', 'link', 'link', 'rt.link'],
  ];
  var current = null;

  function bar() {
    var tools = o.el('div', 'bx-rich-tools');
    tools.setAttribute('role', 'toolbar');
    tools.setAttribute('aria-label', pb.t('rt.toolbar'));
    var allow = current.field.allow || ['bold', 'italic', 'link', 'heading', 'list', 'quote'];
    BUTTONS.forEach(function (b) {
      if (allow.indexOf(b[0]) < 0) {
        return;
      }
      var button = o.button('rt-' + b[1], b[2], pb.t(b[3]));
      button.setAttribute('data-rt', b[1]);
      button.setAttribute('aria-pressed', 'false');
      tools.appendChild(button);
    });
    // Pressing a tool keeps the writing where it is: no focus is taken from the editor.
    tools.addEventListener('mousedown', function (event) { event.preventDefault(); });
    tools.addEventListener('click', function (event) {
      var button = event.target.closest('[data-rt]');
      if (button) { run(button.getAttribute('data-rt')); }
    });
    return tools;
  }

  function place() {
    if (!current) {
      return;
    }
    if (!current.tools.isConnected) {
      o.layer().appendChild(current.tools);
    }
    var b = o.box(current.el);
    o.at(current.tools, { top: b.top - o.px(6), left: b.left });
    refresh();
    if (o.apart) { o.apart(); }
  }

  function refresh() {
    if (!current) {
      return;
    }
    var editor = current.editor;
    Array.prototype.forEach.call(current.tools.querySelectorAll('[data-rt]'), function (button) {
      var name = button.getAttribute('data-rt');
      var active = name === 'link' ? 'link' : (rt.commands[name] || {}).active;
      if (!active) { return; }
      var on = Array.isArray(active) ? editor.isActive(active[0], active[1]) : editor.isActive(active);
      button.setAttribute('aria-pressed', String(on));
    });
  }

  function run(name) {
    var editor = current.editor;
    if (name === 'link') {
      current.holding = true;
      var href = editor.getAttributes('link').href || '';
      // The words the link is on: the selection, or the whole link the caret is in.
      if (href) { editor.chain().extendMarkRange('link').run(); }
      var from = editor.state.selection.from;
      var to = editor.state.selection.to;
      var said = editor.state.doc.textBetween(from, to, ' ');
      pb.inline.picker(current.el, href, function () {}, function (url, words) {
        current.holding = false;
        var chain = editor.chain().focus().setTextSelection({ from: from, to: to });
        if (!url) {
          chain.extendMarkRange('link').unsetLink().run();
        } else if (words && words !== said) {
          // New words, or none were selected: the words are put in as the link.
          chain.insertContent({ type: 'text', text: words, marks: [{ type: 'link', attrs: { href: url } }] }).run();
        } else {
          chain.setLink({ href: url }).run();
        }
      }, href ? function () {
        current.holding = false;
        editor.chain().focus().setTextSelection({ from: from, to: to }).extendMarkRange('link').unsetLink().run();
      } : null, said);
      return;
    }
    rt.commands[name].run(editor.chain().focus()).run();
  }

  function finish() {
    if (!current || current.holding) {
      return;
    }
    var done = current;
    current = null;
    done.tools.remove();
    done.editor.destroy();
    // Drawn again from the document, as a visitor will have it, and the server's word on it.
    pb.canvas.draw(done.section);
    pb.inline.check(done.key);
  }

  /** The editor's position after `n` characters of its words, as the page counted them. */
  function textPos(editor, n) {
    var found = null;
    editor.state.doc.descendants(function (node, pos) {
      if (found !== null || !node.isText) {
        return found === null;
      }
      if (n <= node.text.length) {
        found = pos + n;
      } else {
        n -= node.text.length;
      }
      return false;
    });
    return found;
  }

  pb.inline.rich = function (el, block, path, field, point) {
    if (current && current.el === el) {
      return;
    }
    finish();
    var html = pb.inline.get(block, path) || '';
    el.innerHTML = '';
    var editor = new window.BoxletTipTap.Editor({
      element: el,
      extensions: rt.extensions(window.BoxletTipTap),
      injectCSS: false,
      content: html,
      onUpdate: function () {
        var now = pb.block(block.key);
        if (now) { pb.inline.write(now, path, editor.getHTML()); }
      },
    });
    current = { editor: editor, el: el, key: block.key, section: block.section, path: path, field: field, holding: false, tools: null };
    current.tools = bar();
    place();
    editor.on('selectionUpdate', refresh);
    editor.on('transaction', refresh);
    editor.on('blur', function () {
      // After the press that blurred it has landed: a toolbar press keeps it, a link panel holds it.
      setTimeout(function () { if (current && current.editor === editor && !editor.isFocused) { finish(); } }, 0);
    });
    // The caret where the press was, as in any text; at the end when that is not a place in it.
    // Asked once the editor is laid out, and focused without scrolling, which moved the page
    // from under the point pressed: every first press put the caret at the end (D-182).
    editor.commands.focus('end', { scrollIntoView: false });
    var counted = point && point.offset !== null && point.offset !== undefined ? textPos(editor, point.offset) : null;
    if (counted !== null) {
      editor.commands.setTextSelection(counted);
    } else if (point) {
      el.ownerDocument.defaultView.requestAnimationFrame(function () {
        var at = editor.isDestroyed ? null : editor.view.posAtCoords({ left: point.x, top: point.y });
        if (at) { editor.commands.setTextSelection(at.pos); }
      });
    }
  };

  // The layer drawn again keeps the toolbar over the words being written.
  pb.on('painted', place);
  // Undo, Discard or a restore replace the document: whatever was being written goes with it.
  pb.on('replace', function () {
    if (current) {
      current.holding = false;
      finish();
    }
  });
})();
