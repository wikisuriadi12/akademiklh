<?php
if (Auth::user() && !Auth::can('pos')) { redirect(Auth::can('gudang.view') ? '?p=gudang' : '?p=penjualan'); }
Auth::require('pos');
$scope = null;
$unit = Pos::TOKO;

if (is_post()) {
    csrf_check();
    $sidP = (int) in_str('santri_id', '0');
    try {
        $items = [];
        foreach ((array) ($_POST['qty'] ?? []) as $bid => $q) { $items[(int) $bid] = (int) $q; }
        $dibayar = (int) preg_replace('/\D/', '', in_str('dibayar', '0'));
        $diskon = (int) preg_replace('/\D/', '', in_str('diskon', '0'));
        $bukti = null; $qrisRef = in_str('qris_ref');
        if (in_str('metode') === 'qris' && $qrisRef !== '') {
            QrisApi::periksaUntukJual($qrisRef, Pos::hitung($items, in_str('pelanggan'), $sidP > 0, $diskon));
        } elseif (in_str('metode') === 'qris') {
            if (QrisApi::siap()) { throw new InvalidArgumentException('Gunakan QR dinamis yang ditampilkan kasir.'); }
            if (in_str('qris_ok') !== '1') { throw new InvalidArgumentException('Centang konfirmasi bahwa notifikasi pembayaran QRIS sudah masuk dan nominalnya sesuai.'); }
            $bukti = BuktiQris::siapkan($_FILES['bukti'] ?? null);
        }
        $id = Pos::jual($unit, $sidP ?: null, $items, in_str('metode'), $dibayar, Auth::id(), $scope, $diskon, in_str('pelanggan'), in_str('tempo') ?: null);
        if ($qrisRef !== '' && in_str('metode') === 'qris') { QrisApi::pakai($qrisRef, $id); }
        if ($bukti !== null) { try { BuktiQris::simpan($id, $bukti); } catch (Throwable $ex) { flash('Penjualan tersimpan, tetapi foto bukti gagal disimpan: ' . $ex->getMessage(), 'err'); redirect('?p=struk&id=' . $id); } }
        flash('Penjualan tersimpan.');
        redirect('?p=struk&id=' . $id);
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('?p=pos');
    }
}

$barang = Gudang::daftarMarket();
usort($barang, static fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
$noPesanan = (int) Db::val('SELECT COUNT(*) FROM penjualan WHERE tanggal = ?', [date('Y-m-d')]) + 1;
$hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w')];
$tanggal = $hari . ', ' . (int) date('j') . ' ' . Domain::BULAN[(int) date('n')] . ' ' . date('Y');
$koperasi = 'Koperasi ' . trim((string) preg_replace('/^Yayasan\s+/i', '', Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim')));
$user = Auth::user();

$kategori = [];
foreach ($barang as $b) { $k = $b['kategori'] !== '' ? $b['kategori'] : 'Lainnya'; $kategori[$k] = true; }
$kategori = array_keys($kategori);

/** Dua huruf awal kartu: huruf pertama dua kata pertama (satu kata: dua huruf pertama). */
$inisial = static function (string $nama): string {
    $w = preg_split('/\s+/u', trim($nama)) ?: [];
    $s = mb_substr($w[0] ?? '?', 0, 1) . (count($w) > 1 ? mb_substr($w[1], 0, 1) : mb_substr($w[0] ?? '', 1, 1));
    return mb_strtoupper($s);
};
$nada = static function (string $kat): string {
    $m = ['kantin' => 'hijau', 'alat tulis' => 'biru', 'perlengkapan' => 'krem', 'busana' => 'merah', 'kitab & buku' => 'ungu'];
    $k = mb_strtolower($kat);
    if (isset($m[$k])) { return $m[$k]; }
    $set = ['hijau', 'biru', 'krem', 'merah', 'ungu'];
    return $set[crc32($k) % count($set)];
};

$qrisOn = Qris::aktif() || QrisApi::siap();
$cfg = [
    'unit' => $unit,
    'pesanan' => $noPesanan,
    'qris' => ['dinamis' => QrisApi::siap(), 'simulasi' => QrisApi::provider() === 'simulasi' && Auth::can('users'), 'aktif' => $qrisOn || QrisApi::siap(), 'img' => $qrisOn ? Qris::url() : '', 'nama' => Settings::get('qris_nama'), 'nmid' => Settings::get('qris_nmid')],
    'cari' => '?p=pos_cari',
    'qrisBuat' => '?p=qris_buat',
    'qrisStatus' => '?p=qris_status',
    'csrf' => csrf_token(),
];
$rail = [
    ['?p=pos', 'Kasir', 'grid', true, true],
    ['?p=penjualan', 'Riwayat', 'clock', Auth::can('penjualan'), false],
    ['?p=gudang&lok=ikhwan', 'Market', 'box', Auth::can('gudang.view'), false],
    ['?p=gudang&lok=gudang', 'Gudang', 'box', Auth::can('gudang.view'), false],
    ['?p=barang_masuk', 'Masuk', 'plus', Auth::can('gudang.edit'), false],
    ['?p=piutang', 'Bon', 'receipt', Auth::can('pos') || Auth::can('laporan'), false],
    ['?p=hutang', 'Hutang', 'wallet', Auth::can('gudang.edit') || Auth::can('laporan'), false],
    ['?p=laporan_pos', 'Laporan', 'bar-chart', Auth::can('penjualan') || Auth::can('gudang.view'), false],
    ['?p=dompet', 'Santri', 'users', Auth::can('dompet'), false],
    ['?p=akun_pos', 'Akun', 'shield', Auth::can('users'), false],
];
ob_start(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&amp;display=swap">
<link rel="stylesheet" href="assets/pos.css?v=3">
<div class="kp" data-cfg="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <nav class="kp-rail" aria-label="Menu kasir">
    <a class="kp-logo" href="?p=pos" title="<?= e(Settings::get('nama_lembaga')) ?>">LH</a>
    <?php foreach ($rail as [$href, $label, $ic, $boleh, $on]): if (!$boleh) { continue; } ?>
      <a class="kp-ri<?= $on ? ' on' : '' ?>" href="<?= e($href) ?>"><?= icon($ic, 22) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <details class="kp-me">
      <summary title="<?= e($user['nama']) ?>"><?= e(mb_strtoupper(mb_substr($user['nama'], 0, 1))) ?></summary>
      <div class="kp-menu"><b><?= e($user['nama']) ?></b><small><?= e(Domain::ROLE[$user['role']] ?? $user['role']) ?></small>
        <a href="?p=akun"><?= icon('key', 16) ?> Akun saya</a><a href="?p=logout"><?= icon('log-out', 16) ?> Keluar</a></div>
    </details>
  </nav>

  <main class="kp-main">
    <header class="kp-head">
      <div><h1>Kasir</h1><p><?= e($koperasi) ?> &middot; <?= e($tanggal) ?></p></div>
    </header>

    <?php if (!$barang): ?><div class="note warn" style="margin:0 0 12px">Belum ada barang di stok Market. Buka menu Stok, pilih barangnya, lalu Transfer stok dari Gudang ke Market.</div><?php endif; ?>
    <label class="kp-search"><?= icon('search', 20) ?><input type="search" id="kp-q" placeholder="Cari nama barang, SKU, atau pindai barcode" autocomplete="off" autofocus></label>

    <div class="kp-chips" id="kp-chips">
      <button type="button" class="on" data-kat="">Semua</button>
      <?php foreach ($kategori as $k): ?><button type="button" data-kat="<?= e($k) ?>"><?= e($k) ?></button><?php endforeach; ?>
    </div>

    <div class="kp-grid" id="kp-grid">
      <?php foreach ($barang as $b):
          $kat = $b['kategori'] !== '' ? $b['kategori'] : 'Lainnya';
          $qty = (int) $b['qty']; $habis = $qty <= 0; $rendah = !$habis && $qty <= (int) $b['stok_min']; ?>
        <button type="button" class="kp-card<?= $habis ? ' habis' : '' ?>" <?= $habis ? 'disabled' : '' ?>
          data-id="<?= (int) $b['id'] ?>" data-nama="<?= e($b['nama']) ?>" data-kode="<?= e($b['kode']) ?>" data-harga="<?= (int) $b['harga_jual'] ?>" data-hp="<?= ($b['harga_pegawai'] ?? null) !== null ? (int) $b['harga_pegawai'] : '' ?>" data-stok="<?= $qty ?>" data-kat="<?= e($kat) ?>"
          data-cari="<?= e(mb_strtolower($b['nama'] . ' ' . $b['kode'] . ' ' . $kat)) ?>">
          <span class="kp-av n-<?= e($nada($kat)) ?>"><?= e($inisial($b['nama'])) ?></span>
          <span class="kp-qty" hidden></span>
          <b class="kp-nm"><?= e($b['nama']) ?></b>
          <span class="kp-sku"><?= e($b['kode']) ?></span>
          <span class="kp-row"><span class="kp-pr"><?= rp($b['harga_jual']) ?></span>
            <span class="kp-st<?= $rendah ? ' low' : '' ?><?= $habis ? ' out' : '' ?>"><?= $habis ? 'Habis' : 'Stok ' . $qty . ($rendah ? ' &middot; menipis' : '') ?></span></span>
        </button>
      <?php endforeach; ?>
    </div>
    <p class="kp-none" id="kp-none" hidden>Tidak ada barang yang cocok.</p>
    <p class="kp-foot" id="kp-foot" data-total="<?= count($barang) ?>" data-unit="">Menampilkan <?= count($barang) ?> dari <?= count($barang) ?> barang</p>
  </main>

  <aside class="kp-panel">
    <form method="post" id="kp-form" autocomplete="off" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="unit" value="<?= e($unit) ?>">
      <input type="hidden" name="santri_id" id="f-santri" value="">
      <input type="hidden" name="metode" id="f-metode" value="tunai">
      <input type="hidden" name="diskon" id="f-diskon" value="0">
      <input type="hidden" name="dibayar" id="f-dibayar" value="0">
      <input type="hidden" name="qris_ok" id="f-qris" value="0">
      <input type="hidden" name="qris_ref" id="f-qref" value="">
      <input type="hidden" name="tempo" id="f-tempo" value="">
      <input type="hidden" name="pelanggan" id="f-pelanggan" value="Umum">
      <input type="file" name="bukti" id="f-bukti" accept="image/*" capture="environment" hidden>
      <div id="f-items"></div>

      <div class="kp-ph"><h2>Pesanan #<?= e(str_pad((string) $noPesanan, 4, '0', STR_PAD_LEFT)) ?></h2><span class="kp-pill" id="kp-n">0 item</span><button type="button" class="kp-clear" id="kp-clear">Kosongkan</button></div>
      <div class="kp-list" id="kp-list"><p class="kp-empty">Ketuk barang di sebelah kiri untuk menambah ke pesanan.</p></div>

      <div class="kp-sum">
        <div class="kp-line"><span>Subtotal</span><span id="kp-sub">Rp 0</span></div>
        <div class="kp-line"><span>Diskon</span><button type="button" class="kp-disc" id="kp-disc">+ Tambah diskon</button></div>
        <div class="kp-total"><span>Total</span><b id="kp-total">Rp 0</b></div>
      </div>
      <div class="kp-cust" id="kp-cust">
        <span>Pelanggan</span>
        <button type="button" data-c="umum" class="on">Umum</button>
        <button type="button" data-c="pegawai">Pegawai</button>
        <button type="button" data-c="santri" id="kp-c-santri">Santri</button>
      </div>
      <input class="kp-peg" id="in-pegawai" placeholder="Nama pegawai (boleh dikosongkan)" maxlength="60" hidden>
      <div class="kp-tiles" id="kp-tiles">
        <button type="button" data-m="tunai"><?= icon('cash', 22) ?><span>Tunai</span></button>
        <button type="button" data-m="qris" <?= $qrisOn ? '' : 'disabled title="QRIS belum diaktifkan di Pengaturan"' ?>><?= icon('qr', 22) ?><span>BNI QRIS</span></button>
        <button type="button" data-m="dompet"><?= icon('wallet', 22) ?><span id="kp-dompet-l">Saldo Santri</span></button>
        <button type="button" data-m="hutang"><?= icon('receipt', 22) ?><span>Hutang / Bon</span></button>
      </div>
      <button type="button" class="kp-pay" id="kp-pay"><span id="kp-pay-l">Bayar Tunai</span><b id="kp-pay-a">Rp 0</b></button>
    </form>
  </aside>
</div>

<dialog class="kp-dlg" id="dlg-bayar"><div class="kp-dbody" id="dlg-bayar-b"></div></dialog>
<dialog class="kp-dlg" id="dlg-diskon"><div class="kp-dbody">
  <h3>Diskon pesanan</h3>
  <label class="kp-fl">Nominal diskon (Rp)<input id="in-diskon" inputmode="numeric" placeholder="0"></label>
  <div class="kp-quick"><button type="button" data-pct="5">5%</button><button type="button" data-pct="10">10%</button><button type="button" data-pct="15">15%</button></div>
  <div class="kp-dact"><button type="button" class="kp-btn ghost" id="dk-hapus">Hapus diskon</button><button type="button" class="kp-btn" id="dk-ok">Terapkan</button></div>
</div></dialog>
<dialog class="kp-dlg" id="dlg-santri"><div class="kp-dbody">
  <h3>Pilih santri</h3>
  <label class="kp-fl">Cari nama atau NIS<input id="in-santri" autocomplete="off" placeholder="Ketik minimal 2 huruf"></label>
  <ul class="kp-sl" id="sl-hasil"></ul>
  <div class="kp-dact"><button type="button" class="kp-btn ghost" data-tutup>Tutup</button></div>
</div></dialog>
<script src="assets/pos.js?v=9" defer></script>
<?php render('Kasir', ob_get_clean(), 'bare');
