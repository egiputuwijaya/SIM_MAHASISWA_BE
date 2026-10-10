<?php
/**
 * controllers/AuthController.php
 * Logika login SSO, verifikasi token, & logout.
 *
 * Token dibuat JWT-style: base64url(payload) + "." + HMAC-SHA256(payload, APP_KEY)
 * sehingga stateless, tidak perlu tabel tambahan, dan tetap bisa diverifikasi
 * oleh SSO Client cukup dengan GET /api/v1/verify.
 */

declare(strict_types=1);

class AuthController
{
    private PenggunaModel $pengguna;

    public function __construct()
    {
        $this->pengguna = new PenggunaModel();
    }

    /**
     * POST /api/v1/login
     * Body JSON/form: { "username": "...", "password": "..." }
     */
    public function login(?array $user = null): void
    {
        $body     = req_body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        // Validasi input
        $errors = [];
        if ($username === '') {
            $errors['username'] = 'Username wajib diisi.';
        }
        if ($password === '') {
            $errors['password'] = 'Password wajib diisi.';
        }
        if ($errors !== []) {
            ResponseHelper::error('Data login tidak lengkap.', 422, $errors);
        }

        $user = $this->pengguna->findByUsername($username);

        // Pesan error dibuat umum agar tidak membocorkan keberadaan akun
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            ResponseHelper::error('Username atau password salah.', 401);
        }

        if (($user['status_aktif'] ?? '') !== 'Aktif') {
            ResponseHelper::error('Akun Anda dinonaktifkan. Hubungi administrator.', 403);
        }

        // Perbarui login terakhir & tulis audit trail
        $this->pengguna->updateLastLogin((int) $user['id_pengguna']);
        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'pengguna',
            'record_id'   => (string) $user['id_pengguna'],
            'aksi'        => 'LOGIN',
            'data_baru'   => ['username' => $user['username'], 'waktu' => date('Y-m-d H:i:s')],
        ]);

        $issued = self::issueToken($user);

        ResponseHelper::success([
            'token'      => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'expires_at' => $issued['expires_at'],
            'profil'     => self::publicProfile($user),
        ], 'Login berhasil.');
    }

    /**
     * GET /api/v1/verify
     * Endpoint publik untuk SSO Client: cek token & kembalikan profil terkini.
     * Token dikirim via header Authorization: Bearer <token> atau ?token=...
     */
    public function verify(?array $user = null): void
    {
        $token = AuthMiddleware::extractToken();

        if ($token === null || $token === '') {
            ResponseHelper::error('Token tidak ditemukan.', 401);
        }

        $payload = self::verifyToken($token);

        if ($payload === null) {
            ResponseHelper::unauthorized('Token tidak valid atau kadaluarsa.');
        }

        $user = $this->pengguna->findById((int) ($payload['uid'] ?? 0));

        if ($user === null || ($user['status_aktif'] ?? '') !== 'Aktif') {
            ResponseHelper::unauthorized('Pengguna pada token tidak ditemukan atau tidak aktif.');
        }

        ResponseHelper::success([
            'valid'      => true,
            'expires_at' => date('Y-m-d H:i:s', (int) $payload['exp']),
            'profil'     => self::publicProfile($user),
        ], 'Token valid.');
    }

    /**
     * POST /api/v1/logout (terproteksi)
     * Token bersifat stateless — sisi klien wajib membuang token.
     * Aktivitas tetap dicatat ke audit_log.
     */
    public function logout(?array $user = null): void
    {
        $userId = (int) ($user['id_pengguna'] ?? 0);

        $this->pengguna->logAudit([
            'id_pengguna' => $userId,
            'tabel_nama'  => 'pengguna',
            'record_id'   => (string) $userId,
            'aksi'        => 'LOGOUT',
        ]);

        ResponseHelper::success(['logged_out' => true], 'Logout berhasil. Silakan hapus token di sisi klien.');
    }

    // =====================================================================
    // Token (HMAC-SHA256)
    // =====================================================================

    /**
     * Buat token unik untuk pengguna.
     *
     * @return array{token: string, expires_in: int, expires_at: string}
     */
    public static function issueToken(array $user): array
    {
        $secret = (string) app_config('key');

        if ($secret === '') {
            ResponseHelper::error('APP_KEY belum diatur di file .env.', 500);
        }

        $ttl = (int) app_config('auth.ttl', 3600);
        $now = time();

        $payload = [
            'uid'  => (int) $user['id_pengguna'],
            'un'   => (string) $user['username'],
            'role' => (string) ($user['kode_role'] ?? ''),
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ];

        $encoded   = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encoded, $secret);

        return [
            'token'      => $encoded . '.' . $signature,
            'expires_in' => $ttl,
            'expires_at' => date('Y-m-d H:i:s', $now + $ttl),
        ];
    }

    /**
     * Verifikasi token. Mengembalikan payload bila valid, null bila tidak.
     */
    public static function verifyToken(string $token): ?array
    {
        $secret = (string) app_config('key');
        $parts  = explode('.', $token);

        if ($secret === '' || count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if ($encoded === '' || $signature === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $secret);

        if (!hash_equals($expected, $signature)) {
            return null; // tanda tangan tidak cocok -> token dipalsukan
        }

        $json = self::base64UrlDecode($encoded);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['uid'], $payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < time()) {
            return null; // kadaluarsa
        }

        return $payload;
    }

    /** Data profil aman (tanpa password_hash) untuk response API. */
    public static function publicProfile(array $user): array
    {
        return [
            'id_pengguna'       => (int) ($user['id_pengguna'] ?? 0),
            'username'          => (string) ($user['username'] ?? ''),
            'nama_lengkap'      => (string) ($user['nama_lengkap'] ?? ''),
            'email'             => $user['email'] ?? null,
            'no_hp'             => $user['no_hp'] ?? null,
            'status_aktif'      => (string) ($user['status_aktif'] ?? ''),
            'last_login'        => $user['last_login'] ?? null,
            'role' => [
                'kode' => (string) ($user['kode_role'] ?? ''),
                'nama' => (string) ($user['nama_role'] ?? ''),
            ],
            'scope' => [
                'id_universitas'    => isset($user['id_universitas']) ? (int) $user['id_universitas'] : null,
                'id_fakultas'       => isset($user['id_fakultas']) ? (int) $user['id_fakultas'] : null,
                'id_program_studi'  => isset($user['id_program_studi']) ? (int) $user['id_program_studi'] : null,
            ],
        ];
    }

    // ---------------------------------------------------------------------

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
