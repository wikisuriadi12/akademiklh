<?php
// Salin menjadi config.php lalu isi. Jangan di-commit.
return [
    'db_host'  => 'localhost',
    'db_name'  => 'isi_nama_database',
    'db_user'  => 'isi_user_mysql',
    'db_pass'  => 'isi_password_mysql',
    'app_name' => 'SPP & Data Santri - Yayasan Luqmanul Hakim',
    'timezone' => 'Asia/Jakarta',
    // Pembagian subdomain. Kosongkan 'domain' bila belum dipakai (semua dari satu alamat, mis. di PC lokal).
    'domain'    => 'yayasanluqmanulhakimmarelan.com',
    'subdomain' => [
        'pendidikan' => 'akademiklh', // akademiklh.domain → santri, tahfidz, absensi, izin, SPP (+ portal pilih sistem)
        'pos'        => 'poslh',      // poslh.domain      → kasir, dompet, penjualan, stok gudang
        'wali'       => 'walilh',     // walilh.domain     → portal wali (tanpa akun)
    ],
    'portal_utama' => false,          // true bila domain utama juga diarahkan ke aplikasi ini
    // Pembayaran SPP lewat BNI Virtual Account (e-Collection). Rahasia disimpan DI SINI, bukan di database.
    'bni' => [
        'mode'      => 'simulasi',          // simulasi | sandbox | produksi
        'client_id' => '',                  // dari BNI
        'secret'    => '',                  // secret key dari BNI
        'prefix'    => '8001',              // awalan nomor VA dari BNI (mis. 8 + kode klien)
        'url'       => 'https://apibeta.bni-ecollection.com/',   // sandbox; produksi: sesuai BNI
        'ips'       => [],                  // opsional: daftar IP BNI yang boleh memanggil callback
        'secret_cb' => '',                  // opsional: token tambahan di URL callback (?p=bni_callback&k=TOKEN)
    ],
];
