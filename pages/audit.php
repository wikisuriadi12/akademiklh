<?php
Auth::require('audit');
$objek = in_str('objek');
$aksi = in_str('aksi');
$where = ['1=1'];
$p = [];
if ($objek !== '') { $where[] = 'a.objek = ?'; $p[] = $objek; }
if ($aksi !== '') { $where[] = 'a.aksi = ?'; $p[] = $aksi; }
$rows = Db::all('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC LIMIT 200', $p);
$objekList = array_column(Db::all('SELECT DISTINCT objek FROM audit_log ORDER BY objek'), 'objek');
$aksiList = array_column(Db::all('SELECT DISTINCT aksi FROM audit_log ORDER BY aksi'), 'aksi');
ob_start(); ?>
<div class="page-h"><div><h1>Log Audit</h1><p>200 aktivitas terakhir. Catatan ini tidak dapat diubah dari aplikasi.</p></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="audit">
    <select name="objek"><option value="">Semua objek</option><?= options($objekList, $objek, false) ?></select>
    <select name="aksi"><option value="">Semua aksi</option><?= options($aksiList, $aksi, false) ?></select>
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Waktu</th><th>Petugas</th><th>Aksi</th><th>Objek</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="nowrap" data-label="Waktu"><?= e($r['created_at']) ?></td><td data-label="Petugas"><?= e($r['username'] ?: 'publik/sistem') ?></td>
        <td data-label="Aksi"><span class="badge"><?= e($r['aksi']) ?></span></td><td data-label="Objek"><?= e($r['objek']) ?><?= $r['objek_id'] ? ' #' . (int) $r['objek_id'] : '' ?></td><td data-label="Detail"><small><?= e($r['detail']) ?></small></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty">Belum ada aktivitas.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render('Log Audit', ob_get_clean());
