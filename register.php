<?php
session_start();
require_once 'config.php';

$errors = $pdo ? [] : [($dbError ?? 'Database connection failed')];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullname = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = 'mahasiswa';

    if ($username === '' || $password === '') {
        $errors[] = 'Username dan password wajib diisi.';
    }

    if (empty($errors)) {
        try {
            // check username exists
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username');
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                $errors[] = 'Nama pengguna sudah terdaftar. Pilih username lain.';
            } else {
                // get role_id
                $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
                $stmt->execute([':name' => $role]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$r) {
                    $errors[] = 'Role tidak ditemukan di sistem.';
                } else {
                    $role_id = $r['id'];
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare('INSERT INTO users (username,password,full_name,email,role_id) VALUES (:username,:password,:full_name,:email,:role_id)');
                    $ins->execute([':username'=>$username,':password'=>$hash,':full_name'=>$fullname,':email'=>$email,':role_id'=>$role_id]);
                    $user_id = $pdo->lastInsertId();

                    $nim = trim($_POST['nim'] ?? '');
                    $prodi = trim($_POST['prodi'] ?? '');
                    $angkatan = intval($_POST['angkatan'] ?? 0) ?: null;
                    $p = $pdo->prepare('INSERT INTO mahasiswa_profile (user_id,nim,prodi,angkatan) VALUES (:user_id,:nim,:prodi,:angkatan)');
                    $p->execute([':user_id'=>$user_id,':nim'=>$nim,':prodi'=>$prodi,':angkatan'=>$angkatan]);

                    // auto-login
                    $_SESSION['user'] = ['id'=>(int)$user_id,'username'=>$username,'full_name'=>$fullname,'role'=>$role];
                    header('Location: index.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            $errors[] = 'Gagal mendaftar: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - Sub-SIAKAD</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans min-h-screen flex items-center justify-center">
    <div class="w-full max-w-lg p-6 bg-white rounded-2xl shadow border border-gray-100">
        <h2 class="text-2xl font-bold mb-4">Daftar Akun Baru</h2>
        <?php if ($errors): ?>
            <div class="mb-3 text-sm text-rose-700 bg-rose-50 p-2 rounded">
                <?php foreach ($errors as $err) echo '<div>'.htmlspecialchars($err).'</div>'; ?>
                <?php if (!$pdo): ?>
                    <div class="mt-2">Solusi:
                        <ul class="ml-4 list-disc text-xs">
                            <li>Pastikan MySQL/MariaDB server berjalan.</li>
                            <li>Import <a href="schema_mysql_seed.sql" class="text-sky-600 hover:underline">schema_mysql_seed.sql</a> ke MySQL/phpMyAdmin.</li>
                            <li>Atau jalankan <code>php seed_db.php</code> dari folder project.</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-3">
            <div>
                <label class="text-xs text-gray-600">Username</label>
                <input name="username" required class="w-full px-3 py-2 border rounded mt-1">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-gray-600">Password</label>
                    <input name="password" type="password" required class="w-full px-3 py-2 border rounded mt-1">
                </div>
                <div>
                    <label class="text-xs text-gray-600">Nama Lengkap</label>
                    <input name="full_name" class="w-full px-3 py-2 border rounded mt-1">
                </div>
            </div>
            <div>
                <label class="text-xs text-gray-600">Email</label>
                <input name="email" type="email" class="w-full px-3 py-2 border rounded mt-1">
            </div>

            <div class="rounded bg-sky-50 border border-sky-100 text-sky-700 text-xs p-3">
                Akun baru otomatis dibuat sebagai Mahasiswa. Admin Akademik dapat mengubah role menjadi Dosen, Admin Akademik, atau Pimpinan Fakultas setelah akun dibuat.
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-xs text-gray-600">NIM</label>
                    <input name="nim" class="w-full px-3 py-2 border rounded mt-1">
                </div>
                <div>
                    <label class="text-xs text-gray-600">Prodi</label>
                    <input name="prodi" class="w-full px-3 py-2 border rounded mt-1">
                </div>
                <div>
                    <label class="text-xs text-gray-600">Angkatan</label>
                    <input name="angkatan" type="number" class="w-full px-3 py-2 border rounded mt-1">
                </div>
            </div>

            <div class="flex items-center justify-between">
                <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded">Daftar dan Masuk</button>
                <a href="login.php" class="text-xs text-sky-600 hover:underline">Kembali ke login</a>
            </div>
        </form>
    </div>

    <script>
        // init DB button handler
        const initBtn = document.getElementById('init-db-btn');
        if (initBtn) {
            initBtn.addEventListener('click', async () => {
                const status = document.getElementById('init-db-status');
                initBtn.disabled = true;
                status.innerText = 'Membuat database...';
                try {
                    const res = await fetch('init_db.php');
                    const text = await res.text();
                    if (res.ok) {
                        status.innerText = 'Selesai. Reloading...';
                        setTimeout(() => location.reload(), 800);
                    } else {
                        status.innerText = 'Gagal: lihat console.';
                        console.error(text);
                        initBtn.disabled = false;
                    }
                } catch (e) {
                    status.innerText = 'Gagal: ' + e.message;
                    initBtn.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
