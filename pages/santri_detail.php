<?php
Auth::require('santri.view');
$id = (int) in_str('id', '0');
$s = $id ? Santri::find($id) : null;
if (!$s) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Santri tidak ditemukan.</div></div>'); exit; }
Auth::assertUnit($s['unit']);
$keu = Auth::can('tagihan.view');
$tagihan = $keu ? Spp::tagihanSantri($id) : [];
$riwayat = $keu ? Spp::riwayatSantri($id, 15) : [];
$totNom = 0; $totBayar = 0;
foreach ($tagihan as $t) { $totNom += (int) $t['nominal']; $totBayar += (int) $t['dibayar']; }
$sisa = $totNom - $totBayar;
$pf = Auth::can('santri.profil') ? Santri::profil($id) : null;
$lembaga = Settings::get('nama_lembaga');
$wa = wa_link($s['hp_wali'], "Assalamu'alaikum {$s['nama_wali']}, kami dari $lembaga" . ($sisa > 0 ? '. Kami mengingatkan sisa pembayaran ananda ' . $s['nama'] . ' sebesar ' . rp($sisa) . '. Mohon konfirmasinya. Jazakumullahu khairan.' : ' terkait ananda ' . $s['nama'] . '.'));
ob_start(); ?>
<div class="page-h">
  <div class="who"><?= avatar($s['nama'], $s['unit'], $s['foto'] ?? null, $id) ?><div><h1><?= e($s['nama']) ?></h1><p><?= e($s['nis']) ?> &middot; <?= e($s['jenjang']) ?><?= $s['kelas'] ? ' / ' . e($s['kelas']) : '' ?> &middot; <?= e(Domain::UNIT[$s['unit']]) ?> &middot; <?= badge($s['status']) ?></p></div></div>
  <div class="acts">
    <?php if (Auth::can('bayar')): ?><a class="btn" href="?p=bayar&amp;santri=<?= $id ?>"><?= icon('credit-card') ?> Bayar</a><?php endif; ?>
    <a class="btn ghost" href="?p=riwayat&amp;id=<?= $id ?>"><?= icon('clipboard') ?> Riwayat</a>
    <?php if (Auth::can('santri.edit')): ?><a class="btn ghost" href="?p=santri_form&amp;id=<?= $id ?>"><?= icon('edit') ?> Ubah</a><?php endif; ?>
    <?php if ($wa): ?><a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?> WhatsApp wali</a><?php endif; ?>
  </div>
</div>

<?php if ($keu): ?>
<div class="kpis">
  <div class="kpi"><div class="chip c-info"><?= icon('file-text', 22) ?></div><div><span>Total tagihan</span><b><?= rp($totNom) ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Sudah dibayar</span><b><?= rp($totBayar) ?></b></div></div>
  <div class="kpi"><div class="chip <?= $sisa > 0 ? 'c-err' : 'c-ok' ?>"><?= icon($sisa > 0 ? 'alert' : 'check', 22) ?></div><div><span>Sisa tagihan</span><b><?= rp($sisa) ?></b></div></div>
</div>
<?php endif; ?>

<div class="grid g2" style="margin-bottom:16px">
  <div class="card">
    <h2>Biodata</h2>
    <dl class="kv">
      <dt>Jenis kelamin</dt><dd><?= $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></dd>
      <dt>Tempat, tgl. lahir</dt><dd><?= e(trim($s['tempat_lahir'] . ', ' . $s['tanggal_lahir'], ', ')) ?: '-' ?></dd>
      <dt>Tahun masuk</dt><dd><?= e($s['tahun_masuk']) ?></dd>
      <dt>Asal sekolah</dt><dd><?= e($s['asal_sekolah'] ?: '-') ?></dd>
      <dt>Alamat</dt><dd><?= e($s['alamat'] ?: '-') ?></dd>
      <dt>Potongan SPP</dt><dd><?= (int) $s['potongan_spp'] > 0 ? rp($s['potongan_spp']) . ' / bulan' : '-' ?></dd>
      <?php if ($s['catatan']): ?><dt>Catatan</dt><dd><?= e($s['catatan']) ?></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card">
    <h2>Wali santri</h2>
    <dl class="kv">
      <dt>Nama</dt><dd><?= e($s['nama_wali']) ?><?= $s['hubungan_wali'] ? ' (' . e($s['hubungan_wali']) . ')' : '' ?></dd>
      <dt>No. HP</dt><dd><?= e($s['hp_wali'] ?: '-') ?></dd>
      <dt>Pendaftaran</dt><dd><?= $s['sumber'] === 'online' ? 'Online' : ($s['sumber'] === 'impor' ? 'Impor data' : 'Input petugas') ?></dd>
    </dl>
  </div>
</div>

<?php if (Auth::can('santri.profil')): $pfv = static fn(string $k) => e((string) ($pf[$k] ?? '')) ?: '-'; ?>
<div class="grid g2" style="margin-bottom:16px">
  <div class="card">
    <h2>Identitas</h2>
    <dl class="kv">
      <dt>NISN</dt><dd><?= $pfv('nisn') ?></dd><dt>NIS (buku induk)</dt><dd><?= $pfv('nis_internal') ?></dd>
      <dt>NIK</dt><dd><?= $pfv('nik') ?></dd><dt>No. KK</dt><dd><?= $pfv('no_kk') ?></dd>
      <dt>Tanggal masuk</dt><dd><?= $pfv('tgl_masuk') ?></dd><dt>VA lama</dt><dd><?= $pfv('va_lama') ?></dd>
      <dt>NPSN asal sekolah</dt><dd><?= $pfv('npsn_asal') ?></dd><dt>Alamat asal sekolah</dt><dd><?= $pfv('alamat_asal') ?></dd>
      <dt>Saudara / anak ke</dt><dd><?= $pfv('jumlah_saudara') ?> / <?= $pfv('anak_ke') ?></dd>
      <dt>Tahun ajaran</dt><dd><?= $pfv('tahun_ajaran') ?></dd><dt>Usia masuk</dt><dd><?= $pfv('usia_masuk') ?></dd><dt>Keterangan</dt><dd><?= $pfv('keterangan') ?></dd>
    </dl>
  </div>
  <div class="card">
    <h2>Orang tua</h2>
    <dl class="kv">
      <dt>Ayah</dt><dd><?= $pfv('ayah_nama') ?></dd><dt>NIK ayah</dt><dd><?= $pfv('ayah_nik') ?></dd>
      <dt>Pendidikan / pekerjaan</dt><dd><?= $pfv('ayah_pendidikan') ?> / <?= $pfv('ayah_pekerjaan') ?></dd>
      <dt>Penghasilan</dt><dd><?= $pfv('ayah_penghasilan') ?></dd><dt>HP/WA ayah</dt><dd><?= $pfv('hp_ayah') ?></dd>
      <dt>Ibu</dt><dd><?= $pfv('ibu_nama') ?></dd><dt>NIK ibu</dt><dd><?= $pfv('ibu_nik') ?></dd>
      <dt>Pendidikan / pekerjaan</dt><dd><?= $pfv('ibu_pendidikan') ?> / <?= $pfv('ibu_pekerjaan') ?></dd>
      <dt>Penghasilan</dt><dd><?= $pfv('ibu_penghasilan') ?></dd><dt>HP/WA ibu</dt><dd><?= $pfv('hp_ibu') ?></dd>
    </dl>
  </div>
</div>
<?php endif; ?>

<?php if ($keu): ?>
<div class="card">
  <h2>Tagihan</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Tagihan</th><th class="r">Nominal</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($tagihan, 0, 24) as $t): ?>
      <tr><td data-label="Tagihan"><b><?= e(Spp::label($t)) ?></b></td><td class="r" data-label="Nominal"><?= rp($t['nominal']) ?></td><td class="r" data-label="Dibayar"><?= rp($t['dibayar']) ?></td><td class="r" data-label="Sisa"><?= rp($t['nominal'] - $t['dibayar']) ?></td><td data-label="Status"><?= status_tagihan((int) $t['nominal'], (int) $t['dibayar']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$tagihan): ?><tr><td colspan="5"><div class="empty"><?= icon('file-text') ?><div>Belum ada tagihan.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="card">
  <h2>Riwayat pembayaran</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Kuitansi</th><th>Tanggal</th><th>Untuk</th><th class="r">Jumlah</th><th>Metode</th></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $r): ?>
      <tr class="<?= $r['dibatalkan'] ? 'void' : '' ?>"><td data-label="Kuitansi"><a href="?p=kuitansi&amp;id=<?= (int) $r['id'] ?>"><?= e($r['no_kuitansi']) ?></a></td><td data-label="Tanggal"><?= e($r['tanggal']) ?></td>
        <td data-label="Untuk"><?= e(Spp::label($r)) ?></td><td class="r" data-label="Jumlah"><?= rp($r['jumlah']) ?></td><td data-label="Metode"><?= e($r['metode']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$riwayat): ?><tr><td colspan="5"><div class="empty">Belum ada pembayaran.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif;
$__th = Tahfidz::rekapSantri($id); $__kh = Kehadiran::rekapSantri($id, date('Y-m')); $__iz = Izin::untukSantri($id, 3); ?>
<div class="grid g3" style="margin-top:16px">
  <div class="card"><h2>Tahfidz</h2><b style="font-size:1.4rem"><?= str_replace('.', ',', (string) $__th['juz']) ?> juz</b><br><small class="mut"><?= $__th['bulan_ini'] ?> setoran bulan ini<?= $__th['rata'] !== null ? ' · rata nilai ' . $__th['rata'] : '' ?></small><?php if (Auth::can('tahfidz.view')): ?><br><a href="?p=tahfidz&amp;santri=<?= $id ?>">Lihat rincian</a><?php endif; ?></div>
  <div class="card"><h2>Kehadiran bulan ini</h2><b style="font-size:1.4rem"><?= $__kh['persen'] === null ? '-' : $__kh['persen'] . '%' ?></b><br><small class="mut">hadir <?= $__kh['H'] ?> · izin <?= $__kh['I'] ?> · sakit <?= $__kh['S'] ?> · alpa <?= $__kh['A'] ?></small></div>
  <div class="card"><h2>Izin terakhir</h2><?php foreach ($__iz as $z): ?><div style="margin-bottom:6px"><span class="badge iz-<?= e($z['status']) ?>"><?= e(Izin::STATUS[$z['status']]) ?></span> <?= e(Izin::JENIS[$z['jenis']]) ?> · <?= e(substr($z['mulai'], 0, 10)) ?></div><?php endforeach; ?><?php if (!$__iz): ?><span class="mut">Belum ada izin.</span><?php endif; ?></div>
</div>
<?php render($s['nama'], ob_get_clean());
