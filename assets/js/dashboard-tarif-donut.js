/* Dashboard D-T: busbar distribution ring — hover/focus/tap baris panel.
   Vanilla only (GR-01). Tidak ada count-up, tidak ada library chart. */
(function () {
  'use strict';

  var root = document.getElementById('tarifdist');
  if (!root) return;

  var valueEl = root.querySelector('[data-donut-value]');
  var labelEl = root.querySelector('[data-donut-label]');
  var metaEl  = root.querySelector('[data-donut-meta]');
  if (!valueEl || !labelEl || !metaEl) return;

  var rows = Array.prototype.slice.call(root.querySelectorAll('[data-donut-row]'));
  var segs = Array.prototype.slice.call(root.querySelectorAll('[data-donut-seg]'));
  var idle = {
    value: valueEl.textContent,
    label: labelEl.textContent,
    meta:  metaEl.textContent
  };
  var pinned = '';

  function rowFor(key) {
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].getAttribute('data-donut-row') === key) return rows[i];
    }
    return null;
  }

  function paint(key) {
    var row = rowFor(key);
    if (!row) return;

    root.classList.add('is-hovering');

    for (var i = 0; i < rows.length; i++) {
      var on = rows[i] === row;
      rows[i].classList.toggle('is-active', on);
      rows[i].setAttribute('aria-pressed', (on && pinned === key) ? 'true' : 'false');
    }
    for (var j = 0; j < segs.length; j++) {
      segs[j].classList.toggle('is-active', segs[j].getAttribute('data-donut-seg') === key);
    }

    labelEl.textContent = row.getAttribute('data-donut-name') || '';
    valueEl.textContent = row.getAttribute('data-donut-rp') || '';
    metaEl.textContent  = (row.getAttribute('data-donut-pct') || '') + ' dari total';
  }

  function reset() {
    if (pinned) {
      paint(pinned);
      return;
    }

    root.classList.remove('is-hovering');
    for (var i = 0; i < rows.length; i++) {
      rows[i].classList.remove('is-active');
      rows[i].setAttribute('aria-pressed', 'false');
    }
    for (var j = 0; j < segs.length; j++) {
      segs[j].classList.remove('is-active');
    }

    valueEl.textContent = idle.value;
    labelEl.textContent = idle.label;
    metaEl.textContent  = idle.meta;
  }

  function keyOf(el) {
    return el.getAttribute('data-donut-row') || el.getAttribute('data-donut-seg') || '';
  }

  function bindHover(el) {
    el.addEventListener('pointerenter', function () {
      if (!pinned) paint(keyOf(el));
    });
    el.addEventListener('pointerleave', function () {
      if (!pinned) reset();
    });
  }

  for (var r = 0; r < rows.length; r++) {
    (function (row) {
      bindHover(row);
      row.addEventListener('focus', function () {
        if (!pinned) paint(keyOf(row));
      });
      row.addEventListener('blur', function () {
        if (!pinned) reset();
      });
      row.addEventListener('click', function () {
        var key = keyOf(row);
        pinned = (pinned === key) ? '' : key;
        if (pinned) { paint(pinned); } else { reset(); }
      });
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          pinned = '';
          reset();
          row.blur();
        }
      });
    })(rows[r]);
  }

  for (var s = 0; s < segs.length; s++) {
    (function (seg) {
      bindHover(seg);
      seg.addEventListener('click', function () {
        var key = keyOf(seg);
        pinned = (pinned === key) ? '' : key;
        if (pinned) { paint(pinned); } else { reset(); }
      });
    })(segs[s]);
  }
})();
