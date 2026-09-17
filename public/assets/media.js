/*
 * The picture library's two optional conveniences. Without this file the screen works:
 * the upload form submits, and the focal point is set with two number fields.
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
 *
 * 2. SETTING THE FOCAL POINT BY CLICKING THE PICTURE. The click only fills in the two
 *    number fields the form posts anyway, so there is one path to the server, not two.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-media-upload]');
  if (form) {
    upload(form);
  }

  var focal = document.querySelector('[data-focal-form]');
  if (focal) {
    focalPoint(focal);
  }

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

    input.addEventListener('change', check);
    form.addEventListener('submit', function (event) {
      if (!check()) {
        event.preventDefault();
      }
    });

    // Dropping files onto the form. The files are put into the real input rather than
    // posted separately, so the drop and the button take the same path — including the
    // size check above.
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
      input.files = event.dataTransfer.files;
      check();
    });
  }

  function focalPoint(form) {
    var frame = form.querySelector('[data-focal-frame]');
    var marker = form.querySelector('[data-focal-marker]');
    var x = form.querySelector('[data-focal-input-x]');
    var y = form.querySelector('[data-focal-input-y]');
    if (!x || !y) {
      return;
    }

    function place() {
      if (!marker) {
        return;
      }
      marker.style.insetInlineStart = clamp(x.value) + '%';
      marker.style.insetBlockStart = clamp(y.value) + '%';
    }

    function clamp(value) {
      var number = parseInt(value, 10);
      if (isNaN(number)) {
        return 50;
      }
      return Math.max(0, Math.min(100, number));
    }

    if (frame) {
      frame.addEventListener('click', function (event) {
        var box = frame.getBoundingClientRect();
        if (!box.width || !box.height) {
          return;
        }
        x.value = Math.round(((event.clientX - box.left) / box.width) * 100);
        y.value = Math.round(((event.clientY - box.top) / box.height) * 100);
        place();
      });
    }
    x.addEventListener('input', place);
    y.addEventListener('input', place);
    place();
  }
})();
