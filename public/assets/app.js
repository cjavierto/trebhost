(function () {
  'use strict';

  // Copy-to-clipboard buttons: <button data-copy="#inputId">
  document.addEventListener('click', function (e) {
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var input = document.querySelector(copy.getAttribute('data-copy'));
      if (!input) return;
      var done = function () {
        var label = copy.querySelector('.lbl');
        if (!label) return;
        var old = label.textContent;
        label.textContent = 'Copied';
        setTimeout(function () { label.textContent = old; }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
      } else {
        input.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* user can copy manually */ }
      }
      return;
    }

    var open = e.target.closest('[data-open-dialog]');
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-open-dialog'));
      if (dlg && dlg.showModal) { dlg.showModal(); var f = dlg.querySelector('input'); if (f) f.focus(); }
      return;
    }
    var close = e.target.closest('[data-close-dialog]');
    if (close) { var d = close.closest('dialog'); if (d) d.close(); }
  });

  // Confirm before destructive forms: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Live table filtering: search box + optional status pills
  document.querySelectorAll('[data-filter-table]').forEach(function (box) {
    var table = document.querySelector(box.getAttribute('data-filter-table'));
    if (!table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search]'));
    var empty = document.querySelector(box.getAttribute('data-empty') || '');
    var status = 'all';
    var pills = document.querySelectorAll('[data-status-filter]');

    function apply() {
      var q = box.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (r) {
        var ok = (!q || r.getAttribute('data-search').indexOf(q) !== -1) &&
                 (status === 'all' || r.getAttribute('data-status') === status);
        r.hidden = !ok;
        if (ok) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
    }
    box.addEventListener('input', apply);
    pills.forEach(function (p) {
      p.addEventListener('click', function () {
        status = p.getAttribute('data-status-filter');
        pills.forEach(function (x) { x.setAttribute('aria-pressed', x === p ? 'true' : 'false'); });
        apply();
      });
    });
  });

  // Changing the date range reloads with ?range=
  var range = document.getElementById('range');
  if (range) {
    range.addEventListener('change', function () {
      window.location.search = '?range=' + encodeURIComponent(range.value);
    });
  }
})();
