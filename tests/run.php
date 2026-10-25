<?php
// Uji logika inti dengan SQLite memori: php tests/run.php
declare(strict_types=1);
require __DIR__ . '/bootstrap_db.php';

test_db();

$fail = 0;
function ok(string $name, bool $cond): void { global $fail; echo ($cond ? 'PASS' : 'FAIL') . "  $name\n"; if (!$cond) { $fail++; } }
function throws(string $name, callable $fn): void { try { $fn(); ok($name, false); } catch (InvalidArgumentException $e) { ok($name . ' [' . $e->getMessage() . ']', true); } }

// Domain
ok('tahun ajaran Okt 2026', Domain::tahunAjaran('2026-10') === '2026/2027');
ok('tahun ajaran Mar 2027', Domain::tahunAjaran('2027-03') === '2026/2027');
ok('tahun ajaran Jul 2026', Domain::tahunAjaran('2026-07') === '2026/2027');
ok('tahun ajaran Jun 2026', Domain::tahunAjaran('2026-06') === '2025/2026');
ok('periode valid', Domain::validPeriode('2026-10') && !Domain::validPeriode('2026-13'));

// Santri
$base = ['nama' => 'Ahmad', 'jenis_kelamin' => 'L', 'jenjang' => 'Ula', 'nama_wali' => 'Budi', 'tahun_masuk' => '2026', 'status' => 'aktif', 'potongan_spp' => '50.000'];
[$c, $e] = Santri::validate($base);
ok('validasi lolos & unit ikhwan', !$e && $c['unit'] === 'ikhwan' && $c['potongan_spp'] === 50000);
[, $e2] = Santri::validate(['nama' => '']);
ok('validasi gagal data kosong', count($e2) >= 4);
$id1 = Santri::create($c, null);
ok('NIS otomatis (26 + ikhwan 1 + Ula 2 + urut)', Santri::find($id1)['nis'] === '26120001');
[$c2] = Santri::validate(['nama' => 'Aisyah', 'jenis_kelamin' => 'P', 'jenjang' => 'Ula', 'nama_wali' => 'Umar', 'tahun_masuk' => '2026', 'status' => 'aktif']);
$id2 = Santri::create($c2, null);
ok('santri putri otomatis akhwat', Santri::find($id2)['unit'] === 'akhwat');
[$c3] = Santri::validate(['nama' => 'Zaid', 'jenis_kelamin' => 'L', 'jenjang' => 'Ulya', 'nama_wali' => 'Ali', 'tahun_masuk' => '2026', 'status' => 'nonaktif']);
Santri::create($c3, null);
throws('NIS ganda ditolak', fn() => Santri::create(array_merge($c, ['nis' => Santri::find($id1)['nis']]), null));

// Tagihan
$ta = '2026/2027';
Spp::setTarif($ta, 'Ula', 'ikhwan', 300000, null);
$r = Spp::generate('2026-10', null, null);
ok('generate: 1 dibuat, akhwat tanpa tarif, nonaktif dilewati', $r['dibuat'] === 1 && ($r['tanpa_tarif']['Ula Akhwat'] ?? 0) === 1);
$tg = Spp::tagihanSantri($id1)[0];
ok('potongan 50rb diterapkan', (int) $tg['nominal'] === 250000);
$r2 = Spp::generate('2026-10', null, null);
ok('generate ulang idempoten', $r2['dibuat'] === 0 && $r2['sudah_ada'] === 1);
Spp::setTarif($ta, 'Ula', 'akhwat', 280000, null);
$r3 = Spp::generate('2026-10', 'akhwat', null);
ok('scope akhwat hanya akhwat', $r3['dibuat'] === 1 && count(Spp::daftarTagihan(['periode' => '2026-10'], null)) === 2);
ok('scope membatasi daftar', count(Spp::daftarTagihan(['periode' => '2026-10'], 'ikhwan')) === 1);
throws('periode salah', fn() => Spp::generate('2026-99', null, null));

// Pembayaran
$tid = (int) $tg['id'];
$pid = Spp::bayar($tid, date('Y-m-d'), 100000, 'tunai', '', 1, null);
ok('kuitansi bernomor', str_starts_with(Spp::kuitansi($pid)['no_kuitansi'], 'KW-') && Spp::kuitansi($pid)['sisa'] === 150000);
throws('bayar melebihi sisa', fn() => Spp::bayar($tid, date('Y-m-d'), 150001, 'tunai', '', 1, null));
throws('bayar nol', fn() => Spp::bayar($tid, date('Y-m-d'), 0, 'tunai', '', 1, null));
throws('tanggal masa depan', fn() => Spp::bayar($tid, date('Y-m-d', strtotime('+2 days')), 1000, 'tunai', '', 1, null));
throws('metode salah', fn() => Spp::bayar($tid, date('Y-m-d'), 1000, 'kartu', '', 1, null));
throws('di luar scope akun', fn() => Spp::bayar($tid, date('Y-m-d'), 1000, 'tunai', '', 1, 'akhwat'));
Spp::bayar($tid, date('Y-m-d'), 150000, 'transfer', 'lunas', 1, null);
ok('lunas', Spp::getTagihan($tid)['dibayar'] == 250000);
throws('tagihan lunas tak bisa dibayar lagi', fn() => Spp::bayar($tid, date('Y-m-d'), 1, 'tunai', '', 1, null));

// Pembatalan
throws('alasan batal pendek', fn() => Spp::batalkan($pid, 'x', 1, null));
Spp::batalkan($pid, 'salah input nominal', 1, null);
ok('batal mengurangi dibayar', Spp::getTagihan($tid)['dibayar'] == 150000);
throws('batal dua kali', fn() => Spp::batalkan($pid, 'salah input nominal', 1, null));
ok('data pembayaran tetap ada (tidak dihapus)', (int) Db::val('SELECT COUNT(*) FROM pembayaran') === 2);

// Laporan
$tung = Spp::tunggakan(null);
ok('tunggakan: ikhwan sisa 100rb + akhwat 280rb', count($tung) === 2 && array_sum(array_column($tung, 'total')) === 380000);
ok('tunggakan scope ikhwan', count(Spp::tunggakan('ikhwan')) === 1);
$pen = Spp::penerimaan(date('Y-m-d'), date('Y-m-d'), null);
ok('penerimaan abaikan yang dibatalkan', count($pen) === 1 && (int) $pen[0]['jumlah'] === 150000);
$rp = Spp::ringkasPeriode('2026-10', null);
ok('ringkas periode', $rp['jumlah'] === 2 && $rp['ditagih'] === 530000 && $rp['dibayar'] === 150000);

// Auth: pembuatan user
$uid = Auth::createUser('bendahara1', 'Bendahara Ikhwan', 'bendahara', 'ikhwan', 'rahasia12345', null);
ok('user dibuat dengan hash', password_verify('rahasia12345', Db::val('SELECT password_hash FROM users WHERE id=?', [$uid])));
throws('password pendek', fn() => Auth::createUser('x1x', 'X', 'admin', 'semua', 'pendek', null));
throws('username ganda', fn() => Auth::createUser('bendahara1', 'X', 'admin', 'semua', 'rahasia12345', null));


// ---------- V2: jenis biaya & tagihan lain ----------
$seragam = Spp::jenisId('SERAGAM');
$r = Spp::buatTagihanLain($seragam, '2026-10', 200000, 'Batik', ['jenjang' => 'Ula'], null, null);
ok('tagihan lain untuk semua santri Ula aktif', $r['dibuat'] === 2);
$r = Spp::buatTagihanLain($seragam, '2026-10', 200000, 'Batik', ['jenjang' => 'Ula'], null, null);
ok('tagihan lain idempoten', $r['dibuat'] === 0 && $r['sudah_ada'] === 2);
$r = Spp::buatTagihanLain($seragam, '2026-10', 150000, 'Kaos olahraga', ['santri_id' => $id1], null, null);
ok('keterangan berbeda = tagihan terpisah', $r['dibuat'] === 1);
throws('SPP tidak bisa lewat tagihan lain', fn() => Spp::buatTagihanLain(Spp::jenisId('SPP'), '2026-10', 1000, '', [], null, null));
throws('filter tanpa santri', fn() => Spp::buatTagihanLain($seragam, '2026-10', 1000, 'x', ['jenjang' => 'TKQ'], null, null));
throws('nominal nol', fn() => Spp::buatTagihanLain($seragam, '2026-10', 0, 'x', [], null, null));
throws('akun akhwat tidak bisa menagih ikhwan', fn() => Spp::buatTagihanLain($seragam, '2026-10', 99000, 'Peci', ['unit' => 'ikhwan'], 'akhwat', null));
$tl = array_values(array_filter(Spp::tagihanSantri($id1), fn($t) => $t['jenis_kode'] === 'SERAGAM'));
ok('label tagihan lain', Spp::label($tl[0]) === 'Seragam - Batik' || Spp::label($tl[0]) === 'Seragam - Kaos olahraga');
ok('label SPP', Spp::label(Spp::tagihanSantri($id1)[count(Spp::tagihanSantri($id1)) - 1]) !== '');
$kid = Spp::bayar((int) $tl[0]['id'], date('Y-m-d'), 50000, 'tunai', '', 1, null);
ok('kuitansi tagihan lain berlabel', str_starts_with(Spp::kuitansi($kid)['label'], 'Seragam'));
ok('daftar tagihan filter jenis', count(Spp::daftarTagihan(['jenis' => $seragam], null)) >= 3);
$jid = Spp::tambahJenis('qurban', 'Tabungan Qurban', null);
ok('tambah jenis biaya', $jid > 0);
throws('kode jenis ganda', fn() => Spp::tambahJenis('QURBAN', 'x', null));
throws('SPP tak bisa dinonaktifkan', fn() => Spp::setJenisAktif(Spp::jenisId('SPP'), false, null));
Spp::setJenisAktif($jid, false, null);
throws('jenis nonaktif ditolak', fn() => Spp::buatTagihanLain($jid, '2026-10', 1000, '', [], null, null));

// ---------- V2: grafik data ----------
$pb = Spp::penerimaanPerBulan(6, null);
ok('penerimaan per bulan 6 bulan', count($pb) === 6 && $pb[date('Y-m')] > 0);
ok('terbaru: 2 pembayaran aktif (1 batal tak dihitung)', count(Spp::terbaru(null, 3)) === 2);

// ---------- V2: pendaftaran online ----------
$daftar = ['nama' => 'Calon Satu', 'jenis_kelamin' => 'P', 'jenjang' => 'TKQ', 'nama_wali' => 'Ibu Calon', 'hp_wali' => '081234560987', 'asal_sekolah' => 'TK Aisyiyah'];
$cs = Santri::daftarOnline($daftar);
ok('pendaftar online jadi calon akhwat', $cs['status'] === 'calon' && $cs['unit'] === 'akhwat' && $cs['sumber'] === 'online');
throws('pendaftar tanpa HP ditolak', fn() => Santri::daftarOnline(array_merge($daftar, ['hp_wali' => ''])));
ok('hitung calon', Santri::hitungStatus('calon', null) >= 1);
throws('terima di luar scope', fn() => Santri::terima((int) $cs['id'], 'ikhwan', null));
Santri::terima((int) $cs['id'], null, null);
ok('calon diterima jadi aktif', Santri::find((int) $cs['id'])['status'] === 'aktif');
throws('terima dua kali', fn() => Santri::terima((int) $cs['id'], null, null));
$cs2 = Santri::daftarOnline(array_merge($daftar, ['nama' => 'Calon Dua']));
throws('tolak butuh alasan', fn() => Santri::tolak((int) $cs2['id'], '', null, null));
Santri::tolak((int) $cs2['id'], 'Kuota penuh', null, null);
ok('ditolak tercatat', Santri::find((int) $cs2['id'])['status'] === 'ditolak');

// ---------- V2: naik kelas ----------
$n = Santri::naikkan([$id1, $id2], 'pindah_kelas', '', '4A', null, 1);
ok('pindah kelas 2 santri', $n === 2 && Santri::find($id1)['kelas'] === '4A' && Santri::find($id1)['jenjang'] === 'Ula');
$n = Santri::naikkan([$id1], 'pindah_kelas', 'Wustha', '1A', null, 1);
ok('naik jenjang', Santri::find($id1)['jenjang'] === 'Wustha');
$n = Santri::naikkan([$id1, $id2], 'lulus', '', '', 'ikhwan', 1);
ok('lulus hormati scope', $n === 1 && Santri::find($id1)['status'] === 'lulus' && Santri::find($id2)['status'] === 'aktif');
throws('naik tanpa tujuan', fn() => Santri::naikkan([$id2], 'pindah_kelas', '', '', null, 1));
throws('naik tanpa pilihan', fn() => Santri::naikkan([], 'lulus', '', '', null, 1));

// ---------- V2: impor CSV ----------
$tmp = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($tmp, "\xEF\xBB\xBFnama;jk;jenjang;kelas;wali;hp;tahun_masuk\nSiti Aminah;Perempuan;ula;2A;Pak Amin;081300001111;2025\nRoni;L;Ulya;1A;Bu Roni;081300002222;2025\n");
$im = Santri::importCsv($tmp, null, 1);
ok('impor 2 baris (titik koma, alias kolom, BOM)', $im['ok'] && $im['count'] === 2);
$bad = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($bad, "nama,jenis_kelamin,jenjang,nama_wali\nOke,L,Ula,Wali\n,X,Salah,\n");
$before = (int) Db::val('SELECT COUNT(*) FROM santri');
$im2 = Santri::importCsv($bad, null, 1);
ok('impor semua-atau-tidak: baris salah membatalkan semuanya', !$im2['ok'] && $im2['jumlah_galat'] === 1 && (int) Db::val('SELECT COUNT(*) FROM santri') === $before);
$noreq = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($noreq, "nama,jenjang\nA,Ula\n");
throws('kolom wajib hilang', fn() => Santri::importCsv($noreq, null, 1));
$sc = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($sc, "nama,jenis_kelamin,jenjang,nama_wali\nPutra,L,Ula,W\n");
$im3 = Santri::importCsv($sc, 'akhwat', 1);
ok('impor hormati scope unit', !$im3['ok']);
ok('template CSV punya header & contoh', count(Santri::templateCsv()) === 3);
foreach ([$tmp, $bad, $noreq, $sc] as $f) { @unlink($f); }

// ---------- V2: portal wali ----------
$nisAisyah = Santri::find($id2)['nis'];
Db::exec('UPDATE santri SET hp_wali = ? WHERE id = ?', ['0812-5550-6608', $id2]);
ok('verifikasi wali benar', Santri::verifikasiWali($nisAisyah, '6608')['id'] == $id2);
ok('verifikasi wali salah HP', Santri::verifikasiWali($nisAisyah, '0000') === null);
ok('verifikasi wali NIS tidak ada', Santri::verifikasiWali('99999999', '6608') === null);
ok('verifikasi wali format salah', Santri::verifikasiWali($nisAisyah, '66') === null);

// ---------- V2: pengaturan, rate limit, util ----------
ok('pengaturan bawaan', Settings::get('nama_lembaga') === 'Yayasan Luqmanul Hakim');
Settings::set('rekening', 'BSI 1234567890 a.n. Yayasan');
ok('pengaturan tersimpan', Settings::get('rekening') === 'BSI 1234567890 a.n. Yayasan');
throws('pengaturan tak dikenal', fn() => Settings::set('xyz', '1'));
$ok = 0; for ($i = 0; $i < 7; $i++) { if (RateLimit::hit('t', 'ip1', 5, 60)) { $ok++; } }
ok('rate limit: 5 lolos, sisanya diblokir', $ok === 5);
ok('rate limit: kunci lain tak terpengaruh', RateLimit::hit('t', 'ip2', 5, 60));
ok('wa_link 08 -> 62', wa_link('0812-3456-7890', 'Halo') === 'https://wa.me/6281234567890?text=Halo');
ok('wa_link nomor kosong', wa_link('', 'x') === null);
ok('rp_short', rp_short(3300000) === '3,3 jt' && rp_short(450000) === '450 rb');
ok('initials', initials('Muhammad Fatih') === 'MF');

// ---------- V3: gudang, POS, dompet ----------
[$cb] = Gudang::validateBarang(['kode' => 'ab-1', 'nama' => 'Pulpen', 'kategori' => 'ATK', 'satuan' => '', 'harga_beli' => '1.500', 'harga_jual' => '2.500', 'stok_min' => '5']);
ok('barang: kode jadi kapital, harga diparse, satuan bawaan', $cb['kode'] === 'AB-1' && $cb['harga_jual'] === 2500 && $cb['satuan'] === 'pcs');
[, $eb] = Gudang::validateBarang(['kode' => '!', 'nama' => '', 'harga_jual' => '0']);
ok('barang: validasi gagal', count($eb) === 3);
$b1 = Gudang::simpanBarang($cb, null, null);
throws('barang: kode ganda ditolak', fn() => Gudang::simpanBarang($cb, null, null));
ok('stok awal 0', Gudang::stok($b1, 'ikhwan') === 0);
Gudang::masuk($b1, 'ikhwan', 20, 1600, 'Toko A', '', null, null);
ok('barang masuk menambah stok & memperbarui harga beli', Gudang::stok($b1, 'ikhwan') === 20 && (int) Gudang::find($b1)['harga_beli'] === 1600);
ok('stok unit lain terpisah', Gudang::stok($b1, 'akhwat') === 0);
throws('masuk 0 ditolak', fn() => Gudang::masuk($b1, 'ikhwan', 0, 0, '', '', null, null));
throws('akun akhwat tak bisa ubah stok ikhwan', fn() => Gudang::masuk($b1, 'ikhwan', 5, 0, '', '', null, 'akhwat'));
throws('keluar tanpa alasan ditolak', fn() => Gudang::keluar($b1, 'ikhwan', 1, '', null, null));
throws('keluar melebihi stok ditolak', fn() => Gudang::keluar($b1, 'ikhwan', 99, 'rusak', null, null));
Gudang::keluar($b1, 'ikhwan', 2, 'rusak', null, null);
ok('keluar mengurangi stok', Gudang::stok($b1, 'ikhwan') === 18);
Gudang::opname($b1, 'ikhwan', 15, '', null, null);
ok('opname menyamakan stok', Gudang::stok($b1, 'ikhwan') === 15);
ok('riwayat stok tercatat 3 mutasi', count(Gudang::riwayat($b1, null)) === 3);

[$cb2] = Gudang::validateBarang(['kode' => 'SB-9', 'nama' => 'Sabun', 'harga_beli' => '3000', 'harga_jual' => '5000', 'stok_min' => '2']);
$b2 = Gudang::simpanBarang($cb2, null, null);
Gudang::masuk($b2, 'ikhwan', 10, 3000, '', '', null, null);

throws('jual tanpa barang ditolak', fn() => Pos::jual('ikhwan', null, [], 'tunai', 0, null, null));
throws('jual uang kurang ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 2], 'tunai', 4000, null, null));
throws('jual melebihi stok ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 50], 'tunai', 999999, null, null));
// pelanggan umum / pegawai di kasir
$idU = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Pegawai: Budi');
ok('penjualan pegawai menyimpan nama pelanggan', Db::val('SELECT pelanggan FROM penjualan WHERE id = ?', [$idU]) === 'Pegawai: Budi');
$idV = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Umum');
ok('penjualan umum tanpa santri tersimpan', Db::val('SELECT pelanggan FROM penjualan WHERE id = ?', [$idV]) === 'Umum' && Db::val('SELECT santri_id FROM penjualan WHERE id = ?', [$idV]) === null);
Db::exec('UPDATE barang SET harga_pegawai = 777 WHERE id = ?', [$b1]);
$hj = (int) Db::val('SELECT harga_jual FROM barang WHERE id = ?', [$b1]);
$idP = Pos::jual('ikhwan', null, [$b1 => 2], 'tunai', 1000000, null, null, 0, 'Pegawai: Ani');
ok('pegawai dikenai harga pegawai', (int) Db::val('SELECT total FROM penjualan WHERE id = ?', [$idP]) === 1554);
$idQ = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Umum');
ok('umum tetap harga umum', (int) Db::val('SELECT total FROM penjualan WHERE id = ?', [$idQ]) === $hj);
Pos::batalkan($idP, 'uji', null, null); Pos::batalkan($idQ, 'uji', null, null);
Db::exec('UPDATE barang SET harga_pegawai = NULL WHERE id = ?', [$b1]);
Pos::batalkan($idU, 'uji', null, null); Pos::batalkan($idV, 'uji', null, null);
// impor Excel master barang + stok
$xl = Xlsx::tulis(['Master Barang' => array_merge([Gudang::IMPOR_HEADER], Gudang::templateContoh(), [['IMP-9', 'Barang Impor Uji', 'Uji', 'pak', '1.000', 'Rp 2.500', 'Rp 2.000', 3, 7, '']])]);
$xf = sys_get_temp_dir() . '/uji-impor.xlsx'; file_put_contents($xf, $xl);
$baca = Xlsx::baca($xf);
ok('xlsx tulis lalu baca kembali', count($baca) === 5 && $baca[1][1] === 'Air Mineral 600 ml' && $baca[1][5] === '3500' && $baca[4][0] === 'IMP-9');
$r1 = Gudang::imporBarang($baca, false, null);
ok('impor xlsx sukses (4 baru)', $r1['ok'] && $r1['baru'] === 4);
$iid = (int) Db::val("SELECT id FROM barang WHERE kode = 'IMP-9'");
ok('impor harga dan stok gudang benar', (int) Db::val('SELECT harga_jual FROM barang WHERE id = ?', [$iid]) === 2500 && Gudang::stok($iid, Pos::GUDANG) === 7 && Gudang::stok($iid, Pos::TOKO) === 0);
$kid = (int) Db::val("SELECT id FROM barang WHERE kode = 'KTN-001'");
ok('stok market terisi tampil di kasir', Gudang::stok($kid, Pos::TOKO) === 24 && in_array($kid, array_map(static fn($x) => (int) $x['id'], Gudang::daftarMarket()), true));
$r2 = Gudang::imporBarang($baca, false, null);
ok('impor ulang tanpa perbarui ditolak semua', !$r2['ok'] && $r2['jumlah_galat'] === 4 && (int) Db::val('SELECT COUNT(*) FROM barang WHERE kode = ?', ['IMP-9']) === 1);
$baca[4][5] = '3000'; $baca[4][8] = '2';
$r3 = Gudang::imporBarang(array_slice($baca, 0, 1) + [1 => $baca[4]], true, null);
ok('impor perbarui menimpa harga dan stok', $r3['ok'] && $r3['ubah'] === 1 && (int) Db::val('SELECT harga_jual FROM barang WHERE id = ?', [$iid]) === 3000 && Gudang::stok($iid, Pos::GUDANG) === 2);
$salah = [Gudang::IMPOR_HEADER, ['', 'Tanpa kode', '', '', 'abc', '0', '', 'x', ''], ['OK-1', 'Valid', '', '', '', '1000', '', '5', '']];
$r4 = Gudang::imporBarang($salah, false, null);
ok('baris salah menggagalkan seluruh impor', !$r4['ok'] && (int) Db::val("SELECT COUNT(*) FROM barang WHERE kode = 'OK-1'") === 0);
ok('header tanpa kolom wajib ditolak', !Gudang::imporBarang([['foo', 'bar'], ['1', '2']], false, null)['ok']);
$csv = sys_get_temp_dir() . '/uji-impor.csv'; file_put_contents($csv, "\xEF\xBB\xBFKode Barang;Nama Barang;Harga Jual;Stok Market\nCSV-1;Barang CSV;4.500;9\n");
$r5 = Gudang::imporBarang(Xlsx::bacaCsv($csv), false, null);
$cid = (int) Db::val("SELECT id FROM barang WHERE kode = 'CSV-1'");
ok('impor CSV titik koma dengan alias judul', $r5['ok'] && Gudang::stok($cid, Pos::TOKO) === 9);
foreach (['IMP-9', 'CSV-1', 'KTN-001', 'KTN-002', 'ATK-001'] as $kd) {
    $bid = (int) Db::val('SELECT id FROM barang WHERE kode = ?', [$kd]);
    if ($bid) { Db::exec('DELETE FROM stok WHERE barang_id = ?', [$bid]); Db::exec('DELETE FROM stok_mutasi WHERE barang_id = ?', [$bid]); Db::exec('DELETE FROM barang WHERE id = ?', [$bid]); }
}
@unlink($xf); @unlink($csv);

// satu toko: gabung stok Akhwat ke LH Mart
Gudang::ubah($b1, 'akhwat', 5, 'masuk', ['catatan' => 'uji gabung', 'user_id' => null]);
$sebelum = Gudang::stok($b1, Pos::TOKO);
Gudang::gabungAkhwat(null);
ok('stok akhwat lama pindah ke gudang', Gudang::stok($b1, Pos::GUDANG) >= 5 && Gudang::stok($b1, 'akhwat') === 0 && Gudang::stok($b1, Pos::TOKO) === $sebelum);
$g0 = Gudang::stok($b1, Pos::GUDANG);
Gudang::transfer($b1, Pos::GUDANG, Pos::TOKO, 3, '', null);
ok('transfer gudang ke market', Gudang::stok($b1, Pos::GUDANG) === $g0 - 3 && Gudang::stok($b1, Pos::TOKO) === $sebelum + 3);
throws('transfer melebihi stok gudang ditolak', fn() => Gudang::transfer($b1, Pos::GUDANG, Pos::TOKO, 99999, '', null));
throws('transfer lokasi sama ditolak', fn() => Gudang::transfer($b1, Pos::TOKO, Pos::TOKO, 1, '', null));
Gudang::transfer($b1, Pos::TOKO, Pos::GUDANG, 3, 'uji retur', null);
$gBaru = (int) Db::val("SELECT COALESCE(MAX(id),0)+1 FROM barang");
$idBaru = Gudang::simpanBarang(['kode' => 'ZZ-GDG', 'nama' => 'Barang hanya gudang', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 100, 'harga_jual' => 200, 'stok_min' => 0], null, null);
Gudang::masuk($idBaru, Pos::GUDANG, 10, 100, '', '', null, null);
ok('barang hanya di gudang tidak terbaca kasir', !in_array($idBaru, array_map(static fn($r) => (int) $r['id'], Gudang::daftarMarket()), true));
throws('transfer ke market ditolak bila item belum ada di stok market', fn() => Gudang::transfer($idBaru, Pos::GUDANG, Pos::TOKO, 4, '', null));
$idQ2 = Gudang::simpanBarang(['kode' => 'ZZ-Q2', 'nama' => 'Ambil Banyak', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 1, 'harga_jual' => 2, 'stok_min' => 0], null, null);
ok('belumDiMarket memuat barang baru', in_array($idBaru, array_map(static fn($r) => (int) $r['id'], Gudang::belumDiMarket()), true));
ok('daftarkanBanyak', Gudang::daftarkanBanyak([$idBaru, $idQ2], null) === 2 && !in_array($idBaru, array_map(static fn($r) => (int) $r['id'], Gudang::belumDiMarket()), true));
Db::exec('DELETE FROM stok WHERE barang_id = ?', [$idQ2]); Db::exec('DELETE FROM stok_mutasi WHERE barang_id = ?', [$idQ2]); Db::exec('DELETE FROM barang WHERE id = ?', [$idQ2]);
Gudang::daftarkanMarket($idBaru, null);
ok('daftarkan ke market', Gudang::daftarkanMarket($idBaru, null) === false && in_array($idBaru, array_map(static fn($r) => (int) $r['id'], Gudang::daftarMarket()), true));
Gudang::transfer($idBaru, Pos::GUDANG, Pos::TOKO, 4, '', null);
ok('barang setelah transfer terbaca kasir', in_array($idBaru, array_map(static fn($r) => (int) $r['id'], Gudang::daftarMarket()), true));
Db::exec('DELETE FROM stok WHERE barang_id = ?', [$idBaru]); Db::exec('DELETE FROM stok_mutasi WHERE barang_id = ?', [$idBaru]); Db::exec('DELETE FROM barang WHERE id = ?', [$idBaru]);
// barang masuk banyak + supplier + laporan + impor kolom 'stok'
$bm1 = Gudang::simpanBarang(['kode' => 'BM-1', 'nama' => 'Masuk Satu', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 100, 'harga_jual' => 200, 'stok_min' => 0], null, null);
$bm2 = Gudang::simpanBarang(['kode' => 'BM-2', 'nama' => 'Masuk Dua', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 100, 'harga_jual' => 200, 'stok_min' => 0], null, null);
throws('barang masuk tanpa supplier ditolak', fn() => Gudang::masukBanyak(Pos::GUDANG, '', '', [[$bm1, 5, 100]], null));
throws('barang masuk tanpa item ditolak', fn() => Gudang::masukBanyak(Pos::GUDANG, 'CV Maju', '', [[0, 0, 0]], null));
throws('barang masuk salah satu baris salah membatalkan semua', fn() => Gudang::masukBanyak(Pos::GUDANG, 'CV Maju', '', [[$bm1, 5, 100], [$bm2, 0, 100]], null));
ok('tidak ada yang masuk saat gagal', Gudang::stok($bm1, Pos::GUDANG) === 0);
ok('barang masuk banyak', Gudang::masukBanyak(Pos::GUDANG, 'CV Maju', 'Faktur 1', [[$bm1, 5, 150], [$bm2, 7, 0]], null) === 2 && Gudang::stok($bm1, Pos::GUDANG) === 5 && Gudang::stok($bm2, Pos::GUDANG) === 7);
ok('harga beli diperbarui dari barang masuk', (int) Db::val('SELECT harga_beli FROM barang WHERE id = ?', [$bm1]) === 150 && (int) Db::val('SELECT harga_beli FROM barang WHERE id = ?', [$bm2]) === 100);
$lm = Gudang::laporanMasuk(date('Y-m-d'), date('Y-m-d'), '', 'CV Maju');
ok('laporan barang masuk per supplier', count($lm) === 2 && $lm[0]['pemasok'] === 'CV Maju' && in_array('CV Maju', Gudang::daftarPemasok(), true));
$sx = [['Kode Barang', 'Nama Produk', 'Harga', 'Stok'], ['OLD-1', 'Barang Sistem Lama', '5000', '12']];
$rx = Gudang::imporBarang($sx, false, null, 'ikhwan');
ok('impor sistem lama: kolom stok ke Market', $rx['ok'] && Gudang::stok((int) Db::val("SELECT id FROM barang WHERE kode='OLD-1'"), Pos::TOKO) === 12);
$ry = Gudang::imporBarang([['Kode', 'Nama', 'Harga Jual', 'Stok'], ['OLD-2', 'Lama Dua', '6000', '4']], false, null);
ok('impor sistem lama: kolom stok ke Gudang bawaan', $ry['ok'] && Gudang::stok((int) Db::val("SELECT id FROM barang WHERE kode='OLD-2'"), Pos::GUDANG) === 4);
foreach ([$bm1, $bm2, (int) Db::val("SELECT id FROM barang WHERE kode='OLD-1'"), (int) Db::val("SELECT id FROM barang WHERE kode='OLD-2'")] as $zid) { Db::exec('DELETE FROM stok WHERE barang_id = ?', [$zid]); Db::exec('DELETE FROM stok_mutasi WHERE barang_id = ?', [$zid]); Db::exec('DELETE FROM barang WHERE id = ?', [$zid]); }
// bukti QRIS
define('BUKTI_UJI', true);
$bq = sys_get_temp_dir() . '/bq_' . bin2hex(random_bytes(4)); define('BUKTI_DIR_UJI', $bq);
$im = imagecreatetruecolor(2000, 1000); $png = sys_get_temp_dir() . '/bq_' . bin2hex(random_bytes(3)) . '.png'; imagepng($im, $png);
throws('bukti qris wajib ada', fn() => BuktiQris::siapkan(null));
throws('bukti qris bukan gambar ditolak', fn() => BuktiQris::siapkan(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'size' => 10]));
$bin = BuktiQris::siapkan(['error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'size' => filesize($png)]);
$gi = getimagesizefromstring($bin);
ok('bukti qris diperkecil jadi jpeg', $gi && $gi[2] === IMAGETYPE_JPEG && $gi[0] <= 1280);
$idBq = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Umum');
$nmBq = BuktiQris::simpan($idBq, $bin);
ok('bukti qris tersimpan dan terhubung ke penjualan', BuktiQris::jalur($nmBq) !== null && Db::val('SELECT bukti_qris FROM penjualan WHERE id = ?', [$idBq]) === $nmBq);
Pos::batalkan($idBq, 'uji', null, null);
foreach (glob($bq . '/*') ?: [] as $zf) { @unlink($zf); } foreach (glob($bq . '/.htaccess') ?: [] as $zf) { @unlink($zf); } @rmdir($bq); @unlink($png);
// QRIS dinamis
$enc = QrisApi::segel('rahasia-uji-123');
ok('rahasia API terenkripsi dan bisa dibuka', strpos($enc, 'rahasia') === false && QrisApi::buka($enc) === 'rahasia-uji-123' && QrisApi::buka('enc1:rusak') === '');
ok('QRIS dinamis nonaktif bila belum diatur', !QrisApi::siap());
Settings::set('qris_api_provider', 'bni'); ok('bni belum berjalan (adapter menunggu)', !QrisApi::siap());
Settings::set('qris_api_provider', 'simulasi');
ok('simulasi siap', QrisApi::siap());
$tq = QrisApi::mulai(7000, null);
ok('transaksi dinamis menunggu', QrisApi::status($tq['ref']) === 'menunggu');
throws('belum lunas tidak bisa dipakai jual', fn() => QrisApi::periksaUntukJual($tq['ref'], 7000));
ok('simulasi lunas', QrisApi::simulasiLunas($tq['ref']) && QrisApi::status($tq['ref']) === 'lunas');
throws('nominal beda ditolak', fn() => QrisApi::periksaUntukJual($tq['ref'], 7001));
QrisApi::periksaUntukJual($tq['ref'], 7000); QrisApi::pakai($tq['ref'], 1);
throws('QRIS tidak bisa dipakai dua kali', fn() => QrisApi::periksaUntukJual($tq['ref'], 7000));
$hit = Pos::hitung([$b1 => 2], 'Umum', false, 0);
ok('Pos::hitung sama dengan harga jual', $hit === 2 * (int) Db::val('SELECT harga_jual FROM barang WHERE id = ?', [$b1]));
Settings::set('qris_api_provider', 'midtrans'); Settings::set('qris_api_secret', QrisApi::segel('SB-Mid-server-uji'));
$kirimLog = [];
QrisApi::$http = function ($m, $u, $h, $b) use (&$kirimLog) {
    $kirimLog[] = [$m, $u, $h, $b];
    if ($m === 'POST') { return [200, json_encode(['status_code' => '201', 'transaction_status' => 'pending', 'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/x/qr-code']]])]; }
    return [200, json_encode(['transaction_status' => 'settlement', 'gross_amount' => '5000.00'])];
};
$tm = QrisApi::mulai(5000, null);
ok('midtrans: QR dibuat dengan basic auth & nominal', $tm['qr_url'] !== null && strpos($kirimLog[0][1], 'api.sandbox.midtrans.com/v2/charge') !== false && in_array('Authorization: Basic ' . base64_encode('SB-Mid-server-uji:'), $kirimLog[0][2], true) && json_decode($kirimLog[0][3], true)['transaction_details']['gross_amount'] === 5000);
ok('midtrans: polling status lunas', QrisApi::status($tm['ref']) === 'lunas');
$tm2 = QrisApi::mulai(6000, null);
$tm3 = QrisApi::mulai(4000, null);
QrisApi::$http = fn() => [200, json_encode(['transaction_status' => 'settlement', 'gross_amount' => '1000.00'])];
ok('midtrans: nominal tidak cocok jadi gagal', QrisApi::status($tm2['ref']) === 'gagal');
QrisApi::$http = fn() => [200, json_encode(['transaction_status' => 'pending'])];
$cb = ['order_id' => $tm3['ref'], 'status_code' => '200', 'gross_amount' => '4000.00', 'transaction_status' => 'settlement'];
[$k1] = QrisApi::callback(json_encode($cb + ['signature_key' => 'salah']));
ok('callback tanda tangan salah ditolak', $k1 === 403 && QrisApi::status($tm3['ref']) === 'menunggu');
[$k2] = QrisApi::callback(json_encode($cb + ['signature_key' => hash('sha512', $tm3['ref'] . '200' . '4000.00' . 'SB-Mid-server-uji')]));
QrisApi::$http = fn() => [200, json_encode(['transaction_status' => 'pending'])];
ok('callback sah menandai lunas', $k2 === 200 && QrisApi::status($tm3['ref']) === 'lunas');
QrisApi::$http = null; Settings::set('qris_api_provider', ''); Settings::set('qris_api_secret', '');
// laporan LH Mart
$lp0 = date('Y-m-d');
$idL1 = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Umum');
$idL2 = Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 1000000, null, null, 0, 'Pegawai: Cici');
$rk = LaporanPos::ringkasan($lp0, $lp0);
ok('laporan ringkasan menghitung transaksi sah', $rk['n'] >= 2 && $rk['omzet'] > 0 && $rk['qty'] >= 2);
$pj = array_column(LaporanPos::perJenis($lp0, $lp0), 'n', 'jenis');
ok('laporan per jenis pelanggan memisah pegawai dan umum', ($pj['pegawai'] ?? 0) >= 1 && ($pj['umum'] ?? 0) >= 1);
ok('laporan produk memuat barang terjual', count(LaporanPos::produk($lp0, $lp0)) >= 1 && count(LaporanPos::kategori($lp0, $lp0)) >= 1 && count(LaporanPos::perKasir($lp0, $lp0)) >= 1);
ok('laporan transaksi filter pegawai', count(LaporanPos::transaksi($lp0, $lp0, '', 'pegawai')) >= 1);
ok('laporan per hari memuat hari ini', LaporanPos::perHari($lp0, $lp0)[$lp0]['n'] >= 2);
ok('laporan stok dan mutasi bisa dibaca', is_array(LaporanPos::stok(Pos::TOKO)) && is_array(LaporanPos::stok(Pos::GUDANG, 'habis')) && is_array(LaporanPos::mutasi($lp0, $lp0)) && is_array(LaporanPos::tidakLaku($lp0, $lp0)));
Pos::batalkan($idL1, 'uji', null, null); Pos::batalkan($idL2, 'uji', null, null);
ok('transaksi batal tidak dihitung di omzet', LaporanPos::ringkasan($lp0, $lp0)['n'] === $rk['n'] - 2 && LaporanPos::ringkasan($lp0, $lp0)['batal_n'] >= 2);
// pengaman login
$_SESSION = [];
LoginGuard::siapkan(); $kd = (string) ($_SESSION['lg_kode'] ?? '');
ok('captcha menyiapkan kode 5 karakter tanpa karakter membingungkan', strlen($kd) === 5 && !preg_match('/[01OIl]/', $kd));
ok('login terlalu cepat ditolak', LoginGuard::cek(['captcha' => $kd]) !== null);
$_SESSION = []; LoginGuard::siapkan(); $kd = (string) ($_SESSION['lg_kode'] ?? ''); $_SESSION['lg_t'] = time() - 10;
ok('honeypot terisi ditolak', LoginGuard::cek(['captcha' => $kd, 'website' => 'http://spam']) !== null);
$_SESSION = []; LoginGuard::siapkan(); $kd = (string) ($_SESSION['lg_kode'] ?? ''); $_SESSION['lg_t'] = time() - 10;
ok('captcha salah ditolak', LoginGuard::cek(['captcha' => 'SALAH']) !== null);
$_SESSION = []; LoginGuard::siapkan(); $kd = (string) ($_SESSION['lg_kode'] ?? ''); $_SESSION['lg_t'] = time() - 10;
ok('captcha benar lolos (huruf kecil & spasi diabaikan)', LoginGuard::cek(['captcha' => ' ' . strtolower($kd) . ' ']) === null);
ok('captcha sekali pakai (replay ditolak)', LoginGuard::cek(['captcha' => $kd]) !== null);
$_SESSION = []; LoginGuard::siapkan(); $_SESSION['lg_t'] = time() - 10; $_SESSION['lg_c']['exp'] = time() - 1;
ok('captcha kedaluwarsa ditolak', LoginGuard::cek(['captcha' => (string) ($_SESSION['lg_kode'] ?? 'X')]) !== null);
ok('input login tak wajar ditolak', !LoginGuard::inputWajar('', 'x') && !LoginGuard::inputWajar('u', str_repeat('a', 500)) && !LoginGuard::inputWajar(str_repeat('u', 100), 'x') && LoginGuard::inputWajar('admin', 'rahasia'));
if (LoginGuard::gdTersedia()) {
    $_SESSION = ['lg_kode' => 'AB7K2'];
    ob_start(); LoginGuard::gambar(); $png = (string) ob_get_clean();
    ok('captcha gambar PNG valid', strncmp($png, "\x89PNG", 4) === 0);
}
$_SESSION = [];
// hutang & konsinyasi
$hk1 = Gudang::simpanBarang(['kode' => 'HK-1', 'nama' => 'Beli Hutang', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 0, 'harga_jual' => 3000, 'stok_min' => 0], null, null);
$hk2 = Gudang::simpanBarang(['kode' => 'HK-2', 'nama' => 'Titipan Roti', 'kategori' => '', 'satuan' => 'pcs', 'harga_beli' => 0, 'harga_jual' => 5000, 'stok_min' => 0], null, null);
throws('hutang tanpa harga beli ditolak', fn() => Gudang::masukBanyak(Pos::GUDANG, 'CV Hutang', '', [[$hk1, 10, 0]], null, 'hutang'));
throws('jatuh tempo salah ditolak', fn() => Gudang::masukBanyak(Pos::GUDANG, 'CV Hutang', '', [[$hk1, 10, 1000]], null, 'hutang', 'bukan-tanggal'));
Gudang::masukBanyak(Pos::GUDANG, 'CV Hutang', '', [[$hk1, 10, 2000]], null, 'hutang', date('Y-m-d', strtotime('-1 day')), 'F-001');
$rkH = Hutang::ringkasan();
ok('hutang tercatat per nama supplier', ($rkH['CV Hutang']['faktur_sisa'] ?? 0) === 20000 && $rkH['CV Hutang']['lewat'] === 20000 && Gudang::stok($hk1, Pos::GUDANG) === 10);
$fH = Hutang::faktur('CV Hutang'); $hid = (int) $fH[0]['id'];
ok('item faktur hutang terlacak', count(Hutang::itemFaktur($hid)) === 1 && (int) Hutang::itemFaktur($hid)[0]['qty'] === 10);
throws('bayar hutang melebihi sisa ditolak', fn() => Hutang::bayar('hutang', 'CV Hutang', $hid, 25000, date('Y-m-d'), '', null));
Hutang::bayar('hutang', 'CV Hutang', $hid, 5000, date('Y-m-d'), 'cicil', null);
ok('hutang sebagian', Hutang::faktur('CV Hutang')[0]['status'] === 'sebagian' && Hutang::ringkasan()['CV Hutang']['faktur_sisa'] === 15000);
Hutang::bayar('hutang', 'CV Hutang', $hid, 15000, date('Y-m-d'), '', null);
ok('hutang lunas dan hilang dari belum lunas', Hutang::faktur('CV Hutang', 'belum') === [] && Hutang::totalHutang() === 0);
// konsinyasi
throws('konsinyasi tanpa harga setor ditolak', fn() => Gudang::masukBanyak(Pos::TOKO, 'Bu Roti', '', [[$hk2, 6, 0]], null, 'konsinyasi'));
Gudang::masukBanyak(Pos::TOKO, 'Bu Roti', '', [[$hk2, 6, 3500]], null, 'konsinyasi');
ok('konsinyasi: stok masuk, belum ada hutang', Gudang::stok($hk2, Pos::TOKO) === 6 && (Hutang::ringkasan()['Bu Roti']['kons_sisa'] ?? 0) === 0 && (int) Db::val("SELECT COUNT(*) FROM hutang WHERE pemasok = 'Bu Roti'") === 0);
throws('konsinyasi supplier lain ditolak', fn() => Gudang::masukBanyak(Pos::TOKO, 'Pak Kue', '', [[$hk2, 1, 3000]], null, 'konsinyasi'));
$idK = Pos::jual('ikhwan', null, [$hk2 => 4], 'tunai', 1000000, null, null, 0, 'Umum');
ok('konsinyasi: terutang sesuai terjual x harga setor', Hutang::konsinyasi('Bu Roti')['sisa'] === 14000 && Hutang::barangKonsinyasi('Bu Roti')[0]['terjual_qty'] === 4);
Pos::batalkan($idK, 'uji', null, null);
ok('penjualan batal tidak jadi hutang konsinyasi', Hutang::konsinyasi('Bu Roti')['sisa'] === 0);
$idK = Pos::jual('ikhwan', null, [$hk2 => 4], 'tunai', 1000000, null, null, 0, 'Umum');
throws('bayar konsinyasi melebihi terjual ditolak', fn() => Hutang::bayar('konsinyasi', 'Bu Roti', null, 15000, date('Y-m-d'), '', null));
Hutang::bayar('konsinyasi', 'Bu Roti', null, 10000, date('Y-m-d'), '', null);
ok('konsinyasi dibayar sebagian', Hutang::konsinyasi('Bu Roti')['sisa'] === 4000 && Hutang::ringkasan()['Bu Roti']['total_sisa'] === 4000);
throws('akhiri konsinyasi saat stok masih ada ditolak', fn() => Hutang::akhiriKonsinyasi($hk2, null));
Gudang::keluar($hk2, Pos::TOKO, 2, 'retur ke supplier', null, null);
Hutang::akhiriKonsinyasi($hk2, null);
ok('konsinyasi diakhiri', trim((string) Gudang::find($hk2)['konsinyasi_pemasok']) === '');
Pos::batalkan($idK, 'uji', null, null);
foreach ([$hk1, $hk2] as $zid) { Db::exec('DELETE FROM stok WHERE barang_id = ?', [$zid]); Db::exec('DELETE FROM stok_mutasi WHERE barang_id = ?', [$zid]); Db::exec('DELETE FROM penjualan_item WHERE barang_id = ?', [$zid]); Db::exec('DELETE FROM barang WHERE id = ?', [$zid]); }
Db::exec('DELETE FROM hutang'); Db::exec('DELETE FROM hutang_bayar');
// piutang pelanggan (bon di kasir)
throws('hutang tanpa nama ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'hutang', 0, null, null, 0, 'Umum'));
throws('hutang tempo salah ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'hutang', 0, null, null, 0, 'Umum: Budi', 'xx'));
$sebelumB1 = Gudang::stok($b1, Pos::TOKO);
$idH = Pos::jual('ikhwan', null, [$b1 => 2], 'hutang', 0, null, null, 0, 'Umum: Budi Santoso', date('Y-m-d', strtotime('-2 days')));
$pH = Db::one('SELECT * FROM piutang WHERE penjualan_id = ?', [$idH]);
ok('hutang kasir: stok berkurang, piutang tercatat atas nama', Gudang::stok($b1, Pos::TOKO) === $sebelumB1 - 2 && $pH['nama'] === 'Budi Santoso' && (int) $pH['dibayar'] === 0 && (int) $pH['total'] === (int) Db::val('SELECT total FROM penjualan WHERE id = ?', [$idH]));
ok('hutang kasir: dibayar 0 dan metode hutang', (int) Db::val('SELECT dibayar FROM penjualan WHERE id = ?', [$idH]) === 0 && Db::val('SELECT metode FROM penjualan WHERE id = ?', [$idH]) === 'hutang');
$rkP = Piutang::ringkasan();
ok('ringkasan piutang per nama + lewat tempo', $rkP['Budi Santoso']['sisa'] === (int) $pH['total'] && $rkP['Budi Santoso']['lewat'] === (int) $pH['total']);
ok('laporan pembelian hutang memuat bon', count(Piutang::laporan(date('Y-m-d'), date('Y-m-d'))) >= 1);
throws('bayar piutang melebihi sisa ditolak', fn() => Piutang::bayar((int) $pH['id'], (int) $pH['total'] + 1, date('Y-m-d'), '', null));
Piutang::bayar((int) $pH['id'], 1000, date('Y-m-d'), 'cicil', null);
ok('piutang sebagian', Db::val('SELECT status FROM piutang WHERE id = ?', [$pH['id']]) === 'sebagian' && Piutang::totalSisa() === (int) $pH['total'] - 1000);
throws('penjualan hutang yang sudah dicicil tidak bisa dibatalkan', fn() => Pos::batalkan($idH, 'uji', null, null));
Piutang::bayar((int) $pH['id'], (int) $pH['total'] - 1000, date('Y-m-d'), '', null);
ok('piutang lunas', Db::val('SELECT status FROM piutang WHERE id = ?', [$pH['id']]) === 'lunas' && Piutang::ringkasan() === []);
$idH2 = Pos::jual('ikhwan', null, [$b1 => 1], 'hutang', 0, null, null, 0, 'Pegawai: Rina');
ok('hutang pegawai memakai harga pegawai bila ada', (int) Db::val('SELECT total FROM penjualan WHERE id = ?', [$idH2]) > 0);
Pos::batalkan($idH2, 'uji', null, null);
ok('batal penjualan hutang menghapus piutang dari daftar', Db::val('SELECT status FROM piutang WHERE penjualan_id = ?', [$idH2]) === 'batal' && !isset(Piutang::ringkasan()['Rina']));
Db::exec('UPDATE piutang SET dibayar = 0 WHERE penjualan_id = ?', [$idH]); Pos::batalkan($idH, 'uji', null, null);
Db::exec('DELETE FROM piutang_bayar'); Db::exec('DELETE FROM piutang');
// santri unit mana pun boleh belanja di satu toko
$sa = (int) Db::val("SELECT id FROM santri WHERE unit = 'akhwat' LIMIT 1");
if ($sa) { Pos::topup($sa, 50000, 'uji', null, null); $ids = Pos::jual(Pos::TOKO, $sa, [$b1 => 1], 'dompet', 0, null, null); ok('santri akhwat bisa belanja di toko', (int) $ids > 0); Pos::batalkan($ids, 'uji', null, null); }


ok('gagal jual tidak mengurangi stok (rollback)', Gudang::stok($b1, 'ikhwan') === 15);
$pj = Pos::jual('ikhwan', null, [$b1 => 2, $b2 => 1], 'tunai', 20000, null, null);
$pjr = Pos::find($pj);
ok('jual tunai: total & nomor struk', (int) $pjr['total'] === 10000 && $pjr['no_struk'] === 'PJ-' . date('Y') . '-' . str_pad((string) $pj, 6, '0', STR_PAD_LEFT));
ok('jual mengurangi stok otomatis', Gudang::stok($b1, 'ikhwan') === 13 && Gudang::stok($b2, 'ikhwan') === 9);
$rk = Pos::ringkas(date('Y-m-d'), date('Y-m-d'), 'ikhwan');
ok('ringkas: omzet 10.000, laba 3.400 (2x900 + 1x2000... modal 1600 & 3000)', $rk['omzet'] === 10000 && $rk['laba'] === 10000 - (2 * 1600 + 3000));
throws('dompet tanpa santri ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'dompet', 0, null, null));
throws('dompet saldo kurang ditolak', fn() => Pos::jual('ikhwan', $id1, [$b1 => 1], 'dompet', 0, null, null));
throws('top up terlalu kecil ditolak', fn() => Pos::topup($id1, 500, '', null, null));
Pos::topup($id1, 20000, 'titipan', null, null);
ok('top up menambah saldo', Pos::saldo($id1) === 20000);
$pj2 = Pos::jual('ikhwan', $id1, [$b2 => 2], 'dompet', 0, null, null);
ok('belanja dompet memotong saldo', Pos::saldo($id1) === 10000 && Gudang::stok($b2, 'ikhwan') === 7);
$pjX = null; try { $pjX = Pos::jual('ikhwan', $id2, [$b1 => 1], 'dompet', 0, null, null); } catch (InvalidArgumentException $e) { }
ok('santri unit mana pun boleh belanja di satu toko (selama saldo cukup)', $pjX !== null || Pos::saldo($id2) < 2500);
if ($pjX) { Pos::batalkan($pjX, 'uji', null, null); }
throws('akun akhwat tak bisa jual di ikhwan', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 5000, null, 'akhwat'));
throws('batal tanpa alasan ditolak', fn() => Pos::batalkan($pj2, '', null, null));
Pos::batalkan($pj2, 'salah input', null, null);
ok('batal mengembalikan stok & saldo', Pos::saldo($id1) === 20000 && Gudang::stok($b2, 'ikhwan') === 9);
throws('batal dua kali ditolak', fn() => Pos::batalkan($pj2, 'lagi', null, null));

// QRIS statis di kasir
throws('QRIS ditolak saat belum diaktifkan', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'qris', 0, null, null));
$tmpQr = tempnam(sys_get_temp_dir(), 'qr');
$gd = imagecreatetruecolor(1200, 1200); imagepng($gd, $tmpQr);
throws('unggah QRIS non-gambar ditolak', fn() => Qris::simpan(['tmp_name' => __FILE__, 'size' => 100, 'error' => 0]));
$nmQr = Qris::simpan(['tmp_name' => $tmpQr, 'size' => (int) filesize($tmpQr), 'error' => 0]);
ok('QRIS: gambar tersimpan & diperkecil maks 1000 px', is_file(APP_ROOT . Qris::DIR . '/' . $nmQr) && getimagesize(APP_ROOT . Qris::DIR . '/' . $nmQr)[0] === 1000);
ok('QRIS belum aktif walau gambar ada', !Qris::aktif());
Settings::set('qris_aktif', '1');
ok('QRIS aktif setelah diaktifkan', Qris::aktif() && Qris::url() === 'assets/qris/' . $nmQr);
$stokQ = Gudang::stok($b1, 'ikhwan');
$pjq = Pos::jual('ikhwan', null, [$b1 => 2], 'qris', 0, null, null);
$pjqr = Pos::find($pjq);
ok('jual QRIS: metode qris, dibayar = total, stok berkurang', $pjqr['metode'] === 'qris' && (int) $pjqr['dibayar'] === (int) $pjqr['total'] && Gudang::stok($b1, 'ikhwan') === $stokQ - 2);
Pos::batalkan($pjq, 'uji QRIS', null, null);
ok('batal QRIS mengembalikan stok', Gudang::stok($b1, 'ikhwan') === $stokQ);
@unlink(APP_ROOT . Qris::DIR . '/' . $nmQr); @unlink(APP_ROOT . Qris::DIR . '/.htaccess'); @rmdir(APP_ROOT . Qris::DIR); @unlink($tmpQr);
Settings::set('qris_aktif', '0'); Settings::set('qris_gambar', '');

// Diskon pesanan
$stokD = Gudang::stok($b1, 'ikhwan');
throws('diskon melebihi subtotal ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 99999, null, null, 999999));
throws('diskon negatif ditolak', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 99999, null, null, -1));
$pjd = Pos::jual('ikhwan', null, [$b1 => 2], 'tunai', 10000, null, null, 1000);
$pjdr = Pos::find($pjd);
ok('diskon: total = subtotal - diskon, tersimpan', (int) $pjdr['total'] === 2 * 2500 - 1000 && (int) $pjdr['diskon'] === 1000 && (int) $pjdr['dibayar'] === 10000);
$rkD = Pos::ringkas(date('Y-m-d'), date('Y-m-d'), 'ikhwan');
Pos::batalkan($pjd, 'uji diskon', null, null);
ok('batal penjualan berdiskon mengembalikan stok', Gudang::stok($b1, 'ikhwan') === $stokD);
ok('laba kotor memperhitungkan diskon', $rkD['laba'] === $rk['laba'] + (2 * 2500 - 2 * 1600 - 1000) && $rkD['omzet'] === $rk['omzet'] + (2 * 2500 - 1000));
ok('ringkas tidak menghitung yang batal', Pos::ringkas(date('Y-m-d'), date('Y-m-d'), 'ikhwan')['n'] === 1);
Pos::koreksi($id1, -5000, 'ditarik wali', null, null);
ok('koreksi saldo', Pos::saldo($id1) === 15000);
throws('koreksi saldo negatif ditolak', fn() => Pos::koreksi($id1, -99999, 'x', null, null));
ok('barang terlaris', Pos::terlaris(date('Y-m-d'), date('Y-m-d'), null)[0]['qty'] == 2);
ok('stok menipis terdeteksi', Gudang::jumlahRendah('akhwat') === 2);
Gudang::setAktif($b1, false, null);
throws('barang nonaktif tak bisa dijual', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 99999, null, null));
ok('role kasir & gudang ada', isset(Domain::ROLE['kasir'], Domain::ROLE['gudang']));

// ---------- sistem terpisah ----------
ok('kasir hanya boleh masuk sistem LH Mart & Gudang', Domain::sistemBoleh('pos', 'kasir') && !Domain::sistemBoleh('pendidikan', 'kasir'));
ok('petugas gudang masuk sistem LH Mart & Gudang, halaman awal stok', Domain::sistemBoleh('pos', 'gudang') && !Domain::sistemBoleh('pendidikan', 'gudang') && Domain::home('pos', 'gudang') === 'gudang' && Domain::home('pos', 'kasir') === 'pos');
ok('tu hanya sistem pendidikan', Domain::sistemBoleh('pendidikan', 'tu') && !Domain::sistemBoleh('pos', 'tu'));
ok('admin boleh semua sistem', Domain::sistemBoleh('pendidikan', 'admin') && Domain::sistemBoleh('pos', 'admin'));
ok('sistem gudang terpisah sudah digabung', !isset(Domain::SISTEM['gudang']));
ok('sistem tak dikenal ditolak', !Domain::sistemBoleh('xyz', 'admin'));

// ---------- manajemen akun & sesi ----------
$ua = Auth::createUser('admin.satu', 'Admin Satu', 'admin', 'semua', 'passwordku123', null);
$ub = Auth::createUser('kasir.tes', 'Kasir Tes', 'kasir', 'ikhwan', 'passwordku123', null);
throws('password pendek ditolak', fn() => Auth::resetPassword($ub, 'pendek', $ua));
Auth::resetPassword($ub, 'passwordbaru99', $ua);
ok('reset password: hash baru berlaku', password_verify('passwordbaru99', Db::val('SELECT password_hash FROM users WHERE id = ?', [$ub])));
ok('reset password menaikkan session_version', (int) Db::val('SELECT session_version FROM users WHERE id = ?', [$ub]) === 2);
Auth::updateUser($ub, 'Kasir Baru', 'gudang', 'akhwat', $ua);
$r = Db::one('SELECT nama, role, unit_akses FROM users WHERE id = ?', [$ub]);
ok('ubah akun: nama, peran, unit', $r['nama'] === 'Kasir Baru' && $r['role'] === 'gudang' && $r['unit_akses'] === 'akhwat');
throws('peran tak valid ditolak', fn() => Auth::updateUser($ub, 'X', 'superuser', 'semua', $ua));
throws('admin tak bisa menurunkan diri sendiri', fn() => Auth::updateUser($ua, 'Admin Satu', 'tu', 'semua', $ua));
throws('admin aktif terakhir dilindungi', fn() => Auth::updateUser($ua, 'Admin Satu', 'tu', 'semua', $ub));
ok('login terakhir kosong', Auth::lastLogin($ub) === null);
Db::audit($ub, 'login', 'users', $ub);
ok('login terakhir tercatat', Auth::lastLogin($ub) !== null);
$_SESSION = ['uid' => $ua, 'sv' => 1, 'sistem' => 'pendidikan', 'last' => time()];
(new ReflectionProperty('Auth', 'cache'))->setValue(null, null);
ok('sesi aktif valid', Auth::user() !== null && Auth::sistem() === 'pendidikan');
$_SESSION['last'] = time() - Auth::IDLE - 5;
(new ReflectionProperty('Auth', 'cache'))->setValue(null, null);
ok('sesi menganggur >2 jam berakhir', Auth::user() === null && !isset($_SESSION['uid']));
$_SESSION = [];
(new ReflectionProperty('Auth', 'cache'))->setValue(null, null);

// ---------- BNI VA ----------
Bni::$override = ['mode' => 'simulasi', 'prefix' => '8001', 'client_id' => '001', 'secret' => 'rahasia-uji'];
$e = BniEnc::encrypt(['trx_id' => 'X1', 'n' => 5], '001', 'rahasia-uji');
ok('BniEnc: bolak-balik', BniEnc::decrypt($e, '001', 'rahasia-uji') === ['trx_id' => 'X1', 'n' => 5]);
ok('BniEnc: kunci salah ditolak', BniEnc::decrypt($e, '001', 'salah') === null);
ok('BniEnc: data rusak ditolak', BniEnc::decrypt('abc', '001', 'rahasia-uji') === null);
ok('BniEnc: aman dari karakter Unicode', BniEnc::decrypt(BniEnc::encrypt(['nama' => 'Zaenab Ä'], '001', 'rahasia-uji'), '001', 'rahasia-uji')['nama'] === 'Zaenab Ä');
[$cv] = Santri::validate(['nama' => 'Hafidz VA', 'jenis_kelamin' => 'L', 'jenjang' => 'Ula', 'nama_wali' => 'Bpk VA', 'hp_wali' => '0812-1111-2222', 'tahun_masuk' => '2026', 'status' => 'aktif']);
$sv = Santri::create($cv, null);
Spp::generate('2026-11', null, null);
$kid = Spp::jenisId('KITAB');
Spp::buatTagihanLain($kid, '2026-11', 120000, 'Paket', ['jenjang' => 'Ula', 'unit' => 'ikhwan'], null, null);
$tagSv = array_values(array_filter(Spp::tagihanSantri($sv), static fn($t) => $t['periode'] === '2026-11'));
$sumSisa = array_sum(array_map(static fn($t) => (int) $t['nominal'] - (int) $t['dibayar'], $tagSv));
ok('santri VA punya 2 tagihan', count($tagSv) === 2 && $sumSisa === 420000);
ok('nomor VA = awalan + NIS', Bni::nomorVa(Santri::find($sv)['nis']) === '8001' . Santri::find($sv)['nis']);
throws('VA tanpa tagihan terpilih ditolak', fn() => Va::buat($sv, [999999], null, null));
throws('akun akhwat tak bisa buat VA santri ikhwan', fn() => Va::buat($sv, [], null, 'akhwat'));
$vid = Va::buat($sv, [], null, null);
$V = Va::find($vid);
ok('label tagihan pada item VA memuat periode', in_array('SPP November 2026', array_map('Spp::label', Va::items($vid)), true));
ok('VA dibuat: status menunggu, jumlah 420.000', $V['status'] === 'menunggu' && (int) $V['jumlah'] === 420000 && count(Va::items($vid)) === 2);
throws('VA kedua saat masih aktif ditolak', fn() => Va::buat($sv, [], null, null));
ok('aktifUntuk menemukan VA', (int) Va::aktifUntuk($sv)['id'] === $vid);

$cid = '001'; $sec = 'rahasia-uji';
$cb = static fn(array $d, string $c = '001', string $k = 'rahasia-uji') => json_encode(['client_id' => $c, 'data' => BniEnc::encrypt($d, $c, $k)]);
$dasar = ['trx_id' => $V['trx_id'], 'virtual_account' => $V['virtual_account'], 'payment_amount' => '420000', 'payment_ntb' => 'NTB123', 'datetime_payment' => date('Y-m-d H:i:s')];
ok('callback: JSON rusak ditolak', Va::proses('bukan json')['status'] === '999');
ok('callback: client_id salah ditolak', Va::proses($cb($dasar, '002'))['status'] === '999');
ok('callback: dekripsi dengan kunci salah ditolak', Va::proses($cb($dasar, '001', 'kunci-lain'))['status'] === '999');
ok('callback: trx tidak dikenal', Va::proses($cb(['trx_id' => 'NOPE'] + $dasar))['status'] === '999');
ok('callback: VA tidak cocok ditolak', Va::proses($cb(['virtual_account' => '80019999'] + $dasar))['status'] === '102');
ok('callback: nominal beda ditolak', Va::proses($cb(['payment_amount' => '400000'] + $dasar))['status'] === '102');
ok('callback ditolak tidak mengubah status', Va::find($vid)['status'] === 'menunggu' && (int) Db::val("SELECT COUNT(*) FROM pembayaran WHERE metode = 'bni_va'") === 0);
ok('callback sah: 000', Va::proses($cb($dasar))['status'] === '000');
$V = Va::find($vid);
ok('VA jadi dibayar + NTB tercatat', $V['status'] === 'dibayar' && $V['ntb'] === 'NTB123');
ok('kedua tagihan lunas otomatis (metode bni_va)', array_sum(array_map(static fn($t) => (int) $t['nominal'] - (int) $t['dibayar'], array_filter(Spp::tagihanSantri($sv), static fn($t) => $t['periode'] === '2026-11'))) === 0
    && (int) Db::val("SELECT COUNT(*) FROM pembayaran WHERE metode = 'bni_va' AND dibatalkan = 0") === 2);
ok('callback ganda idempoten: tetap 000, tidak dobel bayar', Va::proses($cb($dasar))['status'] === '000' && (int) Db::val("SELECT COUNT(*) FROM pembayaran WHERE metode = 'bni_va'") === 2);
ok('log bank mencatat semua callback', (int) Db::val('SELECT COUNT(*) FROM bank_log') >= 8);
throws('VA dibayar tak bisa dibatalkan', fn() => Va::batalkan($vid, null, null));

// kasus: dibayar tunai dulu sebelum VA dibayar (kelebihan)
Spp::generate('2026-12', null, null);
$tg12 = Spp::tagihanSantri($sv)[0];
$v2 = Va::buat($sv, [(int) $tg12['id']], null, null);
Spp::bayar((int) $tg12['id'], date('Y-m-d'), (int) $tg12['nominal'], 'tunai', '', null, null);
$V2 = Va::find($v2);
$rr = Va::proses($cb(['trx_id' => $V2['trx_id'], 'virtual_account' => $V2['virtual_account'], 'payment_amount' => (string) $V2['jumlah'], 'payment_ntb' => 'NTB777']));
ok('bayar tunai sebelum VA: tetap ack 000, kelebihan ditandai', $rr['status'] === '000' && (int) Va::find($v2)['kelebihan'] === (int) $V2['jumlah']);

// batal & kedaluwarsa
Spp::generate('2027-01', null, null);
$v3 = Va::buat($sv, [], null, null);
Va::batalkan($v3, null, null);
ok('VA dibatalkan', Va::find($v3)['status'] === 'batal');
$r3 = Va::proses($cb(['trx_id' => Va::find($v3)['trx_id'], 'payment_amount' => (string) Va::find($v3)['jumlah']]));
ok('callback untuk VA batal ditolak', $r3['status'] === '999');
$v4 = Va::buat($sv, [], null, null);
Db::exec('UPDATE va_billing SET expired_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $v4]);
ok('VA lewat masa berlaku jadi kedaluwarsa', Va::kedaluwarsa() === 1 && Va::find($v4)['status'] === 'kedaluwarsa');
$sm = Va::buat($sv, [], null, null);
ok('simulasi bayar penuh melunasi', Va::simulasiBayar($sm)['status'] === '000' && Va::find($sm)['status'] === 'dibayar');
Spp::generate('2027-02', null, null);
$sm2 = Va::buat($sv, [], null, null);
ok('simulasi nominal kurang ditolak', Va::simulasiBayar($sm2, Va::find($sm2)['jumlah'] - 1000)['status'] === '102' && Va::find($sm2)['status'] === 'menunggu');
ok('izin callback: tanpa batasan = boleh', Va::izinkan('1.2.3.4', ''));
Bni::$override = ['mode' => 'simulasi', 'secret_cb' => 'tok', 'ips' => ['10.0.0.1']];
ok('izin callback: token salah ditolak', !Va::izinkan('10.0.0.1', 'x') && Va::izinkan('10.0.0.1', 'tok') && !Va::izinkan('9.9.9.9', 'tok'));
Bni::$override = null;

// ---------- V5: Tahfidz ----------
ok('grade nilai', Tahfidz::grade(95) === 'A' && Tahfidz::grade(85) === 'B+' && Tahfidz::grade(72) === 'B' && Tahfidz::grade(65) === 'C' && Tahfidz::grade(40) === 'D');
$base = ['santri_id' => $sv, 'tanggal' => date('Y-m-d'), 'jenis' => 'ziyadah', 'juz' => 30, 'surat' => 'An-Naba', 'ayat' => '1-20', 'halaman' => '2', 'nilai' => '88'];
$h1 = Tahfidz::setor($base, null, null);
ok('setoran tersimpan + grade otomatis', Db::val('SELECT grade FROM hafalan WHERE id = ?', [$h1]) === 'B+');
throws('juz 31 ditolak', fn() => Tahfidz::setor(['juz' => 31] + $base, null, null));
throws('halaman 0 ditolak', fn() => Tahfidz::setor(['halaman' => '0'] + $base, null, null));
throws('nilai 101 ditolak', fn() => Tahfidz::setor(['nilai' => '101'] + $base, null, null));
throws('tanggal masa depan ditolak', fn() => Tahfidz::setor(['tanggal' => date('Y-m-d', strtotime('+2 days'))] + $base, null, null));
throws('jenis tak dikenal ditolak', fn() => Tahfidz::setor(['jenis' => 'x'] + $base, null, null));
throws('santri unit lain ditolak', fn() => Tahfidz::setor($base, null, 'akhwat'));
Tahfidz::setor(['halaman' => '19,5'] + $base, null, null);
Tahfidz::setor(['jenis' => 'murojaah', 'juz' => 29, 'halaman' => '5'] + $base, null, null);
Tahfidz::setor(['juz' => 29, 'halaman' => '10'] + $base, null, null);
$pj = Tahfidz::progresJuz($sv);
ok('progres juz dibatasi 20 halaman, murojaah tak dihitung', $pj[30] == 20 && $pj[29] == 10);
ok('rekap: total 1,5 juz', Tahfidz::rekapSantri($sv)['juz'] == 1.5 && Tahfidz::rekapSantri($sv)['bulan_ini'] === 4);
ok('belum setor tidak memuat santri yang sudah setor', !in_array($sv, array_map(static fn($r) => (int) $r['id'], Tahfidz::belumSetor(date('Y-m-d'), [], null)), true));
throws('batal tanpa alasan ditolak', fn() => Tahfidz::batalkan($h1, '', null, null));
Tahfidz::batalkan($h1, 'salah santri', null, null);
ok('setoran batal tidak dihitung', Tahfidz::progresJuz($sv)[30] == 19.5 && count(Tahfidz::harian(date('Y-m-d'), [], null)) === 3);

// ---------- V5: Perizinan ----------
$m = date('Y-m-d H:i', time() + 3600); $e = date('Y-m-d H:i', time() + 3 * 86400);
throws('alasan terlalu pendek ditolak', fn() => Izin::ajukan($sv, 'pulang', $m, $e, 'ok', 'wali', null, null));
throws('waktu kembali sebelum keluar ditolak', fn() => Izin::ajukan($sv, 'pulang', $e, $m, 'acara keluarga', 'wali', null, null));
throws('izin > 30 hari ditolak', fn() => Izin::ajukan($sv, 'pulang', $m, date('Y-m-d H:i', time() + 40 * 86400), 'acara keluarga', 'wali', null, null));
throws('wali tak bisa izin di masa lalu', fn() => Izin::ajukan($sv, 'pulang', date('Y-m-d H:i', time() - 5 * 3600), $e, 'acara keluarga', 'wali', null, null));
throws('format waktu salah ditolak', fn() => Izin::ajukan($sv, 'pulang', 'besok', $e, 'acara keluarga', 'wali', null, null));
$iz = Izin::ajukan($sv, 'pulang', str_replace(' ', 'T', $m), $e, 'Walimah kakak', 'wali', null, null);
ok('izin wali berstatus menunggu', Izin::find($iz)['status'] === 'menunggu' && Izin::hitung('menunggu', null) === 1);
throws('izin bentrok ditolak', fn() => Izin::ajukan($sv, 'berobat', $m, $e, 'kontrol gigi', 'wali', null, null));
throws('akun akhwat tak bisa menyetujui izin ikhwan', fn() => Izin::setujui($iz, '', null, 'akhwat'));
throws('tolak tanpa alasan ditolak', fn() => Izin::tolak($iz, '', null, null));
throws('tidak bisa keluar sebelum disetujui', fn() => Izin::keluar($iz, null, null));
Izin::setujui($iz, 'hati-hati di jalan', $ua, null);
ok('disetujui + penyetuju tercatat', Izin::find($iz)['status'] === 'disetujui' && (int) Izin::find($iz)['penyetuju_id'] === $ua);
throws('menyetujui dua kali ditolak', fn() => Izin::setujui($iz, '', null, null));
Izin::keluar($iz, null, null);
ok('status keluar & tab aktif', Izin::find($iz)['status'] === 'keluar' && Izin::hitung('aktif', null) === 1 && Izin::hitung('terlambat', null) === 0);
ok('izin aktif terdeteksi pada tanggalnya', Izin::aktifPada($sv, date('Y-m-d', time() + 86400)) !== null && Izin::aktifPada($sv, date('Y-m-d', time() + 10 * 86400)) === null);
Db::exec('UPDATE izin SET selesai = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 7200), $iz]);
ok('terlambat terdeteksi', Izin::hitung('terlambat', null) === 1);
ok('kembali terlambat ditandai', Izin::kembali($iz, null, null) === true && (int) Izin::find($iz)['terlambat'] === 1);
$iz2 = Izin::ajukan($sv, 'berobat', $m, date('Y-m-d H:i', time() + 5 * 3600), 'Kontrol gigi', 'petugas', $ua, null);
ok('izin dari petugas langsung disetujui', Izin::find($iz2)['status'] === 'disetujui');
Izin::batal($iz2, null, null);
ok('izin dibatalkan', Izin::find($iz2)['status'] === 'batal' && count(Izin::daftar('riwayat', null)) === 2);

// ---------- V5: Kehadiran ----------
ok('sesi bawaan dari pengaturan', in_array('Ngaji Subuh', Kehadiran::sesiList(), true));
$besok = date('Y-m-d', time() + 86400);
$iz3 = Izin::ajukan($sv, 'pulang', date('Y-m-d 06:00', time() + 86400), date('Y-m-d 20:00', time() + 86400), 'Acara keluarga', 'petugas', $ua, null);
$lb = Kehadiran::lembar($besok, 'Ngaji Subuh', ['jenjang' => 'Ula', 'unit' => 'ikhwan'], null);
$row = array_values(array_filter($lb, static fn($r) => (int) $r['id'] === $sv))[0] ?? null;
ok('izin disetujui otomatis terisi I di lembar absensi', $row && $row['h_status'] === 'I' && !$row['h_tersimpan']);
throws('absensi tanggal masa depan ditolak', fn() => Kehadiran::simpan($besok, 'Ngaji Subuh', [$sv => ['status' => 'H']], null, null));
throws('absensi kosong ditolak', fn() => Kehadiran::simpan(date('Y-m-d'), 'Ngaji Subuh', [$sv => ['status' => '']], null, null));
throws('status tak dikenal ditolak', fn() => Kehadiran::simpan(date('Y-m-d'), 'Ngaji Subuh', [$sv => ['status' => 'X']], null, null));
throws('absensi santri unit lain ditolak', fn() => Kehadiran::simpan(date('Y-m-d'), 'Ngaji Subuh', [$sv => ['status' => 'H']], null, 'akhwat'));
ok('simpan absensi 2 santri', Kehadiran::simpan(date('Y-m-d'), 'Ngaji Subuh', [$sv => ['status' => 'H'], $id1 => ['status' => 'A', 'ket' => 'tanpa kabar']], null, null) === 2);
Kehadiran::simpan(date('Y-m-d'), 'Ngaji Subuh', [$sv => ['status' => 'S', 'ket' => 'demam']], null, null);
ok('simpan ulang memperbarui, tidak dobel', (int) Db::val("SELECT COUNT(*) FROM kehadiran WHERE santri_id = ? AND sesi = 'Ngaji Subuh'", [$sv]) === 1 && Db::val("SELECT status FROM kehadiran WHERE santri_id = ?", [$sv]) === 'S');
Kehadiran::simpan(date('Y-m-d'), 'Ngaji Maghrib', [$sv => ['status' => 'H']], null, null);
$rk = Kehadiran::rekapSantri($sv, date('Y-m'));
ok('rekap bulanan per status + persen', $rk['H'] === 1 && $rk['S'] === 1 && $rk['persen'] === 50);
ok('role ustadz & pembina masuk sistem pendidikan', Domain::sistemBoleh('pendidikan', 'ustadz') && Domain::sistemBoleh('pendidikan', 'pembina') && !Domain::sistemBoleh('pos', 'ustadz'));

// ---------- subdomain ----------
Host::$override = ['domain' => '', 'subdomain' => ['pendidikan' => 'akademik', 'pos' => 'pos', 'wali' => 'wali']];
ok('tanpa domain: mode satu alamat', Host::sistem('localhost') === null && Host::periksa('pos', null) === null && Host::url('pos') === '?p=login&sistem=pos');
Host::$override = ['domain' => 'contoh.sch.id', 'subdomain' => ['pendidikan' => 'akademik', 'pos' => 'pos', 'wali' => 'wali'], 'portal_utama' => true];
ok('host dikenali', Host::sistem('akademik.contoh.sch.id') === 'pendidikan' && Host::sistem('POS.contoh.sch.id:443') === 'pos' && Host::sistem('wali.contoh.sch.id') === 'wali' && Host::sistem('contoh.sch.id') === 'portal' && Host::sistem('www.contoh.sch.id') === 'portal');
ok('url per sistem', Host::url('pos') === 'https://pos.contoh.sch.id/' && Host::url('wali', 'p=cek') === 'https://wali.contoh.sch.id/?p=cek' && Host::url('portal') === 'https://contoh.sch.id/');
ok('halaman sistemnya boleh', Host::periksa('tahfidz', 'pendidikan') === null && Host::periksa('gudang', 'pos') === null && Host::periksa('cek', 'wali') === null && Host::periksa('login', 'pos') === null);
ok('halaman kasir di subdomain akademik dialihkan ke pos', Host::periksa('pos', 'pendidikan') === 'https://pos.contoh.sch.id/?p=pos');
ok('halaman santri di subdomain pos dialihkan ke akademik', Host::periksa('santri', 'pos') === 'https://akademik.contoh.sch.id/?p=santri');
ok('portal wali tidak bisa membuka halaman petugas', Host::periksa('users', 'wali') === 'https://akademik.contoh.sch.id/?p=users' && Host::periksa('login', 'wali') === 'https://wali.contoh.sch.id/?p=cek');
ok('cek tagihan di subdomain akademik dialihkan ke wali', Host::periksa('cek', 'pendidikan') === 'https://wali.contoh.sch.id/?p=cek');
ok('domain utama hanya portal', Host::periksa('portal', 'portal') === null && Host::periksa('dashboard', 'portal') === 'https://akademik.contoh.sch.id/?p=dashboard');
Host::$override = ['domain' => 'yayasanluqmanulhakimmarelan.com', 'subdomain' => ['pendidikan' => 'akademiklh', 'pos' => 'poslh', 'wali' => 'walilh'], 'portal_utama' => false];
ok('domain nyata: akademiklh dikenali', Host::sistem('akademiklh.yayasanluqmanulhakimmarelan.com') === 'pendidikan' && Host::url('pos') === 'https://poslh.yayasanluqmanulhakimmarelan.com/');
ok('tanpa portal utama: halaman depan akademiklh = login akademik', Host::url('portal') === 'https://akademiklh.yayasanluqmanulhakimmarelan.com/' && Host::periksa('portal', 'pendidikan') === '?p=login&sistem=pendidikan' && Host::periksa('portal', 'pos') === '?p=login&sistem=pos');
ok('callback BNI boleh di semua host', Host::periksa('bni_callback', 'wali') === null && Host::periksa('bni_callback', 'portal') === null);
ok('semua halaman terdaftar punya pemilik', (function () { foreach (glob(APP_ROOT . '/pages/*.php') as $f) { $p = basename($f, '.php'); if ($p[0] === '_' || $p === 'portal') { continue; } if (!Host::milik($p) && !in_array($p, Domain::HALAMAN_UMUM, true)) { echo "  tanpa pemilik: $p\n"; return false; } } return true; })());

// Profil santri (identitas & orang tua)
[$pc, $pe] = Santri::validateProfil(['nik' => '1271121104110002', 'ayah_nama' => 'Budi', 'jumlah_saudara' => '3', 'tgl_masuk' => '2026-07-20', 'hp_ayah' => '0853 7345 8125']);
ok('NIK paspor dan NPSN PKBM diterima', !Santri::validateProfil(['ayah_nik' => 'Paspor : S3836017', 'npsn_asal' => 'P2963580'])[1] && count(Santri::validateProfil(['nik' => '12;DROP'])[1]) === 1);
ok('profil valid', !$pe && $pc['nik'] === '1271121104110002' && $pc['jumlah_saudara'] === '3' && $pc['ibu_nama'] === null);
[, $pe2] = Santri::validateProfil(['nisn' => '12ab', 'anak_ke' => 'x', 'tgl_masuk' => '2026-13-45', 'hp_ibu' => 'abc']);
ok('profil tidak valid ditolak', count($pe2) === 4);
$pid = Santri::create(Santri::validate(['nama' => 'Uji Profil', 'jenis_kelamin' => 'P', 'jenjang' => 'Ula', 'nama_wali' => 'Ayah Uji', 'tahun_masuk' => '2026', 'status' => 'aktif'])[0], null);
Santri::simpanProfil($pid, $pc);
$pf = Santri::profil($pid);
ok('profil tersimpan dan terbaca di tabel santri', $pf && $pf['nik'] === '1271121104110002' && (int) $pf['jumlah_saudara'] === 3 && $pf['tgl_masuk'] === '2026-07-20');
Santri::simpanProfil($pid, Santri::validateProfil([])[0]);
ok('profil kosong mengosongkan kolom', !array_filter(Santri::profil($pid), static fn($v) => $v !== null));
$tmp = tempnam(sys_get_temp_dir(), 'imp');
file_put_contents($tmp, "nama,jenis_kelamin,jenjang,kelas,nama_wali,hp_wali,tahun_masuk,nis,nisn,nik,ayah_nama,ibu_penghasilan,anak_ke\nImpor Profil,L,Wustha,7,Ayah X,081234567890,2026,IMP-001,3116982828,1271121104110002,Ayah X,RP.1.000.000 - RP.2.000.000,2\n");
$r = Santri::importCsv($tmp, null, null);
$sid = (int) Db::val("SELECT id FROM santri WHERE nis = 'IMP-001'");
$ip = Santri::profil($sid);
ok('impor menyimpan profil', $r['ok'] && $ip && $ip['nisn'] === '3116982828' && $ip['ibu_penghasilan'] === 'RP.1.000.000 - RP.2.000.000' && (int) $ip['anak_ke'] === 2);
file_put_contents($tmp, "nama,jenis_kelamin,jenjang,nama_wali,nisn\nNISN Salah,L,Ula,Ayah,12abc\n");
$r2 = Santri::importCsv($tmp, null, null);
ok('impor menolak NISN bukan angka', !$r2['ok'] && $r2['jumlah_galat'] === 1);
unlink($tmp);
// Mode perbarui: isi profil santri yang sudah ada tanpa menambah/menggandakan
$tmp2 = tempnam(sys_get_temp_dir(), 'upd');
file_put_contents($tmp2, "KLS,NIS,NIS LOKAL,NIK SISWA,NAMA SISWA,TGL LAHIR,L/P,AYAH,NIK AYAH,PENGHASILAN,IBU,NIK IBU,PENGHASILAN\n3A,0001,IMP-001,9999888877776666,Impor Profil,2015-01-01,LAKI-LAKI,Ayah X,Paspor : S1,RP.3.000.000 - RP.5.000.000,Ibu Y,1271000000000001,TIDAK ADA\n");
$jml0 = (int) Db::val('SELECT COUNT(*) FROM santri');
$ru = Santri::importCsv($tmp2, null, null, true);
$pu = Santri::profil($sid);
ok('mode perbarui mengisi profil tanpa menambah santri', $ru['ok'] && $ru['count'] === 1 && (int) Db::val('SELECT COUNT(*) FROM santri') === $jml0 && $pu['nik'] === '9999888877776666' && $pu['ayah_penghasilan'] === 'RP.3.000.000 - RP.5.000.000' && $pu['ibu_penghasilan'] === 'TIDAK ADA');
ok('mode perbarui tidak mengubah data inti', Santri::find($sid)['nama'] === 'Impor Profil' && Santri::find($sid)['jenjang'] === 'Wustha');
file_put_contents($tmp2, "KLS,NIS LOKAL,NAMA SISWA,L/P,AYAH\n3A,TIDAK-ADA-123,Orang Baru,LAKI-LAKI,Ayah\n");
$rt = Santri::importCsv($tmp2, null, null, true);
ok('mode perbarui menolak santri yang tidak ada', !$rt['ok'] && (int) Db::val('SELECT COUNT(*) FROM santri') === $jml0);
unlink($tmp2);
$kol = array_map(static fn($r) => $r['name'], Db::all('PRAGMA table_info(data_santri_excel)'));
ok('tampilan data_santri_excel punya semua kolom Excel', count(array_intersect(['KLS', 'VITUAL ACCOUNT', 'NISN', 'NIS', 'NIS LOKAL', 'NIK SISWA', 'NAMA SISWA', 'TEMPAT LAHIR', 'TGL LAHIR', 'USIA MASUK', 'L/P', 'TGL MASUK', 'TAHUN AJARAN', 'ALAMAT', 'NPSN ASAL SEKOLAH', 'NAMA ASAL SEKOLAH', 'ALAMAT ASAL SEKOLAH', 'KK', 'AYAH', 'NIK AYAH', 'PENDIDIKAN AYAH', 'PEKERJAAN AYAH', 'PENGHASILAN AYAH', 'IBU', 'NIK IBU', 'PENDIDIKAN IBU', 'PEKERJAAN IBU', 'PENGHASILAN IBU', 'NO. WA AYAH', 'NO. WA IBU', 'JUMLAH SAUDARA', 'ANAK KE', 'KETERANGAN'], $kol)) === 33);
$vx = Db::one("SELECT * FROM data_santri_excel WHERE `NIS LOKAL` = 'IMP-001'");
ok('tampilan Excel: isi sesuai (L/P, NIK, ayah)', $vx && $vx['L/P'] === 'LAKI-LAKI' && $vx['NIK SISWA'] === '9999888877776666' && $vx['AYAH'] === 'Ayah X');
ok('hanya admin/TU boleh lihat profil', (function () { $m = new ReflectionClassConstant('Auth', 'PERMS'); $v = $m->getValue()['santri.profil'] ?? []; return $v === ['admin', 'tu']; })());
Host::$override = null;

// Foto santri
define('FOTO_UJI', true);
$fd = sys_get_temp_dir() . '/foto-uji-' . bin2hex(random_bytes(4));
define('FOTO_DIR_UJI', $fd);
$fs = (int) Db::val('SELECT id FROM santri LIMIT 1');
$im = imagecreatetruecolor(1200, 900); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
$tj = tempnam(sys_get_temp_dir(), 'jpg'); imagejpeg($im, $tj, 90);
$nm = Foto::simpan($fs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tj, 'size' => filesize($tj), 'name' => 'a.jpg']);
$ukr = getimagesize($fd . '/' . $nm);
ok('foto: tersimpan sebagai JPEG, diperkecil ke 480px', Foto::namaValid($nm) && $ukr[0] === 480 && $ukr[1] === 360 && $ukr['mime'] === 'image/jpeg');
ok('foto: nama tercatat di santri & URL tersedia', Santri::find($fs)['foto'] === $nm && str_contains((string) Foto::url($fs, $nm), 'p=foto&id=' . $fs));
ok('foto: folder dilindungi .htaccess', is_file($fd . '/.htaccess'));
$tp = tempnam(sys_get_temp_dir(), 'png'); imagepng($im, $tp);
$nm2 = Foto::simpan($fs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tp, 'size' => filesize($tp), 'name' => 'b.png']);
ok('foto: unggah ulang mengganti & menghapus berkas lama', $nm2 !== $nm && !is_file($fd . '/' . $nm) && is_file($fd . '/' . $nm2));
$tf = tempnam(sys_get_temp_dir(), 'fake'); file_put_contents($tf, "<?php echo 'x'; ?>");
throws('foto: berkas bukan gambar ditolak', fn() => Foto::simpan($fs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tf, 'size' => filesize($tf), 'name' => 'x.jpg']));
file_put_contents($tf, "GIF89a" . str_repeat("\0", 50));
throws('foto: GIF/format lain ditolak', fn() => Foto::simpan($fs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tf, 'size' => filesize($tf), 'name' => 'x.gif']));
throws('foto: terlalu besar ditolak', fn() => Foto::simpan($fs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tp, 'size' => 7 * 1024 * 1024, 'name' => 'b.png']));
throws('foto: tanpa berkas ditolak', fn() => Foto::simpan($fs, ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0]));
ok('foto: nama berbahaya tidak lolos (path traversal)', !Foto::namaValid('../config.php') && !Foto::namaValid('s1-../../x.jpg') && Foto::jalur('../../etc/passwd') === null);
Foto::hapus($fs);
ok('foto: hapus membersihkan kolom & berkas', Santri::find($fs)['foto'] === null && !is_file($fd . '/' . $nm2));
$st = avatar('Ahmad Fulan', 'ikhwan', $nm2, $fs);
ok('avatar: tanpa foto tampil inisial, dengan foto tampil gambar', str_contains(avatar('Ahmad Fulan', 'ikhwan'), 'AF') && str_contains($st, '<img'));
foreach (array_merge(glob($fd . '/*') ?: [], glob($fd . '/.htaccess') ?: []) as $gf) { @unlink($gf); } @rmdir($fd); @unlink($tj); @unlink($tp); @unlink($tf);

// Riwayat santri
$bd = sys_get_temp_dir() . '/dok-uji-' . bin2hex(random_bytes(4));
define('BERKAS_DIR_UJI', $bd);
$rs = (int) Db::val('SELECT id FROM santri LIMIT 1');
ok('riwayat: kelas saat ini dari data santri', Riwayat::kelasSaatIni(['jenjang' => 'TKQ', 'kelas' => 'A']) === 'TKQ' && Riwayat::kelasSaatIni(['jenjang' => 'Ula', 'kelas' => '3A']) === '3' && Riwayat::kelasSaatIni(['jenjang' => 'Ulya', 'kelas' => '10']) === '10' && Riwayat::kelasSaatIni(['jenjang' => 'Ula', 'kelas' => '']) === '');
ok('riwayat: raport belum diatur => tanpa tautan', Riwayat::urlRaport('123', '3', '1') === null);
Db::exec("INSERT INTO settings (k, v) VALUES ('raport_url', 'https://raport.contoh.id/nilai?nis={nis}&kelas={kelas}&semester={semester}')");
(function () { $r = new ReflectionProperty('Settings', 'cache'); $r->setValue(null, null); })();
ok('riwayat: tautan raport terisi nis/kelas/semester', Riwayat::urlRaport('A 1/2', '10', '2') === 'https://raport.contoh.id/nilai?nis=A%201%2F2&kelas=10&semester=2');
ok('riwayat: kelas/semester di luar daftar ditolak', Riwayat::urlRaport('1', '13', '1') === null && Riwayat::urlRaport('1', '3', '3') === null);
Db::exec("UPDATE settings SET v = 'javascript:alert(1)' WHERE k = 'raport_url'");
(function () { $r = new ReflectionProperty('Settings', 'cache'); $r->setValue(null, null); })();
ok('riwayat: alamat raport non-https ditolak', Riwayat::urlRaport('1', '3', '1') === null);
$pdf = tempnam(sys_get_temp_dir(), 'pdf'); file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$ft = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $pdf, 'size' => filesize($pdf), 'name' => 'surat.pdf'];
$nmS = Riwayat::simpanMasuk($rs, '2024-07-15', 'Pindahan', 'SDIT Contoh', $ft, false, null);
$sr = Santri::find($rs);
ok('riwayat masuk: tersimpan (tanggal, status, asal, surat PDF)', $sr['tgl_masuk'] === '2024-07-15' && $sr['status_masuk'] === 'Pindahan' && $sr['asal_sekolah'] === 'SDIT Contoh' && str_ends_with($nmS, '.pdf') && is_file($bd . '/' . $nmS) && Berkas::mime($nmS) === 'application/pdf');
throws('riwayat masuk: status selain Baru/Pindahan ditolak', fn() => Riwayat::simpanMasuk($rs, '2024-07-15', 'Lainnya', '', null, false, null));
throws('riwayat masuk: tanggal tidak valid ditolak', fn() => Riwayat::simpanMasuk($rs, '2024-02-31', 'Baru', '', null, false, null));
$php = tempnam(sys_get_temp_dir(), 'php'); file_put_contents($php, "<?php system(\$_GET['x']);");
throws('berkas: skrip PHP berkedok PDF ditolak', fn() => Berkas::simpan('c', $rs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $php, 'size' => filesize($php), 'name' => 'x.pdf']));
file_put_contents($php, "<html><script>alert(1)</script>");
throws('berkas: HTML ditolak', fn() => Berkas::simpan('c', $rs, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $php, 'size' => filesize($php), 'name' => 'x.png']));
ok('berkas: nama berbahaya ditolak', !Berkas::namaValid('../x.pdf') && !Berkas::namaValid('r1-abc.pdf') && Berkas::jalur('../../etc/passwd') === null);
Riwayat::simpanMasuk($rs, '2024-07-15', 'Baru', 'SDIT Contoh', null, true, null);
ok('riwayat masuk: hapus surat membersihkan berkas', Santri::find($rs)['surat_rekomendasi'] === null && !is_file($bd . '/' . $nmS) && Santri::find($rs)['status_masuk'] === 'Baru');
$pid = Riwayat::tambahPrestasi($rs, 'Tahfidz', 'Juara 1 MTQ Kota', '2025', $ft, null);
$pl = Riwayat::prestasi($rs);
ok('prestasi: tambah dengan sertifikat', count($pl) === 1 && $pl[0]['jenis'] === 'Tahfidz' && (int) $pl[0]['tahun'] === 2025 && is_file($bd . '/' . $pl[0]['sertifikat']));
throws('prestasi: jenis tidak dikenal ditolak', fn() => Riwayat::tambahPrestasi($rs, 'Hack', 'x', '', null, null));
throws('prestasi: nama kosong ditolak', fn() => Riwayat::tambahPrestasi($rs, 'Seni', '  ', '', null, null));
throws('prestasi: tahun tidak masuk akal ditolak', fn() => Riwayat::tambahPrestasi($rs, 'Seni', 'Lomba', '1800', null, null));
$sert = $pl[0]['sertifikat'];
Riwayat::hapusPrestasi($pid, $rs + 999, null);
ok('prestasi: tidak bisa dihapus lewat santri lain', count(Riwayat::prestasi($rs)) === 1);
Riwayat::hapusPrestasi($pid, $rs, null);
ok('prestasi: hapus membersihkan data & berkas', count(Riwayat::prestasi($rs)) === 0 && !is_file($bd . '/' . $sert));
foreach (array_merge(glob($bd . '/*') ?: [], glob($bd . '/.htaccess') ?: []) as $gf) { @unlink($gf); } @rmdir($bd); @unlink($pdf); @unlink($php);

echo $fail === 0 ? "\nSemua uji lulus.\n" : "\n$fail uji GAGAL.\n";
exit($fail === 0 ? 0 : 1);
