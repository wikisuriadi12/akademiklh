<?php
Auth::require('users');
if (is_post()) {
    csrf_check();
    $h = Skema::terapkan();
    Db::audit(Auth::id(), 'migrasi', 'database', null, $h['ok'] . ' perintah, ' . count($h['gagal']) . ' gagal');
    flash($h['gagal'] ? 'Sebagian migrasi gagal: ' . implode(' | ', array_slice($h['gagal'], 0, 3)) : 'Migrasi selesai. Skema database sudah diperbarui.', $h['gagal'] ? 'err' : 'ok');
    redirect('?p=db_cek');
}
$hasil = Skema::cek(); $kurang = count(array_filter($hasil, static fn($r) => !$r['ok']));
ob_start(); ?>
<div class="page-h"><div><h1>Periksa Database</h1><p>Memastikan tabel dan kolom yang dibutuhkan fitur LH Mart sudah ada.</p></div></div>
<div class="card">
  <p><?= $kurang === 0 ? '<span class="badge b-lunas">Skema lengkap</span> Semua yang dibutuhkan sudah ada.' : '<span class="badge b-belum">' . $kurang . ' bagian belum ada</span> Klik tombol di bawah untuk memperbaruinya otomatis.' ?></p>
  <div class="scroll"><table class="rt"><thead><tr><th>Bagian</th><th>Status</th><th>Berkas migrasi</th></tr></thead><tbody>
  <?php foreach ($hasil as $r): ?><tr><td><?= e($r['label']) ?></td><td data-label="Status"><?= $r['ok'] ? '<span class="badge b-lunas">Ada</span>' : '<span class="badge b-belum">Belum ada</span>' ?></td><td data-label="Berkas"><small><?= e($r['file']) ?></small></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><button class="btn" data-confirm="Jalankan semua migrasi database? Aman diulang; data tidak dihapus."><?= icon('check') ?> Perbarui database sekarang</button></form>
  <p class="mut" style="margin-top:10px">Hanya menambah tabel/kolom yang belum ada. Tidak menghapus atau mengubah data. Sebaiknya cadangkan database dulu lewat phpMyAdmin (Ekspor).</p>
</div>
<?php render('Periksa Database', ob_get_clean());
