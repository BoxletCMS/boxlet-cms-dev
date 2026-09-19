/*
 * Changing the page: adding, duplicating, moving, removing, and re-drawing a block as it
 * is edited.
 *
 * A section in the canvas and its field group in the form are the same block seen twice,
 * so every change here moves both together and then renumbers. The block's HTML always
 * comes from the server; this file never builds one.
 */
(function () {
  'use strict';

  var api = window.boxletBuilder;
  if (!api) {
    return;
  }

  var keyCounter = 0;
  var timer = null;
  // The newest redraw sent per block key, so a slow answer cannot overwrite a fast one.
  var drawing = {};

  function post(params) {
    var body = new URLSearchParams();
    body.set('_csrf', api.form.querySelector('input[name="_csrf"]').value);
    Object.keys(params).forEach(function (key) {
      body.set(key, params[key]);
    });

    return fetch(api.panel.getAttribute('data-insert-url'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (response) {
      if (!response.ok) {
        throw new Error(String(response.status));
      }
      return response.text();
    }).then(function (html) {
      var holder = document.createElement('div');
      holder.innerHTML = html;
      return {
        canvas: holder.querySelector('template[data-block-canvas]'),
        fields: holder.querySelector('template[data-block-fields]'),
      };
    });
  }

  function place(index, section, group) {
    var doc = api.frame.contentDocument;
    var list = api.sections();
    var main = doc.querySelector('[data-bx-blocks]');
    if (index >= list.length) {
      main.appendChild(section);
    } else {
      main.insertBefore(section, list[index]);
    }
    var existing = api.groupNodes();
    if (index >= existing.length) {
      api.groups.appendChild(group);
    } else {
      api.groups.insertBefore(group, existing[index]);
    }
    api.renumber();
    // A block arrives as HTML from the server, so its rich text field is a plain textarea
    // and its picture field a plain select until these turn them into editors.
    if (window.boxletRichText) {
      window.boxletRichText.scan(group);
    }
    // The picker had no scan until the repeater needed one for a newly added item, so an
    // inserted block kept the bare select where every block already on the page showed a
    // picker. It still posted the right field — which is why nobody saw it — but it was
    // not the control the rest of the editor offers.
    if (window.boxletPicker) {
      window.boxletPicker.scan(group);
    }
    api.tellCanvas('refresh', {});
    api.show(index);
    api.tellCanvas('select', { index: index });
  }

  function insert(type, button) {
    if (!api.frame.contentDocument) {
      return;
    }
    var at = api.target === null ? api.sections().length : api.target;
    button.setAttribute('aria-busy', 'true');
    api.say(api.panel.getAttribute('data-text-inserting'));

    post({ type: type, index: String(at) })
      .then(function (parts) {
        if (!parts.canvas || !parts.fields) {
          throw new Error('malformed');
        }
        var key = 'n' + keyCounter++;
        var fragment = api.frame.contentDocument.importNode(parts.canvas.content, true);
        fragment.querySelector('section').setAttribute('data-bx-key', key);

        var group = parts.fields.content.firstElementChild.cloneNode(true);
        group.setAttribute('data-block-key', key);
        group.hidden = true;

        api.say('');
        api.target = null;
        place(at, fragment, group);
      })
      .catch(function () {
        api.say(api.panel.getAttribute('data-text-failed'));
      })
      .then(function () {
        button.removeAttribute('aria-busy');
      });
  }

  /**
   * The selected block's values, renamed from blocks[n][...] to block[...] so the server
   * can clean and re-render just this one.
   */
  function values(group) {
    var out = {};
    group.querySelectorAll('[name]').forEach(function (element) {
      if (element.type === 'checkbox' && !element.checked) {
        return;
      }
      out[element.name.replace(/^blocks\[[^\]]*\]/, 'block')] = element.value;
    });
    return out;
  }

  // Re-draw the selected block from what is currently typed, so the canvas shows what a
  // save would store rather than what was stored last.
  function redraw() {
    var index = api.selected();
    var group = index >= 0 ? api.groups.querySelector('[data-block-group="' + index + '"]') : null;
    var section = api.sections()[index];
    if (!group || !section) {
      return;
    }
    var type = group.querySelector('[name$="[type]"]');
    if (!type) {
      return;
    }

    // Pin the request to the block, not to where it currently sits. Moving or dragging a
    // block while an answer is in flight would otherwise drop that answer onto whichever
    // block had taken over the position, quietly replacing its content with another's.
    var key = section.getAttribute('data-bx-key');
    var ticket = (drawing[key] || 0) + 1;
    drawing[key] = ticket;

    post(Object.assign({ type: type.value, index: String(index) }, values(group)))
      .then(function (parts) {
        var doc = api.frame.contentDocument;
        var current = doc && doc.querySelector('[data-bx-key="' + key + '"]');
        if (drawing[key] !== ticket || !parts.canvas || !current) {
          return; // superseded by a later edit, or the block is gone
        }
        var fragment = doc.importNode(parts.canvas.content, true);
        var fresh = fragment.querySelector('section');
        fresh.setAttribute('data-bx-key', key);
        // A stale mark belongs to the block, not to what was typed into it: only "Mark as
        // up to date" clears it, so a redraw carries it over (D-043, step 3).
        if (current.hasAttribute('data-bx-stale')) {
          fresh.setAttribute('data-bx-stale', '');
        }
        if (current.classList.contains('bx-selected')) {
          fresh.classList.add('bx-selected');
        }
        current.replaceWith(fragment);
        api.tellCanvas('refresh', {});
      })
      .catch(function (error) {
        // The canvas keeps its last good drawing and Save still validates on the server,
        // but a redraw that fails silently is a canvas quietly telling the truth about
        // nothing, so say so where it can be seen.
        if (window.console) {
          window.console.error('boxlet: could not redraw the block', error);
        }
      });
  }

  /**
   * Turn a cloned field group's rich text back into the plain textarea the server sent,
   * so place() can set it up as a new editor.
   *
   * A clone of a live editor is not an editor: it carries data-richtext-ready, so setup
   * skips it, and an editor host still bound to the block it was copied from. What is on
   * screen matters too — in rich mode the text lives in the hidden input, while the
   * textarea still holds what the server rendered, so a copy that ignored it would show
   * the old text and quietly discard the author's edits.
   */
  function unsetRichText(group) {
    group.querySelectorAll('textarea[data-richtext-source]').forEach(function (textarea) {
      var field = textarea.closest('[data-richtext]');
      if (!field) {
        return;
      }
      var hidden = field.querySelector('input[type="hidden"][name]');
      if (hidden) {
        textarea.value = hidden.value;
        textarea.name = hidden.name;
        hidden.remove();
      }
      var editor = field.querySelector('[data-richtext-editor]');
      if (editor) {
        editor.remove();
      }
      field.classList.remove('richtext-rich', 'richtext-plain');
      textarea.removeAttribute('data-richtext-ready');
    });
  }

  /**
   * Turn a cloned field group's pickers back into the plain selects the server sent, so
   * place() can raise new ones on them.
   *
   * The same reasoning as unsetRichText above: a clone of a live picker is not a picker.
   * It carries data-picker-ready, so scan() skips it, and the button and panel beside it
   * are dead markup whose listeners stayed with the block it was copied from — a duplicate
   * whose picture field could be read but never changed.
   */
  function unsetPicker(group) {
    group.querySelectorAll('select[data-picker-ready]').forEach(function (select) {
      var picker = select.parentNode.querySelector('.media-picker');
      if (picker) {
        picker.remove();
      }
      select.hidden = false;
      select.removeAttribute('data-picker-ready');
    });
  }

  function act(action) {
    var index = api.selected();
    var group = api.groups.querySelector('[data-block-group="' + index + '"]');
    var section = api.sections()[index];
    if (!group || !section) {
      return;
    }

    if (action === 'remove') {
      section.remove();
      group.remove();
      api.renumber();
      api.tellCanvas('refresh', {});
      api.show(-1);
      api.tellCanvas('select', { index: -1 });
      return;
    }

    if (action === 'duplicate') {
      var key = 'n' + keyCounter++;
      var copy = section.cloneNode(true);
      copy.setAttribute('data-bx-key', key);
      copy.classList.remove('bx-selected');
      var groupCopy = group.cloneNode(true);
      groupCopy.setAttribute('data-block-key', key);
      groupCopy.hidden = true;
      unsetRichText(groupCopy);
      unsetPicker(groupCopy);
      // A duplicate is a new block. Keeping the id would make the save overwrite the
      // block it was copied from instead of adding one.
      var id = groupCopy.querySelector('[name$="[id]"]');
      if (id) {
        id.remove();
      }
      place(index + 1, copy, groupCopy);
      return;
    }

    var to = action === 'up' ? index - 1 : index + 1;
    var list = api.sections();
    if (to < 0 || to >= list.length) {
      return;
    }
    var groups = api.groupNodes();
    if (action === 'up') {
      list[to].before(section);
      groups[to].before(group);
    } else {
      list[to].after(section);
      groups[to].after(group);
    }
    api.renumber();
    api.tellCanvas('refresh', {});
    api.show(to);
    api.tellCanvas('select', { index: to });
  }

  api.act = act;

  api.form.addEventListener('click', function (event) {
    var add = event.target.closest && event.target.closest('[data-add-type]');
    if (add) {
      event.preventDefault();
      insert(add.getAttribute('data-add-type'), add);
      return;
    }
    var action = event.target.closest && event.target.closest('[data-block-action]');
    if (action) {
      event.preventDefault();
      act(action.getAttribute('data-block-action'));
    }
  });

  // On blur, and shortly after typing stops: often enough to feel live, rarely enough
  // not to render on every keystroke. 300ms is the figure in the 2h brief — at 500 it
  // read as lag rather than as the page following you.
  api.groups.addEventListener('change', redraw);
  api.groups.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(redraw, 300);
  });
})();
