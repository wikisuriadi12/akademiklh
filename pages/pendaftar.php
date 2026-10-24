<?php
Auth::require('pendaftar');
$scope = Auth::scope();

if (is_post()) {
    csrf_check();
    $sid = (int) in_str('santri');
    try {
        if (in_str('aksi') === 'terima') {
            Santri::terima($sid, $scope, Auth::id());
            $extra = '';
            $biaya = (int) preg_replace('/\D/', '', in_str('biaya'));
            if (in_str('buat_tagihan') === '1' && $biaya > 0) {
                Spp::buatTagihanLain(Spp::jenisId('PENDAFTARAN'), date('Y-m'), $biaya, 'Pendaftaran ' . date('Y'), ['santri_id' => $sid], $scope, Auth::id());
                $extra = ' Tagihan uang pendaftaran ' . rp($biaya) . ' dibuat.';
            }
            flash('Calon santri diterima dan kini berstatus aktif.' . $extra);
        } elseif (in_str('aksi') === 'tolak') {
            Santri::tolak($sid, in_str('alasan'), $scope, Auth::id());
            flash('Pendaftaran ditolak.', 'warn');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=pendaftar');
}
[$rows] = Santri::daftar(['status' => 'calon'], $scope, 1, true);
$biayaDefault = (int) Settings::get('biaya_pendaftaran', '0');
ob_start(); ?>
<div class="page-h">
  <div><h1>Pendaftar Baru</h1><p><?= count($rows) ?> calon santri menunggu verifikasi</p></div>
  <div class="acts">
    <?php if (Settings::get('pendaftaran_buka') === '1'): ?><a class="btn ghost" href="<?= e(Host::url('wali', 'p=daftar')) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> Formulir publik</a><?php endif; ?>
    <?php if (Auth::can('santri.edit')): ?><a class="btn" href="?p=santri_form"><?= icon('plus') ?> Tambah manual</a><?php endif; ?>
  </div>
</div>
<?php if (!$rows): ?>
  <div class="card"><div class="empty"><?= icon('user-check') ?><div>Tidak ada pendaftar yang menunggu. Bagikan tautan formulir pendaftaran kepada calon wali santri.</div></div></div>
<?php endif; ?>
<?php foreach ($rows as $r): $wa = wa_link($r['hp_wali'], "Assalamu'alaikum {$r['nama_wali']}, kami dari " . Settings::get('nama_lembaga') . ". Pendaftaran ananda {$r['nama']} telah kami terima dan sedang diverifikasi."); ?>
<div class="card">
  <div class="who" style="margin-bottom:12px"><?= avatar($r['nama'], $r['unit']) ?><div><b style="font-size:1.05rem"><?= e($r['nama']) ?></b><br><small>No. pendaftaran <?= e($r['nis']) ?> &middot; <?= e(substr((string) $r['created_at'], 0, 10)) ?> &middot; <?= $r['sumber'] === 'online' ? 'daftar online' : 'input petugas' ?></small></div></div>
  <div class="grid g3" style="margin-bottom:14px">
    <dl class="kv" style="grid-template-columns:110px 1fr"><dt>Jenjang</dt><dd><?= e($r['jenjang']) ?> &middot; <?= e(Domain::UNIT[$r['unit']]) ?></dd><dt>Lahir</dt><dd><?= e(trim($r['tempat_lahir'] . ', ' . $r['tanggal_lahir'], ', ')) ?: '-' ?></dd></dl>
    <dl class="kv" style="grid-template-columns:110px 1fr"><dt>Asal sekolah</dt><dd><?= e($r['asal_sekolah'] ?: '-') ?></dd><dt>Alamat</dt><dd><?= e($r['alamat'] ?: '-') ?></dd></dl>
    <dl class="kv" style="grid-template-columns:110px 1fr"><dt>Wali</dt><dd><?= e($r['nama_wali']) ?></dd><dt>HP</dt><dd><?= e($r['hp_wali'] ?: '-') ?> <?php if ($wa): ?><a class="btn sm wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message', 14) ?></a><?php endif; ?></dd></dl>
  </div>
  <div class="grid g2">
    <form method="post" class="filters" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="terima"><input type="hidden" name="santri" value="<?= (int) $r['id'] ?>">
      <?php if ($biayaDefault > 0): ?>
        <label class="chk" style="margin:0"><input type="checkbox" name="buat_tagihan" value="1" checked> Buat tagihan pendaftaran</label>
        <input name="biaya" data-rupiah inputmode="numeric" value="<?= $biayaDefault ?>" size="10" aria-label="Biaya pendaftaran">
      <?php endif; ?>
      <button class="btn" data-confirm="Terima calon santri ini?"><?= icon('check') ?> Terima</button>
    </form>
    <form method="post" class="filters" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="santri" value="<?= (int) $r['id'] ?>">
      <input name="alasan" placeholder="Alasan penolakan" required>
      <button class="btn danger"><?= icon('x') ?> Tolak</button>
    </form>
  </div>
</div>
<?php endforeach;
render('Pendaftar Baru', ob_get_clean());
