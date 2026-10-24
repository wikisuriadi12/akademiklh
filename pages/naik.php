<?php
Auth::require('naik');
$scope = Auth::scope();

if (is_post()) {
    csrf_check();
    try {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $n = Santri::naikkan($ids, in_str('aksi'), in_str('jenjang_tujuan'), in_str('kelas_tujuan'), $scope, Auth::id());
        flash($n . ' santri ' . (in_str('aksi') === 'lulus' ? 'diluluskan.' : 'dipindahkan ke kelas/jenjang baru.'));
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=naik&' . http_build_query(array_filter(['unit' => in_str('unit'), 'jenjang' => in_str('jenjang'), 'kelas' => in_str('kelas')], 'strlen')));
}
$f = ['unit' => in_str('unit'), 'jenjang' => in_str('jenjang'), 'kelas' => in_str('kelas'), 'status' => 'aktif'];
$ada = $f['unit'] !== '' || $f['jenjang'] !== '' || $f['kelas'] !== '';
$rows = [];
if ($ada) { [$rows] = Santri::daftar($f, $scope, 1, true); }
$kelasList = array_column(Db::all("SELECT DISTINCT kelas FROM santri WHERE status='aktif' AND kelas IS NOT NULL AND kelas <> ''" . ($scope ? ' AND unit = ?' : '') . ' ORDER BY kelas', $scope ? [$scope] : []), 'kelas');
ob_start(); ?>
<div class="page-h"><div><h1>Naik Kelas &amp; Kelulusan</h1><p>Pilih kelompok santri, centang yang akan diproses, lalu tentukan tujuannya.</p></div></div>
<div class="card">
  <form method="get" class="filters">
    <input type="hidden" name="p" value="naik">
    <?php if (!$scope): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
    <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, $f['jenjang'], false) ?></select>
    <select name="kelas"><option value="">Semua kelas</option><?= options($kelasList, $f['kelas'], false) ?></select>
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
</div>
<?php if ($ada): ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="unit" value="<?= e($f['unit']) ?>"><input type="hidden" name="jenjang" value="<?= e($f['jenjang']) ?>"><input type="hidden" name="kelas" value="<?= e($f['kelas']) ?>">
  <h2><?= count($rows) ?> santri aktif ditemukan</h2>
  <?php if ($rows): ?>
  <div class="scroll"><table>
    <thead><tr><th style="width:40px"><input type="checkbox" data-check-all=".pilih" checked aria-label="Pilih semua"></th><th>Santri</th><th>Unit</th><th>Jenjang / Kelas</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><input type="checkbox" class="pilih" name="ids[]" value="<?= (int) $r['id'] ?>" checked></td>
        <td><div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['nis']) ?></small></span></div></td>
        <td><?= e(Domain::UNIT[$r['unit']]) ?></td><td><?= e($r['jenjang']) ?><?= $r['kelas'] ? ' / ' . e($r['kelas']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card flat" style="margin:16px 0 0;background:var(--surface2)">
    <h3>Tujuan</h3>
    <div class="filters">
      <select name="jenjang_tujuan"><option value="">Jenjang tetap</option><?= options(Domain::JENJANG, '', false) ?></select>
      <input name="kelas_tujuan" placeholder="Kelas tujuan, mis. 4A" list="kelas-opsi">
      <datalist id="kelas-opsi"><?php foreach ($kelasList as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
      <button class="btn" name="aksi" value="pindah_kelas" data-confirm="Pindahkan santri yang dicentang?"><?= icon('trending-up') ?> Pindahkan</button>
      <button class="btn danger" name="aksi" value="lulus" data-confirm="Luluskan santri yang dicentang? Tagihan baru tidak akan dibuat lagi untuk mereka."><?= icon('user-check') ?> Luluskan</button>
    </div>
    <small>Tagihan yang sudah ada tidak berubah. Tarif bulan berikutnya mengikuti jenjang baru.</small>
  </div>
  <?php else: ?><div class="empty"><?= icon('users') ?><div>Tidak ada santri aktif pada pilihan ini.</div></div><?php endif; ?>
</form>
<?php endif;
render('Naik Kelas', ob_get_clean());
