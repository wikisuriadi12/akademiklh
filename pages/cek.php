<?php
// Portal wali (publik): cek tagihan dengan NIS + 4 digit terakhir HP wali.
$err = '';
$vaErr = '';
$izErr = '';
$izOk = false;
$s = null;
if (is_post()) {
    csrf_check();
    $nis = in_str('nis');
    $last4 = in_str('hp4');
    $boleh = RateLimit::hit('cek-ip', RateLimit::ip(), 20, 600) && RateLimit::hit('cek-nis', strtolower($nis), 8, 3600);
    if (!$boleh) {
        $err = 'Terlalu banyak percobaan. Silakan coba lagi nanti atau hubungi admin.';
    } else {
        $s = Santri::verifikasiWali($nis, $last4);
        if (!$s) { $err = 'Data tidak cocok. Periksa NIS dan 4 digit terakhir nomor HP wali yang terdaftar.'; }
        elseif (in_str('aksi') === 'izin' && Settings::get('izin_wali') === '1') {
            if (!RateLimit::hit('izin-wali', (string) $s['id'], 5, 86400)) {
                $izErr = 'Terlalu banyak pengajuan hari ini. Hubungi pembina.';
            } else {
                try { Izin::ajukan((int) $s['id'], in_str('jenis'), in_str('mulai'), in_str('selesai'), in_str('alasan'), 'wali', null, null); $izOk = true; }
                catch (InvalidArgumentException $ex) { $izErr = $ex->getMessage(); }
            }
        }
        elseif (in_str('aksi') === 'va' && Settings::get('va_aktif') === '1') {
            if (!RateLimit::hit('va-wali', (string) $s['id'], 5, 3600)) {
                $vaErr = 'Terlalu sering membuat VA. Coba lagi nanti.';
            } elseif (!Va::aktifUntuk((int) $s['id'])) {
                try { Va::buat((int) $s['id'], [], null, null); } catch (InvalidArgumentException $ex) { $vaErr = $ex->getMessage(); }
            }
        }
    }
}
$cfgS = Settings::all();
ob_start(); ?>
<div class="page-h"><div><h1>Cek Tagihan Santri</h1><p>Untuk wali santri. Masukkan NIS dan 4 digit terakhir nomor HP wali yang terdaftar.</p></div></div>
<?php if (!$s): ?>
<form method="post" class="card" style="max-width:520px" autocomplete="off">
  <?= csrf_field() ?>
  <?php if ($err): ?><div class="note err"><?= e($err) ?></div><?php endif; ?>
  <label>NIS santri <input name="nis" required inputmode="numeric" placeholder="mis. 26120001" value="<?= e(in_str('nis')) ?>"></label>
  <label>4 digit terakhir HP wali <input name="hp4" required inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="mis. 6608"></label>
  <button class="btn"><?= icon('search') ?> Lihat tagihan</button>
  <p class="mut" style="font-size:.85rem;margin-bottom:0">NIS tertera pada kartu/berkas santri. Bila lupa, hubungi admin <?= $cfgS['telepon'] ? '(' . e($cfgS['telepon']) . ')' : '' ?>.</p>
</form>
<?php else:
    $tagihan = Spp::tagihanSantri((int) $s['id']);
    $sisa = 0;
    foreach ($tagihan as $t) { $sisa += max(0, (int) $t['nominal'] - (int) $t['dibayar']); }
    $waAdmin = wa_link($cfgS['wa_admin'], "Assalamu'alaikum, saya wali dari {$s['nama']} (NIS {$s['nis']}). Saya ingin konfirmasi pembayaran.");
    ?>
<div class="card">
  <div class="who"><?= avatar($s['nama'], $s['unit']) ?><div><b style="font-size:1.1rem"><?= e($s['nama']) ?></b><br><small><?= e($s['nis']) ?> &middot; <?= e($s['jenjang']) ?><?= $s['kelas'] ? ' / ' . e($s['kelas']) : '' ?> &middot; <?= e(Domain::UNIT[$s['unit']]) ?></small></div>
    <div style="margin-left:auto;text-align:right"><small>Sisa tagihan</small><br><b class="big" style="color:<?= $sisa > 0 ? 'var(--err)' : 'var(--ok)' ?>"><?= rp($sisa) ?></b></div></div>
</div>
<div class="card">
  <h2>Rincian tagihan</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Tagihan</th><th class="r">Nominal</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($tagihan, 0, 24) as $t): ?>
      <tr><td data-label="Tagihan"><b><?= e(Spp::label($t)) ?></b></td><td class="r" data-label="Nominal"><?= rp($t['nominal']) ?></td><td class="r" data-label="Dibayar"><?= rp($t['dibayar']) ?></td><td class="r" data-label="Sisa"><?= rp($t['nominal'] - $t['dibayar']) ?></td><td data-label="Status"><?= status_tagihan((int) $t['nominal'], (int) $t['dibayar']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$tagihan): ?><tr><td colspan="5"><div class="empty">Belum ada tagihan.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php $th = Tahfidz::rekapSantri((int) $s['id']); $kh = Kehadiran::rekapSantri((int) $s['id'], date('Y-m')); ?>
<div class="card"><h2>Perkembangan ananda</h2>
  <div class="va-meta"><div><small>Total hafalan</small><br><b><?= str_replace('.', ',', (string) $th['juz']) ?> juz</b></div>
    <div><small>Setoran bulan ini</small><br><b><?= $th['bulan_ini'] ?> kali</b></div>
    <div><small>Kehadiran <?= e(Domain::BULAN[(int) date('n')]) ?></small><br><b><?= $kh['persen'] === null ? '-' : $kh['persen'] . '%' ?></b> <small class="mut">izin <?= $kh['I'] ?> · sakit <?= $kh['S'] ?> · alpa <?= $kh['A'] ?></small></div></div>
  <?php if ($th['terakhir']): $t = $th['terakhir']; ?><p class="mut" style="margin:12px 0 0;font-size:.9rem">Setoran terakhir: <?= e(Tahfidz::JENIS[$t['jenis']]) ?> · Juz <?= (int) $t['juz'] ?><?= $t['surat'] ? ' ' . e($t['surat']) : '' ?> <?= e($t['ayat']) ?> · nilai <b><?= e($t['grade']) ?></b> · <?= e($t['tanggal']) ?></p><?php endif; ?>
  <?php foreach (array_slice($th['per_juz'], -3, 3, true) as $j => $hal): $pc = (int) round($hal / Tahfidz::HAL_PER_JUZ * 100); ?>
    <div style="margin:10px 0 0"><div style="display:flex;justify-content:space-between"><span>Juz <?= $j ?></span><b><?= $pc ?>%</b></div><div class="bar-bg"><div style="width:<?= $pc ?>%"></div></div></div>
  <?php endforeach; ?>
</div>
<?php if (Settings::get('izin_wali') === '1'): $izl = Izin::untukSantri((int) $s['id'], 5); ?>
<div class="card" id="izin"><h2>Izin santri</h2>
  <?php if ($izOk): ?><div class="note">Pengajuan izin terkirim. Pembina akan meninjau; status tampil di bawah.</div><?php endif; ?>
  <?php if ($izErr): ?><div class="note err"><?= e($izErr) ?></div><?php endif; ?>
  <?php if ($izl): ?><ul class="list" style="margin-bottom:12px"><?php foreach ($izl as $z): ?>
    <li><div class="sp"><b><?= e(Izin::JENIS[$z['jenis']]) ?></b> · <?= e(substr($z['mulai'], 0, 16)) ?> s.d. <?= e(substr($z['selesai'], 0, 16)) ?><br><small><?= e($z['alasan']) ?><?= $z['catatan'] ? ' · catatan: ' . e($z['catatan']) : '' ?></small></div><span class="badge iz-<?= e($z['status']) ?>"><?= e(Izin::STATUS[$z['status']]) ?></span></li>
  <?php endforeach; ?></ul><?php endif; ?>
  <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="nis" value="<?= e($s['nis']) ?>"><input type="hidden" name="hp4" value="<?= e(in_str('hp4')) ?>"><input type="hidden" name="aksi" value="izin">
    <fieldset style="border:0;padding:0;margin:0"><label>Jenis <select name="jenis"><?= options(Izin::JENIS) ?></select></label>
      <label>Keluar (jemput) <input type="datetime-local" name="mulai" required></label>
      <label>Kembali ke pondok <input type="datetime-local" name="selesai" required></label>
      <label class="full">Alasan <input name="alasan" required minlength="5" placeholder="mis. acara keluarga, berobat"></label></fieldset>
    <button class="btn"><?= icon('clipboard') ?> Ajukan izin</button></form>
</div>
<?php endif; ?>
<?php if (Settings::get('va_aktif') === '1' && Bni::siap() && $sisa > 0 || ($vaAktif = Va::aktifUntuk((int) $s['id']))): $vaAktif = $vaAktif ?? Va::aktifUntuk((int) $s['id']); ?>
<div class="card va-box">
  <h2>Bayar lewat Virtual Account BNI</h2>
  <?php if ($vaErr): ?><div class="note err"><?= e($vaErr) ?></div><?php endif; ?>
  <?php if ($vaAktif): ?>
    <small>Nomor Virtual Account BNI</small>
    <div class="va-no"><?= e($vaAktif['virtual_account']) ?></div>
    <div class="va-meta"><div><small>Jumlah yang harus dibayar (pas)</small><br><b><?= rp($vaAktif['jumlah']) ?></b></div><div><small>Berlaku sampai</small><br><b><?= e(substr($vaAktif['expired_at'], 0, 16)) ?></b></div></div>
    <p class="mut" style="font-size:.88rem">Bayar lewat ATM BNI, BNI Mobile, atau transfer bank lain (menu Virtual Account). Tagihan otomatis lunas setelah pembayaran diterima; halaman ini diperbarui saat dibuka kembali.</p>
  <?php else: ?>
    <p style="margin-top:0">Buat nomor VA khusus untuk membayar seluruh tagihan di atas (<b><?= rp($sisa) ?></b>) tanpa perlu konfirmasi manual.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="nis" value="<?= e($s['nis']) ?>"><input type="hidden" name="hp4" value="<?= e(in_str('hp4')) ?>"><input type="hidden" name="aksi" value="va">
      <button class="btn"><?= icon('wallet') ?> Buat nomor VA BNI</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (trim($cfgS['rekening']) !== '' || $waAdmin): ?>
<div class="card">
  <h2>Cara pembayaran</h2>
  <?php if (trim($cfgS['rekening']) !== ''): ?><p style="white-space:pre-line;margin-top:0"><?= e($cfgS['rekening']) ?></p><?php endif; ?>
  <p class="mut" style="font-size:.88rem">Pembayaran tunai dapat dilakukan di kantor bendahara. Setelah transfer, mohon konfirmasi agar segera dicatat.</p>
  <?php if ($waAdmin): ?><a class="btn wa" href="<?= e($waAdmin) ?>" target="_blank" rel="noopener"><?= icon('message') ?> Konfirmasi via WhatsApp</a><?php endif; ?>
</div>
<?php endif; ?>
<p><a href="?p=cek">&laquo; Cek santri lain</a></p>
<?php endif;
render('Cek Tagihan', ob_get_clean(), true);
