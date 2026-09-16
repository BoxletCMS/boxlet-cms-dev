/*
 * Reordering the page list by dragging.
 *
 * The buttons already work without this file, and they are what a keyboard uses. This
 * only adds the drag, and it submits the same form the buttons submit rather than posting
 * on its own: one endpoint, one CSRF token, nothing to keep in step.
 *
 * SortableJS rather than native drag and drop, for the same reason canvas.js uses it: it
 * handles touch, and a tablet is a real case for this screen.
 */
(function () {
  'use strict';

  var rows = document.querySelector('[data-page-rows]');
  var form = document.querySelector('[data-page-order]');
  if (!rows || !form || !window.Sortable) {
    return;
  }

  // A page is ordered among its siblings, never across them. Dropping a child into
  // another parent's run of rows would look like re-parenting, which is deliberately not
  // something a drag can do (PLAN.md D-011) — so a drag that crosses a group is put back.
  function group(row) {
    return row ? row.getAttribute('data-page-group') : null;
  }

  window.Sortable.create(rows, {
    handle: '[data-page-handle]',
    draggable: 'tr[data-page-id]',
    animation: 120,
    ghostClass: 'is-dragging',
    onMove: function (event) {
      return group(event.dragged) === group(event.related);
    },
    onEnd: function (event) {
      if (event.oldIndex === event.newIndex) {
        return;
      }
      var moved = group(event.item);
      var ids = [];
      Array.prototype.forEach.call(rows.querySelectorAll('tr[data-page-id]'), function (row) {
        if (group(row) === moved) {
          ids.push(row.getAttribute('data-page-id'));
        }
      });
      form.querySelector('[name="order"]').value = ids.join(',');
      form.submit();
    },
  });
})();
