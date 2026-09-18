/*
 * The picture library's optional conveniences. Without this file the screen works: the
 * drop zone is the file input's label and an Upload button submits what was chosen.
 *
 * 1. REFUSING AN OVERSIZED FILE BEFORE IT IS SENT. This is the only place such a file can
 *    be refused readably. nginx answers a body over client_max_body_size with its own 413
 *    page before PHP runs, so no template of ours is involved and nothing we write can
 *    change what is on screen. PHP's own post_max_size is caught server-side, but by then
 *    the file has been uploaded and thrown away — the person waited for nothing.
 *
 *    The limits checked here are PHP's, which are what this server reports. nginx's is
 *    invisible from PHP and may be lower; that case still reaches the 413, which is why
 *    the server-side message exists as well.

 */
(function () {
  'use strict';

  // A picture's Replace: its drop zone opens under the buttons when asked for (D-039).
  var replaceToggle = document.querySelector('[data-replace-toggle]');
  var replacePanel = document.querySelector('[data-replace-panel]');
  if (replaceToggle && replacePanel) {
    replacePanel.hidden = true;
    replaceToggle.addEventListener('click', function () {
      var open = replacePanel.hidden;
      replacePanel.hidden = !open;
      replaceToggle.setAttribute('aria-expanded', String(open));
    });
  }

  // The library's drop zone, and a picture's Replace (D-039): the same behaviour for both.
  Array.prototype.forEach.call(document.querySelectorAll('[data-media-upload]'), upload);

  function upload(form) {
    var input = form.querySelector('[data-media-input]');
    var error = form.querySelector('[data-media-error]');
    if (!input || !error) {
      return;
    }

    var maxFile = parseInt(form.getAttribute('data-max-file'), 10) || 0;
    var maxRequest = parseInt(form.getAttribute('data-max-request'), 10) || 0;

    function fill(template, values) {
      return Object.keys(values).reduce(function (text, key) {
        return text.split(':' + key).join(values[key]);
      }, template);
    }

    // Matches Bytes::human() on the server, so the two never disagree about a size.
    function human(bytes) {
      if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(1) + ' MB';
      }
      if (bytes >= 1024) {
        return Math.round(bytes / 1024) + ' KB';
      }
      return bytes + ' B';
    }

    function problem() {
      var files = input.files;
      if (!files || !files.length) {
        return null;
      }
      var total = 0;
      for (var i = 0; i < files.length; i++) {
        total += files[i].size;
        if (maxFile > 0 && files[i].size > maxFile) {
          return fill(form.getAttribute('data-too-large'), {
            name: files[i].name,
            size: human(files[i].size),
            limit: form.getAttribute('data-file-label'),
          });
        }
      }
      if (maxRequest > 0 && total > maxRequest) {
        return fill(form.getAttribute('data-too-large-total'), {
          size: human(total),
          limit: form.getAttribute('data-request-label'),
        });
      }
      return null;
    }

    function check() {
      var message = problem();
      error.textContent = message || '';
      error.hidden = !message;
      return !message;
    }

    // Chosen or dropped, a picture goes up at once: there is no button to press (D-038).
    // A refused file stays on screen with its reason instead.
    function send() {
      if (!check() || !input.files || !input.files.length) {
        return;
      }
      form.classList.add('is-uploading');
      var text = form.querySelector('.dropzone-text');
      if (text) {
        text.textContent = form.getAttribute('data-uploading');
      }
      form.submit();
    }

    input.addEventListener('change', send);
    form.addEventListener('submit', function (event) {
      if (!check()) {
        event.preventDefault();
      }
    });

    // Dropping files onto the form. The files are put into the real input rather than
    // posted separately, so the drop and the button take the same path — including the
    // size check above.
    // A replacement takes one file: a drop of several keeps the first rather than failing.
    var single = !input.multiple;

    ['dragenter', 'dragover'].forEach(function (name) {
      form.addEventListener(name, function (event) {
        event.preventDefault();
        form.classList.add('is-dropping');
      });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      form.addEventListener(name, function (event) {
        if (name === 'dragleave' && form.contains(event.relatedTarget)) {
          return;
        }
        form.classList.remove('is-dropping');
      });
    });
    form.addEventListener('drop', function (event) {
      event.preventDefault();
      if (!event.dataTransfer || !event.dataTransfer.files.length) {
        return;
      }
      // DataTransfer is the only way to write to a file input; assigning .files a plain
      // array does nothing and the form would submit empty.
      if (single && event.dataTransfer.files.length > 1) {
        var one = new DataTransfer();
        one.items.add(event.dataTransfer.files[0]);
        input.files = one.files;
      } else {
        input.files = event.dataTransfer.files;
      }
      send();
    });
  }
})();
