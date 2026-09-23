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

  /* A block arriving from the server is named blocks[n0]; a page may already hold an n0,
     and two blocks with one name is one block after the save. So a group is named the
     moment it is placed, with the same key the canvas pairs it by (D-094). */
  function place(index, section, group) {
    /* Read off the GROUP, which is always an element: an inserted block arrives as a
       DocumentFragment, and asking a fragment for an attribute throws — which the caller's
       catch then reported as "the block could not be added", a network message for a
       programming mistake. */
    var key = group.getAttribute('data-block-key');
    if (key && window.boxletBlocks) {
      // The band it was already given, if it has one: minting a second key here would name
      // the group's fields for one band and the band's own group for another (D-099).
      window.boxletBlocks.name(group, key, group.getAttribute('data-section-key') || undefined);
    }
    var doc = api.frame.contentDocument;
    /* BANDS, because this puts a band into the page. api.sections() counts BLOCKS since
       D-099, and a block standing in a column is not a child of <main> — insertBefore
       against one throws. */
    var list = api.bands();
    var main = doc.querySelector('[data-bx-blocks]');
    if (index >= list.length) {
      main.appendChild(section);
    } else {
      main.insertBefore(section, list[index]);
    }
    /* AND THE GROUP GOES WHERE THAT BAND'S FIRST BLOCK'S GROUP GOES.
       The groups are in the page's reading order — bands in order, and within one, its
       blocks — so a band's position and a group's position are the same NUMBER only while
       every band holds one block. Counted instead: how many blocks stand in the bands
       before this one. */
    var before = 0;
    list.slice(0, index).forEach(function (band) {
      var inside = band.querySelectorAll('.section-column > *');
      before += inside.length === 0 ? 1 : inside.length;
    });
    var existing = api.groupNodes();
    if (before >= existing.length) {
      api.groups.appendChild(group);
    } else {
      api.groups.insertBefore(group, existing[before]);
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
    api.tellCanvas('select', { index: index });
  }

  function insert(type, button) {
    if (!api.frame.contentDocument) {
      return;
    }
    // A POSITION ON THE PAGE, OR AN ADDRESS INSIDE A BAND (PLAN.md D-099). The second kind
    // comes from the + in an empty column and names which band and which of its columns.
    var into = api.target !== null && typeof api.target === 'object' ? api.target : null;
    var at = api.target === null ? api.bands().length : (into ? 0 : api.target);
    button.setAttribute('aria-busy', 'true');
    api.say(api.panel.getAttribute('data-text-inserting'));

    var asked = into
      ? { type: type, index: '0', column: String(into.column) }
      : { type: type, index: String(at) };

    post(asked)
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
        if (into) {
          placeInColumn(into, drawn, group);
        } else {
          /* A BAND OF ITS OWN, so its fields come too. Without this the block would post a
             section key nothing had described, Page::update() would give it the fallback
             band, and the style the character composed for it — which is on the canvas the
             author is looking at — would not be in the save. */
          var band = parts.band ? parts.band.content.firstElementChild.cloneNode(true) : null;
          var bandKey = window.boxletBlocks ? window.boxletBlocks.mintSection() : 'm' + keyCounter;
          if (band) {
            band.setAttribute('data-section-group', bandKey);
            api.sectionGroups().appendChild(band);
          }
          if (window.boxletBlocks) {
            window.boxletBlocks.name(group, key, bandKey);
            window.boxletBlocks.name(band || group, key, bandKey);
          }
          group.setAttribute('data-section-key', bandKey);
          place(at, fragment, group);
        }
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
   * What the server needs to draw this block as it stands: its own fields, AND THE BAND'S.
   *
   * The band's were here all along until D-099 moved them into a group of their own, and
   * the day they moved, a single keystroke redrew the block with the CHARACTER's composed
   * style instead of the band's — so typing one letter made a tinted, airy, wide, centred
   * band go plain, normal, narrow and left on the canvas. Nothing was lost from the
   * database; the editor simply stopped telling the truth about the page, which is the one
   * thing it is for.
   */
  function values(group) {
    var out = {};
    group.querySelectorAll('[name]').forEach(function (element) {
      if (element.type === 'checkbox' && !element.checked) {
        return;
      }
      out[element.name.replace(/^blocks\[[^\]]*\]/, 'block')] = element.value;
    });
    var band = bandGroupOf(group);
    if (band) {
      band.querySelectorAll('[name]').forEach(function (element) {
        out[element.name.replace(/^sections\[[^\]]*\]/, 'section')] = element.value;
      });
    }

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

  /** The group holding the fields of the band this block's group stands in. */
  function bandGroupOf(group) {
    var key = group.getAttribute('data-section-key');

    return key ? document.querySelector('[data-section-group="' + key + '"]') : null;
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

  function act(action) {
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
      place(index + 1, copy, groupCopy);
      return;
    }

    var to = action === 'up' ? index - 1 : index + 1;
    var list = api.sections();
    if (to < 0 || to >= list.length) {
      return;
    }
    api.commit();
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
