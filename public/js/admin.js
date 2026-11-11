// tick jobs on the list: "tick all", a running count, and a question before a bulk reject
(function () {
  var form = document.getElementById('bulk');
  if (!form) return;
  var all = document.getElementById('pick-all'), out = document.getElementById('picked');
  var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.pick')); };
  var count = function () { out.textContent = boxes().filter(function (b) { return b.checked; }).length; };
  all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); count(); });
  boxes().forEach(function (b) { b.addEventListener('change', count); });
  form.addEventListener('submit', function (e) {
    var q = e.submitter && e.submitter.dataset.confirm;
    if (q && !confirm(q)) e.preventDefault();
  });
})();

(function () {
  var root = document.documentElement;

  // light / dark, remembered in this browser only
  try { if (localStorage.getItem('theme') === 'dark') root.dataset.theme = 'dark'; } catch (e) {}
  var theme = document.getElementById('theme');
  if (theme) theme.onclick = function () {
    var dark = root.dataset.theme === 'dark';
    if (dark) delete root.dataset.theme; else root.dataset.theme = 'dark';
    try { localStorage.setItem('theme', dark ? 'light' : 'dark'); } catch (e) {}
  };

  // show / hide a password
  document.querySelectorAll('.reveal').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = document.getElementById(b.getAttribute('aria-controls'));
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // mobile menu
  var side = document.getElementById('side');
  var menu = document.getElementById('menu');
  var close = document.getElementById('close');
  if (menu && side) menu.onclick = function () { side.classList.add('open'); };
  if (close && side) close.onclick = function () { side.classList.remove('open'); };

  // dialogs: <button data-open="id"> opens <dialog id="id">; <button data-close> closes the enclosing one
  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = document.getElementById(b.dataset.open);
      if (d && d.showModal) d.showModal();
    });
  });
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('dialog').close(); });
  });

  // confirm before buttons that change things
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.dataset.confirm)) e.preventDefault();
    });
  });

  // new source: suggest a frequency for the chosen type, until the person picks one themselves
  var sourceForm = document.getElementById('source-form');
  var typeSelect = document.getElementById('source_type');
  var freqSelect = document.getElementById('collection_frequency');
  if (sourceForm && sourceForm.dataset.suggestions && typeSelect && freqSelect) {
    var suggestions = JSON.parse(sourceForm.dataset.suggestions);
    var labels = JSON.parse(sourceForm.dataset.frequencyLabels);
    var hint = document.getElementById('frequency-hint');
    var chosen = false;
    freqSelect.addEventListener('change', function () { chosen = true; });
    typeSelect.addEventListener('change', function () {
      var s = suggestions[typeSelect.value];
      if (!s) return;
      if (!chosen) freqSelect.value = s;
      if (hint) hint.textContent = 'Suggested for this type of source: ' + labels[s].toLowerCase() + '. You can change it.';
    });
  }

  // show settings only for the chosen collector
  var collector = document.getElementById('collector');
  function syncCollector() {
    if (!collector) return;
    document.querySelectorAll('[data-for-collector]').forEach(function (el) {
      el.hidden = el.dataset.forCollector !== collector.value;
    });
  }
  if (collector) { collector.addEventListener('change', syncCollector); syncCollector(); }

  // repeatable extraction-pattern rows
  var rules = document.getElementById('rules');
  var tpl = document.getElementById('rule-template');
  var add = document.getElementById('add-rule');
  function nextIndex() { return rules.querySelectorAll('.rule').length + Date.now() % 100000; }
  if (rules && tpl && add) {
    add.addEventListener('click', function () {
      rules.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, nextIndex()));
    });
    rules.addEventListener('click', function (e) {
      if (e.target.matches('[data-remove-rule]')) e.target.closest('.rule').remove();
    });
  }

  // extraction preview: post the form as typed, show the answer in a dialog
  var pv = document.getElementById('preview-btn');
  if (pv) pv.addEventListener('click', function () {
    var form = document.getElementById('source-form');
    var out = document.getElementById('preview-out');
    var dlg = document.getElementById('preview-dlg');
    out.textContent = 'Working…';
    dlg.showModal();
    var body = new FormData(form);
    body.delete('_method'); // the edit form is spoofed as PUT; the preview route is a plain POST
    fetch(pv.dataset.url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) { out.innerHTML = html; })
      .catch(function (e) { out.textContent = 'Could not load the preview (' + e.message + '). Reload the page and try again.'; });
  });
})();
