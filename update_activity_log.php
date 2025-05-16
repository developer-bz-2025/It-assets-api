<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/api/config/database.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    DB::statement('ALTER TABLE activity_log ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at');
    echo "Successfully added updated_at column\n";
    
    // Verify the change
    $columns = DB::select('SHOW COLUMNS FROM activity_log');
    echo "\nUpdated table structure:\n";
    print_r($columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 