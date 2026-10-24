<?php
Auth::require('penjualan');
$scope = Auth::scope();
$dari = Domain::validDate(in_str('dari')) ? in_str('dari') : date('Y-m-01');
$sampai = Domain::validDate(in_str('sampai')) ? in_str('sampai') : date('Y-m-d');
$unit = $scope ?? (isset(Domain::UNIT[in_str('unit')]) ? in_str('unit') : '');
$metode = in_array(in_str('metode'), ['tunai', 'dompet'], true) ? in_str('metode') : '';
$rows = Pos::daftar(['dari' => $dari, 'sampai' => $sampai, 'unit' => $unit, 'metode' => $metode], $scope, 300);
if (in_str('csv') === '1') {
    csv_out('penjualan-' . $dari . '-' . $sampai . '.csv', ['No struk', 'Tanggal', 'Unit', 'Santri', 'Metode', 'Total', 'Status', 'Kasir'],
        array_map(static fn($r) => [$r['no_struk'], $r['tanggal'], Domain::UNIT[$r['unit']], $r['santri_nama'] ?? '', $r['metode'], $r['total'], $r['dibatalkan'] ? 'batal' : 'sah', $r['kasir'] ?? ''], $rows));
    exit;
}
$rk = Pos::ringkas($dari, $sampai, $unit ?: null);
$top = Pos::terlaris($dari, $sampai, $unit ?: null, 8);
$hari = Pos::perHari(7, $unit ?: null);
$lbl = [];
foreach ($hari as $d => $v) { $lbl[substr($d, 8) . '/' . substr($d, 5, 2)] = $v; }
$topMap = [];
foreach ($top as $t) { $topMap[$t['nama']] = (int) $t['qty']; }
ob_start(); ?>
<div class="page-h"><div><h1>Penjualan Koperasi</h1><p>Riwayat transaksi kasir, omzet, dan laba kotor.</p></div>
  <div class="acts"><a class="btn ghost" href="?<?= e(http_build_query(['p' => 'penjualan', 'dari' => $dari, 'sampai' => $sampai, 'unit' => $unit, 'metode' => $metode, 'csv' => 1])) ?>"><?= icon('download') ?> Ekspor CSV</a><?php if (Auth::can('pos')): ?><a class="btn" href="?p=pos"><?= icon('cart') ?> Ke kasir</a><?php endif; ?></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="penjualan">
    <input type="date" name="dari" value="<?= e($dari) ?>"><input type="date" name="sampai" value="<?= e($sampai) ?>">
    <?php if ($scope === null): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $unit) ?></select><?php endif; ?>
    <select name="metode"><option value="">Semua metode</option><?= options(['tunai' => 'Tunai', 'dompet' => 'Dompet'], $metode) ?></select>
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
  <div class="kpis" style="margin-bottom:0">
    <div class="kpi"><div class="chip"><?= icon('receipt', 22) ?></div><div><span>Transaksi</span><b><?= $rk['n'] ?></b></div></div>
    <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Omzet</span><b><?= rp($rk['omzet']) ?></b></div></div>
    <div class="kpi"><div class="chip c-info"><?= icon('trending-up', 22) ?></div><div><span>Laba kotor</span><b><?= rp($rk['laba']) ?></b></div></div>
  </div>
</div>
<div class="grid g2" style="margin-bottom:16px">
  <div class="card"><h2>Omzet 7 hari terakhir</h2><?= Charts::bars($lbl) ?></div>
  <div class="card"><h2>Barang terlaris</h2><?= $topMap ? Charts::hbars($topMap, static fn($v) => $v . ' terjual') : '<div class="empty">Belum ada penjualan.</div>' ?></div>
</div>
<div class="card"><h2>Transaksi</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>No. struk</th><th>Tanggal</th><th>Unit</th><th>Santri</th><th>Metode</th><th class="r">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><b><?= e($r['no_struk']) ?></b></td><td data-label="Tanggal"><?= e($r['tanggal']) ?></td><td data-label="Unit"><?= e(Domain::UNIT[$r['unit']]) ?></td>
        <td data-label="Santri"><?= e($r['santri_nama'] ?? '-') ?></td><td data-label="Metode"><?= $r['metode'] === 'dompet' ? 'Dompet' : 'Tunai' ?></td>
        <td class="r" data-label="Total"><?= rp($r['total']) ?></td>
        <td data-label="Status"><?= $r['dibatalkan'] ? '<span class="badge b-belum">Batal</span>' : '<span class="badge b-lunas">Sah</span>' ?></td>
        <td class="r"><a class="btn sm ghost" href="?p=struk&amp;id=<?= (int) $r['id'] ?>">Struk</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada transaksi pada rentang ini.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render('Penjualan', ob_get_clean());
