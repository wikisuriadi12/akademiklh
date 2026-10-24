<?php
Auth::require('laporan');
$scope = Auth::scope();
$jenis = in_str('jenis', 'penerimaan') === 'tunggakan' ? 'tunggakan' : 'penerimaan';
$f = ['unit' => in_str('unit'), 'jenjang' => in_str('jenjang')];
$dari = in_str('dari', date('Y-m-01'));
$sampai = in_str('sampai', date('Y-m-d'));
if (!Domain::validDate($dari) || !Domain::validDate($sampai)) { $dari = date('Y-m-01'); $sampai = date('Y-m-d'); }
$lembaga = Settings::get('nama_lembaga');
$rekening = trim(Settings::get('rekening'));

ob_start();
if ($jenis === 'tunggakan') {
    $rows = Spp::tunggakan($scope, $f);
    if (in_str('export') === 'csv') {
        csv_out('tunggakan.csv', ['NIS', 'Nama', 'Unit', 'Jenjang', 'Kelas', 'Wali', 'HP Wali', 'Jumlah Tagihan', 'Sejak', 'Total Tunggakan'],
            array_map(static fn($r) => [$r['nis'], $r['nama'], $r['unit'], $r['jenjang'], $r['kelas'], $r['nama_wali'], $r['hp_wali'], $r['bulan'], $r['sejak'], $r['total']], $rows));
    }
    $total = array_sum(array_map(static fn($r) => (int) $r['total'], $rows));
    ?>
    <div class="kpis">
      <div class="kpi"><div class="chip c-err"><?= icon('alert', 22) ?></div><div><span>Total tunggakan</span><b><?= rp($total) ?></b></div></div>
      <div class="kpi"><div class="chip c-acc"><?= icon('users', 22) ?></div><div><span>Santri menunggak</span><b><?= count($rows) ?></b></div></div>
    </div>
    <div class="scroll"><table class="rt">
      <thead><tr><th>Santri</th><th>Wali</th><th class="r">Tagihan</th><th>Sejak</th><th class="r">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $pesan = "Assalamu'alaikum {$r['nama_wali']}, kami dari $lembaga. Mengingatkan pembayaran ananda {$r['nama']} yang belum diselesaikan sebesar " . rp($r['total']) . " ({$r['bulan']} tagihan sejak " . Domain::namaPeriode($r['sejak']) . ')'
              . ($rekening !== '' ? ". Pembayaran dapat melalui: $rekening" : '') . '. Mohon konfirmasinya. Jazakumullahu khairan.';
          $wa = wa_link($r['hp_wali'], $pesan); ?>
        <tr><td class="who-c"><a class="who" href="?p=santri_detail&amp;id=<?= (int) $r['santri_id'] ?>" style="color:inherit;text-decoration:none"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?> &middot; <?= e($r['nis']) ?></small></span></a></td>
          <td data-label="Wali"><?= e($r['nama_wali']) ?><br><small><?= e($r['hp_wali']) ?></small></td>
          <td class="r" data-label="Tagihan"><?= (int) $r['bulan'] ?></td><td data-label="Sejak"><?= e(Domain::namaPeriode($r['sejak'])) ?></td>
          <td class="r" data-label="Total"><b><?= rp($r['total']) ?></b></td>
          <td class="r noprint"><?php if ($wa): ?><a class="btn sm wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message', 15) ?> Ingatkan</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('check') ?><div>Tidak ada tunggakan. Alhamdulillah.</div></div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr class="tot"><th colspan="4">Total tunggakan (<?= count($rows) ?> santri)</th><th class="r"><?= rp($total) ?></th><th></th></tr></tfoot><?php endif; ?>
    </table></div>
    <?php
} else {
    $rows = Spp::penerimaan($dari, $sampai, $scope, $f);
    if (in_str('export') === 'csv') {
        csv_out('penerimaan.csv', ['Tanggal', 'Kuitansi', 'NIS', 'Nama', 'Unit', 'Jenjang', 'Untuk', 'Metode', 'Jumlah'],
            array_map(static fn($r) => [$r['tanggal'], $r['no_kuitansi'], $r['nis'], $r['nama'], $r['unit'], $r['jenjang'], Spp::label($r), $r['metode'], $r['jumlah']], $rows));
    }
    $total = 0; $per = []; $perJenis = [];
    foreach ($rows as $r) {
        $total += (int) $r['jumlah'];
        $k = $r['jenjang'] . ' ' . Domain::UNIT[$r['unit']];
        $per[$k] = ($per[$k] ?? 0) + (int) $r['jumlah'];
        $perJenis[$r['jenis_nama']] = ($perJenis[$r['jenis_nama']] ?? 0) + (int) $r['jumlah'];
    }
    arsort($per); arsort($perJenis);
    ?>
    <div class="kpis">
      <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Total penerimaan</span><b><?= rp($total) ?></b><small><?= count($rows) ?> pembayaran</small></div></div>
    </div>
    <?php if ($per): ?>
    <div class="grid g2" style="margin-bottom:16px">
      <div class="card flat" style="margin:0"><h3>Per jenjang &amp; unit</h3><?= Charts::hbars($per, 'rp_short') ?></div>
      <div class="card flat" style="margin:0"><h3>Per jenis biaya</h3><?= Charts::hbars($perJenis, 'rp_short') ?></div>
    </div>
    <?php endif; ?>
    <div class="scroll"><table class="rt">
      <thead><tr><th>Tanggal</th><th>Kuitansi</th><th>Santri</th><th>Untuk</th><th>Metode</th><th class="r">Jumlah</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td data-label="Tanggal"><?= e($r['tanggal']) ?></td><td data-label="Kuitansi"><a href="?p=kuitansi&amp;id=<?= (int) $r['id'] ?>"><?= e($r['no_kuitansi']) ?></a></td>
          <td data-label="Santri"><?= e($r['nama']) ?> <small><?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?></small></td>
          <td data-label="Untuk"><?= e(Spp::label($r)) ?></td><td data-label="Metode"><?= e($r['metode']) ?></td><td class="r" data-label="Jumlah"><?= rp($r['jumlah']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">Tidak ada pembayaran pada rentang ini.</div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows): ?><tfoot><tr class="tot"><th colspan="5">Total</th><th class="r"><?= rp($total) ?></th></tr></tfoot><?php endif; ?>
    </table></div>
    <?php
}
$isi = ob_get_clean();
$qe = ['p' => 'laporan', 'jenis' => $jenis, 'dari' => $dari, 'sampai' => $sampai, 'unit' => $f['unit'], 'jenjang' => $f['jenjang']];
ob_start(); ?>
<div class="page-h"><div><h1>Laporan</h1><p><?= $jenis === 'penerimaan' ? 'Penerimaan ' . e($dari) . ' s/d ' . e($sampai) : 'Tagihan yang belum lunas, semua periode' ?></p></div>
  <div class="acts noprint">
    <a class="btn ghost" href="?<?= e(http_build_query(array_filter($qe + ['export' => 'csv'], 'strlen'))) ?>"><?= icon('download') ?> Ekspor CSV</a>
    <button class="btn ghost" data-print><?= icon('printer') ?> Cetak</button>
  </div></div>
<div class="card">
  <div class="tabs"><a class="<?= $jenis === 'penerimaan' ? 'on' : '' ?>" href="?p=laporan&amp;jenis=penerimaan">Penerimaan</a><a class="<?= $jenis === 'tunggakan' ? 'on' : '' ?>" href="?p=laporan&amp;jenis=tunggakan">Tunggakan</a></div>
  <form method="get" class="filters"><input type="hidden" name="p" value="laporan"><input type="hidden" name="jenis" value="<?= e($jenis) ?>">
    <?php if ($jenis === 'penerimaan'): ?><input type="date" name="dari" value="<?= e($dari) ?>" aria-label="Dari"> <span class="mut">s/d</span> <input type="date" name="sampai" value="<?= e($sampai) ?>" aria-label="Sampai"><?php endif; ?>
    <?php if (!$scope): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
    <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, $f['jenjang'], false) ?></select>
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
  <?= $isi ?>
</div>
<?php render('Laporan', ob_get_clean());
