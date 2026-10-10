<?php
/**
 * config/app.php
 * Konfigurasi umum aplikasi: loader .env, timezone, base URL, dsb.
 *
 * Dipakai sebagai:
 *   $config = require 'config/app.php';   // full array
 *   app_config('base_url');               // akses per-key
 *   env('DB_HOST', '127.0.0.1');          // akses variabel environment
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!function_exists('env')) {
    /**
     * Membaca variabel environment dari file .env (sederhana, tanpa library eksternal).
     * Format yang didukung: KEY=VALUE, komentar #, tanda kutip tunggal/ganda.
     */
    function env(string $key, $default = null)
    {
        static $vars = null;

        if ($vars === null) {
            $vars   = [];
            $file   = BASE_PATH . '/.env';

            if (is_file($file) && is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Lewati komentar
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }

                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }

                    $name  = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));

                    // Buang tanda kutip pembungkus
                    if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                        $quote = $value[0];
                        $len   = strlen($value);
                        if ($len >= 2 && $value[$len - 1] === $quote) {
                            $value = substr($value, 1, $len - 2);
                        }
                    }

                    if ($name !== '') {
                        $vars[$name] = $value;
                    }
                }
            }
        }

        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key, null);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

if (!function_exists('app_config')) {
    /**
     * Konfigurasi umum aplikasi (di-cache setelah pemanggilan pertama).
     */
    function app_config(?string $key = null, $default = null)
    {
        static $config = null;

        if ($config === null) {
            $timezone = env('APP_TIMEZONE', 'Asia/Jakarta');
            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = 'UTC';
            }

            $allowedExt = array_values(array_filter(array_map(function ($ext) {
                return strtolower(trim($ext));
            }, explode(',', (string) env('UPLOAD_ALLOWED_EXT', 'jpg,jpeg,png')))));

            $config = [
                'name'          => env('APP_NAME', 'SIM Mahasiswa API'),
                'env'           => env('APP_ENV', 'production'),
                'debug'         => env_bool('APP_DEBUG', false),
                'timezone'      => $timezone,
                'base_url'      => rtrim(env('APP_BASE_URL', ''), '/'),
                'key'           => (string) env('APP_KEY', ''),
                'auth' => [
                    'ttl'       => (int) env('AUTH_TOKEN_TTL', 3600), // detik
                    'issuer'    => env('APP_NAME', 'SIM Mahasiswa API'),
                ],
                'cors' => [
                    'origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),
                    'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                    'headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-HTTP-Method-Override'],
                    'max_age' => 86400,
                ],
                'upload' => [
                    'base_dir'    => BASE_PATH . '/uploads',
                    'subdir'      => 'mahasiswa',
                    'public_path' => 'uploads',
                    'max_kb'      => (int) env('UPLOAD_MAX_SIZE', 2048), // 2 MB default
                    'allowed_ext' => $allowedExt !== [] ? $allowedExt : ['jpg', 'jpeg', 'png'],
                ],
            ];
        }

        if ($key === null) {
            return $config;
        }

        if (array_key_exists($key, $config)) {
            return $config[$key];
        }

        // Dot notation: app_config('upload.base_dir') -> $config['upload']['base_dir']
        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

// Terapkan timezone global
date_default_timezone_set(app_config('timezone'));

return app_config();
