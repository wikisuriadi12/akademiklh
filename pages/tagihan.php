<?php
Auth::require('tagihan.view');
$scope = Auth::scope();

if (is_post() && Auth::can('tagihan.generate')) {
    csrf_check();
    try {
        if (in_str('aksi') === 'lain') {
            $f = ['unit' => in_str('unit'), 'jenjang' => in_str('jenjang'), 'kelas' => in_str('kelas')];
            $nis = in_str('nis');
            if ($nis !== '') {
                $s = Db::one('SELECT id FROM santri WHERE nis = ?', [$nis]);
                if (!$s) { throw new InvalidArgumentException('NIS tidak ditemukan.'); }
                $f['santri_id'] = (int) $s['id'];
            }
            $r = Spp::buatTagihanLain((int) in_str('jenis'), in_str('periode'), (int) preg_replace('/\D/', '', in_str('nominal')), in_str('keterangan'), $f, $scope, Auth::id());
            flash("Tagihan dibuat untuk {$r['dibuat']} santri" . ($r['sudah_ada'] ? ", {$r['sudah_ada']} sudah ada sebelumnya." : '.'));
        } else {
            $r = Spp::generate(in_str('periode'), $scope, Auth::id());
            $msg = "Tagihan {$r['dibuat']} dibuat, {$r['sudah_ada']} sudah ada sebelumnya.";
            if ($r['tanpa_tarif']) {
                $t = [];
                foreach ($r['tanpa_tarif'] as $k => $n) { $t[] = "$k ($n)"; }
                $msg .= ' Dilewati karena tarif ' . $r['tahun_ajaran'] . ' belum diatur: ' . implode(', ', $t) . '.';
            }
            flash($msg, $r['tanpa_tarif'] ? 'warn' : 'ok');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=tagihan&periode=' . urlencode(in_str('periode')));
}

$f = ['periode' => in_str('periode', date('Y-m')), 'unit' => in_str('unit'), 'jenjang' => in_str('jenjang'), 'status' => in_str('status'), 'q' => in_str('q'), 'jenis' => in_str('jenis_f')];
if (!Domain::validPeriode($f['periode'])) { $f['periode'] = date('Y-m'); }
$rows = Spp::daftarTagihan($f, $scope);
$tot = ['n' => 0, 'b' => 0];
foreach ($rows as $r) { $tot['n'] += (int) $r['nominal']; $tot['b'] += (int) $r['dibayar']; }
$jenis = Spp::jenisList();
$jenisSekali = array_values(array_filter($jenis, static fn($j) => $j['tipe'] === 'sekali'));

if (in_str('export') === 'csv') {
    csv_out('tagihan-' . $f['periode'] . '.csv', ['Periode', 'NIS', 'Nama', 'Unit', 'Jenjang', 'Kelas', 'Tagihan', 'Nominal', 'Dibayar', 'Sisa'],
        array_map(static fn($r) => [$r['periode'], $r['nis'], $r['nama'], $r['unit'], $r['jenjang'], $r['kelas'], Spp::label($r), $r['nominal'], $r['dibayar'], $r['nominal'] - $r['dibayar']], $rows));
}
$canGen = Auth::can('tagihan.generate');
ob_start(); ?>
<div class="page-h"><div><h1>Tagihan</h1><p><?= e(Domain::namaPeriode($f['periode'])) ?> &middot; <?= count($rows) ?> tagihan</p></div>
  <div class="acts"><a class="btn ghost" href="?<?= e(http_build_query(array_merge(['p' => 'tagihan', 'export' => 'csv'], array_filter($f, 'strlen')))) ?>"><?= icon('download') ?> Ekspor CSV</a></div></div>

<?php if ($canGen): ?>
<div class="grid g2" style="margin-bottom:16px">
  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Buat tagihan SPP bulanan</h2>
    <div class="filters">
      <input type="month" name="periode" value="<?= e($f['periode']) ?>" required>
      <button class="btn" data-confirm="Buat tagihan SPP untuk semua santri aktif pada bulan ini?"><?= icon('plus') ?> Buat tagihan</button>
    </div>
    <small>Hanya santri aktif. Yang sudah punya tagihan bulan itu dilewati, tarif memakai tahun ajaran terkait dikurangi potongan santri.</small>
  </form>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="lain">
    <h2>Tagihan biaya lain</h2>
    <div class="filters">
      <select name="jenis" required><?= options(array_column($jenisSekali, 'nama', 'id')) ?></select>
      <input type="month" name="periode" value="<?= e($f['periode']) ?>" required>
      <input name="nominal" data-rupiah inputmode="numeric" placeholder="Nominal" size="10" required>
    </div>
    <div class="filters">
      <input name="keterangan" placeholder="Keterangan (mis. Batik)" maxlength="100">
      <?php if (!$scope): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT) ?></select><?php endif; ?>
      <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, '', false) ?></select>
      <input name="kelas" placeholder="Kelas (opsional)" size="10">
      <input name="nis" placeholder="atau NIS tertentu" size="12">
    </div>
    <button class="btn ghost" data-confirm="Buat tagihan ini untuk santri yang sesuai pilihan?"><?= icon('plus') ?> Buat tagihan</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="tagihan">
    <input type="month" name="periode" value="<?= e($f['periode']) ?>">
    <?php if (!$scope): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
    <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, $f['jenjang'], false) ?></select>
    <select name="jenis_f"><option value="">Semua jenis</option><?= options(array_column($jenis, 'nama', 'id'), $f['jenis']) ?></select>
    <select name="status"><option value="">Semua status</option><?= options(['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum' => 'Belum bayar'], $f['status']) ?></select>
    <input name="q" placeholder="Nama / NIS" value="<?= e($f['q']) ?>">
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Santri</th><th>Tagihan</th><th class="r">Nominal</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="who-c"><a class="who" href="?p=santri_detail&amp;id=<?= (int) $r['santri_id'] ?>" style="color:inherit;text-decoration:none"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?> &middot; <?= e($r['nis']) ?></small></span></a></td>
        <td data-label="Tagihan"><?= e(Spp::label($r)) ?></td>
        <td class="r" data-label="Nominal"><?= rp($r['nominal']) ?></td><td class="r" data-label="Dibayar"><?= rp($r['dibayar']) ?></td><td class="r" data-label="Sisa"><?= rp($r['nominal'] - $r['dibayar']) ?></td>
        <td data-label="Status"><?= status_tagihan((int) $r['nominal'], (int) $r['dibayar']) ?></td>
        <td class="r"><?php if (Auth::can('bayar') && $r['dibayar'] < $r['nominal']): ?><a class="btn sm" href="?p=bayar&amp;santri=<?= (int) $r['santri_id'] ?>">Bayar</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="empty"><?= icon('file-text') ?><div>Belum ada tagihan untuk filter ini.</div></div></td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr class="tot"><th colspan="2">Total</th><th class="r"><?= rp($tot['n']) ?></th><th class="r"><?= rp($tot['b']) ?></th><th class="r"><?= rp($tot['n'] - $tot['b']) ?></th><th colspan="2"></th></tr></tfoot><?php endif; ?>
  </table></div>
</div>
<?php render('Tagihan', ob_get_clean());
