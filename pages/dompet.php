<?php
Auth::require('dompet');
$scope = null;
$sid = (int) in_str('santri', '0');
$num = static fn(string $k): int => (int) preg_replace('/\D/', '', in_str($k, '0'));
if (is_post()) {
    csrf_check();
    $sid = (int) in_str('santri_id', '0');
    try {
        if (in_str('aksi') === 'koreksi') {
            if (!Auth::can('dompet.koreksi')) { throw new InvalidArgumentException('Hanya admin/bendahara yang boleh mengoreksi saldo.'); }
            $d = $num('jumlah') * (in_str('arah') === 'kurang' ? -1 : 1);
            Pos::koreksi($sid, $d, in_str('alasan'), Auth::id(), $scope);
            flash('Saldo dikoreksi dan tercatat di jejak audit.', 'warn');
        } else {
            Pos::topup($sid, $num('jumlah'), in_str('catatan'), Auth::id(), $scope);
            flash('Top up berhasil.');
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=dompet&santri=' . $sid);
}
$q = in_str('q');
$hasil = [];
if ($q !== '') { [$hasil] = Santri::daftar(['q' => $q], $scope, 1); }
$santri = $sid ? Santri::find($sid) : null;
if ($santri) { Auth::assertUnit($santri['unit']); }
$saldo = $santri ? Pos::saldo($sid) : 0;
$riwayat = $santri ? Pos::riwayatDompet($sid, 30) : [];
$daftar = $santri ? [] : Pos::daftarSaldo($scope);
ob_start(); ?>
<div class="page-h"><div><h1>Dompet Santri</h1><p>Uang saku santri yang dititipkan wali, dipakai belanja di koperasi.</p></div>
  <div class="kpi" style="padding:10px 16px"><div class="chip c-ok"><?= icon('wallet', 20) ?></div><div><span>Total saldo dititipkan</span><b style="font-size:1.15rem"><?= rp(Pos::totalSaldo($scope)) ?></b></div></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="dompet">
    <input name="q" placeholder="Cari nama santri, NIS, atau wali..." value="<?= e($q) ?>" style="flex:1;min-width:240px"><button class="btn"><?= icon('search') ?> Cari</button></form>
  <?php if ($q !== ''): ?><ul class="list"><?php foreach ($hasil as $r): ?>
    <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?> &middot; saldo <?= rp(Pos::saldo((int) $r['id'])) ?></small></div><a class="btn sm" href="?p=dompet&amp;santri=<?= (int) $r['id'] ?>">Pilih <?= icon('arrow-right', 15) ?></a></li>
  <?php endforeach; ?><?php if (!$hasil): ?><li class="mut">Tidak ditemukan.</li><?php endif; ?></ul><?php endif; ?>
</div>
<?php if ($santri): ?>
<div class="card">
  <div class="who"><?= avatar($santri['nama'], $santri['unit']) ?><span><b><?= e($santri['nama']) ?></b><small><?= e($santri['nis']) ?> &middot; <?= e($santri['jenjang']) ?> <?= e(Domain::UNIT[$santri['unit']]) ?></small></span>
    <span style="margin-left:auto;text-align:right"><small>Saldo</small><br><b style="font-size:1.5rem"><?= rp($saldo) ?></b></span></div>
</div>
<div class="grid g2" style="margin-bottom:16px">
  <form method="post" class="card form"><h2>Top up saldo</h2><?= csrf_field() ?><input type="hidden" name="santri_id" value="<?= $sid ?>"><input type="hidden" name="aksi" value="topup">
    <label>Nominal <input name="jumlah" data-rupiah inputmode="numeric" required></label>
    <label>Catatan <input name="catatan" placeholder="Dititipkan wali, transfer..."></label>
    <button class="btn"><?= icon('plus') ?> Top up</button></form>
  <?php if (Auth::can('dompet.koreksi')): ?>
  <form method="post" class="card form"><h2>Koreksi saldo</h2><?= csrf_field() ?><input type="hidden" name="santri_id" value="<?= $sid ?>"><input type="hidden" name="aksi" value="koreksi">
    <label>Arah <select name="arah"><?= options(['tambah' => 'Tambah saldo', 'kurang' => 'Kurangi saldo']) ?></select></label>
    <label>Nominal <input name="jumlah" data-rupiah inputmode="numeric" required></label>
    <label>Alasan <input name="alasan" required placeholder="Salah input, penarikan wali..."></label>
    <button class="btn ghost" data-confirm="Koreksi saldo dompet?">Simpan koreksi</button></form>
  <?php endif; ?>
</div>
<div class="card"><h2>Riwayat dompet</h2>
  <div class="scroll"><table class="rt"><thead><tr><th>Waktu</th><th>Jenis</th><th class="r">Jumlah</th><th class="r">Saldo akhir</th><th>Catatan</th></tr></thead><tbody>
  <?php foreach ($riwayat as $m): ?>
    <tr><td><?= e(substr($m['created_at'], 0, 16)) ?></td><td data-label="Jenis"><?= e(['topup' => 'Top up', 'belanja' => 'Belanja', 'batal' => 'Batal belanja', 'koreksi' => 'Koreksi'][$m['tipe']]) ?></td>
      <td class="r" data-label="Jumlah"><b style="color:var(--<?= (int) $m['jumlah'] > 0 ? 'ok' : 'err' ?>)"><?= ((int) $m['jumlah'] > 0 ? '+' : '') . rp($m['jumlah']) ?></b></td><td class="r" data-label="Saldo akhir"><?= rp($m['saldo_setelah']) ?></td>
      <td data-label="Catatan"><?= e($m['catatan'] ?: '-') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$riwayat): ?><tr><td colspan="5" class="empty">Belum ada transaksi dompet.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php else: ?>
<div class="card"><h2>Santri bersaldo</h2>
  <ul class="list"><?php foreach ($daftar as $r): ?>
    <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?></small></div><b><?= rp($r['saldo']) ?></b><a class="btn sm ghost" href="?p=dompet&amp;santri=<?= (int) $r['id'] ?>">Buka</a></li>
  <?php endforeach; ?><?php if (!$daftar): ?><li class="mut">Belum ada saldo. Cari santri untuk top up.</li><?php endif; ?></ul>
</div>
<?php endif; ?>
<?php render('Dompet Santri', ob_get_clean());
