<?php
// Tabel lengkap kini menjadi tampilan utama menu Data Santri (admin/TU).
Auth::require('santri.profil');
redirect('?' . http_build_query(array_merge($_GET, ['p' => 'santri'])));
