<?php
Auth::require('hadir.view');
$scope = Auth::scope();
$edit = Auth::can('hadir.edit');
$tab = in_str('tab') === 'rekap' ? 'rekap' : 'absen';
$tgl = Domain::validDate(in_str('tanggal')) ? in_str('tanggal') : date('Y-m-d');
$sesiL = Kehadiran::sesiList();
$sesi = in_array(in_str('sesi'), $sesiL, true) ? in_str('sesi') : $sesiL[0];
$bulan = Domain::validPeriode(in_str('bulan')) ? in_str('bulan') : date('Y-m');
$f = ['jenjang' => in_array(in_str('jenjang'), Domain::JENJANG, true) ? in_str('jenjang') : '', 'kelas' => in_str('kelas'), 'unit' => $scope ?? (isset(Domain::UNIT[in_str('unit')]) ? in_str('unit') : '')];
$qs = http_build_query(['p' => 'kehadiran', 'tab' => $tab, 'tanggal' => $tgl, 'sesi' => $sesi, 'bulan' => $bulan] + $f);
if (is_post() && $edit) {
    csrf_check();
    try {
        $data = [];
        foreach ((array) ($_POST['st'] ?? []) as $sid => $st) { $data[(int) $sid] = ['status' => (string) $st, 'ket' => (string) ($_POST['ket'][$sid] ?? '')]; }
        $n = Kehadiran::simpan(in_str('tanggal'), in_str('sesi'), $data, Auth::id(), $scope);
        flash("Absensi $n santri tersimpan.");
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?' . $qs);
}
$perluFilter = $f['jenjang'] === '' && $f['kelas'] === '';
$lembar = $tab === 'absen' && !$perluFilter ? Kehadiran::lembar($tgl, $sesi, $f, $scope) : [];
$rekap = $tab === 'rekap' && !$perluFilter ? Kehadiran::rekapKelas($bulan, $f, $scope) : [];
$hit = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0, '' => 0];
foreach ($lembar as $r) { $hit[$r['h_status']] = ($hit[$r['h_status']] ?? 0) + 1; }
ob_start(); ?>
<div class="page-h"><div><h1>Kehadiran</h1><p>H = hadir · I = izin · S = sakit · A = alpa. Izin yang disetujui terisi otomatis.</p></div></div>
<div class="tabs"><a class="<?= $tab === 'absen' ? 'on' : '' ?>" href="?<?= e(http_build_query(['tab' => 'absen'] + ['p' => 'kehadiran'] + $f)) ?>">Isi absensi</a><a class="<?= $tab === 'rekap' ? 'on' : '' ?>" href="?<?= e(http_build_query(['p' => 'kehadiran', 'tab' => 'rekap'] + $f)) ?>">Rekap bulanan</a></div>
<div class="card"><form method="get" class="filters"><input type="hidden" name="p" value="kehadiran"><input type="hidden" name="tab" value="<?= $tab ?>">
  <?php if ($scope === null): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
  <select name="jenjang"><option value="">Pilih jenjang</option><?= options(array_combine(Domain::JENJANG, Domain::JENJANG), $f['jenjang']) ?></select>
  <input name="kelas" placeholder="Kelas (mis. 3A)" value="<?= e($f['kelas']) ?>" style="width:130px">
  <?php if ($tab === 'absen'): ?><select name="sesi"><?= options(array_combine($sesiL, $sesiL), $sesi) ?></select><input type="date" name="tanggal" value="<?= e($tgl) ?>" max="<?= date('Y-m-d') ?>">
  <?php else: ?><input type="month" name="bulan" value="<?= e($bulan) ?>"><?php endif; ?>
  <button class="btn ghost"><?= icon('search') ?> Tampilkan</button></form>
<?php if ($perluFilter): ?><div class="empty">Pilih jenjang atau kelas dulu.</div>
<?php elseif ($tab === 'absen'): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tanggal" value="<?= e($tgl) ?>"><input type="hidden" name="sesi" value="<?= e($sesi) ?>">
  <div class="scroll"><table class="rt"><thead><tr><th>Santri</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>
  <?php foreach ($lembar as $r): $id = (int) $r['id']; ?>
    <tr><td class="who-c"><div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['jenjang'] . ' ' . $r['kelas']) ?><?= $r['h_tersimpan'] ? '' : ($r['h_status'] ? ' · dari izin' : '') ?></small></span></div></td>
      <td data-label="Status"><div class="hadir"><?php foreach (Kehadiran::STATUS as $k => $lab): ?><label class="h-<?= $k ?>" title="<?= e($lab) ?>"><input type="radio" name="st[<?= $id ?>]" value="<?= $k ?>" <?= $r['h_status'] === $k ? 'checked' : '' ?> <?= $edit ? '' : 'disabled' ?>><span><?= $k ?></span></label><?php endforeach; ?></div></td>
      <td data-label="Keterangan"><input name="ket[<?= $id ?>]" value="<?= e($r['h_ket']) ?>" placeholder="opsional" <?= $edit ? '' : 'disabled' ?> style="width:100%"></td></tr>
  <?php endforeach; ?>
  <?php if (!$lembar): ?><tr><td colspan="3" class="empty">Tidak ada santri aktif pada filter ini.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($edit && $lembar): ?><div class="acts" style="margin-top:12px"><button type="button" class="btn ghost" data-semua-hadir>Tandai yang kosong: Hadir</button><button class="btn"><?= icon('check') ?> Simpan absensi</button></div><?php endif; ?>
  </form>
<?php else: ?>
  <div class="scroll"><table class="rt"><thead><tr><th>Santri</th><th class="r">H</th><th class="r">I</th><th class="r">S</th><th class="r">A</th><th class="r">Kehadiran</th></tr></thead><tbody>
  <?php foreach ($rekap as $r): $k = $r['rekap']; ?>
    <tr><td class="who-c"><div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['jenjang'] . ' ' . $r['kelas']) ?></small></span></div></td>
      <td class="r" data-label="Hadir"><?= $k['H'] ?></td><td class="r" data-label="Izin"><?= $k['I'] ?></td><td class="r" data-label="Sakit"><?= $k['S'] ?></td><td class="r" data-label="Alpa"><b style="color:<?= $k['A'] > 0 ? 'var(--err)' : 'inherit' ?>"><?= $k['A'] ?></b></td>
      <td class="r" data-label="Kehadiran"><?= $k['persen'] === null ? '<span class="mut">-</span>' : '<b>' . $k['persen'] . '%</b>' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<?php render('Kehadiran', ob_get_clean());
