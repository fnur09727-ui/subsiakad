# SETUP Sub-SIAKAD dengan MySQL/phpMyAdmin

## Langkah 1: Pastikan MySQL Server Berjalan

### Windows (XAMPP/WAMP)
```
- Buka XAMPP Control Panel
- Klik "Start" pada baris "MySQL"
- Tunggu hingga status berubah menjadi "Running" (warna hijau)
```

### Windows (Manual MySQL Installation)
```
# Check if MySQL service running (PowerShell as Admin):
Get-Service | Where-Object {$_.Name -like "MySQL*"}

# Start MySQL service if not running:
Start-Service -Name "MySQL80"  # Sesuaikan versi
```

## Langkah 2: Buka phpMyAdmin

1. Buka browser ke: **http://localhost/phpmyadmin**
2. Login dengan:
   - Username: `root`
   - Password: (kosongkan atau sesuai konfigurasi)

## Langkah 3: Import Database Schema

1. Di phpMyAdmin, klik **"Import"** di menu atas
2. Pilih file: **schema_mysql_seed.sql** dari folder SUBSIAKAD
3. Klik **"Go"** untuk import
4. Database `subsia_kad` akan dibuat otomatis dengan semua tabel dan data test

## Langkah 4: Verifikasi Database Credentials

Edit file **config.php** jika perlu sesuaikan:

```php
$dbHost = 'localhost';    // Alamat MySQL server
$dbUser = 'root';         // Username MySQL
$dbPass = '';             // Password MySQL (kosong jika tidak ada)
$dbName = 'subsia_kad';   // Nama database
```

## Langkah 5: Jalankan Aplikasi

```powershell
# Di folder SUBSIAKAD:
php -S localhost:8000

# Buka browser:
# http://localhost:8000/login.php
```

## Test Login

Akun test yang tersedia:

| Username | Password | Role |
|----------|----------|------|
| admin | admin123 | Admin Akademik |
| dosen | dosen123 | Dosen |
| mahasiswa | mhs123 | Mahasiswa |

## Troubleshooting

**Error: "SQLSTATE[HY000] [1049] Unknown database 'subsia_kad'"**
- Database belum di-import. Lakukan Langkah 3 lagi.

**Error: "SQLSTATE[28000] [1045] Access denied for user 'root'@'localhost'"**
- Periksa MySQL credentials di `config.php`
- Pastikan username/password benar

**Error: "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away"**
- MySQL server tidak berjalan. Mulai ulang MySQL service.

## Switching Between SQLite dan MySQL

Di file **config.php**, ubah baris:
```php
$dbType = 'mysql';  // Ganti ke 'sqlite' untuk gunakan SQLite
```

- `'mysql'` = Gunakan MySQL (recommended untuk production)
- `'sqlite'` = Gunakan SQLite (file-based, no server needed)
