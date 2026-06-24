<?php
// ==================== SESSION & SECURITY ====================
session_start();

// Redirect ke login jika belum authenticated
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

// Load database config
require_once 'config.php';

$currentUser = $_SESSION['user']['username'];
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$userRole = $_SESSION['user']['role']; // 'mahasiswa', 'dosen', 'admin_akademik', 'pimpinan_fakultas'
$currentTime = date('Y-m-d H:i:s');
$accessNotice = '';
$successNotice = '';

if (!$currentUserId && $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $currentUser]);
        $currentUserId = (int) $stmt->fetchColumn();
        $_SESSION['user']['id'] = $currentUserId;
    } catch (Exception $e) {
        $currentUserId = 0;
    }
}

if ($pdo) {
    try {
        ensureAcademicSchema($pdo);
    } catch (Exception $e) {
        $accessNotice = 'Migrasi data akademik gagal: ' . $e->getMessage();
    }
}

// ==================== INITIALIZE DATA ====================
$fallbackMahasiswaData = [
    ['nim' => '21001', 'nama' => 'Ahmad Fauzi', 'tugas' => 85, 'uts' => 80, 'uas' => 90, 'akhir' => 85.5, 'grade' => 'A', 'mk' => 'Pemrograman Berbasis Web', 'sks' => 3, 'kode' => 'IF302'],
    ['nim' => '21002', 'nama' => 'Siti Rahmawati', 'tugas' => 70, 'uts' => 75, 'uas' => 68, 'akhir' => 70.7, 'grade' => 'B', 'mk' => 'Pemrograman Berbasis Web', 'sks' => 3, 'kode' => 'IF302'],
    ['nim' => '21003', 'nama' => 'Budi Santoso', 'tugas' => 60, 'uts' => 55, 'uas' => 65, 'akhir' => 60.5, 'grade' => 'C', 'mk' => 'Pemrograman Berbasis Web', 'sks' => 3, 'kode' => 'IF302']
];

$bobot = ['tugas' => 30, 'uts' => 30, 'uas' => 40];
$isPeriodeTerbuka = true;
$mahasiswaData = $pdo ? loadNilaiData($pdo) : $fallbackMahasiswaData;
$dosenMataKuliah = $pdo ? loadDosenMataKuliah($pdo, $currentUserId) : [];
$dosenNilaiData = $userRole === 'dosen' ? array_values(array_filter($mahasiswaData, fn($mhs) => ($mhs['dosen_username'] ?? '') === $currentUser)) : $mahasiswaData;
$mahasiswaTranskripData = $pdo ? loadMahasiswaNilaiData($pdo, $currentUserId) : (isset($fallbackMahasiswaData[1]) ? [$fallbackMahasiswaData[1]] : array_slice($fallbackMahasiswaData, 0, 1));
$upcomingCourses = $pdo ? loadUpcomingCourses($pdo, $currentUserId, $userRole) : [];
$upcomingTasks = $pdo ? loadUpcomingTasks($pdo, $currentUserId, $userRole) : [];
$allUsersForAdmin = $pdo && $userRole === 'admin_akademik' ? loadUsersForAdmin($pdo) : [];

if (isset($_SESSION['mahasiswa_data']) && is_array($_SESSION['mahasiswa_data'])) {
    $mahasiswaData = $pdo ? $mahasiswaData : $_SESSION['mahasiswa_data'];
}
if (isset($_SESSION['bobot']) && is_array($_SESSION['bobot'])) {
    $bobot = $_SESSION['bobot'];
}
if (isset($_SESSION['periode_terbuka'])) {
    $isPeriodeTerbuka = (bool) $_SESSION['periode_terbuka'];
}
if (!isset($_SESSION['audit_log'])) {
    $_SESSION['audit_log'] = [];
}

// ==================== HANDLE FORM SUBMISSIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $mutatingActions = ['toggle_periode', 'update_bobot', 'simpan_nilai', 'ubah_data_laporan', 'verifikasi_nilai', 'tambah_mata_kuliah', 'tambah_tugas', 'update_user_role'];

    if ($userRole === 'pimpinan_fakultas' && in_array($action, $mutatingActions, true)) {
        $accessNotice = 'Akses ditolak. Pimpinan Fakultas hanya dapat melihat statistik akademik dan mengunduh laporan.';
        logAudit("PIMPINAN FAKULTAS ({$currentUser}): Percobaan ubah data ditolak", $pdo);
    }
    
    // Only admin can toggle period
    if ($action === 'toggle_periode' && $userRole === 'admin_akademik') {
        if ($isPeriodeTerbuka && empty($_SESSION['nilai_terverifikasi'])) {
            $accessNotice = 'Periode belum bisa ditutup. Verifikasi nilai dosen terlebih dahulu.';
            logAudit("ADMIN ({$currentUser}): Penutupan periode ditolak karena nilai belum diverifikasi", $pdo);
        } else {
            $isPeriodeTerbuka = !$isPeriodeTerbuka;
            logAudit("ADMIN ({$currentUser}): " . ($isPeriodeTerbuka ? 'Membuka' : 'Menutup') . " periode input nilai", $pdo);
            $_SESSION['periode_terbuka'] = $isPeriodeTerbuka;
            $successNotice = $isPeriodeTerbuka ? 'Periode input nilai dibuka.' : 'Periode input nilai ditutup. Dosen tidak dapat mengedit nilai.';
        }
    }
    
    // Only admin can update bobot
    if ($action === 'update_bobot' && $userRole === 'admin_akademik') {
        $t = (int)($_POST['w_tugas'] ?? 0);
        $uts = (int)($_POST['w_uts'] ?? 0);
        $uas = (int)($_POST['w_uas'] ?? 0);
        
        if (($t + $uts + $uas) === 100) {
            $bobot = ['tugas' => $t, 'uts' => $uts, 'uas' => $uas];
            foreach ($mahasiswaData as $i => $mhs) {
                $akhir = ($mhs['tugas'] * ($t / 100)) + ($mhs['uts'] * ($uts / 100)) + ($mhs['uas'] * ($uas / 100));
                $mahasiswaData[$i]['akhir'] = round($akhir, 1);
                $mahasiswaData[$i]['grade'] = hitungGrade($akhir);
            }
            $_SESSION['bobot'] = $bobot;
            $_SESSION['mahasiswa_data'] = $mahasiswaData;
            logAudit("ADMIN ({$currentUser}): Mengubah bobot master (Tugas: {$t}%, UTS: {$uts}%, UAS: {$uas}%)", $pdo);
            $successNotice = 'Bobot nilai disimpan dan nilai akhir dihitung ulang.';
        } else {
            $accessNotice = 'Bobot ditolak. Total bobot Tugas, UTS, dan UAS wajib 100%.';
        }
    }

    if ($action === 'verifikasi_nilai' && $userRole === 'admin_akademik') {
        $_SESSION['nilai_terverifikasi'] = true;
        logAudit("ADMIN ({$currentUser}): Memverifikasi kelengkapan nilai dosen", $pdo);
        $successNotice = 'Nilai dosen diverifikasi lengkap. Audit log tercatat.';
    }

    if ($action === 'update_user_role' && $userRole === 'admin_akademik' && $pdo) {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $targetRole = $_POST['role'] ?? 'mahasiswa';
        $allowedAssignmentRoles = ['mahasiswa', 'dosen', 'admin_akademik', 'pimpinan_fakultas'];

        if ($targetUserId === $currentUserId) {
            $accessNotice = 'Role akun Anda sendiri tidak dapat diubah dari panel ini.';
        } elseif (!in_array($targetRole, $allowedAssignmentRoles, true)) {
            $accessNotice = 'Role tujuan tidak valid.';
        } else {
            $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
            $roleStmt->execute([':name' => $targetRole]);
            $roleId = (int) $roleStmt->fetchColumn();

            if ($roleId) {
                $pdo->prepare('UPDATE users SET role_id = :role_id WHERE id = :id')->execute([':role_id' => $roleId, ':id' => $targetUserId]);
                ensureProfileForRole($pdo, $targetUserId, $targetRole);
                logAudit("ADMIN ({$currentUser}): Mengutuskan user ID {$targetUserId} menjadi {$targetRole}", $pdo);
                $successNotice = 'Role user berhasil diperbarui oleh Admin Akademik.';
            }
        }
    }

    if ($action === 'tambah_mata_kuliah' && $userRole === 'dosen' && $pdo) {
        $dosenProfileId = getDosenProfileId($pdo, $currentUserId);
        $kode = strtoupper(trim($_POST['kode'] ?? ''));
        $nama = trim($_POST['nama'] ?? '');
        $sks = (int)($_POST['sks'] ?? 0);
        $jadwal = trim($_POST['jadwal_mulai'] ?? '');
        $ruang = trim($_POST['ruang'] ?? '');
        $semester = trim($_POST['semester'] ?? 'Genap 2025/2026');

        if (!$dosenProfileId) {
            $accessNotice = 'Profil dosen belum tersedia. Minta Admin Akademik mengutuskan akun ini sebagai dosen.';
        } elseif ($kode === '' || $nama === '' || $sks < 1 || $sks > 6 || $jadwal === '') {
            $accessNotice = 'Data mata kuliah belum lengkap. Kode, nama, SKS, dan jadwal wajib diisi.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO mata_kuliah (kode, nama, sks, dosen_id, jadwal_mulai, ruang, semester) VALUES (:kode, :nama, :sks, :dosen_id, :jadwal_mulai, :ruang, :semester)');
            $stmt->execute([':kode' => $kode, ':nama' => $nama, ':sks' => $sks, ':dosen_id' => $dosenProfileId, ':jadwal_mulai' => $jadwal, ':ruang' => $ruang, ':semester' => $semester]);
            $mkId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO nilai (mahasiswa_id, mata_kuliah_id, tugas, uts, uas, akhir, grade) SELECT id, :mk_id, 0, 0, 0, 0, "E" FROM mahasiswa_profile')->execute([':mk_id' => $mkId]);
            logAudit("DOSEN ({$currentUser}): Menambahkan mata kuliah {$kode} - {$nama}", $pdo);
            $successNotice = 'Mata kuliah berhasil ditambahkan dan hanya akun dosen ini yang dapat mengaturnya.';
        }
    }

    if ($action === 'tambah_tugas' && $userRole === 'dosen' && $pdo) {
        $dosenProfileId = getDosenProfileId($pdo, $currentUserId);
        $mkId = (int)($_POST['mata_kuliah_id'] ?? 0);
        $judul = trim($_POST['judul'] ?? '');
        $dueAt = trim($_POST['due_at'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        if (!$dosenProfileId || !dosenOwnsCourse($pdo, $dosenProfileId, $mkId)) {
            $accessNotice = 'Tugas ditolak. Dosen hanya dapat membuat tugas untuk mata kuliah miliknya.';
        } elseif ($judul === '' || $dueAt === '') {
            $accessNotice = 'Judul tugas dan deadline wajib diisi.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO tugas (mata_kuliah_id, dosen_id, judul, deskripsi, due_at) VALUES (:mata_kuliah_id, :dosen_id, :judul, :deskripsi, :due_at)');
            $stmt->execute([':mata_kuliah_id' => $mkId, ':dosen_id' => $dosenProfileId, ':judul' => $judul, ':deskripsi' => $deskripsi, ':due_at' => $dueAt]);
            logAudit("DOSEN ({$currentUser}): Menambahkan tugas {$judul}", $pdo);
            $successNotice = 'Tugas berhasil ditambahkan dan akan muncul di panel mahasiswa.';
        }
    }
    
    // Only dosen can save grades
    if ($action === 'simpan_nilai' && $userRole === 'dosen') {
        if ($isPeriodeTerbuka) {
            $postedTugas = $_POST['tugas'] ?? [];
            $postedUts = $_POST['uts'] ?? [];
            $postedUas = $_POST['uas'] ?? [];
            $isValid = true;

            $targetData = $pdo ? $dosenNilaiData : $mahasiswaData;
            foreach ($targetData as $index => $mhs) {
                foreach (['tugas' => $postedTugas, 'uts' => $postedUts, 'uas' => $postedUas] as $field => $values) {
                    $key = $pdo ? (int)($mhs['nilai_id'] ?? 0) : $index;
                    if (!isset($values[$key]) || $values[$key] === '' || !is_numeric($values[$key]) || $values[$key] < 0 || $values[$key] > 100) {
                        $isValid = false;
                    }
                }
            }

            if ($isValid) {
                foreach ($targetData as $index => $mhs) {
                    $key = $pdo ? (int)($mhs['nilai_id'] ?? 0) : $index;
                    $tugas = (float) $postedTugas[$key];
                    $uts = (float) $postedUts[$key];
                    $uas = (float) $postedUas[$key];
                    $akhir = ($tugas * ($bobot['tugas'] / 100)) + ($uts * ($bobot['uts'] / 100)) + ($uas * ($bobot['uas'] / 100));

                    if ($pdo) {
                        $dosenProfileId = getDosenProfileId($pdo, $currentUserId);
                        if ($dosenProfileId && dosenOwnsNilai($pdo, $dosenProfileId, $key)) {
                            $stmt = $pdo->prepare('UPDATE nilai SET tugas = :tugas, uts = :uts, uas = :uas, akhir = :akhir, grade = :grade WHERE id = :id');
                            $stmt->execute([':tugas' => $tugas, ':uts' => $uts, ':uas' => $uas, ':akhir' => round($akhir, 1), ':grade' => hitungGrade($akhir), ':id' => $key]);
                        }
                    } else {
                        $mahasiswaData[$index]['tugas'] = $tugas;
                        $mahasiswaData[$index]['uts'] = $uts;
                        $mahasiswaData[$index]['uas'] = $uas;
                        $mahasiswaData[$index]['akhir'] = round($akhir, 1);
                        $mahasiswaData[$index]['grade'] = hitungGrade($akhir);
                    }
                }

                if (!$pdo) $_SESSION['mahasiswa_data'] = $mahasiswaData;
                $_SESSION['nilai_terverifikasi'] = false;
                logAudit("DOSEN ({$currentUser}): Menyimpan rekapitulasi nilai", $pdo);
                $successNotice = 'Nilai valid, nilai akhir dihitung otomatis, dan rekap berhasil disimpan.';
            } else {
                $accessNotice = 'Nilai ditolak. Semua nilai Tugas, UTS, dan UAS wajib diisi dalam rentang 0-100.';
            }
        } else {
            $accessNotice = 'Edit ditolak. Periode input nilai sudah ditutup oleh admin.';
            logAudit("DOSEN ({$currentUser}): Percobaan edit ditolak karena periode tertutup", $pdo);
        }
    }

    if ($pdo) {
        $mahasiswaData = loadNilaiData($pdo);
        $dosenMataKuliah = loadDosenMataKuliah($pdo, $currentUserId);
        $dosenNilaiData = $userRole === 'dosen' ? array_values(array_filter($mahasiswaData, fn($mhs) => ($mhs['dosen_username'] ?? '') === $currentUser)) : $mahasiswaData;
        $mahasiswaTranskripData = loadMahasiswaNilaiData($pdo, $currentUserId);
        $upcomingCourses = loadUpcomingCourses($pdo, $currentUserId, $userRole);
        $upcomingTasks = loadUpcomingTasks($pdo, $currentUserId, $userRole);
        $allUsersForAdmin = $userRole === 'admin_akademik' ? loadUsersForAdmin($pdo) : [];
    }
}

// ==================== HELPER FUNCTIONS ====================
function logAudit($message, $pdo) {
    $_SESSION['audit_log'][] = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    $_SESSION['audit_log'] = array_slice($_SESSION['audit_log'], -20);
}

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureAcademicSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mata_kuliah (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(50),
        nama VARCHAR(255),
        sks INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!columnExists($pdo, 'mata_kuliah', 'dosen_id')) {
        $pdo->exec("ALTER TABLE mata_kuliah ADD dosen_id INT NULL");
    }
    if (!columnExists($pdo, 'mata_kuliah', 'jadwal_mulai')) {
        $pdo->exec("ALTER TABLE mata_kuliah ADD jadwal_mulai DATETIME NULL");
    }
    if (!columnExists($pdo, 'mata_kuliah', 'ruang')) {
        $pdo->exec("ALTER TABLE mata_kuliah ADD ruang VARCHAR(100) NULL");
    }
    if (!columnExists($pdo, 'mata_kuliah', 'semester')) {
        $pdo->exec("ALTER TABLE mata_kuliah ADD semester VARCHAR(100) NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS nilai (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!tableExists($pdo, 'tugas')) {
        $pdo->exec("CREATE TABLE tugas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mata_kuliah_id INT NOT NULL,
            dosen_id INT NOT NULL,
            judul VARCHAR(255) NOT NULL,
            deskripsi TEXT,
            due_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_tugas_mk FOREIGN KEY (mata_kuliah_id) REFERENCES mata_kuliah(id) ON DELETE CASCADE,
            CONSTRAINT fk_tugas_dosen FOREIGN KEY (dosen_id) REFERENCES dosen_profile(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    $pdo->exec("UPDATE mata_kuliah mk
        SET dosen_id = (SELECT dp.id FROM dosen_profile dp JOIN users u ON u.id = dp.user_id WHERE u.username = 'dosen' LIMIT 1),
            jadwal_mulai = COALESCE(jadwal_mulai, DATE_ADD(NOW(), INTERVAL 3 DAY)),
            ruang = COALESCE(ruang, 'Ruang 301'),
            semester = COALESCE(semester, 'Genap 2025/2026')
        WHERE mk.dosen_id IS NULL");
}

function ensureProfileForRole($pdo, $userId, $role) {
    if ($role === 'mahasiswa') {
        $pdo->prepare("INSERT IGNORE INTO mahasiswa_profile (user_id, nim, prodi, angkatan) VALUES (:user_id, CONCAT('MHS', :user_id), 'Teknik Informatika', YEAR(CURDATE()))")->execute([':user_id' => $userId]);
    } elseif ($role === 'dosen') {
        $pdo->prepare("INSERT IGNORE INTO dosen_profile (user_id, nidn, fakultas, jabatan) VALUES (:user_id, CONCAT('NIDN', :user_id), 'Fakultas Teknik', 'Dosen')")->execute([':user_id' => $userId]);
    } elseif ($role === 'admin_akademik') {
        $pdo->prepare("INSERT IGNORE INTO admin_profile (user_id, staff_id, unit) VALUES (:user_id, CONCAT('ADM', :user_id), 'Bagian Akademik')")->execute([':user_id' => $userId]);
    } elseif ($role === 'pimpinan_fakultas') {
        $pdo->prepare("INSERT IGNORE INTO pimpinan_fakultas_profile (user_id, nip, fakultas, jabatan) VALUES (:user_id, CONCAT('NIP', :user_id), 'Fakultas Teknik', 'Pimpinan Fakultas')")->execute([':user_id' => $userId]);
    }
}

function getDosenProfileId($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT id FROM dosen_profile WHERE user_id = :user_id LIMIT 1');
    $stmt->execute([':user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function dosenOwnsCourse($pdo, $dosenProfileId, $mkId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM mata_kuliah WHERE id = :mk_id AND dosen_id = :dosen_id');
    $stmt->execute([':mk_id' => $mkId, ':dosen_id' => $dosenProfileId]);
    return (int) $stmt->fetchColumn() > 0;
}

function dosenOwnsNilai($pdo, $dosenProfileId, $nilaiId) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM nilai n JOIN mata_kuliah mk ON mk.id = n.mata_kuliah_id WHERE n.id = :nilai_id AND mk.dosen_id = :dosen_id');
    $stmt->execute([':nilai_id' => $nilaiId, ':dosen_id' => $dosenProfileId]);
    return (int) $stmt->fetchColumn() > 0;
}

function loadNilaiData($pdo) {
    $sql = "SELECT n.id AS nilai_id, mp.nim, COALESCE(mu.full_name, mu.username) AS nama,
            COALESCE(n.tugas, 0) AS tugas, COALESCE(n.uts, 0) AS uts, COALESCE(n.uas, 0) AS uas,
            COALESCE(n.akhir, 0) AS akhir, COALESCE(n.grade, 'E') AS grade,
            mk.id AS mata_kuliah_id, mk.kode, mk.nama AS mk, mk.sks, mk.jadwal_mulai, mk.ruang, mk.semester,
            du.username AS dosen_username, COALESCE(du.full_name, du.username) AS dosen_nama
        FROM nilai n
        JOIN mahasiswa_profile mp ON mp.id = n.mahasiswa_id
        JOIN users mu ON mu.id = mp.user_id
        JOIN mata_kuliah mk ON mk.id = n.mata_kuliah_id
        LEFT JOIN dosen_profile dp ON dp.id = mk.dosen_id
        LEFT JOIN users du ON du.id = dp.user_id
        ORDER BY mk.kode, mp.nim";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function loadMahasiswaNilaiData($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT n.id AS nilai_id, mp.nim, COALESCE(u.full_name, u.username) AS nama,
            COALESCE(n.tugas, 0) AS tugas, COALESCE(n.uts, 0) AS uts, COALESCE(n.uas, 0) AS uas,
            COALESCE(n.akhir, 0) AS akhir, COALESCE(n.grade, 'E') AS grade,
            mk.id AS mata_kuliah_id, mk.kode, mk.nama AS mk, mk.sks, mk.jadwal_mulai, mk.ruang, mk.semester
        FROM mahasiswa_profile mp
        JOIN users u ON u.id = mp.user_id
        JOIN nilai n ON n.mahasiswa_id = mp.id
        JOIN mata_kuliah mk ON mk.id = n.mata_kuliah_id
        WHERE mp.user_id = :user_id
        ORDER BY mk.kode");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadDosenMataKuliah($pdo, $userId) {
    $dosenId = getDosenProfileId($pdo, $userId);
    if (!$dosenId) return [];
    $stmt = $pdo->prepare('SELECT * FROM mata_kuliah WHERE dosen_id = :dosen_id ORDER BY kode');
    $stmt->execute([':dosen_id' => $dosenId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadUpcomingCourses($pdo, $userId, $role) {
    if ($role === 'dosen') {
        $dosenId = getDosenProfileId($pdo, $userId);
        if (!$dosenId) return [];
        $stmt = $pdo->prepare('SELECT * FROM mata_kuliah WHERE dosen_id = :dosen_id AND (jadwal_mulai IS NULL OR jadwal_mulai >= NOW()) ORDER BY jadwal_mulai LIMIT 8');
        $stmt->execute([':dosen_id' => $dosenId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->query('SELECT * FROM mata_kuliah WHERE jadwal_mulai IS NULL OR jadwal_mulai >= NOW() ORDER BY jadwal_mulai LIMIT 8');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadUpcomingTasks($pdo, $userId, $role) {
    $params = [];
    $where = 't.due_at >= NOW()';
    if ($role === 'dosen') {
        $dosenId = getDosenProfileId($pdo, $userId);
        if (!$dosenId) return [];
        $where .= ' AND t.dosen_id = :dosen_id';
        $params[':dosen_id'] = $dosenId;
    }

    $stmt = $pdo->prepare("SELECT t.*, mk.kode, mk.nama AS mk FROM tugas t JOIN mata_kuliah mk ON mk.id = t.mata_kuliah_id WHERE {$where} ORDER BY t.due_at LIMIT 8");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadUsersForAdmin($pdo) {
    $stmt = $pdo->query("SELECT u.id, u.username, u.full_name, u.email, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC, u.id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hitungGrade($nilai) {
    if ($nilai >= 80) return 'A';
    if ($nilai >= 70) return 'B';
    if ($nilai >= 60) return 'C';
    if ($nilai >= 50) return 'D';
    return 'E';
}

function gradeKeBobot($grade) {
    $mapping = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'E' => 0];
    return $mapping[$grade] ?? 0;
}

function hitungIPK($data) {
    $totalBobotSKS = 0;
    $totalSKS = 0;
    foreach ($data as $mhs) {
        $totalBobotSKS += (gradeKeBobot($mhs['grade']) * $mhs['sks']);
        $totalSKS += $mhs['sks'];
    }
    return $totalSKS > 0 ? $totalBobotSKS / $totalSKS : 0;
}

function hitungRataRataNilai($data) {
    if (count($data) === 0) return 0;
    $total = 0;
    foreach ($data as $mhs) {
        $total += $mhs['akhir'];
    }
    return $total / count($data);
}

function distribusiIPK($data) {
    $buckets = [
        '3.50 - 4.00' => 0,
        '3.00 - 3.49' => 0,
        '2.50 - 2.99' => 0,
        '< 2.50' => 0,
    ];

    foreach ($data as $mhs) {
        $ipk = gradeKeBobot($mhs['grade']);
        if ($ipk >= 3.5) {
            $buckets['3.50 - 4.00']++;
        } elseif ($ipk >= 3.0) {
            $buckets['3.00 - 3.49']++;
        } elseif ($ipk >= 2.5) {
            $buckets['2.50 - 2.99']++;
        } else {
            $buckets['< 2.50']++;
        }
    }

    return $buckets;
}

function pdfEscape($text) {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $text);
}

function outputSimplePdf($filename, $lines) {
    $content = "BT\n/F1 12 Tf\n50 790 Td\n";
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $content .= "0 -18 Td\n";
        }
        $content .= '(' . pdfEscape($line) . ") Tj\n";
    }
    $content .= "ET";

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $pdf;
    exit;
}

function outputExcelTable($filename, $title, $headers, $rows) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($headers) . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</th></tr>';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// Role-based access control
$allowedRoles = ['mahasiswa', 'dosen', 'admin_akademik', 'pimpinan_fakultas'];
if (!in_array($userRole, $allowedRoles)) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'laporan_akademik') {
    if ($userRole !== 'pimpinan_fakultas') {
        http_response_code(403);
        echo 'Akses ditolak.';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-akademik.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Laporan Akademik - Read Only']);
    fputcsv($out, ['Tanggal Unduh', $currentTime]);
    fputcsv($out, ['Rata-rata Nilai', number_format(hitungRataRataNilai($mahasiswaData), 2)]);
    fputcsv($out, ['Rata-rata IPK', number_format(hitungIPK($mahasiswaData), 2)]);
    fputcsv($out, []);
    fputcsv($out, ['Distribusi IPK', 'Jumlah Mahasiswa']);
    foreach (distribusiIPK($mahasiswaData) as $range => $jumlah) {
        fputcsv($out, [$range, $jumlah]);
    }
    fputcsv($out, []);
    fputcsv($out, ['NIM', 'Nama', 'Kode MK', 'Mata Kuliah', 'SKS', 'Nilai Akhir', 'Grade']);
    foreach ($mahasiswaData as $mhs) {
        fputcsv($out, [$mhs['nim'], $mhs['nama'], $mhs['kode'], $mhs['mk'], $mhs['sks'], $mhs['akhir'], $mhs['grade']]);
    }
    fclose($out);
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'transkrip') {
    if ($userRole !== 'mahasiswa') {
        http_response_code(403);
        echo 'Akses ditolak.';
        exit;
    }

    $format = $_GET['format'] ?? 'excel';
    $transkripData = $mahasiswaTranskripData ?: (isset($mahasiswaData[1]) ? [$mahasiswaData[1]] : array_slice($mahasiswaData, 0, 1));
    $rows = [];
    foreach ($transkripData as $mhs) {
        $rows[] = [$mhs['kode'], $mhs['mk'], $mhs['sks'], number_format($mhs['akhir'], 1), $mhs['grade'], 'Lulus'];
    }

    if ($format === 'pdf') {
        $lines = [
            'Transkrip Nilai Mahasiswa',
            'Mahasiswa: ' . $currentUser,
            'IPK: ' . number_format(hitungIPK($transkripData), 2),
            'Kode | Mata Kuliah | SKS | Nilai | Grade | Status',
        ];
        foreach ($rows as $row) {
            $lines[] = implode(' | ', $row);
        }
        outputSimplePdf('transkrip-nilai.pdf', $lines);
    }

    outputExcelTable('transkrip-nilai.xls', 'Transkrip Nilai Mahasiswa - IPK ' . number_format(hitungIPK($transkripData), 2), ['Kode MK', 'Mata Kuliah', 'SKS', 'Nilai Akhir', 'Grade', 'Status'], $rows);
}

$rataRataNilai = hitungRataRataNilai($mahasiswaData);
$rataRataIPK = hitungIPK($mahasiswaData);
$distribusiIPK = distribusiIPK($mahasiswaData);
$maxDistribusi = max($distribusiIPK) ?: 1;
if (!$mahasiswaTranskripData) {
    $mahasiswaTranskripData = isset($mahasiswaData[1]) ? [$mahasiswaData[1]] : array_slice($mahasiswaData, 0, 1);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-SIAKAD - Modul Nilai & Transkrip</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-container {
            display: none;
        }
        .dashboard-container.active {
            display: block;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans min-h-screen flex flex-col">

    <!-- NAVIGATION BAR -->
    <div class="bg-slate-900 text-white px-6 py-3 flex flex-wrap justify-between items-center shadow-md gap-3">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-university text-sky-400 text-xl"></i>
            <span class="font-bold text-lg tracking-wide">Sub-SIAKAD <span class="text-xs font-normal bg-sky-500 text-white px-2 py-0.5 rounded-full ml-1">v1.0</span></span>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-right">
                <div class="text-xs text-slate-300 uppercase tracking-wider font-semibold">Logged in as</div>
                <div class="font-bold text-sm"><?php echo htmlspecialchars($currentUser); ?></div>
                <div class="text-xs text-sky-300">Role: <span class="font-semibold"><?php echo ucfirst(str_replace('_', ' ', $userRole)); ?></span></div>
            </div>
            <a href="logout.php" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2">
                <i class="fa-solid fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="flex-1 max-w-7xl w-full mx-auto p-4 md:p-6">
        <?php if ($accessNotice): ?>
            <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl text-sm font-medium">
                <i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($accessNotice); ?>
            </div>
        <?php endif; ?>
        <?php if ($successNotice): ?>
            <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl text-sm font-medium">
                <i class="fa-solid fa-circle-check mr-2"></i><?php echo htmlspecialchars($successNotice); ?>
            </div>
        <?php endif; ?>
        
        <!-- ==================== PANEL DOSEN ==================== -->
        <div id="panel-dosen" class="dashboard-container <?php echo $userRole === 'dosen' ? 'active' : ''; ?> space-y-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800"><i class="fa-solid fa-chalkboard-user text-sky-500 mr-2"></i>Manajemen Mata Kuliah & Nilai</h2>
                        <p class="text-sm text-gray-500 mt-1">Dosen hanya dapat mengatur mata kuliah yang dibuat atau diampunya sendiri.</p>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 rounded-xl text-xs text-gray-600 border border-gray-200">
                        <span class="font-bold block text-gray-700 mb-1">Bobot Nilai Aktif:</span>
                        Tugas: <span class="font-semibold"><?php echo $bobot['tugas']; ?>%</span> | UTS: <span class="font-semibold"><?php echo $bobot['uts']; ?>%</span> | UAS: <span class="font-semibold"><?php echo $bobot['uas']; ?>%</span>
                    </div>
                </div>

                <!-- Status Banner -->
                <div class="mb-6 <?php echo $isPeriodeTerbuka ? 'bg-emerald-50 border-emerald-500' : 'bg-rose-50 border-rose-500'; ?> border-l-4 text-<?php echo $isPeriodeTerbuka ? 'emerald' : 'rose'; ?>-800 p-4 rounded-r-lg flex justify-between items-center shadow-sm">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-<?php echo $isPeriodeTerbuka ? 'circle-check text-emerald-500' : 'circle-xmark text-rose-500'; ?> text-lg"></i>
                        <div>
                            <span class="font-bold"><?php echo $isPeriodeTerbuka ? 'Periode Input Nilai Dibuka' : 'Periode Input Nilai Ditutup'; ?></span>
                            <span class="text-sm block"><?php echo $isPeriodeTerbuka ? 'Anda dapat mengisi dan mengoreksi nilai mahasiswa.' : 'Akses pengisian telah dikunci oleh Admin. Anda tidak dapat mengubah nilai.'; ?></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                    <form method="POST" class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
                        <input type="hidden" name="action" value="tambah_mata_kuliah">
                        <h3 class="font-bold text-gray-800 text-sm"><i class="fa-solid fa-book-medical text-sky-500 mr-2"></i>Tambah Mata Kuliah</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <input name="kode" placeholder="Kode MK" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="nama" placeholder="Nama mata kuliah" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="sks" type="number" min="1" max="6" placeholder="SKS" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="ruang" placeholder="Ruang" class="px-3 py-2 border rounded-lg text-sm">
                            <input name="jadwal_mulai" type="datetime-local" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="semester" placeholder="Semester" value="Genap 2025/2026" class="px-3 py-2 border rounded-lg text-sm">
                        </div>
                        <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">
                            <i class="fa-solid fa-plus mr-1"></i> Tambah MK
                        </button>
                    </form>

                    <form method="POST" class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3">
                        <input type="hidden" name="action" value="tambah_tugas">
                        <h3 class="font-bold text-gray-800 text-sm"><i class="fa-solid fa-clipboard-list text-sky-500 mr-2"></i>Tambah Tugas</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <select name="mata_kuliah_id" class="px-3 py-2 border rounded-lg text-sm" required>
                                <option value="">Pilih mata kuliah</option>
                                <?php foreach ($dosenMataKuliah as $mk): ?>
                                    <option value="<?php echo (int)$mk['id']; ?>"><?php echo htmlspecialchars($mk['kode'] . ' - ' . $mk['nama']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="judul" placeholder="Judul tugas" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="due_at" type="datetime-local" class="px-3 py-2 border rounded-lg text-sm" required>
                            <input name="deskripsi" placeholder="Deskripsi singkat" class="px-3 py-2 border rounded-lg text-sm">
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">
                            <i class="fa-solid fa-plus mr-1"></i> Tambah Tugas
                        </button>
                    </form>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="simpan_nilai">
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 font-semibold text-sm">
                                <th class="p-4 w-20">NIM</th>
                                <th class="p-4">Nama Mahasiswa</th>
                                <th class="p-4 w-28">Tugas (<?php echo $bobot['tugas']; ?>%)</th>
                                <th class="p-4 w-28">UTS (<?php echo $bobot['uts']; ?>%)</th>
                                <th class="p-4 w-28">UAS (<?php echo $bobot['uas']; ?>%)</th>
                                <th class="p-4 w-28 text-center">Nilai Akhir</th>
                                <th class="p-4 w-24 text-center">Grade</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700 divide-y divide-gray-100">
                            <?php foreach ($dosenNilaiData as $index => $mhs): ?>
                            <?php $nilaiKey = $pdo ? (int)($mhs['nilai_id'] ?? 0) : $index; ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono font-medium"><?php echo htmlspecialchars($mhs['nim']); ?></td>
                                <td class="p-4 font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($mhs['nama']); ?>
                                    <div class="text-xs text-gray-500 font-normal"><?php echo htmlspecialchars(($mhs['kode'] ?? '') . ' - ' . ($mhs['mk'] ?? '')); ?></div>
                                </td>
                                <td class="p-4">
                                    <input type="number" name="tugas[<?php echo $nilaiKey; ?>]" min="0" max="100" value="<?php echo $mhs['tugas']; ?>" class="w-full px-2 py-1 border rounded-lg text-center text-sm focus:outline-none focus:ring-2 <?php echo $isPeriodeTerbuka ? 'bg-white border-gray-300 focus:ring-sky-500' : 'bg-gray-100 border-gray-200 cursor-not-allowed'; ?>" <?php echo $isPeriodeTerbuka ? '' : 'disabled'; ?>>
                                </td>
                                <td class="p-4">
                                    <input type="number" name="uts[<?php echo $nilaiKey; ?>]" min="0" max="100" value="<?php echo $mhs['uts']; ?>" class="w-full px-2 py-1 border rounded-lg text-center text-sm focus:outline-none focus:ring-2 <?php echo $isPeriodeTerbuka ? 'bg-white border-gray-300 focus:ring-sky-500' : 'bg-gray-100 border-gray-200 cursor-not-allowed'; ?>" <?php echo $isPeriodeTerbuka ? '' : 'disabled'; ?>>
                                </td>
                                <td class="p-4">
                                    <input type="number" name="uas[<?php echo $nilaiKey; ?>]" min="0" max="100" value="<?php echo $mhs['uas']; ?>" class="w-full px-2 py-1 border rounded-lg text-center text-sm focus:outline-none focus:ring-2 <?php echo $isPeriodeTerbuka ? 'bg-white border-gray-300 focus:ring-sky-500' : 'bg-gray-100 border-gray-200 cursor-not-allowed'; ?>" <?php echo $isPeriodeTerbuka ? '' : 'disabled'; ?>>
                                </td>
                                <td class="p-4 text-center font-bold text-gray-800"><?php echo number_format($mhs['akhir'], 1); ?></td>
                                <td class="p-4 text-center">
                                    <span class="px-2.5 py-1 rounded-md text-xs font-bold <?php 
                                        if ($mhs['grade'] === 'A') echo 'bg-emerald-100 text-emerald-800';
                                        elseif ($mhs['grade'] === 'B') echo 'bg-blue-100 text-blue-800';
                                        else echo 'bg-amber-100 text-amber-800';
                                    ?>"><?php echo $mhs['grade']; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-end gap-3">
                    <button type="submit" <?php echo !$isPeriodeTerbuka ? 'disabled' : ''; ?> class="bg-sky-600 hover:bg-sky-700 text-white font-medium px-5 py-2.5 rounded-xl shadow-sm transition flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan & Rekap Otomatis
                    </button>
                </div>
                </form>
            </div>
        </div>

        <!-- ==================== PANEL MAHASISWA ==================== -->
        <div id="panel-mahasiswa" class="dashboard-container <?php echo $userRole === 'mahasiswa' ? 'active' : ''; ?> space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gradient-to-br from-sky-500 to-sky-600 text-white p-6 rounded-2xl shadow-sm">
                    <span class="text-xs uppercase tracking-wider font-semibold opacity-80">Indeks Prestasi Kumulatif (IPK)</span>
                    <h3 class="text-4xl font-extrabold mt-2"><?php echo number_format(hitungIPK($mahasiswaTranskripData), 2); ?></h3>
                    <p class="text-xs mt-2 opacity-70">*Dihitung otomatis dari seluruh semester</p>
                </div>
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <span class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Total SKS Diambil</span>
                    <h3 class="text-3xl font-bold text-gray-800 mt-2">18 <span class="text-sm font-normal text-gray-500">SKS</span></h3>
                    <p class="text-xs mt-2 text-emerald-600 font-medium"><i class="fa-solid fa-circle-check"></i> Semester Aktif (Genap 2025/2026)</p>
                </div>
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between">
                    <span class="text-xs text-gray-400 uppercase tracking-wider font-semibold">Cetak Dokumen Resmi</span>
                    <div class="flex gap-2 mt-4">
                        <a href="grades.php?download=transkrip&format=pdf" class="flex-1 bg-rose-500 hover:bg-rose-600 text-white py-2 rounded-xl text-xs font-medium transition flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fa-solid fa-file-pdf"></i> Unduh PDF
                        </a>
                        <a href="grades.php?download=transkrip&format=excel" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white py-2 rounded-xl text-xs font-medium transition flex items-center justify-center gap-1.5 shadow-sm">
                            <i class="fa-solid fa-file-excel"></i> Unduh Excel
                        </a>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fa-solid fa-file-invoice text-sky-500 mr-2"></i>Transkrip Nilai Real-Time</h2>
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 font-semibold text-xs uppercase tracking-wider">
                                <th class="p-4">Kode MK</th>
                                <th class="p-4">Nama Mata Kuliah</th>
                                <th class="p-4 text-center">SKS</th>
                                <th class="p-4 text-center">Nilai Angka</th>
                                <th class="p-4 text-center">Huruf (Grade)</th>
                                <th class="p-4 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700 divide-y divide-gray-100">
                            <?php foreach ($mahasiswaTranskripData as $mhs): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($mhs['kode']); ?></td>
                                <td class="p-4 font-medium text-gray-900"><?php echo htmlspecialchars($mhs['mk']); ?></td>
                                <td class="p-4 text-center"><?php echo $mhs['sks']; ?></td>
                                <td class="p-4 text-center font-semibold text-gray-600"><?php echo number_format($mhs['akhir'], 1); ?></td>
                                <td class="p-4 text-center"><span class="font-bold text-gray-800"><?php echo $mhs['grade']; ?></span></td>
                                <td class="p-4 text-center"><span class="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-medium">Lulus</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==================== PANEL ADMIN ==================== -->
        <div id="panel-admin" class="dashboard-container <?php echo $userRole === 'admin_akademik' ? 'active' : ''; ?> space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Kontrol Periode -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fa-solid fa-calendar-check text-indigo-500 mr-2"></i>Kontrol Periode Input</h2>
                        <p class="text-xs text-gray-500 mb-6">Kunci atau buka akses pengisian nilai bagi seluruh dosen pengampu.</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                        <form method="POST" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="text-sm font-semibold text-gray-700 block">Status Sistem Saat Ini:</span>
                                    <span class="text-xs <?php echo $isPeriodeTerbuka ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'; ?> px-2 py-0.5 rounded-full font-medium inline-block mt-1">
                                        <?php echo $isPeriodeTerbuka ? 'Terbuka' : 'Terkunci'; ?>
                                    </span>
                                </div>
                            </div>
                            <input type="hidden" name="action" value="toggle_periode">
                            <button type="submit" class="w-full <?php echo $isPeriodeTerbuka ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'; ?> text-white font-medium text-sm px-4 py-2.5 rounded-xl transition shadow-sm">
                                <i class="fa-solid fa-<?php echo $isPeriodeTerbuka ? 'lock' : 'lock-open'; ?> mr-1.5"></i> <?php echo $isPeriodeTerbuka ? 'Tutup Periode Nilai' : 'Buka Periode Nilai'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Konfigurasi Bobot -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fa-solid fa-sliders text-indigo-500 mr-2"></i>Konfigurasi Master Bobot (%)</h2>
                    <p class="text-xs text-gray-500 mb-4">Total akumulasi bobot wajib berjumlah 100%.</p>
                    <form method="POST" class="space-y-3">
                        <div class="flex items-center gap-3">
                            <span class="w-20 text-sm font-medium text-gray-600">Tugas:</span>
                            <input type="number" name="w_tugas" value="<?php echo $bobot['tugas']; ?>" class="w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-center text-sm focus:outline-none focus:ring-2 focus:ring-sky-500">
                            <span class="text-sm text-gray-400">%</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-20 text-sm font-medium text-gray-600">UTS:</span>
                            <input type="number" name="w_uts" value="<?php echo $bobot['uts']; ?>" class="w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-center text-sm focus:outline-none focus:ring-2 focus:ring-sky-500">
                            <span class="text-sm text-gray-400">%</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="w-20 text-sm font-medium text-gray-600">UAS:</span>
                            <input type="number" name="w_uas" value="<?php echo $bobot['uas']; ?>" class="w-20 px-3 py-1.5 border border-gray-300 rounded-lg text-center text-sm focus:outline-none focus:ring-2 focus:ring-sky-500">
                            <span class="text-sm text-gray-400">%</span>
                        </div>
                        <input type="hidden" name="action" value="update_bobot">
                        <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold py-2 rounded-xl transition mt-3">
                            Simpan Konfigurasi Bobot
                        </button>
                    </form>
                </div>

                <!-- Verifikasi Nilai -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fa-solid fa-clipboard-check text-indigo-500 mr-2"></i>Verifikasi Nilai Dosen</h2>
                    <p class="text-xs text-gray-500 mb-4">Pastikan nilai lengkap sebelum periode input ditutup.</p>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-3">
                        <div>
                            <span class="text-sm font-semibold text-gray-700 block">Status Verifikasi:</span>
                            <span class="text-xs <?php echo !empty($_SESSION['nilai_terverifikasi']) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'; ?> px-2 py-0.5 rounded-full font-medium inline-block mt-1">
                                <?php echo !empty($_SESSION['nilai_terverifikasi']) ? 'Lengkap' : 'Belum Diverifikasi'; ?>
                            </span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="verifikasi_nilai">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-2.5 rounded-xl transition">
                                <i class="fa-solid fa-check mr-1.5"></i> Verifikasi Nilai Lengkap
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fa-solid fa-calendar-days text-sky-500 mr-2"></i>Mata Kuliah Akan Datang</h2>
                    <div class="space-y-3">
                        <?php foreach ($upcomingCourses as $mk): ?>
                            <div class="border border-gray-200 rounded-xl p-4">
                                <div class="flex justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($mk['kode'] . ' - ' . $mk['nama']); ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars(($mk['semester'] ?? '-') . ' | ' . ($mk['ruang'] ?? '-')); ?></div>
                                    </div>
                                    <div class="text-right text-xs font-semibold text-sky-700"><?php echo !empty($mk['jadwal_mulai']) ? date('d M Y H:i', strtotime($mk['jadwal_mulai'])) : 'Belum dijadwalkan'; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$upcomingCourses): ?>
                            <div class="text-sm text-gray-500 border border-dashed border-gray-200 rounded-xl p-4">Belum ada jadwal mata kuliah mendatang.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-800 mb-4"><i class="fa-solid fa-list-check text-sky-500 mr-2"></i>Tugas Akan Datang</h2>
                    <div class="space-y-3">
                        <?php foreach ($upcomingTasks as $task): ?>
                            <div class="border border-gray-200 rounded-xl p-4">
                                <div class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($task['judul']); ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($task['kode'] . ' - ' . $task['mk']); ?></div>
                                <div class="text-xs font-semibold text-rose-700 mt-2">Deadline: <?php echo date('d M Y H:i', strtotime($task['due_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$upcomingTasks): ?>
                            <div class="text-sm text-gray-500 border border-dashed border-gray-200 rounded-xl p-4">Belum ada tugas mendatang.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <h2 class="text-lg font-bold text-gray-800 mb-1"><i class="fa-solid fa-user-gear text-indigo-500 mr-2"></i>Utuskan Role Pengguna</h2>
                <p class="text-xs text-gray-500 mb-4">Semua akun baru otomatis Mahasiswa. Hanya Admin Akademik yang dapat mengutuskan akun menjadi Dosen, Admin Akademik, atau Pimpinan Fakultas.</p>
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 font-semibold text-xs uppercase tracking-wider">
                                <th class="p-4">Username</th>
                                <th class="p-4">Nama</th>
                                <th class="p-4">Role Saat Ini</th>
                                <th class="p-4">Ubah Role</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700 divide-y divide-gray-100">
                            <?php foreach ($allUsersForAdmin as $user): ?>
                            <tr>
                                <td class="p-4 font-semibold"><?php echo htmlspecialchars($user['username']); ?></td>
                                <td class="p-4"><?php echo htmlspecialchars($user['full_name'] ?: '-'); ?></td>
                                <td class="p-4"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?></td>
                                <td class="p-4">
                                    <form method="POST" class="flex gap-2">
                                        <input type="hidden" name="action" value="update_user_role">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                        <select name="role" class="px-3 py-2 border rounded-lg text-sm" <?php echo (int)$user['id'] === $currentUserId ? 'disabled' : ''; ?>>
                                            <?php foreach (['mahasiswa' => 'Mahasiswa', 'dosen' => 'Dosen', 'admin_akademik' => 'Admin Akademik', 'pimpinan_fakultas' => 'Pimpinan Fakultas'] as $roleValue => $roleLabel): ?>
                                                <option value="<?php echo $roleValue; ?>" <?php echo $user['role'] === $roleValue ? 'selected' : ''; ?>><?php echo $roleLabel; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white text-sm px-4 py-2 rounded-lg disabled:opacity-50" <?php echo (int)$user['id'] === $currentUserId ? 'disabled' : ''; ?>>
                                            Simpan
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Audit Log -->
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <h2 class="text-sm font-bold text-gray-800 mb-3"><i class="fa-solid fa-clock-rotate-left text-gray-400 mr-2"></i>Audit Log Sistem</h2>
                <div class="bg-gray-900 text-emerald-400 font-mono text-xs p-4 rounded-xl space-y-1 h-40 overflow-y-auto">
                    <div>[<?php echo $currentTime; ?>] SYSTEM: Sistem Manajemen Nilai Telah Diinisialisasi.</div>
                    <?php foreach (array_reverse($_SESSION['audit_log'] ?? []) as $log): ?>
                        <div><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ==================== PANEL PIMPINAN FAKULTAS ==================== -->
        <div id="panel-pimpinan-fakultas" class="dashboard-container <?php echo $userRole === 'pimpinan_fakultas' ? 'active' : ''; ?> space-y-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800"><i class="fa-solid fa-chart-pie text-sky-500 mr-2"></i>Laporan Akademik Fakultas</h2>
                        <p class="text-sm text-gray-500 mt-1">Mode read-only untuk monitoring statistik akademik dan unduh laporan.</p>
                    </div>
                    <a href="grades.php?download=laporan_akademik" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition inline-flex items-center justify-center gap-2">
                        <i class="fa-solid fa-download"></i> Unduh Laporan
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-sky-50 border border-sky-100 p-5 rounded-xl">
                        <span class="text-xs uppercase tracking-wider font-semibold text-sky-700">Rata-rata Nilai</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo number_format($rataRataNilai, 2); ?></h3>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-100 p-5 rounded-xl">
                        <span class="text-xs uppercase tracking-wider font-semibold text-emerald-700">Rata-rata IPK</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo number_format($rataRataIPK, 2); ?></h3>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 p-5 rounded-xl">
                        <span class="text-xs uppercase tracking-wider font-semibold text-slate-600">Total Data Mahasiswa</span>
                        <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo count($mahasiswaData); ?></h3>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="border border-gray-200 rounded-xl p-5">
                        <h3 class="text-sm font-bold text-gray-800 mb-4"><i class="fa-solid fa-bars-progress text-indigo-500 mr-2"></i>Distribusi IPK</h3>
                        <div class="space-y-4">
                            <?php foreach ($distribusiIPK as $range => $jumlah): ?>
                                <div>
                                    <div class="flex justify-between text-xs text-gray-600 mb-1">
                                        <span class="font-semibold"><?php echo htmlspecialchars($range); ?></span>
                                        <span><?php echo $jumlah; ?> mahasiswa</span>
                                    </div>
                                    <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full bg-sky-500 rounded-full" style="width: <?php echo ($jumlah / $maxDistribusi) * 100; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="border border-gray-200 rounded-xl p-5">
                        <h3 class="text-sm font-bold text-gray-800 mb-4"><i class="fa-solid fa-shield-halved text-rose-500 mr-2"></i>Kontrol Akses</h3>
                        <p class="text-sm text-gray-600 mb-4">Role ini tidak memiliki izin untuk mengubah nilai, bobot, atau periode akademik.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="ubah_data_laporan">
                            <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold transition inline-flex items-center gap-2">
                                <i class="fa-solid fa-ban"></i> Coba Ubah Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fa-solid fa-table text-sky-500 mr-2"></i>Ringkasan Nilai Akademik</h3>
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-600 font-semibold text-xs uppercase tracking-wider">
                                <th class="p-4">NIM</th>
                                <th class="p-4">Nama Mahasiswa</th>
                                <th class="p-4">Mata Kuliah</th>
                                <th class="p-4 text-center">Nilai Akhir</th>
                                <th class="p-4 text-center">Grade</th>
                                <th class="p-4 text-center">Status Akses</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700 divide-y divide-gray-100">
                            <?php foreach ($mahasiswaData as $mhs): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="p-4 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($mhs['nim']); ?></td>
                                <td class="p-4 font-semibold text-gray-900"><?php echo htmlspecialchars($mhs['nama']); ?></td>
                                <td class="p-4"><?php echo htmlspecialchars($mhs['mk']); ?></td>
                                <td class="p-4 text-center font-semibold"><?php echo number_format($mhs['akhir'], 1); ?></td>
                                <td class="p-4 text-center font-bold"><?php echo htmlspecialchars($mhs['grade']); ?></td>
                                <td class="p-4 text-center"><span class="text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded-full font-medium">Read Only</span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$mahasiswaData): ?>
                            <tr>
                                <td colspan="6" class="p-6 text-center text-sm text-gray-500">Belum ada data nilai akademik.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- FOOTER -->
    <footer class="bg-white border-t border-gray-200 py-4 px-6 text-center text-xs text-gray-400">
        <div>Proyek: <span class="font-semibold text-gray-600">Sub-SIAKAD Modul Nilai</span> | Versi: <span class="font-semibold text-gray-600">1.0</span> | User: <span class="font-semibold text-gray-600"><?php echo htmlspecialchars($currentUser); ?></span> (<?php echo ucfirst(str_replace('_', ' ', $userRole)); ?>)</div>
    </footer>

</body>
</html>
