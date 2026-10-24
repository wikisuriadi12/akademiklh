<?php
Auth::require('va');
$scope = Auth::scope();
$tab = in_str('tab') === 'log' ? 'log' : 'daftar';
$st = isset(Va::STATUS[in_str('status')]) ? in_str('status') : '';
$rows = $tab === 'daftar' ? Va::daftar($st ?: null, $scope) : [];
$rk = Va::ringkas($scope);
$log = $tab === 'log' ? Va::log(80) : [];
$mode = Bni::mode();
ob_start(); ?>
<div class="page-h"><div><h1>Pembayaran Online (BNI)</h1><p>SPP dibayar lewat Virtual Account BNI; tagihan lunas otomatis saat bank memberi notifikasi.</p></div>
  <?php if (Auth::can('va.edit')): ?><a class="btn" href="?p=va_baru"><?= icon('plus') ?> Buat VA</a><?php endif; ?></div>
<?php if ($mode === 'simulasi'): ?><div class="note warn">Mode <b>simulasi</b>: tidak terhubung ke BNI. Pembayaran dapat disimulasikan dari halaman detail VA. Ubah <code>mode</code> di config.php setelah kontrak dan kredensial BNI siap.</div><?php endif; ?>
<div class="kpis">
  <div class="kpi"><div class="chip c-acc"><?= icon('wallet', 22) ?></div><div><span>Menunggu bayar</span><b><?= (int) $rk['menunggu']['n'] ?></b><small class="mut"><?= rp($rk['menunggu']['t']) ?></small></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('check', 22) ?></div><div><span>Sudah dibayar</span><b><?= (int) $rk['dibayar']['n'] ?></b><small class="mut"><?= rp($rk['dibayar']['t']) ?></small></div></div>
  <div class="kpi"><div class="chip c-err"><?= icon('alert', 22) ?></div><div><span>Kedaluwarsa</span><b><?= (int) $rk['kedaluwarsa']['n'] ?></b><small class="mut"><?= rp($rk['kedaluwarsa']['t']) ?></small></div></div>
</div>
<div class="tabs"><a class="<?= $tab === 'daftar' ? 'on' : '' ?>" href="?p=va">Daftar VA</a><a class="<?= $tab === 'log' ? 'on' : '' ?>" href="?p=va&amp;tab=log">Log notifikasi bank</a></div>
<?php if ($tab === 'daftar'): ?>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="va"><select name="status"><option value="">Semua status</option><?= options(Va::STATUS, $st) ?></select><button class="btn ghost"><?= icon('search') ?> Tampilkan</button></form>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Santri</th><th>Nomor VA</th><th class="r">Jumlah</th><th>Berlaku sampai</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="who-c"><div class="who"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?></small></span></div></td>
        <td data-label="Nomor VA"><code><?= e($r['virtual_account']) ?></code></td><td class="r" data-label="Jumlah"><?= rp($r['jumlah']) ?></td>
        <td data-label="Berlaku sampai"><?= e(substr($r['expired_at'], 0, 16)) ?></td>
        <td data-label="Status"><span class="badge vs-<?= e($r['status']) ?>"><?= e(Va::STATUS[$r['status']]) ?></span><?= (int) $r['kelebihan'] > 0 ? ' <span class="badge b-belum">Perlu dicek</span>' : '' ?></td>
        <td class="r"><a class="btn sm ghost" href="?p=va_detail&amp;id=<?= (int) $r['id'] ?>">Detail</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada VA.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php else: ?>
<div class="card"><p class="mut" style="margin-top:0">Semua notifikasi yang masuk dari bank dan permintaan keluar ke BNI dicatat di sini untuk rekonsiliasi.</p>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Waktu</th><th>Arah</th><th>Jenis</th><th>Trx</th><th>Hasil</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): $ok = in_array($l['hasil'], ['ok', 'duplikat'], true); ?>
      <tr><td><?= e($l['created_at']) ?></td><td data-label="Arah"><?= e($l['arah']) ?></td><td data-label="Jenis"><?= e($l['jenis']) ?></td><td data-label="Trx"><code><?= e((string) $l['trx_id']) ?></code></td>
        <td data-label="Hasil"><span class="badge <?= $ok ? 'b-lunas' : 'b-sebagian' ?>"><?= e($l['hasil']) ?></span></td><td data-label="IP"><?= e((string) $l['ip']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$log): ?><tr><td colspan="6" class="empty">Belum ada catatan.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render('Pembayaran Online', ob_get_clean());
