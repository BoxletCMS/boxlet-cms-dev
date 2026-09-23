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
  /* And an era, bumped whenever the page is replaced wholesale (undo, D-079). The ticket
     above pins an answer to its block; this pins it to the page that asked for it, which
     a per-key ticket cannot do — put the page back and the next request for that key
     would take the number the answer in flight already holds. */
  var era = 0;

  function post(params, url) {
    var body = new URLSearchParams();
    body.set('_csrf', api.form.querySelector('input[name="_csrf"]').value);
    Object.keys(params).forEach(function (key) {
      body.set(key, params[key]);
    });

    return fetch(url || api.panel.getAttribute('data-insert-url'), {
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
        // The band this block arrives in, when it arrives as one of its own (D-099).
        band: holder.querySelector('template[data-section-fields]'),
        // A whole band redrawn, from the other endpoint.
        drawn: holder.querySelector('template[data-band-canvas]'),
      };
    });
  }

  /**
   * A BLOCK PUT INTO A COLUMN OF A BAND THAT ALREADY EXISTS (PLAN.md D-099).
   *
   * Not place(): that inserts a band at a position on the page, with the canvas element and
   * the field group at the same index in two flat lists. Here the canvas side goes inside a
   * column and the form side goes after the last group already standing in that band — two
   * different questions, which is why this is its own function rather than a branch inside
   * that one.
   *
   * The group is named with the BAND'S OWN key, not a fresh one: nameGroup() mints a new
   * section key when it is not told which band to join, and a block put into an existing
   * band that minted its own would be saved into a band of its own — the arrangement the
   * author just made, undone by the act of filling it.
   */
  function placeInColumn(into, node, group) {
    var doc = api.frame.contentDocument;
    var band = doc.querySelector('[data-bx-section="' + into.section + '"]');
    var column = band && band.querySelectorAll('.section-column')[into.column];
    if (!column) {
      throw new Error('no column ' + into.column + ' in section ' + into.section);
    }
    column.appendChild(node);

    var key = group.getAttribute('data-block-key');
    if (key && window.boxletBlocks) {
      window.boxletBlocks.name(group, key, into.section);
    }
    // And which column, which nameGroup has no opinion about: it names things, it does not
    // place them.
    group.querySelectorAll('[data-block-column]').forEach(function (input) {
      input.value = String(into.column);
    });

    // AFTER THE LAST GROUP ALREADY IN THIS BAND, so the form's order goes on being the
    // page's order: sections in order, and within one, its blocks.
    var groups = api.groupNodes();
    var last = null;
    groups.forEach(function (candidate) {
      if (candidate.getAttribute('data-section-key') === into.section) {
        last = candidate;
      }
    });
    group.setAttribute('data-section-key', into.section);
    if (last && last.nextSibling) {
      api.groups.insertBefore(group, last.nextSibling);
    } else {
      api.groups.appendChild(group);
    }

    api.renumber();
    if (window.boxletRichText) {
      window.boxletRichText.scan(group);
    }
    if (window.boxletPicker) {
      window.boxletPicker.scan(group);
    }
    api.tellCanvas('refresh', {});
    var index = Number(group.getAttribute('data-block-group'));
    api.show(index);
    /* AND THE CONTENT TAB, because a block was just added and what you do with a new block
       is write in it. Adding one into an empty column left the panel on the Section tab,
       where a block's fields are display: none — so the block existed, was selected, and
       could not be typed into; its own field could not even be focused. */
    api.showTab('content');
    api.selectOnCanvas(index);
  }

  /**
   * A BAND ADDED BETWEEN BANDS (PLAN.md D-101).
   *
   * It arrives EMPTY, with one column, and is selected with the Section tab open — so the
   * next thing on the screen is its arrangement, which is the next thing a person wants.
   * Blocks go into it through the + in its column, which is the order the owner chose
   * (D-099): the shape first, then what stands in it.
   *
   * The server draws both halves, as it does for a block: a band nobody can see is not a
   * band, and a band with no fields is one nothing can be done to.
   */
  function addBand(at) {
    var doc = api.frame.contentDocument;
    if (!doc) {
      return;
    }
    api.say(api.panel.getAttribute('data-text-inserting'));
    post({ 'section[layout]': 'one', 'section[stack]': 'stack' }, api.panel.getAttribute('data-band-url'))
      .then(function (parts) {
        if (!parts.drawn || !parts.band) {
          throw new Error('malformed');
        }
        var key = window.boxletBlocks ? window.boxletBlocks.mintSection() : 'm0';
        var band = parts.band.content.firstElementChild.cloneNode(true);
        band.setAttribute('data-section-group', key);
        if (window.boxletBlocks) {
          window.boxletBlocks.name(band, 'n0', key);
        }
        api.sectionGroups().appendChild(band);

        var fresh = doc.importNode(parts.drawn.content, true).firstElementChild;
        fresh.setAttribute('data-bx-section', key);
        var bands = api.bands();
        var main = doc.querySelector('[data-bx-blocks]');
        api.commit();
        if (at >= bands.length) {
          main.appendChild(fresh);
        } else {
          main.insertBefore(fresh, bands[at]);
        }
        api.say('');
        api.target = null;
        // The page changed, so the outline is redrawn and the form is dirty — the same
        // renumber() every other structural change ends with.
        api.renumber();
        api.tellCanvas('refresh', {});
        /* NOTHING IS SELECTED IN THE PANEL, because a band holds no block yet and the panel
           shows blocks. What is shown is the band's own fields, which is what there is to
           do with it. */
        api.selectBand(key);
      })
      .catch(function (error) {
        api.say(api.panel.getAttribute('data-text-failed'));
        if (window.console) {
          window.console.error('boxlet: could not add the band', error);
        }
      });
  }

  api.addBand = addBand;

  function insert(type, button) {
    if (!api.frame.contentDocument) {
      return;
    }
    /* WHERE IT LANDS IS CHOSEN FIRST (PLAN.md D-103, and the design artifact): which band,
       which of its columns. A card pressed with nowhere aimed at used to add the block as a
       band of its own at the END of the page — a guess, and the one place nobody meant. Now
       it says where to press, which is what the library's own line has said since. */
    var into = api.target !== null && typeof api.target === 'object' ? api.target : null;
    if (into === null) {
      api.say(api.panel.getAttribute('data-text-aim') || '');

      return;
    }
    button.setAttribute('aria-busy', 'true');
    api.say(api.panel.getAttribute('data-text-inserting'));

    post({ type: type, index: '0', column: String(into.column) })
      .then(function (parts) {
        if (!parts.canvas || !parts.fields) {
          throw new Error('malformed');
        }
        // Minted where every other new group's key is, so nothing can collide.
        var key = window.boxletBlocks ? window.boxletBlocks.mint() : 'n' + keyCounter++;
        var fragment = api.frame.contentDocument.importNode(parts.canvas.content, true);
        // A block going into a column is not a <section> — the band around it already is
        // one — so the thing to name is whatever the server drew (SectionRender's 'none').
        var drawn = fragment.querySelector('section') || fragment.firstElementChild;
        drawn.setAttribute('data-bx-key', key);

        var group = parts.fields.content.firstElementChild.cloneNode(true);
        group.setAttribute('data-block-key', key);
        group.hidden = true;

        api.say('');
        api.target = null;
        // Nothing changed until now: the request could still have failed.
        api.commit();
        placeInColumn(into, drawn, group);
      })
      .catch(function (error) {
        api.say(api.panel.getAttribute('data-text-failed'));
        // The message above is about the network, and this is anything at all. A mistake
        // in the code above read as "check your connection" until it was traced by hand.
        if (window.console) {
          window.console.error('boxlet: could not insert the block', error);
        }
      })
      .then(function () {
        button.removeAttribute('aria-busy');
      });
  }

  /**
   * The selected block's values, renamed from blocks[n][...] to block[...] so the server
   * can clean and re-render just this one.
   */
  /**
   * What the server needs to draw this block as it stands: its own fields, and no more.
   *
   * The band's went with them for one day (D-099), because a redraw then drew the block as
   * a whole band and needed the band's style to draw it right. Since D-103 the canvas always
   * draws columns and a redraw replaces the BLOCK alone, so the band's classes are never in
   * what comes back and never at risk.
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

  /**
   * A WHOLE BAND REDRAWN, because its arrangement changed (PLAN.md D-099).
   *
   * Everything the band needs is already on the screen: its own fields, and the fields of
   * every block standing in it. They go to the server with the names they already have, the
   * same parser the save runs cleans them, and what comes back is the band as the visitor
   * would get it.
   *
   * THE KEYS ARE PUT BACK IN ORDER. The drawn band carries none — the server has never
   * heard of them — and they are what pairs a block on the canvas with its fields in the
   * panel. The groups were sent in the page's reading order and SectionRender draws in that
   * same order, column by column, so they zip.
   */
  function redrawBand(group) {
    var doc = api.frame.contentDocument;
    var key = group.getAttribute('data-section-group');
    var band = doc && doc.querySelector('[data-bx-section="' + key + '"]');
    if (!band) {
      return;
    }
    var params = {};
    group.querySelectorAll('[name]').forEach(function (element) {
      params[element.name.replace(/^sections\[[^\]]*\]/, 'section')] = element.value;
    });
    var groups = api.groupNodes().filter(function (candidate) {
      return candidate.getAttribute('data-section-key') === key;
    });
    var keys = [];
    groups.forEach(function (candidate) {
      keys.push(candidate.getAttribute('data-block-key'));
      candidate.querySelectorAll('[name]').forEach(function (element) {
        if (element.type === 'checkbox' && !element.checked) {
          return;
        }
        params[element.name] = element.value;
      });
    });

    post(params, api.panel.getAttribute('data-band-url'))
      .then(function (parts) {
        if (!parts.drawn) {
          throw new Error('malformed');
        }
        var fragment = doc.importNode(parts.drawn.content, true);
        var fresh = fragment.firstElementChild;
        if (!fresh) {
          throw new Error('empty');
        }
        fresh.setAttribute('data-bx-section', key);
        /* THE EDITOR'S OWN MARKS DO NOT COME BACK FROM THE SERVER, which has never heard
           of them: which block was selected, and which has fallen behind its source
           (D-043). Read off the old band by key before it goes, and put back by key —
           by key and not by position, because the whole point of this redraw is that the
           positions have just changed. */
        var marked = {};
        var chosen = null;
        var wasInside = band.querySelectorAll('.section-column > *');
        var had = wasInside.length === 0 ? [band] : Array.prototype.slice.call(wasInside);
        had.forEach(function (block) {
          var name = block.getAttribute('data-bx-key');
          if (block.hasAttribute('data-bx-stale')) {
            marked[name] = true;
          }
          if (block.classList.contains('bx-selected')) {
            chosen = name;
          }
        });

        var inside = fresh.querySelectorAll('.section-column > *');
        var drawn = inside.length === 0 ? [fresh] : Array.prototype.slice.call(inside);
        drawn.forEach(function (block, at) {
          if (!keys[at]) {
            return;
          }
          block.setAttribute('data-bx-key', keys[at]);
          if (marked[keys[at]]) {
            block.setAttribute('data-bx-stale', '');
          }
          if (chosen === keys[at]) {
            block.classList.add('bx-selected');
          }
        });
        api.commit();
        band.replaceWith(fresh);
        api.tellCanvas('refresh', {});
      })
      .catch(function (error) {
        api.say(api.panel.getAttribute('data-text-failed'));
        if (window.console) {
          window.console.error('boxlet: could not redraw the band', error);
        }
      });
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
    var asked = era;

    post(Object.assign({ type: type.value, index: String(index) }, values(group)))
      .then(function (parts) {
        var doc = api.frame.contentDocument;
        var current = doc && doc.querySelector('[data-bx-key="' + key + '"]');
        if (drawing[key] !== ticket || era !== asked || !parts.canvas || !current) {
          return; // superseded by a later edit or an undo, or the block is gone
        }
        var fragment = doc.importNode(parts.canvas.content, true);
        var fresh = fragment.querySelector('section') || fragment.firstElementChild;
        fresh.setAttribute('data-bx-key', key);
        // A stale mark belongs to the block, not to what was typed into it: only "Mark as
        // up to date" clears it, so a redraw carries it over (D-043, step 3).
        if (current.hasAttribute('data-bx-stale')) {
          fresh.setAttribute('data-bx-stale', '');
        }
        /* AND THE NAME OF THE BAND, for the same reason and a newer one (D-099). The server
           has never heard of these marks; they are the editor's, and a redraw that dropped
           this one left the band unaddressable — so choosing a surface and THEN choosing two
           columns did nothing at all, while choosing the columns first worked. Measured,
           after the second choice quietly stopped working in a probe that did both. */
        if (current.hasAttribute('data-bx-section')) {
          fresh.setAttribute('data-bx-section', current.getAttribute('data-bx-section'));
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

  /**
   * THE FOUR THINGS YOU CAN DO TO A WHOLE BAND (PLAN.md D-102).
   *
   * The same four a block has, acting on the band and everything standing in it. A band is
   * three things at once — an element on the canvas, a group of fields, and the blocks it
   * holds — so each of these has to move all three, and the block groups have to move as a
   * RUN: they are contiguous in the form because the form is in the page's reading order.
   */
  function actOnBand(action) {
    var doc = api.frame.contentDocument;
    var key = api.band;
    var band = doc && key ? doc.querySelector('[data-bx-section="' + key + '"]') : null;
    var group = key ? document.querySelector('[data-section-group="' + key + '"]') : null;
    if (!band || !group) {
      return;
    }
    var mine = api.groupNodes().filter(function (candidate) {
      return candidate.getAttribute('data-section-key') === key;
    });

    if (action === 'band-remove') {
      api.commit(api.panel.getAttribute('data-text-band-removed') || '');
      band.remove();
      group.remove();
      mine.forEach(function (candidate) {
        candidate.remove();
      });
      api.renumber();
      api.tellCanvas('refresh', {});
      api.show(-1);

      return;
    }

    if (action === 'band-duplicate') {
      api.commit();
      var freshKey = window.boxletBlocks ? window.boxletBlocks.mintSection() : 'm0';
      var bandCopy = band.cloneNode(true);
      bandCopy.setAttribute('data-bx-section', freshKey);
      bandCopy.classList.remove('bx-band-selected');
      var groupCopy = group.cloneNode(true);
      groupCopy.setAttribute('data-section-group', freshKey);
      groupCopy.hidden = true;
      if (window.boxletBlocks) {
        window.boxletBlocks.name(groupCopy, 'n0', freshKey);
      }
      api.sectionGroups().appendChild(groupCopy);

      /* EVERY BLOCK IN IT COPIED TOO, each with a key of its own and no id — an id would
         make the save write over the block this was copied FROM. The same rule the block
         duplicate follows, applied once per block, and in the order they stand. */
      var drawn = bandCopy.querySelectorAll('.section-column > *');
      var inside = drawn.length === 0 ? [bandCopy] : Array.prototype.slice.call(drawn);
      mine.forEach(function (candidate, at) {
        var copy = candidate.cloneNode(true);
        var freshBlock = window.boxletBlocks ? window.boxletBlocks.mint() : 'n0';
        copy.hidden = true;
        copy.setAttribute('data-section-key', freshKey);
        copy.setAttribute('data-block-key', freshBlock);
        copy.querySelectorAll('[name$="[id]"]').forEach(function (field) {
          field.remove();
        });
        if (window.boxletBlocks) {
          window.boxletBlocks.name(copy, freshBlock, freshKey);
        }
        if (api.unsetLive) {
          api.unsetLive(copy);
        }
        api.groups.appendChild(copy);
        if (inside[at]) {
          inside[at].setAttribute('data-bx-key', freshBlock);
          inside[at].classList.remove('bx-selected');
        }
      });

      band.after(bandCopy);
      api.renumber();
      if (window.boxletRichText) {
        window.boxletRichText.scan(api.groups);
      }
      if (window.boxletPicker) {
        window.boxletPicker.scan(api.groups);
      }
      api.tellCanvas('refresh', {});
      api.selectBand(freshKey);

      return;
    }

    var bands = api.bands();
    var was = bands.indexOf(band);
    var to = action === 'band-up' ? was - 1 : was + 1;
    if (to < 0 || to >= bands.length) {
      return;
    }
    api.commit();
    if (action === 'band-up') {
      bands[to].before(band);
    } else {
      bands[to].after(band);
    }
    /* THE FIELD GROUPS FOLLOW, AND ARE NOT MOVED BY HAND. renumber() puts the band groups
       into the canvas's order (D-102), and the block groups are re-read from it too — so a
       move is one statement about the page and the rest is derived. */
    var after = api.bands();
    var order = [];
    after.forEach(function (one) {
      api.groupNodes().forEach(function (candidate) {
        if (candidate.getAttribute('data-section-key') === one.getAttribute('data-bx-section')) {
          order.push(candidate);
        }
      });
    });
    order.forEach(function (candidate) {
      api.groups.appendChild(candidate);
    });
    api.renumber();
    api.tellCanvas('refresh', {});
    api.selectBand(key);
  }

  function act(action) {
    if (action.indexOf('band-') === 0) {
      actOnBand(action);

      return;
    }
    var index = api.selected();
    var group = api.groups.querySelector('[data-block-group="' + index + '"]');
    var section = api.sections()[index];
    if (!group || !section) {
      return;
    }

    if (action === 'remove') {
      // The one action that destroys work, so it is the one that says it can be undone:
      // the shortcut is not discoverable, and the people who most need it are the ones
      // who do not know it is there (D-079).
      var labelled = group.querySelector('[data-block-label]');
      api.commit(api.panel.getAttribute('data-text-removed').replace(
        ':block',
        labelled ? labelled.getAttribute('data-block-label') : '',
      ));
      section.remove();
      group.remove();
      api.renumber();
      api.tellCanvas('refresh', {});
      api.show(-1);
      api.tellCanvas('select', { index: -1 });
      return;
    }

    if (action === 'duplicate') {
      api.commit();
      var key = window.boxletBlocks ? window.boxletBlocks.mint() : 'n' + keyCounter++;
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
      /* BESIDE THE BLOCK IT WAS COPIED FROM, in the same column (PLAN.md D-103). It used to
         become a band of its own at the next position on the page, which was the only
         answer there was while a page was a list of blocks and is the wrong one now: a copy
         of one of three things in a column belongs in that column. */
      window.boxletBlocks.name(groupCopy, key, group.getAttribute('data-section-key'));
      groupCopy.setAttribute('data-section-key', group.getAttribute('data-section-key'));
      section.after(copy);
      group.after(groupCopy);
      api.renumber();
      if (window.boxletRichText) {
        window.boxletRichText.scan(groupCopy);
      }
      if (window.boxletPicker) {
        window.boxletPicker.scan(groupCopy);
      }
      api.tellCanvas('refresh', {});
      var born = Number(groupCopy.getAttribute('data-block-group'));
      api.show(born);
      api.showTab('content');
      api.selectOnCanvas(born);

      return;
    }

    /* WITHIN ITS OWN COLUMN (PLAN.md D-103). It used to be within the page: a flat list of
       blocks had one order and moving down meant the next place in it. In a tree the next
       place is in the same column, and stepping outside it would drop the block into a
       neighbouring band — leaving one band empty and another holding something nobody put
       there. Across columns is dragging; the whole band has arrows of its own. */
    var column = section.parentNode;
    var siblings = Array.prototype.slice.call(column.children);
    var here = siblings.indexOf(section);
    var to = action === 'up' ? here - 1 : here + 1;
    if (to < 0 || to >= siblings.length) {
      return;
    }
    api.commit();
    var neighbour = siblings[to];
    var neighbourGroup = null;
    api.groupNodes().forEach(function (candidate) {
      if (candidate.getAttribute('data-block-key') === neighbour.getAttribute('data-bx-key')) {
        neighbourGroup = candidate;
      }
    });
    if (action === 'up') {
      neighbour.before(section);
      if (neighbourGroup) {
        neighbourGroup.before(group);
      }
    } else {
      neighbour.after(section);
      if (neighbourGroup) {
        neighbourGroup.after(group);
      }
    }
    api.renumber();
    api.tellCanvas('refresh', {});
    // Which block it is NOW, read back rather than worked out: renumber() has just decided.
    var moved = Number(group.getAttribute('data-block-group'));
    api.show(moved);
    api.selectOnCanvas(moved);
  }

  api.act = act;

  /* Undo puts the field groups back as HTML, which leaves every rich text and picker in
     them dead markup for the same reason a clone's is — hence the second caller these two
     were written for, and the reason they are reachable from outside this file (D-079). */
  api.unsetLive = function (root) {
    unsetRichText(root);
    unsetPicker(root);
  };

  /* Forget every answer still in flight: it was asked for by a page that is now gone. */
  api.forget = function () {
    era += 1;
  };

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
  /*
   * A ROW SIZE THAT ASKS FOR MORE COLUMNS GETS THEM (PLAN.md D-091).
   *
   * Choosing "four in a row" on a block with three columns left an empty cell in the grid
   * and no fourth field to type into. The server tops the content up when it parses, so
   * the canvas would have drawn the fourth column on its own — and the panel would still
   * have had three, which is half the complaint.
   *
   * WHAT "FOUR" MEANS IS NOT KNOWN HERE. The block declares it, the view writes it onto
   * the option as data-wants, and this reads the number off the option that was chosen. It
   * presses the repeater's own Add rather than building an item: one way to add a row,
   * which the fallback editor uses too and which already knows about numbering, the
   * maximum and the empty-state message.
   */
  api.groups.addEventListener('change', function (event) {
    var option = event.target.tagName === 'SELECT' && /\[layout\]$/.test(event.target.name || '')
      ? event.target.options[event.target.selectedIndex]
      : null;
    var wants = option && option.getAttribute('data-wants');
    if (!wants) {
      return;
    }
    var group = event.target.closest('[data-block-group]');
    var asked = {};
    try {
      asked = JSON.parse(wants);
    } catch (error) {
      return;
    }
    Object.keys(asked).forEach(function (field) {
      var repeater = group && group.querySelector('[data-repeater="' + field + '"]');
      var add = repeater && repeater.querySelector('[data-repeater-action="add"]');
      if (!repeater || !add) {
        return;
      }
      // Counted again each time: Add refuses at the maximum, and a loop that trusted its
      // own arithmetic would spin when it did.
      var guard = 0;
      while (repeater.querySelectorAll('[data-repeater-item]').length < asked[field] && guard < 32) {
        if (add.disabled) {
          break;
        }
        add.click();
        guard += 1;
      }
    });
  });

  api.groups.addEventListener('change', redraw);
  api.groups.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(redraw, 300);
  });

  /*
   * AND THE BAND'S OWN FIELDS, which stopped reaching this the day they moved into a group
   * of their own (D-099): the Section tab was a panel where nothing you chose did anything
   * until you saved. It listened here all along; it just listened to the wrong container.
   *
   * TWO THINGS HAPPEN, because a band is drawn by two different pieces of markup. The five
   * style keys are class names on the band's own <section>, which no block redraw can reach
   * when the band holds several — so they are swapped straight onto it, which is instant
   * and needs no round trip. Everything else the server has to draw, so the selected block
   * is redrawn too, carrying the band's fields with it now (values()).
   */
  var bands = document.querySelector('[data-section-groups]');
  if (bands) {
    var STYLE_KEYS = ['surface', 'rhythm', 'width', 'align', 'divider'];
    var paint = function (group) {
      var doc = api.frame.contentDocument;
      var key = group.getAttribute('data-section-group');
      var band = doc && doc.querySelector('[data-bx-section="' + key + '"]');
      if (!band) {
        return;
      }
      STYLE_KEYS.forEach(function (name) {
        var field = group.querySelector('[name$="[style][' + name + ']"]');
        if (!field) {
          return;
        }
        // A snapshot, because classList is LIVE: removing while iterating it skips the
        // entry after each removal, which leaves a second `surface-` class behind and lets
        // the old one win or lose by document order.
        Array.prototype.slice.call(band.classList).forEach(function (had) {
          if (had.indexOf(name + '-') === 0) {
            band.classList.remove(had);
          }
        });
        band.classList.add(name + '-' + field.value);
      });
    };
    bands.addEventListener('change', function (event) {
      var group = event.target.closest && event.target.closest('[data-section-group]');
      if (!group) {
        return;
      }
      var name = event.target.name || '';
      /* THE NUMBER OF COLUMNS IS NOT A CLASS, it is the markup around every block in the
         band, so it is the one choice here the browser cannot make look right by itself.
         The server draws the band — the same SectionRender the page uses, so the two
         shapes stay declared once instead of being written out again in JavaScript. */
      if (/\[(layout|stack)\]$/.test(name)) {
        redrawBand(group);

        return;
      }
      paint(group);
      redraw();
    });
    bands.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(redraw, 300);
    });
  }
})();
