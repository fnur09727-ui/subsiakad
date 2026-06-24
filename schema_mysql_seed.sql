
-- Buat database dan pilih (agar dapat di-import langsung di phpMyAdmin)
CREATE DATABASE IF NOT EXISTS `subsia_kad` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `subsia_kad`;

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS admin_profile;
DROP TABLE IF EXISTS pimpinan_fakultas_profile;
DROP TABLE IF EXISTS dosen_profile;
DROP TABLE IF EXISTS mahasiswa_profile;
DROP TABLE IF EXISTS tugas;
DROP TABLE IF EXISTS nilai;
DROP TABLE IF EXISTS mata_kuliah;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;

CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(255),
  email VARCHAR(255),
  role_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mahasiswa_profile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  nim VARCHAR(50),
  prodi VARCHAR(150),
  angkatan INT,
  CONSTRAINT fk_mhs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dosen_profile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  nidn VARCHAR(50),
  fakultas VARCHAR(150),
  jabatan VARCHAR(150),
  CONSTRAINT fk_dosen_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_profile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  staff_id VARCHAR(50),
  unit VARCHAR(150),
  CONSTRAINT fk_admin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pimpinan_fakultas_profile (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  nip VARCHAR(50),
  fakultas VARCHAR(150),
  jabatan VARCHAR(150),
  CONSTRAINT fk_pimpinan_fakultas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

-- Seed roles
INSERT INTO roles (name) VALUES ('mahasiswa'),('dosen'),('admin_akademik'),('pimpinan_fakultas');

-- Seed users (hashed passwords generated via PHP password_hash)
-- Passwords (plain): admin123, dosen123, mhs123
INSERT INTO users (username, password, full_name, email, role_id) VALUES
('mahasiswa','$2y$12$wYAtOXU6psur8a0lw.CVHOF7kTnjwV0q..I/2/eHHIUbYV04QgKeu','Siti Rahmawati','siti@example.com',(SELECT id FROM roles WHERE name='mahasiswa')),
('dosen','$2y$12$2ytnYvkcP3mpIT/2aD/u8elIRKxNLEedEZTsSrInLXNv9dIIy56Mu','Dr. Budi Santoso','budi@example.com',(SELECT id FROM roles WHERE name='dosen')),
('admin','$2y$12$dg7uICoxtVWxucANAimqNukrw4Q0sqGtndBalQk0Ci0LNkrcp9J0O','Admin Akademik','admin@example.com',(SELECT id FROM roles WHERE name='admin_akademik')),
('pimpinan','$2y$12$i9CmK.Np1DzS5o6fjCEYAuKUPj6Ny78HNLHCtvErsz5GfrE8Y0qcW','Pimpinan Fakultas','pimpinan@example.com',(SELECT id FROM roles WHERE name='pimpinan_fakultas'));

-- Seed profiles (resolve user_id via username)
INSERT INTO mahasiswa_profile (user_id, nim, prodi, angkatan) VALUES
((SELECT id FROM users WHERE username='mahasiswa'),'21002','Teknik Informatika',2021);

INSERT INTO dosen_profile (user_id, nidn, fakultas, jabatan) VALUES
((SELECT id FROM users WHERE username='dosen'),'12345678','Fakultas Teknik','Dosen Madya');

INSERT INTO admin_profile (user_id, staff_id, unit) VALUES
((SELECT id FROM users WHERE username='admin'),'ADM001','Bagian Akademik');

INSERT INTO pimpinan_fakultas_profile (user_id, nip, fakultas, jabatan) VALUES
((SELECT id FROM users WHERE username='pimpinan'),'197001011995031001','Fakultas Teknik','Dekan');

-- Optional: sample mahasiswa records table (for the app's data)
CREATE TABLE mata_kuliah (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(50),
  nama VARCHAR(255),
  sks INT,
  dosen_id INT NULL,
  jadwal_mulai DATETIME NULL,
  ruang VARCHAR(100),
  semester VARCHAR(100),
  CONSTRAINT fk_mk_dosen FOREIGN KEY (dosen_id) REFERENCES dosen_profile(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE nilai (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mahasiswa_id INT NOT NULL,
  mata_kuliah_id INT NOT NULL,
  tugas DECIMAL(5,2),
  uts DECIMAL(5,2),
  uas DECIMAL(5,2),
  akhir DECIMAL(5,2),
  grade VARCHAR(2),
  CONSTRAINT fk_nilai_mahasiswa FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa_profile(id) ON DELETE CASCADE,
  CONSTRAINT fk_nilai_mk FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliah(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tugas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mata_kuliah_id INT NOT NULL,
  dosen_id INT NOT NULL,
  judul VARCHAR(255) NOT NULL,
  deskripsi TEXT,
  due_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tugas_mk FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliah(id) ON DELETE CASCADE,
  CONSTRAINT fk_tugas_dosen FOREIGN KEY (dosen_id) REFERENCES dosen_profile(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- seed sample mata_kuliah and nilai
INSERT INTO mata_kuliah (kode, nama, sks, dosen_id, jadwal_mulai, ruang, semester)
VALUES ('IF302','Pemrograman Berbasis Web',3,(SELECT id FROM dosen_profile WHERE user_id = (SELECT id FROM users WHERE username='dosen')),DATE_ADD(NOW(), INTERVAL 3 DAY),'Ruang 301','Genap 2025/2026');

-- Map sample nilai for mahasiswa (lookup ids)
INSERT INTO nilai (mahasiswa_id, mata_kuliah_id, tugas, uts, uas, akhir, grade)
VALUES (
  (SELECT id FROM mahasiswa_profile WHERE nim='21002'),
  (SELECT id FROM mata_kuliah WHERE kode='IF302'),
  70, 75, 68, 70.7, 'B'
);

INSERT INTO tugas (mata_kuliah_id, dosen_id, judul, deskripsi, due_at)
VALUES (
  (SELECT id FROM mata_kuliah WHERE kode='IF302'),
  (SELECT id FROM dosen_profile WHERE user_id = (SELECT id FROM users WHERE username='dosen')),
  'Latihan Form Login',
  'Buat halaman login sederhana dengan validasi input.',
  DATE_ADD(NOW(), INTERVAL 7 DAY)
);
