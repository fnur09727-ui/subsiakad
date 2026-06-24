<?php
// Database Configuration
// Support both SQLite (file-based) and MySQL (server-based)

$dbType = 'mysql'; // Change to 'sqlite' to use SQLite instead

if ($dbType === 'mysql') {
    // MySQL Configuration
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = ''; // Leave empty if no password
    $dbName = 'subsia_kad';
    
    try {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (PDOException $e) {
        $pdo = null;
        $dbError = "MySQL Connection Error: " . $e->getMessage();
    }
} else {
    // SQLite Configuration
    $dbFile = __DIR__ . '/data.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        $pdo = null;
        $dbError = "SQLite Connection Error: " . $e->getMessage();
    }
}
?>
