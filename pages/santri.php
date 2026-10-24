<?php
Auth::require('santri.view');
$scope = Auth::scope();
$f = ['q' => in_str('q'), 'unit' => in_str('unit'), 'jenjang' => in_str('jenjang'), 'status' => in_str('status')];
$page = max(1, (int) in_str('hal', '1'));

if (in_str('export') === 'csv') {
    [$rows] = Santri::daftar($f, $scope, 1, true);
    csv_out('santri.csv', ['NIS', 'Nama', 'JK', 'Unit', 'Jenjang', 'Kelas', 'Wali', 'HP Wali', 'Tahun Masuk', 'Status', 'Potongan SPP'],
        array_map(static fn($r) => [$r['nis'], $r['nama'], $r['jenis_kelamin'], $r['unit'], $r['jenjang'], $r['kelas'], $r['nama_wali'], $r['hp_wali'], $r['tahun_masuk'], $r['status'], $r['potongan_spp']], $rows));
}
[$rows, $total] = Santri::daftar($f, $scope, $page);
$pages = max(1, (int) ceil($total / Santri::perHalaman()));
$qs = static fn(array $extra = []) => '?' . http_build_query(array_merge(['p' => 'santri'], array_filter($f, 'strlen'), $extra));
ob_start(); ?>
<div class="page-h">
  <div><h1>Data Santri</h1><p><?= $total ?> santri ditemukan</p></div>
  <div class="acts">
    <a class="btn ghost" href="<?= e($qs(['export' => 'csv'])) ?>"><?= icon('download') ?> Ekspor CSV</a>
    <?php if (Auth::can('impor')): ?><a class="btn ghost" href="?p=impor"><?= icon('upload') ?> Impor</a><?php endif; ?>
    <?php if (Auth::can('santri.edit')): ?><a class="btn" href="?p=santri_form"><?= icon('plus') ?> Santri baru</a><?php endif; ?>
  </div>
</div>
<div class="card">
  <form method="get" class="filters">
    <input type="hidden" name="p" value="santri">
    <input name="q" placeholder="Cari nama, NIS, atau wali..." value="<?= e($f['q']) ?>">
    <?php if (!$scope): ?><select name="unit"><option value="">Semua unit</option><?= options(Domain::UNIT, $f['unit']) ?></select><?php endif; ?>
    <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, $f['jenjang'], false) ?></select>
    <select name="status"><option value="">Semua status</option><?= options(Domain::STATUS, $f['status']) ?></select>
    <button class="btn"><?= icon('search') ?> Cari</button>
  </form>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Santri</th><th>Unit</th><th>Jenjang / Kelas</th><th>Wali</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $wa = wa_link($r['hp_wali'], 'Assalamu\'alaikum ' . $r['nama_wali'] . ', kami dari ' . Settings::get('nama_lembaga') . ' terkait ananda ' . $r['nama'] . '.'); ?>
      <tr>
        <td class="who-c"><a class="who" href="?p=santri_detail&amp;id=<?= (int) $r['id'] ?>" style="color:inherit;text-decoration:none"><?= avatar($r['nama'], $r['unit']) ?><span><b><?= e($r['nama']) ?></b><small><?= e($r['nis']) ?></small></span></a></td>
        <td data-label="Unit"><?= e(Domain::UNIT[$r['unit']]) ?></td>
        <td data-label="Jenjang"><?= e($r['jenjang']) ?><?= $r['kelas'] ? ' / ' . e($r['kelas']) : '' ?></td>
        <td data-label="Wali"><?= e($r['nama_wali']) ?><br><small><?= e($r['hp_wali']) ?></small></td>
        <td data-label="Status"><?= badge($r['status']) ?></td>
        <td class="r">
          <a class="btn sm ghost" href="?p=santri_detail&amp;id=<?= (int) $r['id'] ?>"><?= icon('eye', 15) ?> Detail</a>
          <?php if ($wa): ?><a class="btn sm wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp wali"><?= icon('message', 15) ?></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('users') ?><div>Belum ada data santri untuk filter ini.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <div class="pager">Halaman <?= $page ?> dari <?= $pages ?>
      <?php if ($page > 1): ?><a class="btn sm ghost" href="<?= e($qs(['hal' => $page - 1])) ?>">&laquo; Sebelumnya</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn sm ghost" href="<?= e($qs(['hal' => $page + 1])) ?>">Berikutnya &raquo;</a><?php endif; ?></div>
  <?php endif; ?>
</div>
<?php render('Data Santri', ob_get_clean());
