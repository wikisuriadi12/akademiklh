/* Cetak label barang: pengaturan bebas, pratinjau langsung, barcode Code128 (tanpa pustaka luar). */
(function () {
  'use strict';
  var P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
  function code128(txt) {
    var v = [104], i, c, sum = 104;
    for (i = 0; i < txt.length; i++) { c = txt.charCodeAt(i) - 32; if (c < 0 || c > 94) { c = 0; } v.push(c); sum += c * (i + 1); }
    v.push(sum % 103); v.push(106);
    var bars = '', x = 0, w, k, rects = '';
    v.forEach(function (n) { bars += P[n]; });
    for (k = 0; k < bars.length; k++) { w = +bars[k]; if (k % 2 === 0) { rects += '<rect x="' + x + '" y="0" width="' + w + '" height="1"/>'; } x += w; }
    return '<svg class="lb-bc" viewBox="0 0 ' + x + ' 1" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">' + rects + '</svg>';
  }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function rp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }

  var root = $('#lb'); if (!root) { return; }
  var data = JSON.parse(root.getAttribute('data-cfg'));
  var items = data.items, cfg = data.cfg, copies = {};
  var num = ['w', 'h', 'kol', 'gap', 'fNama', 'fHarga'];
  var chk = ['tToko', 'tNama', 'tHarga', 'tKode', 'tBarcode'];
  try { var loc = JSON.parse(localStorage.getItem('lb-cfg') || 'null'); if (loc) { Object.keys(loc).forEach(function (k) { if (k in cfg) { cfg[k] = loc[k]; } }); } } catch (e) {}

  function bind() {
    num.forEach(function (k) { $('#c-' + k).value = cfg[k]; });
    chk.forEach(function (k) { $('#c-' + k).checked = !!+cfg[k]; });
    $('#c-mode').value = cfg.mode; $('#c-rata').value = cfg.rata; $('#c-toko').value = cfg.toko;
  }
  function baca() {
    num.forEach(function (k) { var v = parseFloat($('#c-' + k).value); cfg[k] = isFinite(v) && v > 0 ? v : cfg[k]; });
    chk.forEach(function (k) { cfg[k] = $('#c-' + k).checked ? 1 : 0; });
    cfg.mode = $('#c-mode').value; cfg.rata = $('#c-rata').value; cfg.toko = $('#c-toko').value.slice(0, 40);
    cfg.kol = Math.max(1, Math.min(8, Math.round(cfg.kol)));
    try { localStorage.setItem('lb-cfg', JSON.stringify(cfg)); } catch (e) {}
    $('#c-kolwrap').hidden = cfg.mode !== 'a4';
  }
  function label(b) {
    var h = '<div class="lb-i" style="width:' + cfg.w + 'mm;height:' + cfg.h + 'mm;text-align:' + cfg.rata + '">';
    if (+cfg.tToko && cfg.toko) { h += '<div class="lb-t">' + esc(cfg.toko) + '</div>'; }
    if (+cfg.tNama) { h += '<div class="lb-n" style="font-size:' + cfg.fNama + 'pt">' + esc(b.nama) + '</div>'; }
    if (+cfg.tBarcode && b.kode) { h += '<div class="lb-b">' + code128(b.kode) + '</div>'; }
    if (+cfg.tKode && b.kode) { h += '<div class="lb-k">' + esc(b.kode) + '</div>'; }
    if (+cfg.tHarga) { h += '<div class="lb-h" style="font-size:' + cfg.fHarga + 'pt">' + rp(b.harga) + '</div>'; }
    return h + '</div>';
  }
  function terpilih() { return items.filter(function (b) { return (copies[b.id] || 0) > 0; }); }
  function render() {
    baca();
    var pilih = terpilih(), contoh = pilih[0] || items[0] || { nama: 'Nama Barang', kode: 'KTN-001', harga: 5000 };
    $('#lb-prev').innerHTML = label(contoh);
    var total = 0, html = '';
    pilih.forEach(function (b) { for (var i = 0; i < copies[b.id]; i++) { html += label(b); total++; } });
    var area = $('#lb-cetak');
    area.className = 'lb-cetak ' + (cfg.mode === 'a4' ? 'a4' : 'roll');
    area.style.gridTemplateColumns = cfg.mode === 'a4' ? 'repeat(' + cfg.kol + ', ' + cfg.w + 'mm)' : '';
    area.style.gap = cfg.mode === 'a4' ? cfg.gap + 'mm' : '0';
    area.innerHTML = html;
    $('#lb-ukuran').textContent = '@page{size:' + (cfg.mode === 'a4' ? 'A4' : cfg.w + 'mm ' + cfg.h + 'mm') + ';margin:' + (cfg.mode === 'a4' ? '8mm' : '0') + '}';
    $('#lb-jml').textContent = total + ' label';
    $('#lb-cetak-btn').disabled = total === 0;
    $('#c-json').value = JSON.stringify(cfg);
  }
  // daftar barang
  $('#lb-daftar').addEventListener('input', function (e) {
    var t = e.target; if (!t.dataset.id) { return; }
    copies[t.dataset.id] = Math.max(0, Math.min(500, parseInt(t.value, 10) || 0)); render();
  });
  $('#lb-cari').addEventListener('input', function () {
    var q = this.value.toLowerCase();
    $$('#lb-daftar tr').forEach(function (tr) { tr.hidden = q && tr.dataset.s.indexOf(q) < 0; });
  });
  $('#lb-satu').addEventListener('click', function () { items.forEach(function (b) { copies[b.id] = 1; }); $$('#lb-daftar input').forEach(function (i) { i.value = 1; }); render(); });
  $('#lb-stok').addEventListener('click', function () { items.forEach(function (b) { copies[b.id] = Math.min(b.qty, 500); }); $$('#lb-daftar input').forEach(function (i) { i.value = copies[i.dataset.id]; }); render(); });
  $('#lb-nol').addEventListener('click', function () { copies = {}; $$('#lb-daftar input').forEach(function (i) { i.value = 0; }); render(); });
  $$('#lb-set input, #lb-set select').forEach(function (el) { el.addEventListener('input', render); el.addEventListener('change', render); });
  $('#lb-cetak-btn').addEventListener('click', function () { render(); window.print(); });
  bind(); render();
})();
