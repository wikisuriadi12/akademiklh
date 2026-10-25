<?php
$bolehJual = Auth::can('penjualan'); $bolehMasuk = Auth::can('gudang.view');
if (!$bolehJual && !$bolehMasuk) { Auth::require('penjualan'); }
$tabs = [];
if ($bolehMasuk) { $tabs['masuk'] = 'Barang Masuk'; }
if ($bolehJual) { $tabs['jual'] = 'Penjualan'; $tabs['laris'] = 'Terlaris'; }
$tab = isset($tabs[in_str('tab')]) ? in_str('tab') : array_key_first($tabs);
$dari = Domain::validDate(in_str('dari')) ? in_str('dari') : date('Y-m-01');
$sampai = Domain::validDate(in_str('sampai')) ? in_str('sampai') : date('Y-m-d');
$lok = isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : '';
$pemasok = in_str('pemasok');
$csv = in_str('csv') === '1';
$metodeNama = ['tunai' => 'Tunai', 'dompet' => 'Saldo Santri', 'qris' => 'QRIS'];
$dataMasuk = $dataJual = $dataLaris = [];
if ($tab === 'masuk') {
    $dataMasuk = Gudang::laporanMasuk($dari, $sampai, $lok, $pemasok);
    if ($csv) { csv_out("barang-masuk-$dari-$sampai.csv", ['Waktu', 'Supplier', 'Kode', 'Barang', 'Lokasi', 'Jumlah', 'Satuan', 'Harga beli', 'Total', 'Catatan', 'Petugas'],
        array_map(static fn($r) => [$r['created_at'], $r['pemasok'], $r['kode'], $r['nama'], Pos::LOKASI[$r['unit']] ?? $r['unit'], $r['qty'], $r['satuan'], $r['harga_beli'], (int) $r['qty'] * (int) $r['harga_beli'], $r['catatan'], $r['petugas']], $dataMasuk)); exit; }
} elseif ($tab === 'jual') {
    $dataJual = Db::all('SELECT p.tanggal, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE p.dibatalkan = 0 AND p.tanggal >= ? AND p.tanggal <= ? GROUP BY p.tanggal ORDER BY p.tanggal DESC', [$dari, $sampai]);
    $perMetode = Db::all('SELECT p.metode, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE p.dibatalkan = 0 AND p.tanggal >= ? AND p.tanggal <= ? GROUP BY p.metode', [$dari, $sampai]);
    if ($csv) { csv_out("penjualan-harian-$dari-$sampai.csv", ['Tanggal', 'Transaksi', 'Omzet'], array_map(static fn($r) => [$r['tanggal'], $r['n'], $r['omzet']], $dataJual)); exit; }
} else {
    $dataLaris = Pos::terlaris($dari, $sampai, null, 100);
    if ($csv) { csv_out("terlaris-$dari-$sampai.csv", ['Peringkat', 'Barang', 'Terjual', 'Omzet'], array_map(static fn($r, $i) => [$i + 1, $r['nama'], $r['qty'], $r['omzet']], $dataLaris, array_keys($dataLaris))); exit; }
}
$rk = $tab === 'jual' ? Pos::ringkas($dari, $sampai, null) : null;
ob_start(); ?>
<div class="page-h"><div><h1>Laporan LH Mart</h1><p>Barang masuk, penjualan, dan barang terlaris.</p></div>
  <div class="acts"><a class="btn ghost" href="?<?= e(http_build_query(['p' => 'laporan_pos', 'tab' => $tab, 'dari' => $dari, 'sampai' => $sampai, 'lok' => $lok, 'pemasok' => $pemasok, 'csv' => 1])) ?>"><?= icon('download') ?> Ekspor CSV</a></div></div>
<div class="tabs" style="margin-bottom:12px"><?php foreach ($tabs as $k => $v): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?p=laporan_pos&amp;tab=<?= e($k) ?>&amp;dari=<?= e($dari) ?>&amp;sampai=<?= e($sampai) ?>"><?= e($v) ?></a><?php endforeach; ?></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="laporan_pos"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="date" name="dari" value="<?= e($dari) ?>"><input type="date" name="sampai" value="<?= e($sampai) ?>">
    <?php if ($tab === 'masuk'): ?><select name="lok"><option value="">Semua lokasi</option><?= options(Pos::LOKASI, $lok) ?></select>
    <select name="pemasok"><option value="">Semua supplier</option><?php foreach (Gudang::daftarPemasok() as $p): ?><option <?= $p === $pemasok ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn"><?= icon('search') ?> Tampilkan</button></form>
<?php if ($tab === 'masuk'):
    $tq = 0; $tn = 0; foreach ($dataMasuk as $r) { $tq += (int) $r['qty']; $tn += (int) $r['qty'] * (int) $r['harga_beli']; } ?>
  <div class="kpis"><div class="kpi"><div class="chip"><?= icon('box', 22) ?></div><div><span>Total barang masuk</span><b><?= $tq ?></b></div></div>
    <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Nilai pembelian</span><b><?= rp($tn) ?></b></div></div></div>
  <div class="scroll"><table class="rt"><thead><tr><th>Waktu</th><th>Supplier</th><th>Barang</th><th>Lokasi</th><th class="r">Jumlah</th><th class="r">Harga beli</th><th class="r">Total</th><th>Catatan</th></tr></thead><tbody>
  <?php foreach ($dataMasuk as $r): ?><tr><td><?= e(substr($r['created_at'], 0, 16)) ?></td><td data-label="Supplier"><?= e($r['pemasok'] ?: '-') ?></td><td data-label="Barang"><b><?= e($r['nama']) ?></b><br><small><?= e($r['kode']) ?></small></td><td data-label="Lokasi"><?= e(Pos::LOKASI[$r['unit']] ?? $r['unit']) ?></td><td class="r" data-label="Jumlah"><?= (int) $r['qty'] ?> <?= e($r['satuan']) ?></td><td class="r" data-label="Harga"><?= rp($r['harga_beli']) ?></td><td class="r" data-label="Total"><?= rp((int) $r['qty'] * (int) $r['harga_beli']) ?></td><td data-label="Catatan"><?= e($r['catatan'] ?: '-') ?></td></tr><?php endforeach; ?>
  <?php if (!$dataMasuk): ?><tr><td colspan="8" class="empty">Tidak ada barang masuk pada rentang ini.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif ($tab === 'jual'): ?>
  <div class="kpis"><div class="kpi"><div class="chip"><?= icon('receipt', 22) ?></div><div><span>Transaksi</span><b><?= $rk['n'] ?></b></div></div>
    <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Omzet</span><b><?= rp($rk['omzet']) ?></b></div></div>
    <div class="kpi"><div class="chip c-info"><?= icon('trending-up', 22) ?></div><div><span>Laba kotor</span><b><?= rp($rk['laba']) ?></b></div></div></div>
  <h3>Per metode bayar</h3>
  <div class="scroll"><table class="rt"><thead><tr><th>Metode</th><th class="r">Transaksi</th><th class="r">Omzet</th></tr></thead><tbody>
  <?php foreach ($perMetode as $r): ?><tr><td><?= e($metodeNama[$r['metode']] ?? $r['metode']) ?></td><td class="r"><?= (int) $r['n'] ?></td><td class="r"><?= rp($r['omzet']) ?></td></tr><?php endforeach; ?>
  <?php if (!$perMetode): ?><tr><td colspan="3" class="empty">Belum ada penjualan.</td></tr><?php endif; ?></tbody></table></div>
  <h3>Per hari</h3>
  <div class="scroll"><table class="rt"><thead><tr><th>Tanggal</th><th class="r">Transaksi</th><th class="r">Omzet</th></tr></thead><tbody>
  <?php foreach ($dataJual as $r): ?><tr><td><?= e($r['tanggal']) ?></td><td class="r"><?= (int) $r['n'] ?></td><td class="r"><?= rp($r['omzet']) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="mut"><a href="?p=penjualan&amp;dari=<?= e($dari) ?>&amp;sampai=<?= e($sampai) ?>">Lihat daftar transaksi lengkap</a></p>
<?php else: ?>
  <div class="scroll"><table class="rt"><thead><tr><th>#</th><th>Barang</th><th class="r">Terjual</th><th class="r">Omzet</th></tr></thead><tbody>
  <?php foreach ($dataLaris as $i => $r): ?><tr><td><?= $i + 1 ?></td><td data-label="Barang"><b><?= e($r['nama']) ?></b></td><td class="r" data-label="Terjual"><?= (int) $r['qty'] ?></td><td class="r" data-label="Omzet"><?= rp($r['omzet']) ?></td></tr><?php endforeach; ?>
  <?php if (!$dataLaris): ?><tr><td colspan="4" class="empty">Belum ada penjualan pada rentang ini.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
</div>
<?php render('Laporan LH Mart', ob_get_clean());
