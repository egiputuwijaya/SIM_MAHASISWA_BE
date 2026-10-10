<?php
/**
 * middleware/CorsMiddleware.php
 * Pengaturan Header CORS agar API aman diakses oleh aplikasi web lain
 * (SSO Client: Vue/React/Next/Flutter Web, dsb).
 *
 * Origin yang diizinkan dikontrol lewat variabel CORS_ALLOWED_ORIGINS di .env
 * (dipisah koma). Gunakan "*" hanya untuk development/publik read-only.
 */

declare(strict_types=1);

class CorsMiddleware
{
    /** Panggil paling awal di index.php — menangani preflight OPTIONS juga. */
    public static function handle(): void
    {
        /** @var array $config */
        $config = app_config('cors');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $allowAll = in_array('*', $config['origins'], true);

        // 1. Origin policy
        if ($origin !== null && $origin !== '') {
            if ($allowAll || in_array($origin, $config['origins'], true)) {
                header('Access-Control-Allow-Origin: ' . ($allowAll ? '*' : $origin));

                if (!$allowAll) {
                    header('Vary: Origin'); // penting agar tidak ter-cached keliru oleh CDN/proxy
                }
            }
        } elseif ($allowAll) {
            header('Access-Control-Allow-Origin: *');
        }

        // 2. Metode & header yang diizinkan
        header('Access-Control-Allow-Methods: ' . implode(', ', $config['methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $config['headers']));
        header('Access-Control-Expose-Headers: X-Total-Count');
        header('Access-Control-Max-Age: ' . (int) $config['max_age']);

        // 3. Preflight: browser mengirim OPTIONS sebelum request multipart/authorized
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
