<?php
// Import script untuk setup database MySQL

require_once 'config.php';

if (!$pdo) {
    echo "❌ Error: Cannot connect to database\n";
    echo "Error: " . ($dbError ?? "Unknown error") . "\n";
    exit(1);
}

// Read SQL file
$sqlFile = __DIR__ . '/schema_mysql_seed.sql';
if (!file_exists($sqlFile)) {
    echo "❌ Error: schema_mysql_seed.sql not found\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);

// Simple approach: execute the SQL file with multi_query approach
// Split by semicolon but keep complex statements together
$queries = preg_split('/;(?=\s*$|\s*--|\s*\/\*)/m', $sql);

try {
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        // Skip empty queries and comments
        if (empty($query) || strpos($query, '--') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($query);
            $successCount++;
        } catch (Exception $e) {
            // Some tables might already exist or other minor issues
            $errorCount++;
            // Only show critical errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "⚠️  Query warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✅ Database setup completed!\n";
    echo "✅ Database: subsia_kad\n";
    echo "📊 Queries executed: $successCount (Skipped/Warnings: $errorCount)\n\n";
    
    // Verify database exists and has tables
    try {
        $result = $pdo->query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema='subsia_kad'");
        $tableCount = $result->fetch(PDO::FETCH_ASSOC)['count'];
        echo "📊 Total tables created: $tableCount\n";
        
        // Show seed users
        $result = $pdo->query("SELECT username, (SELECT name FROM roles WHERE id=role_id) as role FROM users ORDER BY id");
        $users = $result->fetchAll(PDO::FETCH_ASSOC);
        echo "\n👤 Seed Users:\n";
        foreach ($users as $user) {
            echo "   - {$user['username']} ({$user['role']})\n";
        }
    } catch (Exception $e) {
        echo "⚠️  Could not verify tables: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Ready to use! Start with: php -S localhost:8000\n";
    
} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

