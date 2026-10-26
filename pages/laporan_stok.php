<?php
Auth::require('gudang.view');
$lokNama = Pos::LOKASI;
$lok = isset($lokNama[in_str('lok')]) ? in_str('lok') : Pos::TOKO;
$sf = in_array(in_str('status'), ['', 'ada', 'menipis', 'habis'], true) ? in_str('status') : '';
$q = trim(in_str('q'));
$kat = trim(in_str('kategori'));
$semua = LaporanPos::stok($lok, '');
$kategoriList = array_values(array_unique(array_filter(array_column($semua, 'kategori'))));
sort($kategoriList);
$status = static fn(array $x): string => $x['qty'] <= 0 ? 'habis' : ($x['qty'] <= $x['stok_min'] ? 'menipis' : 'aman');
$rows = array_values(array_filter($semua, static function ($x) use ($sf, $q, $kat, $status) {
    if ($kat !== '' && $x['kategori'] !== $kat) { return false; }
    if ($q !== '' && stripos($x['nama'] . ' ' . $x['kode'], $q) === false) { return false; }
    $s = $status($x);
    return match ($sf) { 'ada' => $x['qty'] > 0, 'menipis' => $s === 'menipis', 'habis' => $s === 'habis', default => true };
}));
$nama = ['aman' => 'Aman', 'menipis' => 'Menipis', 'habis' => 'Habis'];
$cls = ['aman' => 'b-lunas', 'menipis' => 'b-sebagian', 'habis' => 'b-belum'];
$tStok = 0; $tModal = 0; $tJual = 0; $nMenipis = 0; $nHabis = 0;
foreach ($rows as $x) {
    $tStok += $x['qty']; $tModal += $x['modal']; $tJual += $x['jual'];
    $s = $status($x); if ($s === 'menipis') { $nMenipis++; } elseif ($s === 'habis') { $nHabis++; }
}
if (in_str('csv') === '1') {
    csv_out('laporan-stok-' . $lok . '-' . date('Y-m-d') . '.csv', ['Kode', 'Nama', 'Kategori', 'Satuan', 'Stok', 'Batas min', 'Status', 'Harga beli', 'Harga jual', 'Nilai modal', 'Nilai jual'],
        array_map(static fn($x) => [$x['kode'], $x['nama'], $x['kategori'], $x['satuan'], $x['qty'], $x['stok_min'], $nama[$status($x)], $x['harga_beli'], $x['harga_jual'], $x['modal'], $x['jual']], $rows));
    exit;
}
$url = static fn(array $x = []) => '?' . http_build_query(array_filter(['p' => 'laporan_stok', 'lok' => $lok, 'status' => $sf, 'q' => $q, 'kategori' => $kat] + $x, static fn($v) => $v !== '' && $v !== null));
$seg = static function (array $opsi, string $aktif, string $kunci) use ($url): string {
    $h = '<div class="tabs" style="margin:0">';
    foreach ($opsi as $k => $v) { $h .= '<a class="' . ($aktif === (string) $k ? 'on' : '') . '" href="' . e($url([$kunci => $k])) . '">' . e($v) . '</a>'; }
    return $h . '</div>';
};
$kpi = [['Jumlah barang', (string) count($rows), 'box', ''], ['Total stok', number_format($tStok, 0, ',', '.'), 'box', ''], ['Nilai modal', rp($tModal), 'wallet', 'c-ok'],
    ['Nilai jual', rp($tJual), 'trending-up', 'c-info'], ['Menipis / habis', $nMenipis . ' / ' . $nHabis, 'alert', 'c-acc']];
ob_start(); ?>
<div class="page-h"><div><h1>Laporan Stok LH Mart</h1><p>Posisi stok <?= e($lokNama[$lok]) ?> per <?= e(date('d/m/Y H:i')) ?>.</p></div>
  <div class="acts"><a class="btn ghost" href="<?= e($url(['csv' => 1])) ?>"><?= icon('download') ?> Unduh CSV</a><button type="button" class="btn" onclick="window.print()"><?= icon('receipt') ?> Cetak</button></div></div>
<div class="card">
  <form method="get" class="filters noprint"><input type="hidden" name="p" value="laporan_stok"><input type="hidden" name="lok" value="<?= e($lok) ?>"><input type="hidden" name="status" value="<?= e($sf) ?>">
    <?= $seg($lokNama, $lok, 'lok') ?>
    <?= $seg(['' => 'Semua', 'ada' => 'Ada', 'menipis' => 'Menipis', 'habis' => 'Habis'], $sf, 'status') ?>
    <select name="kategori"><option value="">Semua kategori</option><?php foreach ($kategoriList as $k): ?><option <?= $k === $kat ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari nama atau kode barang" aria-label="Cari nama atau kode barang" style="min-width:220px">
    <button class="btn"><?= icon('search') ?> Tampilkan</button></form>
  <div class="kpis"><?php foreach ($kpi as [$lb, $nilai, $ic, $c]): ?><div class="kpi"><div class="chip <?= e($c) ?>"><?= icon($ic, 22) ?></div><div><span><?= e($lb) ?></span><b><?= e($nilai) ?></b></div></div><?php endforeach; ?></div>
  <div class="scroll"><table class="rt"><thead><tr><th>Kode</th><th>Nama barang</th><th>Kategori</th><th class="r">Stok</th><th class="r">Min</th><th>Status</th><th class="r">Harga beli</th><th class="r">Nilai modal</th><th class="r">Nilai jual</th></tr></thead><tbody>
  <?php foreach ($rows as $x): $s = $status($x); ?><tr>
    <td data-label="Kode"><?= e($x['kode']) ?></td><td data-label="Nama barang"><b><?= e($x['nama']) ?></b></td><td data-label="Kategori"><?= e($x['kategori'] ?: '-') ?></td>
    <td class="r" data-label="Stok"><b><?= (int) $x['qty'] ?></b> <?= e($x['satuan']) ?></td><td class="r" data-label="Min"><?= (int) $x['stok_min'] ?></td>
    <td data-label="Status"><span class="badge <?= $cls[$s] ?>"><?= e($nama[$s]) ?></span></td>
    <td class="r" data-label="Harga beli"><?= rp($x['harga_beli']) ?></td><td class="r" data-label="Nilai modal"><?= rp($x['modal']) ?></td><td class="r" data-label="Nilai jual"><?= rp($x['jual']) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">Tidak ada barang yang cocok dengan pilihan ini.</td></tr><?php endif; ?>
  </tbody><?php if ($rows): ?><tfoot><tr><th colspan="3">Total <?= count($rows) ?> barang</th><th class="r"><?= number_format($tStok, 0, ',', '.') ?></th><th colspan="3"></th><th class="r"><?= rp($tModal) ?></th><th class="r"><?= rp($tJual) ?></th></tr></tfoot><?php endif; ?></table></div>
  <p class="mut" style="margin-top:10px">Habis = stok 0. Menipis = stok sama dengan atau di bawah batas minimum. Nilai modal = stok x harga beli, nilai jual = stok x harga jual umum.</p>
</div>
<?php render('Laporan Stok LH Mart', ob_get_clean());
