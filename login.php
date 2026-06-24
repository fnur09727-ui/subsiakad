<?php
session_start();
require_once 'config.php';

$loginError = $dbError ?? '';
$pdo = $pdo ?? null;

if (isset($_SESSION['user'])) {
    header('Location: grades.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (!$pdo) {
        $loginError = 'Database belum tersedia.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT u.id, u.username, u.password, u.full_name, r.name as role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.username = :username LIMIT 1');
            $stmt->execute([':username' => $user]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && password_verify($pass, $row['password'])) {
                $_SESSION['user'] = [
                    'id' => (int) $row['id'],
                    'username' => $row['username'],
                    'full_name' => $row['full_name'],
                    'role' => $row['role'] ?: 'mahasiswa'
                ];
                header('Location: grades.php');
                exit;
            } else {
                $loginError = 'Nama pengguna atau kata sandi salah.';
            }
        } catch (Exception $e) {
            $loginError = 'Kesalahan autentikasi: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sub-SIAKAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 font-sans min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md p-6 bg-white rounded-2xl shadow border border-gray-100">
        <h2 class="text-2xl font-bold mb-4">Masuk ke Sub-SIAKAD</h2>
        
        <?php if ($loginError): ?>
            <div class="mb-3 text-sm text-rose-700 bg-rose-50 p-2 rounded">
                <?php echo htmlspecialchars($loginError); ?>
                <div class="mt-2 text-xs">
                    <strong>Solusi:</strong>
                    <ol class="ml-4 list-decimal">
                        <li>Pastikan MySQL/MariaDB server berjalan</li>
                        <li>Buka phpMyAdmin (biasanya di http://localhost/phpmyadmin)</li>
                        <li>Buat database baru atau import <a href="schema_mysql_seed.sql" class="text-sky-600 hover:underline">schema_mysql_seed.sql</a></li>
                        <li>Edit <code>config.php</code> sesuai MySQL credentials (host, user, password, database)</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-3">
            <div>
                <label class="text-xs text-gray-600">Nama Pengguna</label>
                <input name="username" required class="w-full px-3 py-2 border rounded mt-1">
            </div>
            <div>
                <label class="text-xs text-gray-600">Kata Sandi</label>
                <input name="password" type="password" required class="w-full px-3 py-2 border rounded mt-1">
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" name="login" class="bg-sky-600 text-white px-4 py-2 rounded">Masuk</button>
                <a href="register.php" class="text-xs text-sky-600 hover:underline">Daftar akun baru</a>
            </div>
        </form>
        
        <div class="mt-6 p-3 bg-blue-50 border border-blue-200 rounded text-xs text-blue-700">
            <strong>🧪 Test Akun:</strong>
            <ul class="ml-4 list-disc mt-2 space-y-1">
                <li><code>admin</code> / <code>admin123</code></li>
                <li><code>dosen</code> / <code>dosen123</code></li>
                <li><code>mahasiswa</code> / <code>mhs123</code></li>
                <li><code>pimpinan</code> / <code>pimpinan123</code></li>
            </ul>
        </div>
    </div>

    <script>
        // Currently using MySQL - no init needed
    </script>
</body>
</html>
