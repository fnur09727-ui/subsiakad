<?php
// Check and seed database

require_once 'config.php';

if (!$pdo) {
    echo "❌ Cannot connect to database\n";
    exit(1);
}

try {
    $pdo->exec("INSERT IGNORE INTO roles (id, name) VALUES (1, 'mahasiswa'), (2, 'dosen'), (3, 'admin_akademik'), (4, 'pimpinan_fakultas')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pimpinan_fakultas_profile (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        nip VARCHAR(50),
        fakultas VARCHAR(150),
        jabatan VARCHAR(150),
        CONSTRAINT fk_pimpinan_fakultas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Check existing users
    $result = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = $result->fetchColumn();
    
    if ($userCount == 0) {
        echo "📋 No users found. Inserting seed users...\n";
        
        // Insert users directly with hardcoded role IDs
        $users = [
            ['mahasiswa', password_hash('mhs123', PASSWORD_DEFAULT), 'Siti Rahmawati', 'siti@example.com', 1],
            ['dosen', password_hash('dosen123', PASSWORD_DEFAULT), 'Dr. Budi Santoso', 'budi@example.com', 2],
            ['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Admin Akademik', 'admin@example.com', 3],
            ['pimpinan', password_hash('pimpinan123', PASSWORD_DEFAULT), 'Pimpinan Fakultas', 'pimpinan@example.com', 4]
        ];
        
        $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role_id) VALUES (?, ?, ?, ?, ?)');
        
        foreach ($users as $user) {
            $stmt->execute($user);
            $userId = $pdo->lastInsertId();
            echo "✅ Created user: {$user[0]} (ID: $userId)\n";
        }
        
        // Insert mahasiswa profile
        $pdo->exec("INSERT INTO mahasiswa_profile (user_id, nim, prodi, angkatan) 
                   SELECT id, '21002', 'Teknik Informatika', 2021 FROM users WHERE username='mahasiswa'");
        
        // Insert dosen profile
        $pdo->exec("INSERT INTO dosen_profile (user_id, nidn, fakultas, jabatan) 
                   SELECT id, '12345678', 'Fakultas Teknik', 'Dosen Madya' FROM users WHERE username='dosen'");
        
        // Insert admin profile
        $pdo->exec("INSERT INTO admin_profile (user_id, staff_id, unit) 
                   SELECT id, 'ADM001', 'Bagian Akademik' FROM users WHERE username='admin'");

        // Insert pimpinan fakultas profile
        $pdo->exec("INSERT INTO pimpinan_fakultas_profile (user_id, nip, fakultas, jabatan)
                   SELECT id, '197001011995031001', 'Fakultas Teknik', 'Dekan' FROM users WHERE username='pimpinan'");
        
        echo "\n✅ Seed profiles created!\n";
    } else {
        echo "✅ Users already exist ($userCount users)\n";
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute(['pimpinan']);
    if ((int)$stmt->fetchColumn() === 0) {
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
        $roleStmt->execute(['pimpinan_fakultas']);
        $roleId = $roleStmt->fetchColumn();

        if ($roleId) {
            $insert = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role_id) VALUES (?, ?, ?, ?, ?)');
            $insert->execute(['pimpinan', password_hash('pimpinan123', PASSWORD_DEFAULT), 'Pimpinan Fakultas', 'pimpinan@example.com', $roleId]);
            echo "✅ Created user: pimpinan\n";
        }
    }

    $profileStmt = $pdo->prepare("SELECT COUNT(*) FROM pimpinan_fakultas_profile p JOIN users u ON p.user_id = u.id WHERE u.username = ?");
    $profileStmt->execute(['pimpinan']);
    if ((int)$profileStmt->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO pimpinan_fakultas_profile (user_id, nip, fakultas, jabatan)
                   SELECT id, '197001011995031001', 'Fakultas Teknik', 'Dekan' FROM users WHERE username='pimpinan'");
        echo "✅ Created pimpinan fakultas profile\n";
    }
    
    // Show all users
    $result = $pdo->query("SELECT u.username, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id");
    $users = $result->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n👤 Users in database:\n";
    foreach ($users as $user) {
        echo "   - {$user['username']} ({$user['role']})\n";
    }
    
    echo "\n✅ Database is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
