<?php
/**
 * middleware/AuthMiddleware.php
 * Pengecekan Token untuk endpoint terproteksi.
 *
 * Alur:
 *   1. Ambil token dari header "Authorization: Bearer <token>"
 *      (fallback: query string ?token= / ?access_token= untuk SSO Client sederhana).
 *   2. Verifikasi tanda tangan HMAC + masa berlaku (lihat AuthController::verifyToken).
 *   3. Muat ulang data pengguna terbaru dari database.
 *   4. Bila valid -> user dikirim ke controller; bila tidak -> 401 & hentikan request.
 */

declare(strict_types=1);

class AuthMiddleware
{
    private static ?array $currentUser = null;

    /** Ekstrak token mentah dari request. */
    public static function extractToken(): ?string
    {
        // Header standar (Apache kadang me-rewrite ke REDIRECT_HTTP_AUTHORIZATION)
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        // Fallback untuk SSO client sederhana: ?token= / ?access_token=
        $query = $_GET['token'] ?? $_GET['access_token'] ?? null;

        if (is_string($query) && $query !== '') {
            return $query;
        }

        return null;
    }

    /**
     * Wajib dipanggil untuk endpoint terproteksi.
     * Mengembalikan data pengguna (tanpa password_hash) atau 401 + exit.
     */
    public static function requireAuth(): array
    {
        $token = self::extractToken();

        if ($token === null || $token === '') {
            ResponseHelper::unauthorized('Token tidak ditemukan. Sertakan header "Authorization: Bearer <token>".');
        }

        $payload = AuthController::verifyToken($token);

        if ($payload === null) {
            ResponseHelper::unauthorized('Token tidak valid atau kadaluarsa.');
        }

        $user = (new PenggunaModel())->findById((int) ($payload['uid'] ?? 0));

        if ($user === null) {
            ResponseHelper::unauthorized('Pengguna pada token tidak ditemukan.');
        }

        if (($user['status_aktif'] ?? '') !== 'Aktif') {
            ResponseHelper::forbidden('Akun Anda dinonaktifkan. Hubungi administrator.');
        }

        unset($user['password_hash']);

        self::$currentUser = $user;

        return $user;
    }

    /**
     * Batasi akses berdasarkan kode_role, contoh:
     *   AuthMiddleware::requireRole($user, ['ADMIN', 'OPERATOR_PRODI']);
     */
    public static function requireRole(array $user, array $allowedRoles): void
    {
        $role = (string) ($user['kode_role'] ?? '');

        if (!in_array($role, $allowedRoles, true)) {
            ResponseHelper::forbidden('Role "' . $role . '" tidak memiliki akses melakukan aksi ini.');
        }
    }

    /** Pengguna yang sedang login (setelah requireAuth). */
    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }
}
