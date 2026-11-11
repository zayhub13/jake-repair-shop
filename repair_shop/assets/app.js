document.addEventListener('DOMContentLoaded', function () {
  var t = document.getElementById('navToggle'), n = document.getElementById('mainNav');
  if (t) t.addEventListener('click', function () { var o = n.classList.toggle('open'); t.setAttribute('aria-expanded', o); });
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) { if (!confirm(el.dataset.confirm)) ev.preventDefault(); });
  });
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(b.dataset.copy); b.textContent = 'Copied';
      setTimeout(function () { b.textContent = 'Copy number'; }, 1800);
    });
  });
  document.querySelectorAll('[data-fill]').forEach(function (s) {
    s.addEventListener('change', function () { document.getElementById(s.dataset.fill).value = s.value; });
  });
  var st = document.getElementById('statusSelect'), cr = document.getElementById('cancelRow');
  if (st && cr) { var f = function () { cr.hidden = st.options[st.selectedIndex].text !== 'Cancelled'; }; st.addEventListener('change', f); f(); }
  var ph = document.getElementById('photo'), pv = document.getElementById('photoName');
  if (ph && pv) ph.addEventListener('change', function () { pv.textContent = ph.files[0] ? ph.files[0].name : 'Tap to add a photo'; });
});
