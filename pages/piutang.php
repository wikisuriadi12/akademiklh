<?php
$bisaBayar = Auth::can('pos');
if (!$bisaBayar && !Auth::can('laporan')) { Auth::require('pos'); }
$n = trim(in_str('n'));
$statusF = in_str('status') === 'semua' ? 'semua' : 'belum';
if (is_post() && $bisaBayar) {
    csrf_check();
    try {
        Piutang::bayar((int) in_str('piutang_id', '0'), (int) preg_replace('/\D/', '', in_str('jumlah', '0')), in_str('tanggal') ?: date('Y-m-d'), in_str('catatan'), Auth::id());
        flash('Pembayaran hutang pelanggan dicatat.');
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=piutang&n=' . rawurlencode($n) . '&status=' . $statusF);
}
$hari = date('Y-m-d'); $sepekan = date('Y-m-d', strtotime('+7 days'));
$tgl = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '-';
$badge = static function (?string $t, int $sisa) use ($hari, $sepekan): string {
    if ($sisa <= 0) { return '<span class="badge b-lunas">Lunas</span>'; }
    if (!$t) { return '<span class="badge">Tanpa tempo</span>'; }
    if ($t < $hari) { return '<span class="badge b-belum">Lewat tempo</span>'; }
    return $t <= $sepekan ? '<span class="badge b-sebagian">Segera</span>' : '<span class="badge b-lunas">Belum tempo</span>';
};
if ($n === '') {
    $rk = Piutang::ringkasan();
    if (in_str('csv') === '1') { csv_out('piutang-pelanggan-' . $hari . '.csv', ['Pelanggan', 'Jumlah bon', 'Total bon', 'Dibayar', 'Sisa', 'Tempo terdekat', 'Lewat tempo'], array_map(static fn($r) => [$r['nama'], $r['n'], $r['total'], $r['dibayar'], $r['sisa'], $r['tempo'], $r['lewat']], array_values($rk))); exit; }
    $tSisa = array_sum(array_column($rk, 'sisa')); $tLewat = array_sum(array_column($rk, 'lewat'));
    ob_start(); ?>
<div class="page-h"><div><h1>Piutang Pelanggan</h1><p>Pembelian hutang (bon) di kasir, dikelompokkan menurut nama pelanggan.</p></div>
  <div class="acts"><a class="btn ghost" href="?p=piutang&amp;csv=1"><?= icon('download') ?> CSV</a><a class="btn ghost" href="?p=laporan_pos&amp;tab=hutang">Laporan pembelian hutang</a></div></div>
<div class="kpis">
  <div class="kpi"><div class="chip c-acc"><?= icon('wallet', 22) ?></div><div><span>Total piutang</span><b><?= rp($tSisa) ?></b></div></div>
  <div class="kpi"><div class="chip"><?= icon('users', 22) ?></div><div><span>Pelanggan berhutang</span><b><?= count($rk) ?></b></div></div>
  <div class="kpi"><div class="chip c-acc"><?= icon('alert', 22) ?></div><div><span>Lewat jatuh tempo</span><b><?= rp($tLewat) ?></b></div></div>
</div>
<div class="card"><div class="scroll"><table class="rt"><thead><tr><th>Pelanggan</th><th class="r">Jumlah bon</th><th class="r">Total bon</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Tempo terdekat</th><th></th></tr></thead><tbody>
  <?php foreach ($rk as $r): ?><tr><td><b><?= e($r['nama']) ?></b></td><td class="r" data-label="Bon"><?= (int) $r['n'] ?></td><td class="r" data-label="Total"><?= rp($r['total']) ?></td><td class="r" data-label="Dibayar"><?= rp($r['dibayar']) ?></td><td class="r" data-label="Sisa"><b><?= rp($r['sisa']) ?></b></td><td data-label="Tempo"><?= $tgl($r['tempo']) ?> <?= $badge($r['tempo'], $r['sisa']) ?></td><td class="r"><a class="btn sm ghost" href="?p=piutang&amp;n=<?= e(rawurlencode($r['nama'])) ?>">Rincian</a></td></tr><?php endforeach; ?>
  <?php if (!$rk): ?><tr><td colspan="7" class="empty">Belum ada hutang pelanggan. Di kasir pilih metode "Hutang / Bon".</td></tr><?php endif; ?></tbody></table></div></div>
<?php render('Piutang Pelanggan', ob_get_clean()); exit;
}
$fk = Piutang::daftar($n, $statusF); $by = Piutang::pembayaran($n);
$sisaAll = 0; foreach (Piutang::daftar($n, 'belum') as $f) { $sisaAll += (int) $f['total'] - (int) $f['dibayar']; }
ob_start(); ?>
<div class="page-h"><div><h1><?= e($n) ?></h1><p>Rincian hutang (bon) pelanggan ini.</p></div><div class="acts"><a class="btn ghost" href="?p=piutang">Kembali</a></div></div>
<div class="kpis"><div class="kpi"><div class="chip c-acc"><?= icon('wallet', 22) ?></div><div><span>Sisa hutang</span><b><?= rp($sisaAll) ?></b></div></div></div>
<div class="card"><div class="tabs" style="margin-bottom:10px"><a class="<?= $statusF === 'belum' ? 'on' : '' ?>" href="?p=piutang&amp;n=<?= e(rawurlencode($n)) ?>">Belum lunas</a><a class="<?= $statusF === 'semua' ? 'on' : '' ?>" href="?p=piutang&amp;n=<?= e(rawurlencode($n)) ?>&amp;status=semua">Semua</a></div>
<?php foreach ($fk as $f): $sisa = (int) $f['total'] - (int) $f['dibayar']; ?>
  <div class="card" style="margin-bottom:10px;background:rgba(127,127,127,.04)">
    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><div><b><?= e($f['no_struk']) ?></b> <small class="mut">&middot; <?= $tgl($f['tanggal']) ?> &middot; tempo <?= $tgl($f['jatuh_tempo']) ?></small> <a class="btn sm ghost" href="?p=struk&amp;id=<?= (int) $f['penjualan_id'] ?>">Struk</a></div><div><?= $badge($f['jatuh_tempo'], $sisa) ?></div></div>
    <div class="mut" style="margin:6px 0">Total <?= rp($f['total']) ?> &middot; dibayar <?= rp($f['dibayar']) ?> &middot; <b>sisa <?= rp($sisa) ?></b></div>
    <details><summary>Barang yang dibeli</summary><ul style="margin:6px 0 0;padding-left:18px"><?php foreach (Piutang::items((int) $f['penjualan_id']) as $it): ?><li><?= e($it['nama']) ?> &times; <?= (int) $it['qty'] ?> = <?= rp($it['subtotal']) ?></li><?php endforeach; ?></ul></details>
    <?php if ($bisaBayar && $sisa > 0): ?><form method="post" class="inline" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="piutang_id" value="<?= (int) $f['id'] ?>"><input type="date" name="tanggal" value="<?= e($hari) ?>" style="width:150px"><input name="jumlah" data-rupiah inputmode="numeric" placeholder="Jumlah (maks <?= e(rp($sisa)) ?>)" required style="width:190px"><input name="catatan" placeholder="Catatan" style="width:150px"><button class="btn sm">Catat bayar</button></form><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$fk): ?><div class="empty">Tidak ada bon<?= $statusF === 'belum' ? ' yang belum lunas' : '' ?>.</div><?php endif; ?></div>
<div class="card" style="margin-top:16px"><h2>Riwayat pembayaran</h2><div class="scroll"><table class="rt"><thead><tr><th>Tanggal</th><th>Struk</th><th class="r">Jumlah</th><th>Catatan</th><th>Petugas</th></tr></thead><tbody>
<?php foreach ($by as $y): ?><tr><td><?= $tgl($y['tanggal']) ?></td><td data-label="Struk"><?= e($y['no_struk']) ?></td><td class="r" data-label="Jumlah"><?= rp($y['jumlah']) ?></td><td data-label="Catatan"><?= e($y['catatan'] ?: '-') ?></td><td data-label="Petugas"><?= e($y['petugas'] ?: '-') ?></td></tr><?php endforeach; ?>
<?php if (!$by): ?><tr><td colspan="5" class="empty">Belum ada pembayaran.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render('Hutang ' . $n, ob_get_clean());
