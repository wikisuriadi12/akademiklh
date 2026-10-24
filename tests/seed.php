<?php
// Data contoh untuk uji tampilan dan pratinjau (BUKAN data asli). Gunakan setelah test_db().
declare(strict_types=1);

function seed_demo(): void
{
    Auth::createUser('wiki', 'Wiki Suryadi', 'admin', 'semua', 'rahasia12345', null);
    Auth::createUser('bendahara1', 'Siti Bendahara', 'bendahara', 'semua', 'rahasia12345', null);
    Auth::createUser('tu.ikhwan', 'Ustadz Ahmad (TU)', 'tu', 'ikhwan', 'rahasia12345', null);

    $data = [
        ['Muhammad Fatih', 'L', 'Ula', '3A', 'Abdullah Rahman', '0812-3456-7801', 0],
        ['Ahmad Zaki', 'L', 'Ula', '5B', 'Syamsul Arifin', '0813-2211-4502', 50000],
        ['Umar Hakim', 'L', 'Wustha', '2A', 'Hasan Basri', '0821-7788-9903', 0],
        ['Yusuf Maulana', 'L', 'Wustha', '3B', 'Ridwan Lubis', '0852-1100-2204', 0],
        ['Ibrahim Nasution', 'L', 'Ulya', '1A', 'Zulkifli Nasution', '0811-6070-1105', 100000],
        ['Salman Al-Farisi', 'L', 'TKQ', 'B1', 'Khairul Anwar', '0822-9090-3306', 0],
        ['Hamzah Siregar', 'L', 'Ulya', '2A', 'Parlindungan Siregar', '0813-7000-4407', 0],
        ['Aisyah Humaira', 'P', 'Ula', '4A', 'Ahmad Dalimunthe', '0812-5050-6608', 0],
        ['Khadijah Zahra', 'P', 'Ula', '2B', 'Marwan Harahap', '0821-4040-7709', 0],
        ['Fatimah Azzahra', 'P', 'Wustha', '1A', 'Irwan Tanjung', '0852-3030-8810', 75000],
        ['Maryam Salsabila', 'P', 'Wustha', '3A', 'Syahrul Pane', '0811-2020-9911', 0],
        ['Hafshah Nurul', 'P', 'Ulya', '3A', 'Budiman Sinaga', '0813-1010-1012', 0],
        ['Ummu Kultsum', 'P', 'TKQ', 'A2', 'Rahmat Hidayat', '0822-8080-1113', 0],
        ['Zainab Rahmah', 'P', 'Ulya', '1B', 'Samsuddin Lubis', '0812-9999-1214', 0],
    ];
    foreach ($data as [$n, $jk, $j, $k, $w, $hp, $pot]) {
        [$c] = Santri::validate(['nama' => $n, 'jenis_kelamin' => $jk, 'jenjang' => $j, 'kelas' => $k, 'nama_wali' => $w, 'hp_wali' => $hp,
            'hubungan_wali' => 'Ayah', 'tahun_masuk' => (string) (2022 + crc32($n) % 5), 'status' => 'aktif', 'potongan_spp' => (string) $pot]);
        Santri::create($c, 1);
    }
    foreach ([['Bilal Ramadhan', 'L', 'Ula', 'Fauzan Ramadhan'], ['Nabila Putri', 'P', 'TKQ', 'Dewi Lestari'], ['Rizky Pratama', 'L', 'Wustha', 'Agus Pratama']] as [$n, $jk, $j, $w]) {
        Santri::daftarOnline(['nama' => $n, 'jenis_kelamin' => $jk, 'jenjang' => $j, 'nama_wali' => $w, 'hp_wali' => '0812-7000-' . random_int(1000, 9999),
            'asal_sekolah' => 'SD Negeri 1 Marelan', 'tempat_lahir' => 'Medan', 'tanggal_lahir' => '2015-03-14', 'alamat' => 'Jl. Marelan Raya No. 12']);
    }

    foreach (['2025/2026' => 0, '2026/2027' => 25000] as $ta => $naik) {
        foreach (['TKQ' => 150000, 'Ula' => 300000, 'Wustha' => 350000, 'Ulya' => 450000] as $j => $nom) {
            Spp::setTarif($ta, $j, 'ikhwan', $nom + $naik, 1);
            Spp::setTarif($ta, $j, 'akhwat', $nom + $naik, 1);
        }
    }
    $today = date('Y-m-d');
    $periods = [];
    for ($i = 5; $i >= 0; $i--) { $periods[] = date('Y-m', strtotime('first day of -' . $i . ' months')); }
    foreach ($periods as $pi => $per) {
        Spp::generate($per, null, 1);
        $isNow = $per === date('Y-m');
        foreach (Db::all("SELECT id, nominal FROM tagihan WHERE periode = ? AND keterangan = '' ORDER BY id", [$per]) as $t) {
            $r = ($t['id'] * 37) % 10;
            $limit = $isNow ? 5 : ($pi >= 4 ? 7 : 9);
            $day = min($today, $per . '-' . str_pad((string) (3 + ($t['id'] * 7) % 22), 2, '0', STR_PAD_LEFT));
            if (!Domain::validDate($day)) { $day = $today; }
            if ($r < $limit) {
                Spp::bayar((int) $t['id'], $day, (int) $t['nominal'], $r % 3 === 0 ? 'transfer' : 'tunai', $r % 3 === 0 ? 'via BSI' : '', 2, null);
            } elseif ($r === $limit) {
                Spp::bayar((int) $t['id'], $day, (int) ($t['nominal'] / 2), 'tunai', 'cicilan 1', 2, null);
            }
        }
    }
    Spp::buatTagihanLain(Spp::jenisId('SERAGAM'), date('Y-m'), 185000, 'Batik', ['jenjang' => 'Ula'], null, 1);
    Spp::buatTagihanLain(Spp::jenisId('KITAB'), date('Y-m'), 120000, 'Paket kitab semester 1', ['jenjang' => 'Wustha'], null, 1);
    $first = (int) Db::val("SELECT t.id FROM tagihan t JOIN jenis_biaya j ON j.id=t.jenis_id WHERE j.kode='SERAGAM' ORDER BY t.id LIMIT 1");
    Spp::bayar($first, $today, 185000, 'tunai', '', 2, null);
    $belum = (int) Db::val('SELECT id FROM tagihan WHERE id NOT IN (SELECT tagihan_id FROM pembayaran) ORDER BY id LIMIT 1');
    $x = Spp::bayar($belum, $today, 50000, 'tunai', '', 2, null);
    Spp::batalkan($x, 'salah input nominal', 2, null);

    Auth::createUser('kasir1', 'Ustadzah Rina (Kasir)', 'kasir', 'akhwat', 'rahasia12345', null);
    Auth::createUser('gudang1', 'Pak Hadi (Gudang)', 'gudang', 'semua', 'rahasia12345', null);
    $brg = [
        ['BK-001', 'Buku tulis 38 lembar', 'Alat tulis', 'pcs', 3000, 4500, 10], ['PN-001', 'Pulpen hitam', 'Alat tulis', 'pcs', 2000, 3000, 12],
        ['SB-001', 'Sabun mandi', 'Perlengkapan mandi', 'pcs', 3500, 5000, 8], ['SP-001', 'Sampo sachet', 'Perlengkapan mandi', 'pcs', 500, 1000, 20],
        ['MK-001', 'Mie instan', 'Makanan', 'pcs', 2800, 3500, 15], ['MK-002', 'Roti isi', 'Makanan', 'pcs', 3000, 4000, 10],
        ['MN-001', 'Air mineral 600ml', 'Minuman', 'btl', 2500, 3500, 12], ['MN-002', 'Teh gelas', 'Minuman', 'pcs', 1200, 2000, 15],
        ['SR-001', 'Sarung', 'Pakaian', 'pcs', 65000, 85000, 3], ['PK-001', 'Peci hitam', 'Pakaian', 'pcs', 18000, 25000, 4],
    ];
    foreach ($brg as $i => [$kode, $nm, $kt, $st, $hb, $hj, $mn]) {
        [$c] = Gudang::validateBarang(['kode' => $kode, 'nama' => $nm, 'kategori' => $kt, 'satuan' => $st, 'harga_beli' => (string) $hb, 'harga_jual' => (string) $hj, 'stok_min' => (string) $mn]);
        $bid = Gudang::simpanBarang($c, null, 1);
        foreach (['ikhwan', 'akhwat'] as $u) {
            $awal = $hj > 20000 ? 8 : 60 + ($i * 7) % 25;
            Gudang::masuk($bid, $u, $awal, $hb, 'Toko Barokah', 'stok awal', 1, null);
        }
    }
    foreach ([8, 9, 10] as $sidD) { Pos::topup($sidD, 50000 + $sidD * 5000, 'titipan wali', 1, null); }
    Pos::topup(1, 75000, 'titipan wali', 1, null);
    $bid = static fn(string $k): int => (int) Db::val('SELECT id FROM barang WHERE kode = ?', [$k]);
    for ($d = 6; $d >= 0; $d--) {
        foreach (['ikhwan', 'akhwat'] as $ui => $u) {
            for ($n = 0; $n < 2 + ($d + $ui) % 3; $n++) {
                $items = [$bid(['BK-001', 'MK-001', 'MN-001', 'PN-001', 'MN-002'][($d + $n) % 5]) => 1 + $n % 3, $bid('MK-002') => 1];
                $pid = Pos::jual($u, null, $items, 'tunai', 100000, 3, null);
                $tgl = date('Y-m-d', strtotime("-$d days"));
                Db::exec('UPDATE penjualan SET tanggal = ? WHERE id = ?', [$tgl, $pid]);
            }
        }
    }
    Pos::jual('akhwat', 8, [$bid('SB-001') => 1, $bid('MN-001') => 2], 'dompet', 0, 3, null);
    $x = Pos::jual('ikhwan', null, [$bid('PN-001') => 3], 'tunai', 10000, 3, null);
    Pos::batalkan($x, 'salah input barang', 3, null);
    Gudang::opname($bid('SB-001'), 'ikhwan', 4, 'opname', 1, null);
    Gudang::opname($bid('MK-002'), 'akhwat', 5, 'opname', 1, null);
    Gudang::opname($bid('PK-001'), 'akhwat', 0, 'opname', 1, null);
    Settings::set('va_aktif', '1');
    Bni::$override = ['mode' => 'simulasi', 'prefix' => '8001', 'client_id' => '001', 'secret' => 'kunci-simulasi'];
    $vaA = Va::buat(5, [], 2, null);
    $vaB = Va::buat(9, [], 2, null);
    Va::simulasiBayar($vaB);
    $vaC = Va::buat(10, [], 2, null);
    Auth::createUser('ust.salman', 'Ust. Salman Hafidz', 'ustadz', 'ikhwan', 'rahasia12345', null);
    Auth::createUser('ustz.rahmah', 'Ustzh. Rahmah (Pembina)', 'pembina', 'akhwat', 'rahasia12345', null);
    $surat = [30 => ['An-Naba', 'An-Nazi\'at', 'Abasa', 'At-Takwir'], 29 => ['Al-Mulk', 'Al-Qalam', 'Al-Haqqah'], 28 => ['Al-Mujadilah', 'Al-Hasyr']];
    $aktif = Db::all("SELECT id FROM santri WHERE status = 'aktif' ORDER BY id");
    foreach ($aktif as $k => $r) {
        $sid = (int) $r['id'];
        for ($d = 20; $d >= 0; $d--) {
            if (($sid + $d) % 3 === 0 || ($d === 0 && $k % 4 === 0)) { continue; }
            $juz = 30 - intdiv(20 - $d + $sid % 3, 9);
            $sl = $surat[$juz] ?? ['Al-Baqarah'];
            $nilai = 62 + (($sid * 7 + $d * 3) % 37);
            Tahfidz::setor(['santri_id' => $sid, 'tanggal' => date('Y-m-d', strtotime("-$d days")), 'jenis' => $d % 5 === 0 ? 'murojaah' : 'ziyadah', 'juz' => $juz,
                'surat' => $sl[($d + $sid) % count($sl)], 'ayat' => (1 + ($d + $sid * 3) % 15) . '-' . (8 + ($d + $sid * 3) % 15), 'halaman' => (string) (1 + ($sid + $d) % 3), 'nilai' => (string) $nilai,
                'kelancaran' => $nilai >= 75 ? 'lancar' : ($nilai >= 65 ? 'kurang' : 'ulang')], 7 + $k % 2, null);
        }
        for ($d = 14; $d >= 0; $d--) {
            foreach (['Ngaji Subuh', 'Sekolah Pagi'] as $si => $sesi) {
                $x = ($sid * 13 + $d * 7 + $si) % 23;
                $st = $x === 0 ? 'A' : ($x === 1 ? 'S' : ($x === 2 ? 'I' : 'H'));
                Kehadiran::simpan(date('Y-m-d', strtotime("-$d days")), $sesi, [$sid => ['status' => $st, 'ket' => $st === 'S' ? 'demam' : '']], 7, null);
            }
        }
    }
    $jam = static fn(int $h): string => date('Y-m-d H:i', time() + $h * 3600);
    Izin::ajukan(8, 'pulang', $jam(30), $jam(78), 'Acara keluarga (walimah kakak)', 'wali', null, null);
    Izin::ajukan(5, 'berobat', $jam(10), $jam(16), 'Kontrol gigi di klinik Marelan', 'wali', null, null);
    $i3 = Izin::ajukan(3, 'pulang', $jam(2), $jam(50), 'Libur pekan', 'petugas', 1, null);
    Izin::keluar($i3, 1, null);
    $i4 = Izin::ajukan(7, 'keperluan', $jam(1), $jam(3), 'Mengurus KTP di kecamatan', 'petugas', 1, null);
    Izin::keluar($i4, 1, null);
    Db::exec('UPDATE izin SET mulai = ?, selesai = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 8 * 3600), date('Y-m-d H:i:s', time() - 3 * 3600), $i4]);
    $i5 = Izin::ajukan(10, 'pulang', $jam(1), $jam(2), 'Libur pekan', 'petugas', 1, null);
    Izin::keluar($i5, 1, null);
    Db::exec('UPDATE izin SET mulai = ?, selesai = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 4 * 86400), date('Y-m-d H:i:s', time() - 2 * 86400), $i5]);
    Izin::kembali($i5, 1, null);
    Settings::set('alamat', 'Jl. Marelan Raya, Medan Marelan, Sumatera Utara');
    Settings::set('telepon', '0812-0000-1234');
    Settings::set('wa_admin', '0812-0000-1234');
    Settings::set('rekening', "BSI 7123456789 a.n. Yayasan Luqmanul Hakim\nBank Sumut 0123456789 a.n. Yayasan Luqmanul Hakim");
    Settings::set('biaya_pendaftaran', '250000');
}
