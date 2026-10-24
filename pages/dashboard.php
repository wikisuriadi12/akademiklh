<?php
if (Auth::user() && !Auth::can('santri.view')) { redirect(Auth::can('pos') ? '?p=pos' : '?p=gudang'); }
Auth::require('santri.view');
$scope = Auth::scope();
$u = Auth::user();
$rekap = Santri::rekap($scope);
$periode = date('Y-m');
$keu = Auth::can('tagihan.view');
$ringkas = $keu ? Spp::ringkasPeriode($periode, $scope) : null;
$tung = Auth::can('laporan') ? Spp::tunggakan($scope) : [];
$totalTung = array_sum(array_map(static fn($r) => (int) $r['total'], $tung));
$perBulan = Auth::can('laporan') ? Spp::penerimaanPerBulan(6, $scope) : [];
$terbaru = Auth::can('laporan') ? Spp::terbaru($scope, 6) : [];
$ik = array_sum($rekap['ikhwan'] ?? []);
$ak = array_sum($rekap['akhwat'] ?? []);
$pend = Auth::can('pendaftar') ? Santri::hitungStatus('calon', $scope) : 0;
$persen = $ringkas && $ringkas['ditagih'] > 0 ? $ringkas['dibayar'] / $ringkas['ditagih'] * 100 : 0;
$hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w')];
$tgl = $hari . ', ' . (int) date('j') . ' ' . Domain::BULAN[(int) date('n')] . ' ' . date('Y');
$perJenjang = [];
foreach ($tung as $r) { $k = $r['jenjang'] . ' ' . Domain::UNIT[$r['unit']]; $perJenjang[$k] = ($perJenjang[$k] ?? 0) + (int) $r['total']; }
arsort($perJenjang);
$perJenjang = array_slice($perJenjang, 0, 6, true);
$bulanLabel = [];
foreach ($perBulan as $ym => $v) { $bulanLabel[substr(Domain::BULAN[(int) substr($ym, 5)], 0, 3)] = $v; }
ob_start(); ?>
<section class="hero">
  <div>
    <h1>Assalamu'alaikum, <?= e(explode(' ', $u['nama'])[0]) ?></h1>
    <p><?= e($tgl) ?> &middot; <?= $keu ? 'Ringkasan SPP ' . e(Domain::namaPeriode($periode)) : 'Ringkasan data santri' ?></p>
  </div>
  <div class="acts">
    <?php if (Auth::can('bayar')): ?><a class="btn" href="?p=bayar"><?= icon('credit-card') ?> Terima pembayaran</a><?php endif; ?>
    <?php if (Auth::can('santri.edit')): ?><a class="btn ghost" href="?p=santri_form"><?= icon('user-plus') ?> Santri baru</a><?php endif; ?>
  </div>
</section>

<div class="kpis">
  <div class="kpi"><div class="chip"><?= icon('users', 22) ?></div><div><span>Santri aktif</span><b><?= $ik + $ak ?></b><small><?= $ik ?> ikhwan &middot; <?= $ak ?> akhwat</small></div></div>
  <?php if ($ringkas): ?>
  <div class="kpi"><div class="chip c-info"><?= icon('file-text', 22) ?></div><div><span>Ditagih <?= e(Domain::namaPeriode($periode)) ?></span><b><?= rp($ringkas['ditagih']) ?></b><small><?= (int) $ringkas['jumlah'] ?> tagihan &middot; <?= (int) $ringkas['lunas'] ?> lunas</small></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Terbayar bulan ini</span><b><?= rp($ringkas['dibayar']) ?></b><small><?= round($persen) ?>% dari tagihan</small></div></div>
  <div class="kpi"><div class="chip c-err"><?= icon('alert', 22) ?></div><div><span>Total tunggakan</span><b><?= rp($totalTung) ?></b><small><?= count($tung) ?> santri menunggak</small></div></div>
  <?php endif; ?>
  <?php if ($pend > 0): ?>
  <a class="kpi" href="?p=pendaftar" style="text-decoration:none;color:inherit"><div class="chip c-acc"><?= icon('user-plus', 22) ?></div><div><span>Pendaftar baru</span><b><?= $pend ?></b><small>menunggu verifikasi</small></div></a>
  <?php endif; ?>
</div>

<?php if ($perBulan): ?>
<div class="grid g-main" style="margin-bottom:16px">
  <div class="card">
    <h2>Penerimaan 6 bulan terakhir</h2>
    <?= Charts::bars($bulanLabel, 'Penerimaan SPP per bulan') ?>
  </div>
  <div class="card">
    <h2>Kolektibilitas bulan ini</h2>
    <?= Charts::donut($persen, 'terbayar') ?>
    <div class="legend"><span><?= rp($ringkas['dibayar'] ?? 0) ?> dari <?= rp($ringkas['ditagih'] ?? 0) ?></span></div>
  </div>
</div>
<?php endif; ?>

<div class="grid g2" style="margin-bottom:16px">
  <div class="card">
    <h2>Santri aktif per jenjang</h2>
    <div class="scroll"><table>
      <thead><tr><th>Unit</th><?php foreach (Domain::JENJANG as $j): ?><th class="r"><?= e($j) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (($scope ? [$scope => Domain::UNIT[$scope]] : Domain::UNIT) as $uk => $ul): ?>
        <tr><td><b><?= e($ul) ?></b></td><?php foreach (Domain::JENJANG as $j): ?><td class="r"><?= (int) ($rekap[$uk][$j] ?? 0) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php if ($perJenjang): ?>
  <div class="card">
    <h2>Tunggakan per jenjang</h2>
    <?= Charts::hbars($perJenjang, 'rp_short') ?>
  </div>
  <?php else: ?>
  <div class="card">
    <h2>Akses cepat</h2>
    <div class="quick">
      <?php if (Auth::can('santri.view')): ?><a href="?p=santri"><?= icon('users', 22) ?>Data santri</a><?php endif; ?>
      <?php if (Auth::can('pendaftar')): ?><a href="?p=pendaftar"><?= icon('user-plus', 22) ?>Pendaftar</a><?php endif; ?>
      <?php if (Auth::can('naik')): ?><a href="?p=naik"><?= icon('trending-up', 22) ?>Naik kelas</a><?php endif; ?>
      <?php if (Auth::can('impor')): ?><a href="?p=impor"><?= icon('upload', 22) ?>Impor data</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($tung || $terbaru): ?>
<div class="grid g2">
  <?php if ($tung): ?>
  <div class="card">
    <h2>Tunggakan terbesar <a class="btn sm ghost" href="?p=laporan&amp;jenis=tunggakan">Semua</a></h2>
    <ul class="list">
      <?php foreach (array_slice($tung, 0, 5) as $r): ?>
        <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?> &middot; <?= (int) $r['bulan'] ?> tagihan</small></div><b><?= rp($r['total']) ?></b></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if ($terbaru): ?>
  <div class="card">
    <h2>Pembayaran terbaru</h2>
    <ul class="list">
      <?php foreach ($terbaru as $r): ?>
        <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><a href="?p=kuitansi&amp;id=<?= (int) $r['id'] ?>"><?= e($r['no_kuitansi']) ?></a> &middot; <?= e($r['tanggal']) ?></small></div><b style="color:var(--ok)">+ <?= rp($r['jumlah']) ?></b></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
<?php endif;
render('Dashboard', ob_get_clean());
