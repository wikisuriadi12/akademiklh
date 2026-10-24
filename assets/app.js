(function () {
  var d = document, root = d.documentElement;

  // Mode gelap/terang
  d.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (t) {
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (x) {}
    }
    if (e.target.closest('[data-menu]')) { d.body.classList.toggle('menu'); }
    if (e.target.id === 'scrim') { d.body.classList.remove('menu'); }
    var cl = e.target.closest('[data-close]');
    if (cl) { cl.parentNode.remove(); }
    var pr = e.target.closest('[data-print]');
    if (pr) { window.print(); }
  });

  // Konfirmasi
  d.addEventListener('submit', function (e) {
    var m = e.submitter && e.submitter.getAttribute('data-confirm') || e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) { e.preventDefault(); }
  });

  // Toast otomatis hilang
  setTimeout(function () {
    d.querySelectorAll('.toast:not(.err)').forEach(function (t) { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); });
  }, 6500);

  // Format ribuan pada input rupiah
  function fmt(v) { return v.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  d.querySelectorAll('input[data-rupiah]').forEach(function (i) {
    i.value = fmt(i.value);
    i.addEventListener('input', function () { i.value = fmt(i.value); });
  });

  // Pilih semua (tabel dengan kotak centang)
  d.addEventListener('change', function (e) {
    if (e.target.matches('[data-check-all]')) {
      d.querySelectorAll(e.target.getAttribute('data-check-all')).forEach(function (c) { c.checked = e.target.checked; });
    }
  });

  // Kasir: hitung total, kembalian, tombol +/-
  d.querySelectorAll('form[data-pos]').forEach(function (f) {
    var tot = f.querySelector('[data-total]'), kem = f.querySelector('[data-kembali]'), met = f.querySelector('[data-metode]'), pay = f.querySelector('input[name=dibayar]');
    function rp(n) { return 'Rp ' + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function hitung() {
      var t = 0;
      f.querySelectorAll('input[data-harga]').forEach(function (i) {
        var q = Math.max(0, Math.min(parseInt(i.value || '0', 10) || 0, parseInt(i.max || '0', 10) || 0));
        t += q * parseInt(i.getAttribute('data-harga'), 10);
        i.closest('.pos-item').classList.toggle('has', q > 0);
      });
      tot.textContent = rp(t);
      var tunai = !met || met.value === 'tunai';
      f.querySelectorAll('[data-tunai]').forEach(function (x) { x.style.display = tunai ? '' : 'none'; });
      var qris = !!met && met.value === 'qris';
      f.querySelectorAll('[data-qris]').forEach(function (x) { x.style.display = qris ? '' : 'none'; });
      f.querySelectorAll('[data-qris-total]').forEach(function (x) { x.textContent = rp(t); });
      var b = parseInt((pay && pay.value || '').replace(/\D/g, '') || '0', 10);
      kem.textContent = rp(Math.max(0, b - t));
    }
    f.addEventListener('click', function (e) {
      var b = e.target.closest('[data-step]');
      if (!b) { return; }
      var i = b.parentNode.querySelector('input'), v = (parseInt(i.value || '0', 10) || 0) + parseInt(b.getAttribute('data-step'), 10);
      i.value = Math.max(0, Math.min(v, parseInt(i.max || '0', 10) || 0)); hitung();
    });
    f.addEventListener('input', hitung); f.addEventListener('change', hitung); hitung();
  });

  // Kehadiran: tandai yang kosong sebagai hadir
  d.addEventListener('click', function (e) {
    var b = e.target.closest('[data-semua-hadir]');
    if (!b) { return; }
    var f = b.closest('form'), names = {};
    f.querySelectorAll('input[type=radio][name^="st["]').forEach(function (r) { names[r.name] = names[r.name] || r.checked; });
    Object.keys(names).forEach(function (n) { if (!names[n]) { var h = f.querySelector('input[name="' + n + '"][value=H]'); if (h) { h.checked = true; } } });
  });
})();
