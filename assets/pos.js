(function () {
  'use strict';
  var root = document.querySelector('.kp');
  if (!root) { return; }
  var cfg = {};
  try { cfg = JSON.parse(root.getAttribute('data-cfg') || '{}'); } catch (x) {}
  var qris = cfg.qris || {};
  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function rp(n) { return 'Rp ' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function digits(v) { return parseInt(String(v || '').replace(/\D/g, '') || '0', 10); }
  function fmt(v) { var d = String(v).replace(/\D/g, ''); return d.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var form = $('#kp-form'), list = $('#kp-list');
  var cards = $$('.kp-card'), prod = {};
  cards.forEach(function (c) {
    prod[c.dataset.id] = { id: c.dataset.id, nama: c.dataset.nama, kode: c.dataset.kode, harga: +c.dataset.harga, hp: c.dataset.hp === '' || c.dataset.hp == null ? null : +c.dataset.hp, stok: +c.dataset.stok, el: c };
  });

  var cart = {}, diskon = 0, santri = null, metode = 'tunai', kat = '', cari = '', tipe = 'umum';
  try { var m0 = localStorage.getItem('kp-metode'); if (m0 === 'qris' && qris.aktif) { metode = 'qris'; } if (m0 === 'tunai') { metode = 'tunai'; } } catch (x) {}
  if (metode === 'tunai' && qris.aktif && !localStorage.getItem('kp-metode')) { metode = 'qris'; }

  function hrg(p) { return tipe === 'pegawai' && p.hp !== null ? p.hp : p.harga; }
  function subtotal() { var t = 0; Object.keys(cart).forEach(function (id) { t += cart[id] * hrg(prod[id]); }); return t; }
  function jumlah() { var n = 0; Object.keys(cart).forEach(function (id) { n += cart[id]; }); return n; }
  function total() { return Math.max(0, subtotal() - diskon); }

  function tambah(id, d) {
    var p = prod[id]; if (!p) { return; }
    var q = (cart[id] || 0) + d;
    if (q > p.stok) { q = p.stok; toast('Stok ' + p.nama + ' hanya ' + p.stok + '.'); }
    if (q <= 0) { delete cart[id]; } else { cart[id] = q; }
    if (diskon > subtotal()) { diskon = subtotal(); }
    render();
  }
  function toast(msg) {
    var t = d$('div'); t.className = 'toast err'; t.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:99;background:#fdeae8;color:#8a1f15;padding:10px 16px;border-radius:12px;font:600 13px system-ui';
    t.textContent = msg; document.body.appendChild(t); setTimeout(function () { t.remove(); }, 2600);
  }
  function d$(n) { return document.createElement(n); }

  function render() {
    // kartu
    cards.forEach(function (c) {
      var q = cart[c.dataset.id] || 0, b = $('.kp-qty', c);
      c.classList.toggle('sel', q > 0);
      b.hidden = q === 0; b.textContent = '×' + q;
    });
    // daftar pesanan
    var ids = Object.keys(cart);
    if (!ids.length) {
      list.innerHTML = '<p class="kp-empty">Ketuk barang di sebelah kiri untuk menambah ke pesanan.</p>';
    } else {
      list.innerHTML = ids.map(function (id) {
        var p = prod[id], q = cart[id];
        return '<div class="kp-it" data-id="' + id + '"><div class="nm">' + esc(p.nama) + '<small>' + rp(hrg(p)) + '</small></div>' +
          '<button type="button" class="stp" data-d="-1" aria-label="Kurangi">−</button><span class="q">' + q + '</span>' +
          '<button type="button" class="stp" data-d="1" aria-label="Tambah">+</button><span class="tt">' + rp(hrg(p) * q) + '</span></div>';
      }).join('');
    }
    var n = jumlah();
    $('#kp-n').textContent = n + ' item';
    $('#kp-sub').textContent = rp(subtotal());
    $('#kp-total').textContent = rp(total());
    var dk = $('#kp-disc');
    dk.textContent = diskon > 0 ? '− ' + rp(diskon) + '  · ubah' : '+ Tambah diskon';
    dk.classList.toggle('set', diskon > 0);
    // pelanggan
    tipe = santri ? 'santri' : (tipe === 'santri' ? 'umum' : tipe);
    $$('#kp-cust button').forEach(function (b) { b.classList.toggle('on', b.dataset.c === tipe); });
    $('#kp-c-santri').textContent = santri ? santri.nama.split(' ')[0] : 'Santri';
    $('#in-pegawai').hidden = tipe !== 'pegawai';
    // metode
    $$('#kp-tiles button').forEach(function (b) { b.classList.toggle('on', b.dataset.m === metode); });
    $('#kp-dompet-l').textContent = santri ? santri.nama.split(' ')[0] : 'Saldo Santri';
    var lab = { tunai: 'Bayar Tunai', qris: 'Bayar dengan BNI QRIS', dompet: 'Bayar dengan Saldo Santri' };
    $('#kp-pay-l').textContent = lab[metode];
    $('#kp-pay-a').textContent = rp(total());
    $('#kp-pay').disabled = n === 0;
    // saring katalog
    var tampil = 0;
    cards.forEach(function (c) {
      var ok = (kat === '' || c.dataset.kat === kat) && (cari === '' || c.dataset.cari.indexOf(cari) !== -1);
      c.hidden = !ok; if (ok) { tampil++; }
    });
    var f = $('#kp-foot');
    f.textContent = 'Menampilkan ' + tampil + ' dari ' + f.dataset.total + ' barang';
    $('#kp-none').hidden = tampil > 0;
  }

  // klik kartu, stepper, kosongkan
  $('#kp-grid').addEventListener('click', function (e) { var c = e.target.closest('.kp-card'); if (c && !c.disabled) { tambah(c.dataset.id, 1); } });
  list.addEventListener('click', function (e) {
    var b = e.target.closest('.stp'); if (!b) { return; }
    tambah(b.closest('.kp-it').dataset.id, parseInt(b.dataset.d, 10));
  });
  $('#kp-clear').addEventListener('click', function () { cart = {}; diskon = 0; render(); });

  // pencarian & barcode
  var q = $('#kp-q');
  q.addEventListener('input', function () { cari = q.value.trim().toLowerCase(); render(); });
  q.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') { return; }
    e.preventDefault();
    var v = q.value.trim().toLowerCase(); if (!v) { return; }
    var hit = cards.filter(function (c) { return c.dataset.kode.toLowerCase() === v && !c.disabled; })[0];
    if (!hit) { var vis = cards.filter(function (c) { return !c.hidden && !c.disabled; }); if (vis.length === 1) { hit = vis[0]; } }
    if (hit) { tambah(hit.dataset.id, 1); q.value = ''; cari = ''; render(); return; }
    var habis = cards.filter(function (c) { return c.disabled && (c.dataset.kode.toLowerCase() === v || c.dataset.cari.indexOf(v) !== -1); })[0];
    toast(habis ? habis.dataset.nama + ' ada, tetapi stok Market 0. Isi stok lewat menu Market (Input stok / Tarik dari Gudang).' : 'Barang tidak ditemukan.');
  });
  $('#kp-chips').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) { return; }
    $$('#kp-chips button').forEach(function (x) { x.classList.toggle('on', x === b); });
    kat = b.dataset.kat; render();
  });

  // jenis pelanggan
  $('#kp-cust').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) { return; }
    var c = b.dataset.c;
    if (c === 'santri') { bukaSantri(); return; }
    santri = null; tipe = c;
    if (metode === 'dompet') { metode = 'tunai'; }
    cards.forEach(function (el) { var p = prod[el.dataset.id], pr = el.querySelector('.kp-pr'); if (pr) { pr.textContent = rp(hrg(p)); } });
    render();
    if (c === 'pegawai') { $('#in-pegawai').focus(); }
  });

  // metode bayar
  $('#kp-tiles').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b || b.disabled) { return; }
    var m = b.dataset.m;
    if (m === 'dompet' && (!santri || metode === 'dompet')) { bukaSantri(); return; }
    metode = m;
    try { if (m !== 'dompet') { localStorage.setItem('kp-metode', m); } } catch (x) {}
    render();
  });

  // dialog helpers
  function buka(d) { if (d.showModal) { d.showModal(); } else { d.setAttribute('open', ''); } }
  function tutup(d) { if (d.close) { d.close(); } else { d.removeAttribute('open'); } }
  $$('.kp-dlg').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d || e.target.closest('[data-tutup]')) { tutup(d); } });
  });

  // diskon
  var dd = $('#dlg-diskon'), din = $('#in-diskon');
  $('#kp-disc').addEventListener('click', function () {
    if (!jumlah()) { toast('Tambahkan barang dulu.'); return; }
    din.value = diskon ? fmt(diskon) : ''; buka(dd); din.focus();
  });
  din.addEventListener('input', function () { din.value = fmt(din.value); });
  $$('[data-pct]', dd).forEach(function (b) { b.addEventListener('click', function () { din.value = fmt(Math.round(subtotal() * parseInt(b.dataset.pct, 10) / 100)); }); });
  $('#dk-ok').addEventListener('click', function () { diskon = Math.min(digits(din.value), subtotal()); tutup(dd); render(); });
  $('#dk-hapus').addEventListener('click', function () { diskon = 0; tutup(dd); render(); });
  din.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#dk-ok').click(); } });

  // santri
  var ds = $('#dlg-santri'), sin = $('#in-santri'), sl = $('#sl-hasil'), tmr = null;
  function bukaSantri() { sin.value = ''; sl.innerHTML = ''; buka(ds); sin.focus(); }
  sin.addEventListener('input', function () {
    clearTimeout(tmr); var v = sin.value.trim();
    if (v.length < 2) { sl.innerHTML = ''; return; }
    tmr = setTimeout(function () {
      fetch(cfg.cari + '&unit=' + encodeURIComponent(cfg.unit) + '&q=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          sl.innerHTML = rows.length ? rows.map(function (r, i) {
            return '<li><button type="button" data-i="' + i + '"><span><b>' + esc(r.nama) + '</b><small>' + esc(r.nis) + ' · ' + esc(r.kelas) + '</small></span><span class="sal">' + rp(r.saldo) + '</span></button></li>';
          }).join('') : '<li class="none">Santri tidak ditemukan.</li>';
          sl._rows = rows;
        }).catch(function () { sl.innerHTML = '<li class="none">Gagal mencari. Coba lagi.</li>'; });
    }, 250);
  });
  sl.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) { return; }
    santri = sl._rows[parseInt(b.dataset.i, 10)]; metode = 'dompet'; tutup(ds); render();
  });

  // pembayaran
  var db = $('#dlg-bayar'), dbb = $('#dlg-bayar-b');
  $('#kp-pay').addEventListener('click', function () {
    if (!jumlah()) { return; }
    var t = total();
    if (metode === 'dompet' && !santri) { bukaSantri(); return; }
    if (metode === 'tunai') {
      var sug = [t, 20000, 50000, 100000].filter(function (v, i, a) { return v >= t && a.indexOf(v) === i; }).slice(0, 4);
      dbb.innerHTML = '<h3>Pembayaran tunai</h3><p class="sub">Total yang harus dibayar</p><div class="kp-big">' + rp(t) + '</div>' +
        '<label class="kp-fl">Uang diterima (Rp)<input id="in-uang" inputmode="numeric" placeholder="0"></label>' +
        '<div class="kp-quick">' + sug.map(function (v, i) { return '<button type="button" data-u="' + v + '">' + (i === 0 ? 'Uang pas' : fmt(v)) + '</button>'; }).join('') + '</div>' +
        '<div class="kp-kv" id="kv-kem"><span>Kembalian</span><b>Rp 0</b></div>' +
        '<div class="kp-dact"><button type="button" class="kp-btn ghost" data-tutup>Batal</button><button type="button" class="kp-btn" id="bt-ok" disabled>Selesaikan</button></div>';
      var u = $('#in-uang', dbb), ok = $('#bt-ok', dbb), kv = $('#kv-kem', dbb);
      var hitung = function () { var v = digits(u.value); ok.disabled = v < t; kv.className = 'kp-kv ' + (v >= t ? 'ok' : ''); $('b', kv).textContent = rp(Math.max(0, v - t)); };
      u.addEventListener('input', function () { u.value = fmt(u.value); hitung(); });
      $$('[data-u]', dbb).forEach(function (b) { b.addEventListener('click', function () { u.value = fmt(b.dataset.u); hitung(); }); });
      u.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !ok.disabled) { ok.click(); } });
      ok.addEventListener('click', function () { kirim({ dibayar: digits(u.value) }); });
      buka(db); u.focus();
    } else if (metode === 'qris') {
      dbb.innerHTML = '<div class="kp-qrbox"><h3>Bayar dengan BNI QRIS</h3><p class="sub">' + esc(qris.nama || '') + (qris.nmid ? ' · NMID ' + esc(qris.nmid) : '') + '</p>' +
        (qris.img ? '<img src="' + esc(qris.img) + '" alt="QRIS">' : '') +
        '<div class="kp-big">' + rp(t) + '</div><p class="sub">Minta pembeli memasukkan nominal tepat di aplikasinya.</p>' +
        '<label class="kp-chk"><input type="checkbox" id="ck-qris"> <span>Notifikasi pembayaran sudah masuk di aplikasi merchant, dan nominalnya sama dengan total</span></label>' +
        '<div class="kp-bukti"><button type="button" class="kp-btn ghost" id="bt-foto">Foto bukti notifikasi</button> <span id="nm-foto" class="sub">Belum ada foto</span><img id="pv-foto" alt="" style="display:none;max-width:160px;margin-top:8px;border-radius:8px"></div></div>' +
        '<div class="kp-dact"><button type="button" class="kp-btn ghost" data-tutup>Batal</button><button type="button" class="kp-btn" id="bt-ok" disabled>Selesaikan</button></div>';
      var ck = $('#ck-qris', dbb), ok2 = $('#bt-ok', dbb), fb = $('#f-bukti');
      try { fb.value = ''; } catch (x) {}
      var cek = function () { ok2.disabled = !(ck.checked && fb.files && fb.files.length); };
      ck.addEventListener('change', cek);
      $('#bt-foto', dbb).addEventListener('click', function () { fb.click(); });
      fb.onchange = function () {
        var f = fb.files && fb.files[0], pv = $('#pv-foto', dbb);
        $('#nm-foto', dbb).textContent = f ? 'Foto terlampir' : 'Belum ada foto';
        if (f && pv) { pv.src = URL.createObjectURL(f); pv.style.display = 'block'; }
        cek();
      };
      ok2.addEventListener('click', function () { kirim({ qris: 1 }); });
      buka(db);
    } else {
      var kurang = santri.saldo < t;
      dbb.innerHTML = '<h3>Bayar dengan saldo santri</h3><p class="sub">' + esc(santri.nama) + ' · ' + esc(santri.nis) + '</p>' +
        '<div class="kp-kv"><span>Saldo saat ini</span><b>' + rp(santri.saldo) + '</b></div><div class="kp-kv"><span>Total belanja</span><b>' + rp(t) + '</b></div>' +
        '<div class="kp-kv ' + (kurang ? 'bad' : 'ok') + '"><span>' + (kurang ? 'Saldo kurang' : 'Sisa saldo') + '</span><b>' + rp(Math.abs(santri.saldo - t)) + '</b></div>' +
        '<div class="kp-dact"><button type="button" class="kp-btn ghost" id="bt-ganti">Ganti santri</button><button type="button" class="kp-btn" id="bt-ok"' + (kurang ? ' disabled' : '') + '>Selesaikan</button></div>';
      $('#bt-ok', dbb).addEventListener('click', function () { kirim({}); });
      $('#bt-ganti', dbb).addEventListener('click', function () { tutup(db); bukaSantri(); });
      buka(db);
    }
  });

  function kirim(x) {
    $('#f-metode').value = metode;
    $('#f-diskon').value = diskon;
    $('#f-santri').value = santri ? santri.id : '';
    var nm = ($('#in-pegawai').value || '').trim();
    $('#f-pelanggan').value = santri ? '' : (tipe === 'pegawai' ? 'Pegawai' + (nm ? ': ' + nm : '') : 'Umum');
    $('#f-dibayar').value = x.dibayar || 0;
    $('#f-qris').value = x.qris ? 1 : 0;
    if (metode !== 'qris') { try { $('#f-bukti').value = ''; } catch (e) {} }
    $('#f-items').innerHTML = Object.keys(cart).map(function (id) { return '<input type="hidden" name="qty[' + id + ']" value="' + cart[id] + '">'; }).join('');
    form.submit();
  }
  form.addEventListener('submit', function (e) { if (!$('#f-items').children.length) { e.preventDefault(); } });

  render();
})();
