<?php
Auth::require('gudang.edit');
$id = (int) in_str('id', '0');
$b = $id ? Gudang::find($id) : null;
if ($id && !$b) { redirect('?p=gudang'); }
$val = $b ?: ['kode' => '', 'nama' => '', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 0, 'harga_jual' => 0, 'stok_min' => 5];
$err = [];
if (is_post()) {
    csrf_check();
    [$c, $err] = Gudang::validateBarang($_POST);
    $val = $c;
    if (!$err) {
        try {
            $nid = Gudang::simpanBarang($c, $id ?: null, Auth::id());
            flash($id ? 'Barang diperbarui.' : 'Barang ditambahkan. Isi stok awal di Gudang lewat "Barang masuk", lalu pindahkan ke Market lewat "Transfer stok".');
            redirect('?p=gudang_item&id=' . $nid);
        } catch (InvalidArgumentException $ex) { $err['kode'] = $ex->getMessage(); }
    }
}
$f = static fn(string $k): string => e((string) ($val[$k] ?? ''));
$er = static fn(string $k): string => '';
ob_start(); ?>
<div class="page-h"><div><h1><?= $id ? 'Ubah barang' : 'Barang baru' ?></h1><p>Katalog dipakai bersama; stok dicatat terpisah di Gudang dan di Market.</p></div></div>
<?php if ($err): ?><div class="note err"><?php foreach ($err as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card form" autocomplete="off">
  <?= csrf_field() ?>
  <fieldset><legend>Data barang</legend>
    <label>Kode <input name="kode" value="<?= $f('kode') ?>" required style="text-transform:uppercase"><?= $er('kode') ?></label>
    <label>Nama barang <input name="nama" value="<?= $f('nama') ?>" required><?= $er('nama') ?></label>
    <label>Kategori <input name="kategori" value="<?= $f('kategori') ?>" list="kat" placeholder="Alat tulis, Makanan, Sabun..."></label>
    <datalist id="kat"><?php foreach (Gudang::kategori() as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
    <label>Satuan <input name="satuan" value="<?= $f('satuan') ?>"></label>
  </fieldset>
  <fieldset><legend>Harga &amp; batas stok</legend>
    <label>Harga beli (modal) <input name="harga_beli" data-rupiah value="<?= $f('harga_beli') ?>" inputmode="numeric"></label>
    <label>Harga jual <input name="harga_jual" data-rupiah value="<?= $f('harga_jual') ?>" inputmode="numeric" required><?= $er('harga_jual') ?></label>
    <label>Batas stok menipis <input name="stok_min" value="<?= $f('stok_min') ?>" inputmode="numeric"></label>
  </fieldset>
  <div class="acts"><button class="btn"><?= icon('check') ?> Simpan</button><a class="btn ghost" href="?p=gudang">Batal</a></div>
</form>
<?php render($id ? 'Ubah barang' : 'Barang baru', ob_get_clean());
