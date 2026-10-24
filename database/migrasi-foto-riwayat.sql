-- Jalankan SEKALI di phpMyAdmin (tab SQL). Menambah foto santri, riwayat masuk, dan tabel prestasi.
ALTER TABLE santri
  ADD COLUMN foto VARCHAR(40) NULL,
  ADD COLUMN status_masuk VARCHAR(10) NULL,
  ADD COLUMN surat_rekomendasi VARCHAR(40) NULL;

CREATE TABLE IF NOT EXISTS prestasi (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  santri_id INT UNSIGNED NOT NULL,
  jenis VARCHAR(30) NOT NULL,
  nama VARCHAR(150) NOT NULL,
  tahun SMALLINT UNSIGNED NULL,
  sertifikat VARCHAR(40) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_prestasi_santri (santri_id),
  CONSTRAINT fk_prestasi_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
