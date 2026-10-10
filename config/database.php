<?php
/**
 * config/database.php
 * Konfigurasi koneksi database PDO (charset utf8mb4, Prepared Statements).
 *
 * Nilai diambil dari file .env:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * models/Database.php memakai array ini untuk membangun objek PDO.
 */

if (!function_exists('env')) {
    // Jika file ini di-include secara mandiri, muat loader .env terlebih dahulu.
    require __DIR__ . '/app.php';
}

$host = (string) env('DB_HOST', '127.0.0.1');
$port = (string) env('DB_PORT', '3306');
$name = (string) env('DB_NAME', 'sim_mahasiswa');
$user = (string) env('DB_USER', 'root');
$pass = (string) env('DB_PASS', '');
$charset = 'utf8mb4'; // Wajib utf8mb4 agar aman untuk emoji/karakter Indonesia

return [
    'dsn'      => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset),
    'username' => $user,
    'password' => $pass,
    'options' => [
        PDO::ATTR_ERRMODE                 => PDO::ERRMODE_EXCEPTION,   // lempar exception, bukan silent fail
        PDO::ATTR_DEFAULT_FETCH_MODE      => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES        => false,                    // Prepared Statement asli (anti SQL Injection)
        PDO::ATTR_STRINGIFY_FETCHES       => false,
    ],
    // Dipakai untuk pesan error/log (tanpa password)
    'info' => [
        'host'     => $host,
        'port'     => $port,
        'database' => $name,
        'username' => $user,
        'charset'  => $charset,
    ],
];
