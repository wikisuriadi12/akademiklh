<?php
$bolehJual = Auth::can('penjualan'); $bolehStok = Auth::can('gudang.view');
if (!$bolehJual && !$bolehStok) { Auth::require('penjualan'); }
$tabs = [];
if ($bolehJual) { $tabs += ['ringkasan' => 'Ringkasan', 'penjualan' => 'Transaksi', 'produk' => 'Per Produk', 'kategori' => 'Per Kategori', 'pelanggan' => 'Per Pelanggan', 'kasir' => 'Per Kasir']; }
if ($bolehStok) { $tabs += ['masuk' => 'Barang Masuk', 'stok' => 'Posisi Stok', 'mutasi' => 'Mutasi Stok']; }
$tab = isset($tabs[in_str('tab')]) ? in_str('tab') : array_key_first($tabs);
$dari = Domain::validDate(in_str('dari')) ? in_str('dari') : date('Y-m-01');
$sampai = Domain::validDate(in_str('sampai')) ? in_str('sampai') : date('Y-m-d');
if ($dari > $sampai) { [$dari, $sampai] = [$sampai, $dari]; }
$metodeNama = ['tunai' => 'Tunai', 'dompet' => 'Saldo Santri', 'qris' => 'QRIS'];
$jenisNama = ['umum' => 'Umum', 'pegawai' => 'Pegawai', 'santri' => 'Santri'];
$tipeNama = ['transfer_masuk' => 'Transfer masuk', 'transfer_keluar' => 'Transfer keluar', 'opname' => 'Opname / koreksi', 'keluar' => 'Keluar (rusak/hilang)'];
$lokNama = Pos::LOKASI;
$f = ['metode' => in_str('metode'), 'jenis' => in_str('jenis'), 'kasir' => (int) in_str('kasir', '0'), 'status' => in_array(in_str('status'), ['sah', 'batal', 'semua'], true) ? in_str('status') : 'sah',
    'urut' => in_array(in_str('urut'), ['qty', 'omzet', 'laba'], true) ? in_str('urut') : 'qty', 'lok' => isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : '', 'pemasok' => in_str('pemasok'),
    'stokf' => in_array(in_str('stokf'), ['', 'habis', 'menipis', 'ada'], true) ? in_str('stokf') : '', 'tipe' => isset($tipeNama[in_str('tipe')]) ? in_str('tipe') : ''];
$stokLok = $f['lok'] !== '' ? $f['lok'] : Pos::TOKO;

// Setiap tab mengisi: $judul, $kolom [label => jenis(t|n|rp|tgl|rp_b)], $baris (nilai mentah), $kpi, $extra
$judul = $tabs[$tab]; $kolom = []; $baris = []; $kpi = []; $extra = ''; $aksiBaris = null; $pesanKosong = 'Tidak ada data pada rentang ini.';
switch ($tab) {
    case 'ringkasan':
        $r = LaporanPos::ringkasan($dari, $sampai);
        $kpi = [['Transaksi', (string) $r['n'], 'receipt', ''], ['Omzet', rp($r['omzet']), 'wallet', 'c-ok'], ['Laba kotor', rp($r['laba']), 'trending-up', 'c-info'], ['Rata-rata per transaksi', rp($r['rata']), 'tag', ''],
            ['Barang terjual', (string) $r['qty'], 'box', ''], ['Diskon diberikan', rp($r['diskon']), 'tag', 'c-acc'], ['Transaksi dibatalkan', $r['batal_n'] . ' (' . rp($r['batal_nilai']) . ')', 'alert', 'c-acc']];
        $hari = LaporanPos::perHari($dari, $sampai);
        $grafik = [];
        foreach ($hari as $d => $v) { $grafik[substr($d, 8) . '/' . substr($d, 5, 2)] = $v['omzet']; }
        if (count($grafik) > 31) { $grafik = array_slice($grafik, -31, null, true); }
        $extra = '<h3>Omzet per hari</h3>' . Charts::bars($grafik, 'Omzet per hari');
        $kolom = ['Tanggal' => 'tgl', 'Transaksi' => 'n', 'Omzet' => 'rp'];
        foreach (array_reverse($hari, true) as $d => $v) { if ($v['n'] > 0) { $baris[] = [$d, $v['n'], $v['omzet']]; } }
        $mm = LaporanPos::perMetode($dari, $sampai); $mj = LaporanPos::perJenis($dari, $sampai);
        $extra .= '<div class="grid g2" style="margin-top:12px"><div><h3>Per metode bayar</h3>' . ($mm ? Charts::hbars(array_column(array_map(static fn($x) => [$metodeNama[$x['metode']] ?? $x['metode'], (int) $x['omzet']], $mm), 1, 0), 'rp') : '<div class="empty">Belum ada.</div>') . '</div>'
            . '<div><h3>Per jenis pelanggan</h3>' . ($mj ? Charts::hbars(array_column(array_map(static fn($x) => [$jenisNama[$x['jenis']] ?? $x['jenis'], (int) $x['omzet']], $mj), 1, 0), 'rp') : '<div class="empty">Belum ada.</div>') . '</div></div><h3 style="margin-top:12px">Rincian harian</h3>';
        break;
    case 'penjualan':
        $rows = LaporanPos::transaksi($dari, $sampai, isset($metodeNama[$f['metode']]) ? $f['metode'] : '', isset($jenisNama[$f['jenis']]) ? $f['jenis'] : '', $f['kasir'], $f['status']);
        $kolom = ['No. struk' => 't', 'Waktu' => 't', 'Pelanggan' => 't', 'Metode' => 't', 'Total' => 'rp', 'Status' => 't', 'Kasir' => 't'];
        $tot = 0;
        foreach ($rows as $x) {
            $pel = $x['santri_nama'] ?? (($x['pelanggan'] ?? '') !== '' ? $x['pelanggan'] : 'Umum');
            $baris[] = [$x['no_struk'], substr((string) $x['created_at'], 0, 16), $pel, $metodeNama[$x['metode']] ?? $x['metode'], (int) $x['total'], (int) $x['dibatalkan'] ? 'Batal' : 'Sah', $x['kasir'] ?? '-', (int) $x['id']];
            if (!(int) $x['dibatalkan']) { $tot += (int) $x['total']; }
        }
        $kpi = [['Jumlah transaksi', (string) count($rows), 'receipt', ''], ['Total (tidak termasuk batal)', rp($tot), 'wallet', 'c-ok']];
        $aksiBaris = static fn($b) => '<a class="btn sm ghost" href="?p=struk&amp;id=' . (int) $b[7] . '">Struk</a>';
        break;
    case 'produk':
        $rows = LaporanPos::produk($dari, $sampai, $f['urut']);
        $kolom = ['#' => 'n', 'Barang' => 't', 'Terjual' => 'n', 'Omzet' => 'rp', 'Laba kotor' => 'rp'];
        foreach ($rows as $i => $x) { $baris[] = [$i + 1, $x['nama'], (int) $x['qty'], (int) $x['omzet'], (int) $x['laba']]; }
        $top = array_slice($rows, 0, 8); $tm = [];
        foreach ($top as $x) { $tm[$x['nama']] = (int) $x[$f['urut']]; }
        $extra = $tm ? '<h3>10 teratas menurut ' . ['qty' => 'jumlah terjual', 'omzet' => 'omzet', 'laba' => 'laba'][$f['urut']] . '</h3>' . Charts::hbars($tm, static fn($v) => $f['urut'] === 'qty' ? $v . ' terjual' : rp($v)) : '';
        $tl = LaporanPos::tidakLaku($dari, $sampai);
        $extra .= $tl ? '<h3 style="margin-top:12px">Tidak laku pada periode ini (' . count($tl) . ' barang di Market)</h3><div class="scroll"><table class="rt"><thead><tr><th>Barang</th><th>Kategori</th><th class="r">Stok Market</th></tr></thead><tbody>'
            . implode('', array_map(static fn($x) => '<tr><td><b>' . e($x['nama']) . '</b><br><small>' . e($x['kode']) . '</small></td><td>' . e($x['kategori'] ?: '-') . '</td><td class="r">' . (int) $x['qty'] . '</td></tr>', array_slice($tl, 0, 50))) . '</tbody></table></div><h3 style="margin-top:12px">Peringkat produk</h3>' : '<h3 style="margin-top:12px">Peringkat produk</h3>';
        $pesanKosong = 'Belum ada penjualan pada rentang ini.';
        break;
    case 'kategori':
        $rows = LaporanPos::kategori($dari, $sampai);
        $kolom = ['Kategori' => 't', 'Terjual' => 'n', 'Omzet' => 'rp', 'Laba kotor' => 'rp'];
        foreach ($rows as $x) { $baris[] = [$x['kategori'], (int) $x['qty'], (int) $x['omzet'], (int) $x['laba']]; }
        $extra = $rows ? Charts::hbars(array_column(array_map(static fn($x) => [$x['kategori'], (int) $x['omzet']], $rows), 1, 0), 'rp') : '';
        break;
    case 'pelanggan':
        $rows = LaporanPos::perJenis($dari, $sampai);
        $kolom = ['Jenis pelanggan' => 't', 'Transaksi' => 'n', 'Omzet' => 'rp'];
        foreach ($rows as $x) { $baris[] = [$jenisNama[$x['jenis']] ?? $x['jenis'], (int) $x['n'], (int) $x['omzet']]; }
        $mm = LaporanPos::perMetode($dari, $sampai);
        $extra = '<h3>Per metode bayar</h3><div class="scroll"><table class="rt"><thead><tr><th>Metode</th><th class="r">Transaksi</th><th class="r">Omzet</th></tr></thead><tbody>'
            . implode('', array_map(static fn($x) => '<tr><td>' . e($metodeNama[$x['metode']] ?? $x['metode']) . '</td><td class="r">' . (int) $x['n'] . '</td><td class="r">' . rp($x['omzet']) . '</td></tr>', $mm)) . ($mm ? '' : '<tr><td colspan="3" class="empty">Belum ada.</td></tr>') . '</tbody></table></div><h3 style="margin-top:12px">Per jenis pelanggan</h3>';
        break;
    case 'kasir':
        $rows = LaporanPos::perKasir($dari, $sampai);
        $kolom = ['Kasir' => 't', 'Transaksi' => 'n', 'Omzet' => 'rp'];
        foreach ($rows as $x) { $baris[] = [$x['kasir'], (int) $x['n'], (int) $x['omzet']]; }
        break;
    case 'masuk':
        $rows = Gudang::laporanMasuk($dari, $sampai, $f['lok'], $f['pemasok']);
        $kolom = ['Waktu' => 't', 'Supplier' => 't', 'Kode' => 't', 'Barang' => 't', 'Lokasi' => 't', 'Jumlah' => 'n', 'Harga beli' => 'rp', 'Total' => 'rp', 'Catatan' => 't'];
        $tq = 0; $tn = 0; $perSup = [];
        foreach ($rows as $x) {
            $sub = (int) $x['qty'] * (int) $x['harga_beli']; $tq += (int) $x['qty']; $tn += $sub;
            $perSup[$x['pemasok'] ?: '(tanpa supplier)'] = ($perSup[$x['pemasok'] ?: '(tanpa supplier)'] ?? 0) + $sub;
            $baris[] = [substr((string) $x['created_at'], 0, 16), $x['pemasok'] ?: '-', $x['kode'], $x['nama'], $lokNama[$x['unit']] ?? $x['unit'], (int) $x['qty'], (int) $x['harga_beli'], $sub, $x['catatan'] ?: '-'];
        }
        $kpi = [['Total barang masuk', (string) $tq, 'box', ''], ['Nilai pembelian', rp($tn), 'wallet', 'c-ok']];
        arsort($perSup);
        $extra = $perSup ? '<h3>Pembelian per supplier</h3>' . Charts::hbars(array_slice($perSup, 0, 8, true), 'rp') . '<h3 style="margin-top:12px">Rincian</h3>' : '';
        break;
    case 'stok':
        $rows = LaporanPos::stok($stokLok, $f['stokf']);
        $kolom = ['Kode' => 't', 'Barang' => 't', 'Kategori' => 't', 'Stok' => 'n', 'Batas min' => 'n', 'Nilai modal' => 'rp', 'Nilai jual' => 'rp'];
        $tm = 0; $tj = 0; $habis = 0; $tipis = 0;
        foreach ($rows as $x) {
            $tm += $x['modal']; $tj += $x['jual']; if ($x['qty'] <= 0) { $habis++; } elseif ($x['qty'] <= $x['stok_min']) { $tipis++; }
            $baris[] = [$x['kode'], $x['nama'], $x['kategori'] ?: '-', $x['qty'], $x['stok_min'], $x['modal'], $x['jual']];
        }
        $kpi = [['Jenis barang', (string) count($rows), 'box', ''], ['Nilai modal', rp($tm), 'wallet', 'c-ok'], ['Potensi nilai jual', rp($tj), 'trending-up', 'c-info'], ['Habis / menipis', $habis . ' / ' . $tipis, 'alert', 'c-acc']];
        $judul = 'Posisi Stok ' . $lokNama[$stokLok];
        break;
    case 'mutasi':
        $rows = LaporanPos::mutasi($dari, $sampai, $f['tipe']);
        $kolom = ['Waktu' => 't', 'Jenis' => 't', 'Lokasi' => 't', 'Kode' => 't', 'Barang' => 't', 'Jumlah' => 'n', 'Stok akhir' => 'n', 'Catatan' => 't', 'Petugas' => 't'];
        foreach ($rows as $x) { $baris[] = [substr((string) $x['created_at'], 0, 16), $tipeNama[$x['tipe']] ?? $x['tipe'], $lokNama[$x['unit']] ?? $x['unit'], $x['kode'], $x['nama'], (int) $x['qty'], (int) $x['stok_setelah'], $x['catatan'] ?: '-', $x['petugas'] ?: '-']; }
        break;
}
$nKol = count($kolom);
if (in_str('csv') === '1') {
    csv_out('laporan-' . $tab . '-' . $dari . '-' . $sampai . '.csv', array_keys($kolom), array_map(static fn($b) => array_slice($b, 0, $nKol), $baris));
    exit;
}
$qs = static fn(array $x = []) => '?' . http_build_query(array_filter(['p' => 'laporan_pos', 'tab' => $GLOBALS['tab'], 'dari' => $GLOBALS['dari'], 'sampai' => $GLOBALS['sampai'], 'metode' => $GLOBALS['f']['metode'], 'jenis' => $GLOBALS['f']['jenis'], 'kasir' => $GLOBALS['f']['kasir'] ?: '', 'status' => $GLOBALS['f']['status'], 'urut' => $GLOBALS['f']['urut'], 'lok' => $GLOBALS['f']['lok'], 'pemasok' => $GLOBALS['f']['pemasok'], 'stokf' => $GLOBALS['f']['stokf'], 'tipe' => $GLOBALS['f']['tipe']] + $x, static fn($v) => $v !== '' && $v !== null));
$kasirList = $tab === 'penjualan' ? Db::all('SELECT id, nama FROM users WHERE role IN (\'kasir\',\'admin\') ORDER BY nama') : [];
$fmtSel = static fn($v, $jenis) => match ($jenis) { 'rp' => rp($v), 'n' => number_format((int) $v, 0, ',', '.'), 'tgl' => date('d/m/Y', strtotime((string) $v)), default => e((string) $v) };
ob_start(); ?>
<div class="page-h"><div><h1>Laporan LH Mart</h1><p>Periode <?= e(date('d/m/Y', strtotime($dari))) ?> s/d <?= e(date('d/m/Y', strtotime($sampai))) ?>. Hanya transaksi sah yang dihitung kecuali disebut lain.</p></div>
  <div class="acts"><a class="btn ghost" href="<?= e($qs(['csv' => 1])) ?>"><?= icon('download') ?> Ekspor CSV</a><button type="button" class="btn ghost" onclick="window.print()"><?= icon('receipt') ?> Cetak</button></div></div>
<div class="tabs" style="margin-bottom:12px;flex-wrap:wrap"><?php foreach ($tabs as $k => $v): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?p=laporan_pos&amp;tab=<?= e($k) ?>&amp;dari=<?= e($dari) ?>&amp;sampai=<?= e($sampai) ?>"><?= e($v) ?></a><?php endforeach; ?></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="laporan_pos"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($tab !== 'stok'): ?><input type="date" name="dari" value="<?= e($dari) ?>"><input type="date" name="sampai" value="<?= e($sampai) ?>">
    <?php foreach ([['Hari ini', 0], ['7 hari', 6], ['30 hari', 29]] as [$lb, $h]): ?><a class="btn sm ghost" href="?p=laporan_pos&amp;tab=<?= e($tab) ?>&amp;dari=<?= e(date('Y-m-d', strtotime("-$h days"))) ?>&amp;sampai=<?= e(date('Y-m-d')) ?>"><?= e($lb) ?></a><?php endforeach; ?><?php endif; ?>
    <?php if ($tab === 'penjualan'): ?>
      <select name="metode"><option value="">Semua metode</option><?= options($metodeNama, $f['metode']) ?></select>
      <select name="jenis"><option value="">Semua pelanggan</option><?= options($jenisNama, $f['jenis']) ?></select>
      <select name="kasir"><option value="0">Semua kasir</option><?php foreach ($kasirList as $k): ?><option value="<?= (int) $k['id'] ?>" <?= $f['kasir'] === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['nama']) ?></option><?php endforeach; ?></select>
      <select name="status"><?= options(['sah' => 'Hanya sah', 'batal' => 'Hanya batal', 'semua' => 'Semua'], $f['status']) ?></select>
    <?php elseif ($tab === 'produk'): ?><select name="urut"><?= options(['qty' => 'Urut jumlah terjual', 'omzet' => 'Urut omzet', 'laba' => 'Urut laba'], $f['urut']) ?></select>
    <?php elseif ($tab === 'masuk'): ?><select name="lok"><option value="">Semua lokasi</option><?= options(Pos::LOKASI, $f['lok']) ?></select>
      <select name="pemasok"><option value="">Semua supplier</option><?php foreach (Gudang::daftarPemasok() as $p): ?><option <?= $p === $f['pemasok'] ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select>
    <?php elseif ($tab === 'stok'): ?><select name="lok"><?= options(Pos::LOKASI, $stokLok) ?></select><select name="stokf"><?= options(['' => 'Semua barang', 'ada' => 'Ada stok', 'menipis' => 'Menipis', 'habis' => 'Habis'], $f['stokf']) ?></select>
    <?php elseif ($tab === 'mutasi'): ?><select name="tipe"><option value="">Semua jenis</option><?= options($tipeNama, $f['tipe']) ?></select><?php endif; ?>
    <button class="btn"><?= icon('search') ?> Tampilkan</button></form>
  <?php if ($kpi): ?><div class="kpis"><?php foreach ($kpi as [$lb, $nilai, $ic, $cls]): ?><div class="kpi"><div class="chip <?= e($cls) ?>"><?= icon($ic, 22) ?></div><div><span><?= e($lb) ?></span><b><?= e($nilai) ?></b></div></div><?php endforeach; ?></div><?php endif; ?>
  <?= $extra ?>
  <div class="scroll"><table class="rt"><thead><tr><?php foreach ($kolom as $lb => $j): ?><th class="<?= in_array($j, ['n', 'rp'], true) ? 'r' : '' ?>"><?= e($lb) ?></th><?php endforeach; ?><?= $aksiBaris ? '<th></th>' : '' ?></tr></thead><tbody>
  <?php foreach ($baris as $b): ?><tr><?php $i = 0; foreach ($kolom as $lb => $j): ?><td class="<?= in_array($j, ['n', 'rp'], true) ? 'r' : '' ?>" data-label="<?= e($lb) ?>"><?= $fmtSel($b[$i], $j) ?></td><?php $i++; endforeach; ?><?= $aksiBaris ? '<td class="r">' . $aksiBaris($b) . '</td>' : '' ?></tr><?php endforeach; ?>
  <?php if (!$baris): ?><tr><td colspan="<?= $nKol + 1 ?>" class="empty"><?= e($pesanKosong) ?></td></tr><?php endif; ?></tbody></table></div>
  <?php if (count($baris) >= 1000): ?><p class="mut">Menampilkan batas maksimum baris. Persempit rentang tanggal atau ekspor CSV.</p><?php endif; ?>
</div>
<?php render('Laporan LH Mart', ob_get_clean());
