<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$u = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub-SIAKAD - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Navbar -->
    <div class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center shadow-md">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-university text-sky-400 text-2xl"></i>
            <h1 class="text-xl font-bold">Sub-SIAKAD</h1>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-right">
                <div class="text-xs text-gray-400">Logged in as</div>
                <div class="font-bold"><?php echo htmlspecialchars($u['username']); ?></div>
            </div>
            <a href="logout.php" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                <i class="fa-solid fa-sign-out-alt mr-1"></i> Logout
            </a>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-2xl mx-auto mt-12 p-6 bg-white rounded-2xl shadow-lg border border-gray-200">
        <h1 class="text-3xl font-bold text-gray-800 mb-3">✅ Login Berhasil</h1>
        <p class="text-gray-600 mb-2">Selamat datang, <strong><?php echo htmlspecialchars($u['username']); ?></strong>!</p>
        <p class="text-gray-600 mb-8">Role Anda: <strong class="text-sky-600"><?php echo ucfirst(str_replace('_', ' ', $u['role'])); ?></strong></p>
        
        <div class="space-y-3">
            <a href="grades.php" class="bg-sky-600 hover:bg-sky-700 text-white px-6 py-3 rounded-lg inline-flex items-center gap-2 transition w-full text-center justify-center">
                <i class="fa-solid fa-chart-line"></i> <?php echo $u['role'] === 'pimpinan_fakultas' ? 'Buka Laporan Akademik' : 'Buka Modul Nilai & Transkrip'; ?>
            </a>
            <a href="register.php" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-3 rounded-lg inline-flex items-center gap-2 transition w-full text-center justify-center">
                <i class="fa-solid fa-user-plus"></i> Daftar User Baru
            </a>
        </div>
    </div>
</body>
</html>
