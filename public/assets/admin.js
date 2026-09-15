/*
 * Admin behaviour. All of it is optional: without JavaScript every form still submits
 * and the server does the same work.
 *
 * The page editor part only adds, removes and reorders field groups, and renumbers
 * blocks[n] in their names. It never knows which fields a block has; new groups are
 * cloned from <template> elements the server rendered.
 */
(function () {
  'use strict';

  document.documentElement.classList.add('js');

  // Buttons with data-confirm ask before they submit.
  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-confirm]');
    if (button && !window.confirm(button.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  var form = document.querySelector('form[data-page-editor]');
  if (!form) {
    return;
  }
  var list = form.querySelector('[data-block-list]');
  var dirty = false;
  var dragged = null;

  function markDirty() {
    dirty = true;
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  form.addEventListener('submit', function () {
    dirty = false;
  });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  function groups() {
    return Array.prototype.filter.call(list.children, function (child) {
      return child.hasAttribute('data-block');
    });
  }

  // Makes every blocks[n] name and block-n- id follow the order on screen.
  function renumber() {
    groups().forEach(function (group, index) {
      group.querySelectorAll('[name]').forEach(function (element) {
        element.name = element.name.replace(/^blocks\[[^\]]*\]/, 'blocks[' + index + ']');
      });
      group.querySelectorAll('[id]').forEach(function (element) {
        element.id = element.id.replace(/^block-[^-]+-/, 'block-' + index + '-');
      });
      group.querySelectorAll('label[for]').forEach(function (label) {
        label.htmlFor = label.htmlFor.replace(/^block-[^-]+-/, 'block-' + index + '-');
      });
    });
    markDirty();
  }

  function templateFor(type) {
    var found = null;
    document.querySelectorAll('template[data-block-template]').forEach(function (template) {
      if (template.getAttribute('data-block-template') === type) {
        found = template;
      }
    });
    return found;
  }

  form.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-editor-action]');
    if (!button) {
      return;
    }
    var action = button.getAttribute('data-editor-action');

    if (action === 'add') {
      var template = templateFor(form.querySelector('[data-add-type]').value);
      if (!template) {
        return; // let the form submit; the server adds the block
      }
      event.preventDefault();
      list.appendChild(template.content.cloneNode(true));
      renumber();
      var added = groups().pop();
      var field = added && added.querySelector('input:not([type="hidden"]), textarea, select');
      if (field) {
        field.focus();
      }
      return;
    }

    var group = button.closest('[data-block]');
    if (!group) {
      return;
    }
    event.preventDefault();
    if (action === 'remove') {
      group.remove();
    } else if (action === 'up' && group.previousElementSibling) {
      list.insertBefore(group, group.previousElementSibling);
    } else if (action === 'down' && group.nextElementSibling) {
      list.insertBefore(group.nextElementSibling, group);
    }
    renumber();
    if (action !== 'remove') {
      button.focus();
    }
  });

  // Drag and drop, started from a group's handle only, so text in fields stays selectable.
  list.addEventListener('mousedown', function (event) {
    var handle = event.target.closest && event.target.closest('[data-drag-handle]');
    if (handle) {
      handle.closest('[data-block]').setAttribute('draggable', 'true');
    }
  });
  document.addEventListener('mouseup', function () {
    if (!dragged) {
      groups().forEach(function (group) {
        group.removeAttribute('draggable');
      });
    }
  });
  list.addEventListener('dragstart', function (event) {
    dragged = event.target.closest && event.target.closest('[data-block]');
    if (!dragged) {
      return;
    }
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', '');
    dragged.classList.add('is-dragging');
  });
  list.addEventListener('dragover', function (event) {
    if (!dragged) {
      return;
    }
    event.preventDefault();
    var over = event.target.closest && event.target.closest('[data-block]');
    if (!over || over === dragged || over.parentNode !== list) {
      return;
    }
    var box = over.getBoundingClientRect();
    list.insertBefore(dragged, event.clientY > box.top + box.height / 2 ? over.nextElementSibling : over);
  });
  list.addEventListener('drop', function (event) {
    if (dragged) {
      event.preventDefault();
    }
  });
  list.addEventListener('dragend', function () {
    if (!dragged) {
      return;
    }
    dragged.classList.remove('is-dragging');
    dragged.removeAttribute('draggable');
    dragged = null;
    renumber();
  });
})();
