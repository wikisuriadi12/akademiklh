<?php
$lihat = Auth::can('gudang.edit') || Auth::can('laporan');
if (!$lihat) { Auth::require('gudang.edit'); }
$bisaBayar = Auth::can('gudang.edit');
$s = trim(in_str('s'));
$statusF = in_str('status') === 'semua' ? 'semua' : 'belum';
if (is_post() && $bisaBayar) {
    csrf_check();
    $num = static fn(string $k): int => (int) preg_replace('/\D/', '', in_str($k, '0'));
    try {
        switch (in_str('aksi')) {
            case 'bayar_faktur':
                Hutang::bayar('hutang', $s, (int) in_str('hutang_id', '0'), $num('jumlah'), in_str('tanggal') ?: date('Y-m-d'), in_str('catatan'), Auth::id());
                flash('Pembayaran hutang dicatat.'); break;
            case 'bayar_kons':
                Hutang::bayar('konsinyasi', $s, null, $num('jumlah'), in_str('tanggal') ?: date('Y-m-d'), in_str('catatan'), Auth::id());
                flash('Pembayaran konsinyasi dicatat.'); break;
            case 'akhiri':
                Hutang::akhiriKonsinyasi((int) in_str('barang_id', '0'), Auth::id());
                flash('Status konsinyasi barang diakhiri.'); break;
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=hutang&s=' . rawurlencode($s) . '&status=' . $statusF);
}
$hari = date('Y-m-d'); $sepekan = date('Y-m-d', strtotime('+7 days'));
$badgeTempo = static function (?string $t, int $sisa) use ($hari, $sepekan): string {
    if ($sisa <= 0) { return '<span class="badge b-lunas">Lunas</span>'; }
    if ($t === null || $t === '') { return '<span class="badge">Tanpa tempo</span>'; }
    if ($t < $hari) { return '<span class="badge b-belum">Lewat tempo</span>'; }
    if ($t <= $sepekan) { return '<span class="badge b-sebagian">Segera</span>'; }
    return '<span class="badge b-lunas">Belum tempo</span>';
};
$tgl = static fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '-';

if ($s === '') {
    $rk = Hutang::ringkasan(); $fk = Hutang::faktur('', 'belum');
    if (in_str('csv') === '1') {
        csv_out('hutang-supplier-' . $hari . '.csv', ['Supplier', 'Faktur belum lunas', 'Sisa hutang faktur', 'Jatuh tempo terdekat', 'Lewat tempo', 'Konsinyasi terjual', 'Konsinyasi dibayar', 'Sisa konsinyasi', 'Total sisa'],
            array_map(static fn($r) => [$r['pemasok'], $r['faktur_n'], $r['faktur_sisa'], $r['tempo'], $r['lewat'], $r['kons_terjual'], $r['kons_bayar'], $r['kons_sisa'], $r['total_sisa']], array_values($rk)));
        exit;
    }
    if (in_str('csv') === 'faktur') {
        csv_out('faktur-hutang-' . $hari . '.csv', ['Supplier', 'Faktur', 'Tanggal', 'Jatuh tempo', 'Total', 'Dibayar', 'Sisa', 'Status'],
            array_map(static fn($r) => [$r['pemasok'], $r['faktur'], $r['tanggal'], $r['jatuh_tempo'], $r['total'], $r['dibayar'], (int) $r['total'] - (int) $r['dibayar'], $r['status']], $fk));
        exit;
    }
    $tFaktur = array_sum(array_column($rk, 'faktur_sisa')); $tKons = 0; $tLewat = array_sum(array_column($rk, 'lewat')); $tSegera = 0;
    foreach ($rk as $r) { $tKons += max(0, $r['kons_sisa']); }
    foreach ($fk as $f) { if ($f['jatuh_tempo'] && $f['jatuh_tempo'] >= $hari && $f['jatuh_tempo'] <= $sepekan) { $tSegera += (int) $f['total'] - (int) $f['dibayar']; } }
    ob_start(); ?>
<div class="page-h"><div><h1>Hutang Supplier</h1><p>Hutang pembelian per faktur dan konsinyasi (dibayar setelah barang terjual), dikelompokkan menurut nama supplier.</p></div>
  <div class="acts"><a class="btn ghost" href="?p=hutang&amp;csv=1"><?= icon('download') ?> CSV per supplier</a><a class="btn ghost" href="?p=hutang&amp;csv=faktur"><?= icon('download') ?> CSV faktur</a><?php if ($bisaBayar): ?><a class="btn" href="?p=barang_masuk"><?= icon('plus') ?> Barang masuk</a><?php endif; ?></div></div>
<div class="kpis">
  <div class="kpi"><div class="chip c-acc"><?= icon('wallet', 22) ?></div><div><span>Hutang faktur belum lunas</span><b><?= rp($tFaktur) ?></b></div></div>
  <div class="kpi"><div class="chip c-info"><?= icon('box', 22) ?></div><div><span>Konsinyasi terjual belum dibayar</span><b><?= rp($tKons) ?></b></div></div>
  <div class="kpi"><div class="chip c-acc"><?= icon('alert', 22) ?></div><div><span>Lewat jatuh tempo</span><b><?= rp($tLewat) ?></b></div></div>
  <div class="kpi"><div class="chip"><?= icon('clock', 22) ?></div><div><span>Jatuh tempo 7 hari ke depan</span><b><?= rp($tSegera) ?></b></div></div>
</div>
<div class="card"><h2>Per supplier</h2><div class="scroll"><table class="rt">
  <thead><tr><th>Supplier</th><th class="r">Hutang faktur</th><th>Jatuh tempo terdekat</th><th class="r">Konsinyasi terutang</th><th class="r">Nilai stok titipan</th><th class="r">Total sisa</th><th></th></tr></thead><tbody>
  <?php foreach ($rk as $r): ?><tr><td><b><?= e($r['pemasok']) ?></b></td>
    <td class="r" data-label="Hutang faktur"><?= rp($r['faktur_sisa']) ?><?= $r['faktur_n'] ? '<br><small>' . (int) $r['faktur_n'] . ' faktur</small>' : '' ?></td>
    <td data-label="Jatuh tempo"><?= $tgl($r['tempo']) ?> <?= $r['faktur_sisa'] > 0 ? $badgeTempo($r['tempo'], $r['faktur_sisa']) : '' ?></td>
    <td class="r" data-label="Konsinyasi"><?= rp(max(0, $r['kons_sisa'])) ?></td><td class="r" data-label="Stok titipan"><?= rp($r['kons_stok_nilai']) ?></td>
    <td class="r" data-label="Total"><b><?= rp($r['total_sisa']) ?></b></td>
    <td class="r"><a class="btn sm ghost" href="?p=hutang&amp;s=<?= e(rawurlencode($r['pemasok'])) ?>">Rincian</a></td></tr><?php endforeach; ?>
  <?php if (!$rk): ?><tr><td colspan="7" class="empty">Belum ada hutang atau konsinyasi. Catat lewat menu Barang Masuk dengan jenis "Hutang" atau "Konsinyasi".</td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="card" style="margin-top:16px"><h2>Faktur hutang belum lunas</h2><div class="scroll"><table class="rt">
  <thead><tr><th>Supplier</th><th>Faktur</th><th>Tanggal</th><th>Jatuh tempo</th><th class="r">Total</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($fk as $f): $sisa = (int) $f['total'] - (int) $f['dibayar']; ?><tr><td><a href="?p=hutang&amp;s=<?= e(rawurlencode($f['pemasok'])) ?>"><b><?= e($f['pemasok']) ?></b></a></td><td data-label="Faktur"><?= e($f['faktur'] ?: '-') ?></td><td data-label="Tanggal"><?= $tgl($f['tanggal']) ?></td><td data-label="Jatuh tempo"><?= $tgl($f['jatuh_tempo']) ?></td>
    <td class="r" data-label="Total"><?= rp($f['total']) ?></td><td class="r" data-label="Dibayar"><?= rp($f['dibayar']) ?></td><td class="r" data-label="Sisa"><b><?= rp($sisa) ?></b></td><td data-label="Status"><?= $badgeTempo($f['jatuh_tempo'], $sisa) ?></td></tr><?php endforeach; ?>
  <?php if (!$fk): ?><tr><td colspan="8" class="empty">Tidak ada faktur hutang yang belum lunas.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render('Hutang Supplier', ob_get_clean());
    exit;
}

// ---- rincian satu supplier ----
$fk = Hutang::faktur($s, $statusF); $kons = Hutang::konsinyasi($s); $bk = Hutang::barangKonsinyasi($s); $by = Hutang::pembayaran($s);
$sisaFaktur = 0; foreach (Hutang::faktur($s, 'belum') as $f) { $sisaFaktur += (int) $f['total'] - (int) $f['dibayar']; }
if (in_str('csv') === '1') {
    csv_out('hutang-' . preg_replace('/[^A-Za-z0-9]+/', '-', $s) . '-' . $hari . '.csv', ['Jenis', 'Faktur / Barang', 'Tanggal', 'Jatuh tempo', 'Total', 'Dibayar', 'Sisa'],
        array_merge(array_map(static fn($f) => ['Faktur hutang', $f['faktur'], $f['tanggal'], $f['jatuh_tempo'], $f['total'], $f['dibayar'], (int) $f['total'] - (int) $f['dibayar']], $fk),
            array_map(static fn($b) => ['Konsinyasi', $b['nama'], '', '', $b['terjual_nilai'], '', ''], $bk)));
    exit;
}
$bayarForm = static function (string $aksi, array $extra, int $maks) {
    $h = '<form method="post" class="inline" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' . csrf_field() . '<input type="hidden" name="aksi" value="' . e($aksi) . '">';
    foreach ($extra as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
    return $h . '<input type="date" name="tanggal" value="' . e(date('Y-m-d')) . '" style="width:150px"><input name="jumlah" data-rupiah inputmode="numeric" placeholder="Jumlah (maks ' . e(rp($maks)) . ')" required style="width:190px"><input name="catatan" placeholder="Catatan" style="width:150px"><button class="btn sm">Catat bayar</button></form>';
};
ob_start(); ?>
<div class="page-h"><div><h1><?= e($s) ?></h1><p>Hutang pembelian dan konsinyasi supplier ini.</p></div>
  <div class="acts"><a class="btn ghost" href="?p=hutang&amp;s=<?= e(rawurlencode($s)) ?>&amp;csv=1"><?= icon('download') ?> CSV</a><a class="btn ghost" href="?p=hutang">Kembali</a></div></div>
<div class="kpis">
  <div class="kpi"><div class="chip c-acc"><?= icon('wallet', 22) ?></div><div><span>Sisa hutang faktur</span><b><?= rp($sisaFaktur) ?></b></div></div>
  <div class="kpi"><div class="chip c-info"><?= icon('box', 22) ?></div><div><span>Konsinyasi terutang (terjual)</span><b><?= rp(max(0, $kons['sisa'])) ?></b></div></div>
  <div class="kpi"><div class="chip"><?= icon('box', 22) ?></div><div><span>Nilai stok titipan</span><b><?= rp($kons['stok_nilai']) ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('check', 22) ?></div><div><span>Total sisa</span><b><?= rp($sisaFaktur + max(0, $kons['sisa'])) ?></b></div></div>
</div>
<div class="card"><h2>Faktur hutang</h2>
  <div class="tabs" style="margin-bottom:10px"><a class="<?= $statusF === 'belum' ? 'on' : '' ?>" href="?p=hutang&amp;s=<?= e(rawurlencode($s)) ?>">Belum lunas</a><a class="<?= $statusF === 'semua' ? 'on' : '' ?>" href="?p=hutang&amp;s=<?= e(rawurlencode($s)) ?>&amp;status=semua">Semua</a></div>
  <?php foreach ($fk as $f): $sisa = (int) $f['total'] - (int) $f['dibayar']; ?>
  <div class="card" style="margin-bottom:10px;background:rgba(127,127,127,.04)">
    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><div><b>Faktur <?= e($f['faktur'] ?: '#' . $f['id']) ?></b> <small class="mut">&middot; <?= $tgl($f['tanggal']) ?> &middot; tempo <?= $tgl($f['jatuh_tempo']) ?></small></div><div><?= $badgeTempo($f['jatuh_tempo'], $sisa) ?></div></div>
    <div class="mut" style="margin:6px 0">Total <?= rp($f['total']) ?> &middot; dibayar <?= rp($f['dibayar']) ?> &middot; <b>sisa <?= rp($sisa) ?></b></div>
    <details><summary>Barang di faktur ini</summary><div class="scroll"><table class="rt"><thead><tr><th>Barang</th><th class="r">Jumlah</th><th class="r">Harga beli</th><th class="r">Subtotal</th></tr></thead><tbody>
      <?php foreach (Hutang::itemFaktur((int) $f['id']) as $it): ?><tr><td><?= e($it['nama']) ?> <small class="mut"><?= e($it['kode']) ?></small></td><td class="r"><?= (int) $it['qty'] ?> <?= e($it['satuan']) ?></td><td class="r"><?= rp($it['harga_beli']) ?></td><td class="r"><?= rp((int) $it['qty'] * (int) $it['harga_beli']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
    <?php if ($bisaBayar && $sisa > 0): ?><div style="margin-top:8px"><?= $bayarForm('bayar_faktur', ['hutang_id' => $f['id']], $sisa) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$fk): ?><div class="empty">Tidak ada faktur<?= $statusF === 'belum' ? ' yang belum lunas' : '' ?>.</div><?php endif; ?>
</div>
<div class="card" style="margin-top:16px"><h2>Konsinyasi (titipan)</h2>
  <p class="mut">Barang titipan dibayar ke supplier hanya untuk yang sudah terjual, sebesar harga setor per unit.</p>
  <div class="scroll"><table class="rt"><thead><tr><th>Barang</th><th class="r">Stok sekarang</th><th class="r">Harga setor</th><th class="r">Terjual</th><th class="r">Nilai terjual</th><th></th></tr></thead><tbody>
  <?php foreach ($bk as $b): ?><tr><td><b><?= e($b['nama']) ?></b><br><small><?= e($b['kode']) ?></small></td><td class="r" data-label="Stok"><?= (int) $b['stok'] ?> <?= e($b['satuan']) ?></td><td class="r" data-label="Harga setor"><?= rp($b['harga_beli']) ?></td><td class="r" data-label="Terjual"><?= $b['terjual_qty'] ?></td><td class="r" data-label="Nilai"><?= rp($b['terjual_nilai']) ?></td>
    <td class="r"><?php if ($bisaBayar && (int) $b['stok'] === 0): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="akhiri"><input type="hidden" name="barang_id" value="<?= (int) $b['id'] ?>"><button class="btn sm ghost" data-confirm="Akhiri konsinyasi barang ini? Barang kembali jadi barang biasa.">Akhiri</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$bk): ?><tr><td colspan="6" class="empty">Tidak ada barang konsinyasi dari supplier ini.</td></tr><?php endif; ?></tbody></table></div>
  <div class="mut" style="margin:8px 0">Terjual <?= rp($kons['terjual']) ?> &middot; sudah dibayar <?= rp($kons['dibayar']) ?> &middot; <b>sisa <?= rp(max(0, $kons['sisa'])) ?></b></div>
  <?php if ($bisaBayar && $kons['sisa'] > 0): ?><?= $bayarForm('bayar_kons', [], $kons['sisa']) ?><?php endif; ?>
</div>
<div class="card" style="margin-top:16px"><h2>Riwayat pembayaran</h2><div class="scroll"><table class="rt"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Faktur</th><th class="r">Jumlah</th><th>Catatan</th><th>Petugas</th></tr></thead><tbody>
  <?php foreach ($by as $y): ?><tr><td><?= $tgl($y['tanggal']) ?></td><td data-label="Jenis"><?= $y['jenis'] === 'hutang' ? 'Hutang faktur' : 'Konsinyasi' ?></td><td data-label="Faktur"><?= e($y['faktur'] ?? '-') ?></td><td class="r" data-label="Jumlah"><?= rp($y['jumlah']) ?></td><td data-label="Catatan"><?= e($y['catatan'] ?: '-') ?></td><td data-label="Petugas"><?= e($y['petugas'] ?: '-') ?></td></tr><?php endforeach; ?>
  <?php if (!$by): ?><tr><td colspan="6" class="empty">Belum ada pembayaran.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render('Hutang ' . $s, ob_get_clean());
