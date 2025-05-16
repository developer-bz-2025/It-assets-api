<?php

use Illuminate\Database\Capsule\Manager as DB;

try {
    DB::statement('ALTER TABLE activity_log ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at');
    echo "Successfully added updated_at column to activity_log table\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 