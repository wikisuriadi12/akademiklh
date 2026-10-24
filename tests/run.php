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
throws('santri unit lain tak bisa belanja di unit ini', fn() => Pos::jual('ikhwan', $id2, [$b1 => 1], 'dompet', 0, null, null));
throws('akun akhwat tak bisa jual di ikhwan', fn() => Pos::jual('ikhwan', null, [$b1 => 1], 'tunai', 5000, null, 'akhwat'));
throws('batal tanpa alasan ditolak', fn() => Pos::batalkan($pj2, '', null, null));
Pos::batalkan($pj2, 'salah input', null, null);
ok('batal mengembalikan stok & saldo', Pos::saldo($id1) === 20000 && Gudang::stok($b2, 'ikhwan') === 9);
throws('batal dua kali ditolak', fn() => Pos::batalkan($pj2, 'lagi', null, null));
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
ok('kasir hanya boleh masuk sistem Koperasi & Gudang', Domain::sistemBoleh('pos', 'kasir') && !Domain::sistemBoleh('pendidikan', 'kasir'));
ok('petugas gudang masuk sistem Koperasi & Gudang, halaman awal stok', Domain::sistemBoleh('pos', 'gudang') && !Domain::sistemBoleh('pendidikan', 'gudang') && Domain::home('pos', 'gudang') === 'gudang' && Domain::home('pos', 'kasir') === 'pos');
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
ok('portal tanpa domain utama tampil di akademiklh', Host::url('portal') === 'https://akademiklh.yayasanluqmanulhakimmarelan.com/?p=portal' && Host::periksa('portal', 'pendidikan') === null && Host::periksa('portal', 'pos') === 'https://akademiklh.yayasanluqmanulhakimmarelan.com/?p=portal');
ok('callback BNI boleh di semua host', Host::periksa('bni_callback', 'wali') === null && Host::periksa('bni_callback', 'portal') === null);
ok('semua halaman terdaftar punya pemilik', (function () { foreach (glob(APP_ROOT . '/pages/*.php') as $f) { $p = basename($f, '.php'); if ($p[0] === '_' || $p === 'portal') { continue; } if (!Host::milik($p) && !in_array($p, Domain::HALAMAN_UMUM, true)) { echo "  tanpa pemilik: $p\n"; return false; } } return true; })());
Host::$override = null;

echo $fail === 0 ? "\nSemua uji lulus.\n" : "\n$fail uji GAGAL.\n";
exit($fail === 0 ? 0 : 1);
