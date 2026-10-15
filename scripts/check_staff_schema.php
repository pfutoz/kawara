<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Connecting with DB_HOST=" . DB_HOST . "\n";
    $stmt = $pdo->query("SELECT column_name, data_type, column_default, is_nullable FROM information_schema.columns WHERE table_name = 'staff' ORDER BY ordinal_position");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "--- staff table columns ---\n";
    foreach ($columns as $col) {
        printf("%-30s %-15s nullable=%s default=%s\n", 
            $col['column_name'], 
            $col['data_type'], 
            $col['is_nullable'], 
            $col['column_default'] ?? 'NULL'
        );
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
