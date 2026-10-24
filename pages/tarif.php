<?php
Auth::require('tarif.view');
$canEdit = Auth::can('tarif.edit');
$ta = in_str('ta', Domain::tahunAjaran(date('Y-m')));
if (!preg_match('#^\d{4}/\d{4}$#', $ta)) { $ta = Domain::tahunAjaran(date('Y-m')); }

if (is_post() && $canEdit) {
    csrf_check();
    $ta = in_str('ta');
    try {
        if (in_str('aksi') === 'salin') {
            $n = Spp::salinTarif(in_str('dari'), $ta, (float) str_replace(',', '.', in_str('naik', '0')), Auth::id());
            flash("$n tarif disalin dari " . in_str('dari') . '.');
        } else {
            foreach (Domain::JENJANG as $j) {
                foreach (array_keys(Domain::UNIT) as $u) {
                    $raw = trim((string) ($_POST['t'][$j][$u] ?? ''));
                    if ($raw === '') { continue; }
                    Spp::setTarif($ta, $j, $u, (int) preg_replace('/\D/', '', $raw), Auth::id());
                }
            }
            flash('Tarif tahun ajaran ' . $ta . ' disimpan.');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=tarif&ta=' . urlencode($ta));
}
$grid = Spp::tarifGrid($ta);
$y = (int) substr($ta, 0, 4);
$opsiTa = [];
for ($i = $y - 2; $i <= $y + 2; $i++) { $opsiTa[] = $i . '/' . ($i + 1); }
$sebelum = ($y - 1) . '/' . $y;
ob_start(); ?>
<div class="page-h"><div><h1>Tarif SPP</h1><p>Tahun ajaran <b><?= e($ta) ?></b> (Juli &ndash; Juni), tarif per bulan.</p></div>
  <form method="get" class="filters" style="margin:0"><input type="hidden" name="p" value="tarif"><select name="ta" onchange="this.form.submit()" aria-label="Tahun ajaran"><?= options($opsiTa, $ta, false) ?></select></form></div>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="ta" value="<?= e($ta) ?>">
  <div class="scroll"><table>
    <thead><tr><th>Jenjang</th><?php foreach (Domain::UNIT as $ul): ?><th><?= e($ul) ?> (Rp/bulan)</th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (Domain::JENJANG as $j): ?>
      <tr><td><b><?= e($j) ?></b></td>
        <?php foreach (array_keys(Domain::UNIT) as $u): $val = $grid[$j][$u] ?? null; ?>
          <td><?php if ($canEdit): ?><input name="t[<?= e($j) ?>][<?= e($u) ?>]" inputmode="numeric" data-rupiah value="<?= $val !== null ? (int) $val : '' ?>" placeholder="belum diatur" style="width:100%;max-width:220px">
              <?php else: ?><?= $val !== null ? rp($val) : '<span class="mut">belum diatur</span>' ?><?php endif; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canEdit): ?><p style="margin-bottom:0"><button class="btn"><?= icon('check') ?> Simpan tarif</button></p><?php endif; ?>
</form>
<?php if ($canEdit): ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="ta" value="<?= e($ta) ?>"><input type="hidden" name="aksi" value="salin">
  <h2>Salin dari tahun ajaran lain</h2>
  <div class="filters">
    <select name="dari" aria-label="Salin dari"><?= options(array_values(array_diff($opsiTa, [$ta])), $sebelum, false) ?></select>
    <label class="chk" style="margin:0">Naik <input name="naik" value="0" size="3" inputmode="decimal" style="width:70px"> %</label>
    <button class="btn ghost" data-confirm="Salin tarif ke <?= e($ta) ?>? Tarif yang sudah terisi tidak diubah.">Salin tarif</button>
  </div>
  <small>Hanya mengisi tarif yang masih kosong; hasil dibulatkan ke ribuan terdekat.</small>
</form>
<?php endif;
render('Tarif SPP', ob_get_clean());
