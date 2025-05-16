<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/api/config/database.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    // Get the table structure
    $columns = DB::select('SHOW COLUMNS FROM activity_log');
    echo "Activity Log Table Structure:\n";
    print_r($columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 