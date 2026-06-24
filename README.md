# 🎓 Sub-SIAKAD - Sistem Manajemen Nilai Akademik

## Panduan Menjalankan Aplikasi

### ✅ Prasyarat
- MySQL Server atau MariaDB sudah installed dan running
- PHP 8.0+ dengan ekstensi pdo_mysql dan pdo_sqlite
- Koneksi internet untuk CDN Tailwind CSS dan Font Awesome

### 🚀 Quick Start (3 Langkah)

#### 1. Pastikan MySQL Server Berjalan
```powershell
# Windows: Verifikasi MySQL listening pada port 3306
netstat -ano | findstr :3306

# Harus ada output seperti:
# TCP    0.0.0.0:3306           0.0.0.0:0              LISTENING       xxxx
```

#### 2. Import Database Schema
```powershell
# Dari folder SUBSIAKAD:
cd c:\Users\fiqis\OneDrive\Desktop\SUBSIAKAD

# Jalankan script import:
php seed_db.php

# Output yang diharapkan:
# ✅ Created user: mahasiswa (ID: 1)
# ✅ Created user: dosen (ID: 2)
# ✅ Created user: admin (ID: 3)
# ✅ Seed profiles created!
# ✅ Database is ready!
```

#### 3. Jalankan PHP Development Server
```powershell
cd c:\Users\fiqis\OneDrive\Desktop\SUBSIAKAD
php -S localhost:8000

# Output:
# [TIME] PHP 8.5.4 Development Server (http://localhost:8000) started
```

#### 4. Buka di Browser
```
http://localhost:8000/login.php
```

---

## 👤 Test Akun

| Username | Password | Role | 
|----------|----------|------|
| **admin** | admin123 | Admin Akademik |
| **dosen** | dosen123 | Dosen |
| **mahasiswa** | mhs123 | Mahasiswa |
| **pimpinan** | pimpinan123 | Pimpinan Fakultas |

---

## 📁 Struktur File

```
SUBSIAKAD/
├── login.php              # Halaman login dengan MySQL authentication
├── register.php           # Halaman registrasi user baru
├── logout.php             # Logout dan clear session
├── index.php              # Dashboard utama (protected)
├── config.php             # Konfigurasi database MySQL/SQLite
├── import_db.php          # Script import schema (opsional)
├── seed_db.php            # Script seed user data
├── schema_mysql_seed.sql  # SQL file dengan schema lengkap
├── SETUP_MYSQL.md         # Detail setup MySQL
└── SETUP_WINDOWS.md       # Ini file, panduan lengkap
```

---

## ⚙️ Konfigurasi Database

### Edit config.php untuk Perubahan Credentials:

```php
// config.php
$dbType = 'mysql'; // atau 'sqlite'

if ($dbType === 'mysql') {
    $dbHost = 'localhost';    // Host MySQL
    $dbUser = 'root';         // Username MySQL
    $dbPass = '';             // Password MySQL
    $dbName = 'subsia_kad';   // Database name
}
```

### Database Schema:
- **roles**: id, name (mahasiswa, dosen, admin_akademik, pimpinan_fakultas)
- **users**: id, username, password (bcrypt), full_name, email, role_id, created_at
- **mahasiswa_profile**: user_id, nim, prodi, angkatan
- **dosen_profile**: user_id, nidn, fakultas, jabatan
- **admin_profile**: user_id, staff_id, unit

---

## 🔐 Security Features

✅ **Password Hashing**
- Menggunakan `password_hash()` dengan algoritma bcrypt (PASSWORD_DEFAULT)
- Verifikasi dengan `password_verify()`

✅ **Session Protection**
- Session dimulai dengan `session_start()`
- User data tersimpan di `$_SESSION['user']`
- Redirect ke login jika session tidak ada

✅ **Input Validation**
- HTML escaping dengan `htmlspecialchars()`
- SQL injection prevention dengan prepared statements (`PDO::prepare`)

✅ **Database Abstraction**
- PDO untuk support MySQL dan SQLite
- Mudah switching antara production (MySQL) dan development (SQLite)

---

## 🛠️ Troubleshooting

### Error: "could not find driver"
**Solusi:**
1. Edit `php.ini`: `C:\php\php.ini`
2. Uncomment: `extension=pdo_mysql`
3. Restart PHP server

### Error: "SQLSTATE[28000]: Access denied"
**Solusi:**
1. Verifikasi MySQL running: `netstat -ano | findstr :3306`
2. Check credentials di `config.php`
3. Verifikasi MySQL user/password

### Error: "Unknown database 'subsia_kad'"
**Solusi:**
```powershell
php seed_db.php
# Script akan membuat database dan seed data otomatis
```

### Error: "Database connection failed"
**Solusi:**
1. Pastikan MySQL server running
2. Check `config.php` credentials
3. Test koneksi dengan: `php -r "require 'config.php'; echo ($pdo ? 'OK' : 'FAILED');"`

---

## 📊 Feature Overview

### Login System ✅
- Form input username/password
- Bcrypt password verification
- Error handling (user not found, wrong password)
- MySQL authentication

### Registration System ✅
- Form dengan dynamic role-specific fields
- Validasi username unique
- Multi-table insert (users + profile)
- Auto-login setelah register

### Session Management ✅
- Protected pages redirect to login
- Session data accessible di semua pages
- Logout clears all session data

### Database ✅
- MySQL support dengan PDO
- Normalized schema dengan FK constraints
- Seed data dengan 3 test users
- Proper datatypes dan indexes

---

## 🎯 Next Steps (Optional Features)

Untuk melengkapi aplikasi:
1. Implement dashboard dengan role-based views
2. Grade input dan calculation engine
3. Student transcript report
4. Admin period management
5. Email notifications
6. Export to PDF/Excel

---

## 📞 Support

Jika ada pertanyaan atau error:
1. Cek error message di terminal PHP server
2. Verify MySQL connection: `mysql -u root`
3. Check database: `SHOW DATABASES;` dan `USE subsia_kad; SHOW TABLES;`
4. Review `config.php` MySQL credentials

---

**Terakhir diupdate:** 22 Juni 2026
**Status:** ✅ Siap Production (Backend)
