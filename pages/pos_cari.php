<?php
// Pencarian santri (JSON) untuk pembayaran dengan saldo di kasir.
Auth::require('pos');
$scope = null;
$q = in_str('q');
$out = [];
if (mb_strlen($q) >= 2) {
    [$rows] = Santri::daftar(['q' => $q], $scope, 1);
    foreach (array_slice($rows, 0, 8) as $r) {
        $out[] = ['id' => (int) $r['id'], 'nama' => $r['nama'], 'nis' => $r['nis'], 'kelas' => trim($r['jenjang'] . ' ' . ($r['kelas'] ?: '')), 'saldo' => Pos::saldo((int) $r['id'])];
    }
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
exit;
