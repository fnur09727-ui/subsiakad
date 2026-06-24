<?php
require_once 'config.php';

echo "=== Testing Database Connection ===\n";
if ($pdo) {
    echo "✅ Database connected successfully\n\n";
    
    // Test user query
    $stmt = $pdo->prepare('SELECT u.username, u.password, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username LIMIT 1');
    $stmt->execute([':username' => 'mahasiswa']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "✅ User mahasiswa found\n";
        echo "  Username: " . $row['username'] . "\n";
        echo "  Role: " . $row['role'] . "\n";
        
        if (password_verify('mhs123', $row['password'])) {
            echo "✅ Password verification successful\n";
        } else {
            echo "❌ Password verification failed\n";
        }
    } else {
        echo "❌ User not found\n";
    }
} else {
    echo "❌ Database connection failed\n";
}

echo "\n=== Testing Dosen User ===\n";
$stmt = $pdo->prepare('SELECT u.username, u.password, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username LIMIT 1');
$stmt->execute([':username' => 'dosen']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && password_verify('dosen123', $row['password'])) {
    echo "✅ Dosen login successful (Role: " . $row['role'] . ")\n";
} else {
    echo "❌ Dosen login failed\n";
}

echo "\n=== Testing Admin User ===\n";
$stmt = $pdo->prepare('SELECT u.username, u.password, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username LIMIT 1');
$stmt->execute([':username' => 'admin']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && password_verify('admin123', $row['password'])) {
    echo "✅ Admin login successful (Role: " . $row['role'] . ")\n";
} else {
    echo "❌ Admin login failed\n";
}
echo "\n=== Testing Pimpinan Fakultas User ===\n";
$stmt = $pdo->prepare('SELECT u.username, u.password, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username LIMIT 1');
$stmt->execute([':username' => 'pimpinan']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && password_verify('pimpinan123', $row['password'])) {
    echo "Pimpinan Fakultas login successful (Role: " . $row['role'] . ")\n";
} else {
    echo "Pimpinan Fakultas login failed\n";
}
?>
