<?php
Auth::require('tahfidz.view');
$scope = Auth::scope();
$edit = Auth::can('tahfidz.edit');
$tgl = Domain::validDate(in_str('tanggal')) ? in_str('tanggal') : date('Y-m-d');
$f = ['jenjang' => in_array(in_str('jenjang'), Domain::JENJANG, true) ? in_str('jenjang') : '', 'kelas' => in_str('kelas'), 'unit' => $scope ?? (isset(Domain::UNIT[in_str('unit')]) ? in_str('unit') : '')];
$qs = http_build_query(['p' => 'tahfidz', 'tanggal' => $tgl] + $f);
if (is_post() && $edit) {
    csrf_check();
    try {
        if (in_str('aksi') === 'batal') {
            Tahfidz::batalkan((int) in_str('id'), in_str('alasan'), Auth::id(), $scope);
            flash('Setoran dibatalkan.', 'warn');
        } else {
            $id = Tahfidz::setor($_POST, Auth::id(), $scope);
            flash('Setoran tersimpan · nilai ' . Db::val('SELECT grade FROM hafalan WHERE id = ?', [$id]) . '.');
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?' . $qs . (in_str('santri_id') ? '&santri=' . (int) in_str('santri_id') : ''));
}
$rows = Tahfidz::harian($tgl, $f, $scope);
$belum = Tahfidz::belumSetor($tgl, $f, $scope);
$rk = Tahfidz::ringkas($tgl, $f, $scope);
$sid = (int) in_str('santri', '0');
$pilih = $sid ? Santri::find($sid) : null;
if ($pilih && $scope !== null && $pilih['unit'] !== $scope) { $pilih = null; }
$rekap = $pilih ? Tahfidz::rekapSantri($sid) : null;
$opsi = [];
[$semua] = Santri::daftar(['status' => 'aktif'] + array_filter($f), $scope, 1, true);
foreach ($semua as $s) { $opsi[$s['id']] = $s['nama'] . ' · ' . $s['jenjang'] . ' ' . $s['kelas'] . ' · ' . $s['nis']; }
ob_start(); ?>
<div class="page-h"><div><h1>Tahfidz · Setoran Hafalan</h1><p><?= e(Domain::namaHari($tgl)) ?> · 1 juz dihitung 20 halaman</p></div></div>
<div class="kpis">
  <div class="kpi"><div class="chip"><?= icon('users', 22) ?></div><div><span>Santri (sesuai filter)</span><b><?= $rk['santri'] ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('check', 22) ?></div><div><span>Sudah setor</span><b><?= $rk['sudah'] ?></b><small class="mut"><?= $rk['santri'] - $rk['sudah'] ?> belum</small></div></div>
  <div class="kpi"><div class="chip c-info"><?= icon('trending-up', 22) ?></div><div><span>Rata-rata hafalan</span><b><?= str_replace('.', ',', (string) $rk['rata_juz']) ?> juz</b></div></div>
</div>
<div class="card"><form method="get" class="filters"><input type="hidden" name="p" value="tahfidz">
  <input type="date" name="tanggal" value="<?= e($tgl) ?>" max="<?= date('Y-m-d') ?>">
  <?php if ($scope === null): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
  <select name="jenjang"><option value="">Semua jenjang</option><?= options(array_combine(Domain::JENJANG, Domain::JENJANG), $f['jenjang']) ?></select>
  <input name="kelas" placeholder="Kelas (mis. 3A)" value="<?= e($f['kelas']) ?>" style="width:130px">
  <button class="btn ghost"><?= icon('search') ?> Tampilkan</button></form></div>
<div class="grid g-main">
  <div>
    <div class="card"><h2>Setoran <?= $tgl === date('Y-m-d') ? 'hari ini' : e($tgl) ?></h2>
      <div class="scroll"><table class="rt"><thead><tr><th>Santri</th><th>Jenis</th><th>Materi</th><th class="r">Hlm</th><th>Nilai</th><?php if ($edit): ?><th></th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td class="who-c"><div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><a href="?<?= e($qs) ?>&amp;santri=<?= (int) $r['santri_id'] ?>"><?= e($r['nama']) ?></a></b><small><?= e($r['jenjang'] . ' ' . $r['kelas']) ?> · <?= e(Tahfidz::KELANCARAN[$r['kelancaran']]) ?></small></span></div></td>
          <td data-label="Jenis"><?= e(explode(' ', Tahfidz::JENIS[$r['jenis']])[0]) ?></td><td data-label="Materi">Juz <?= (int) $r['juz'] ?><?= $r['surat'] ? ' · ' . e($r['surat']) : '' ?><?= $r['ayat'] ? ' ' . e($r['ayat']) : '' ?></td>
          <td class="r" data-label="Hlm"><?= e(str_replace('.0', '', str_replace('.', ',', (string) (float) $r['halaman']))) ?></td>
          <td data-label="Nilai"><span class="badge <?= $r['grade'][0] === 'A' ? 'b-lunas' : ($r['grade'][0] === 'B' ? 'b-sebagian' : 'b-belum') ?>"><?= e($r['grade']) ?> · <?= (int) $r['nilai'] ?></span></td>
          <?php if ($edit): ?><td class="r"><form method="post" class="inline" data-confirm="Batalkan setoran ini?"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="alasan" value="salah input"><button class="btn sm ghost" title="Batalkan"><?= icon('x', 14) ?></button></form></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada setoran.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="card"><h2>Belum setor (<?= count($belum) ?>)</h2><div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach (array_slice($belum, 0, 60) as $b): ?><a class="badge" href="?<?= e($qs) ?>&amp;santri=<?= (int) $b['id'] ?>"><?= e($b['nama']) ?> · <?= e($b['kelas'] ?: $b['jenjang']) ?></a><?php endforeach; ?>
      <?php if (!$belum): ?><span class="mut">Semua sudah setor. Alhamdulillah.</span><?php endif; ?></div></div>
  </div>
  <div>
    <?php if ($edit): ?>
    <form method="post" class="card form" autocomplete="off"><h2>Setoran baru</h2><?= csrf_field() ?><input type="hidden" name="tanggal" value="<?= e($tgl) ?>">
      <label>Santri <select name="santri_id" required><option value="">Pilih santri...</option><?= options(array_map('strval', $opsi), (string) $sid) ?></select></label>
      <label>Jenis <select name="jenis"><?= options(Tahfidz::JENIS) ?></select></label>
      <div style="display:grid;grid-template-columns:80px 1fr 1fr;gap:10px"><label>Juz <input name="juz" inputmode="numeric" required value="<?= $rekap && $rekap['terakhir'] ? (int) $rekap['terakhir']['juz'] : 30 ?>"></label><label>Surat <input name="surat" placeholder="An-Naba"></label><label>Ayat <input name="ayat" placeholder="1-20"></label></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><label>Halaman <input name="halaman" inputmode="decimal" required placeholder="2 atau 0,5"></label><label>Nilai (0-100) <input name="nilai" inputmode="numeric" required placeholder="85"></label></div>
      <label>Kelancaran <select name="kelancaran"><?= options(Tahfidz::KELANCARAN) ?></select></label>
      <label>Catatan <input name="catatan" placeholder="Makhraj huruf ض perlu diperbaiki"></label>
      <small class="mut">Grade otomatis: A ≥ 90 · B+ ≥ 80 · B ≥ 70 · C ≥ 60 · D di bawahnya.</small>
      <button class="btn"><?= icon('check') ?> Simpan setoran</button></form>
    <?php endif; ?>
    <?php if ($pilih && $rekap): ?>
    <div class="card"><h2><?= e($pilih['nama']) ?></h2>
      <div class="va-meta" style="margin-bottom:10px"><div><small>Total hafalan</small><br><b><?= str_replace('.', ',', (string) $rekap['juz']) ?> juz</b></div><div><small>Setoran bulan ini</small><br><b><?= $rekap['bulan_ini'] ?></b></div><div><small>Rata nilai 30 hari</small><br><b><?= $rekap['rata'] ?? '-' ?></b></div></div>
      <?php foreach ($rekap['per_juz'] as $j => $hal): $pc = (int) round($hal / Tahfidz::HAL_PER_JUZ * 100); ?>
        <div style="margin:8px 0"><div style="display:flex;justify-content:space-between"><span>Juz <?= $j ?></span><b><?= $pc ?>%</b></div><div class="bar-bg"><div style="width:<?= $pc ?>%"></div></div></div>
      <?php endforeach; ?>
      <?php if (!$rekap['per_juz']): ?><div class="mut">Belum ada hafalan baru.</div><?php endif; ?>
    </div><?php endif; ?>
  </div>
</div>
<?php render('Tahfidz', ob_get_clean());
