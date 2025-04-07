<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Dotenv\Dotenv;
chdir(dirname(__DIR__));

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
// $dotenv = Dotenv::createImmutable(dirname(__DIR__));
if (!file_exists(__DIR__ . '/../../.env')) {
    die(" .env file is missing in " . realpath(__DIR__ . '/../'));
}
$dotenv->load();

$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_NAME'],
    'username'  => $_ENV['DB_USER'],
    'password'  => $_ENV['DB_PASS'],
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();
