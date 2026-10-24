<?php
Auth::require('izin.view');
$scope = Auth::scope();
$edit = Auth::can('izin.edit');
$tabs = ['menunggu' => 'Menunggu', 'aktif' => 'Sedang berlaku', 'terlambat' => 'Terlambat kembali', 'riwayat' => 'Riwayat'];
$tab = isset($tabs[in_str('tab')]) ? in_str('tab') : 'menunggu';
if (is_post() && $edit) {
    csrf_check();
    $id = (int) in_str('id');
    try {
        switch (in_str('aksi')) {
            case 'baru':
                Izin::ajukan((int) in_str('santri_id'), in_str('jenis'), in_str('mulai'), in_str('selesai'), in_str('alasan'), 'petugas', Auth::id(), $scope);
                flash('Izin dicatat dan langsung disetujui.'); break;
            case 'setujui': Izin::setujui($id, in_str('catatan'), Auth::id(), $scope); flash('Izin disetujui.'); break;
            case 'tolak': Izin::tolak($id, in_str('catatan'), Auth::id(), $scope); flash('Izin ditolak.', 'warn'); break;
            case 'keluar': Izin::keluar($id, Auth::id(), $scope); flash('Santri tercatat keluar.'); break;
            case 'kembali': $t = Izin::kembali($id, Auth::id(), $scope); flash($t ? 'Santri kembali TERLAMBAT; tercatat.' : 'Santri kembali tepat waktu.', $t ? 'warn' : 'ok'); break;
            case 'batal': Izin::batal($id, Auth::id(), $scope); flash('Izin dibatalkan.', 'warn'); break;
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=izin&tab=' . $tab);
}
$rows = Izin::daftar($tab, $scope);
$n = []; foreach (array_keys($tabs) as $k) { $n[$k] = $k === 'riwayat' ? 0 : Izin::hitung($k, $scope); }
$opsi = [];
if ($edit) { [$semua] = Santri::daftar(['status' => 'aktif'], $scope, 1, true); foreach ($semua as $s) { $opsi[$s['id']] = $s['nama'] . ' · ' . $s['jenjang'] . ' ' . $s['kelas'] . ' · ' . Domain::UNIT[$s['unit']]; } }
$fmt = static fn(string $d): string => (int) substr($d, 8, 2) . ' ' . substr(Domain::BULAN[(int) substr($d, 5, 2)], 0, 3) . ' ' . substr($d, 11, 5);
ob_start(); ?>
<div class="page-h"><div><h1>Perizinan Santri</h1><p>Wali mengajukan dari portal → pembina menyetujui → santri keluar → kembali (keterlambatan tercatat).</p></div></div>
<div class="tabs"><?php foreach ($tabs as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?p=izin&amp;tab=<?= $k ?>"><?= e($l) ?><?= $n[$k] ? ' (' . $n[$k] . ')' : '' ?></a><?php endforeach; ?></div>
<div class="grid g3 izin-grid">
<?php foreach ($rows as $r):
    $wa = wa_link($r['hp_wali'], "Assalamu'alaikum {$r['nama_wali']}, terkait izin " . Izin::JENIS[$r['jenis']] . " ananda {$r['nama']} ({$fmt($r['mulai'])} s.d. {$fmt($r['selesai'])}): ");
    $telat = $r['status'] === 'keluar' && $r['selesai'] < date('Y-m-d H:i:s'); ?>
  <div class="card">
    <div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['jenjang'] . ' ' . $r['kelas']) ?> · <?= e(Domain::UNIT[$r['unit']]) ?></small></span>
      <span style="margin-left:auto" class="badge iz-<?= e($r['status']) ?><?= $telat ? ' b-belum' : '' ?>"><?= $telat ? 'Terlambat' : e(Izin::STATUS[$r['status']]) ?></span></div>
    <dl class="kv" style="margin-top:12px"><dt>Jenis</dt><dd><b><?= e(Izin::JENIS[$r['jenis']]) ?></b></dd><dt>Keluar</dt><dd><?= e($fmt($r['mulai'])) ?></dd><dt>Kembali</dt><dd><?= e($fmt($r['selesai'])) ?></dd>
      <dt>Alasan</dt><dd><?= e($r['alasan']) ?></dd><dt>Diajukan</dt><dd><?= $r['pengaju'] === 'wali' ? e($r['nama_wali']) . ' (wali) · portal' : 'petugas' ?></dd>
      <?php if ($r['catatan']): ?><dt>Catatan</dt><dd><?= e($r['catatan']) ?></dd><?php endif; ?>
      <?php if ($r['kembali_at']): ?><dt>Kembali pada</dt><dd><?= e($fmt($r['kembali_at'])) ?><?= $r['terlambat'] ? ' <span class="badge b-belum">terlambat</span>' : '' ?></dd><?php endif; ?></dl>
    <?php if ($edit): ?><div class="acts" style="margin-top:12px">
      <?php if ($r['status'] === 'menunggu'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aksi" value="setujui"><button class="btn sm"><?= icon('check', 15) ?> Setujui</button></form>
        <form method="post" class="inline izin-tolak"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aksi" value="tolak"><input name="catatan" placeholder="Alasan tolak" required style="width:130px;padding:5px 8px"><button class="btn sm danger">Tolak</button></form>
      <?php elseif ($r['status'] === 'disetujui'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aksi" value="keluar"><button class="btn sm">Santri keluar</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aksi" value="batal"><button class="btn sm ghost" data-confirm="Batalkan izin?">Batalkan</button></form>
      <?php elseif ($r['status'] === 'keluar'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aksi" value="kembali"><button class="btn sm"><?= icon('check', 15) ?> Sudah kembali</button></form>
      <?php endif; ?>
      <?php if ($wa && in_array($r['status'], ['menunggu', 'disetujui', 'keluar'], true)): ?><a class="btn sm ghost" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message', 15) ?> Hubungi wali</a><?php endif; ?>
    </div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="card"><div class="empty">Tidak ada izin di tab ini.</div></div><?php endif; ?>
<?php if ($edit): ?>
<form method="post" class="card form" autocomplete="off"><h2>Catat izin (oleh petugas)</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="baru">
  <fieldset><label>Santri <select name="santri_id" required><option value="">Pilih santri...</option><?= options(array_map('strval', $opsi)) ?></select></label>
    <label>Jenis <select name="jenis"><?= options(Izin::JENIS) ?></select></label>
    <label>Keluar <input type="datetime-local" name="mulai" required value="<?= date('Y-m-d\TH:00', time() + 3600) ?>"></label>
    <label>Kembali <input type="datetime-local" name="selesai" required value="<?= date('Y-m-d\T17:00', time() + 86400) ?>"></label>
    <label class="full">Alasan <input name="alasan" required minlength="5" placeholder="Berobat ke puskesmas, dijemput ayah"></label></fieldset>
  <button class="btn"><?= icon('plus') ?> Simpan izin</button></form>
<?php endif; ?>
<?php render('Perizinan', ob_get_clean());
