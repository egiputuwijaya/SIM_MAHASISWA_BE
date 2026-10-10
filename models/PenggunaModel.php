<?php
/**
 * models/PenggunaModel.php
 * Query SQL terkait tabel `pengguna` & `roles` (+ audit_log).
 */

declare(strict_types=1);

class PenggunaModel
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** Cari pengguna berdasarkan username, lengkap dengan role-nya. */
    public function findByUsername(string $username): ?array
    {
        $sql = 'SELECT p.id_pengguna, p.id_role, p.id_universitas, p.id_fakultas, p.id_program_studi,
                       p.username, p.password_hash, p.nama_lengkap, p.email, p.no_hp,
                       p.status_aktif, p.last_login,
                       r.kode_role, r.nama_role
                FROM pengguna p
                INNER JOIN roles r ON r.id_role = p.id_role
                WHERE p.username = :username
                LIMIT 1';

        return $this->db->fetchOne($sql, [':username' => $username]);
    }

    /** Cari pengguna berdasarkan ID, lengkap dengan role-nya. */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT p.id_pengguna, p.id_role, p.id_universitas, p.id_fakultas, p.id_program_studi,
                       p.username, p.password_hash, p.nama_lengkap, p.email, p.no_hp,
                       p.status_aktif, p.last_login,
                       r.kode_role, r.nama_role
                FROM pengguna p
                INNER JOIN roles r ON r.id_role = p.id_role
                WHERE p.id_pengguna = :id
                LIMIT 1';

        return $this->db->fetchOne($sql, [':id' => $id]);
    }

    /** Perbarui timestamp login terakhir. */
    public function updateLastLogin(int $id): void
    {
        $this->db->update(
            'pengguna',
            ['last_login' => date('Y-m-d H:i:s')],
            'id_pengguna = :id_pengguna',
            [':id_pengguna' => $id]
        );
    }

    /**
     * Catat aktivitas ke tabel `audit_log`.
     *
     * @param array $entry [id_pengguna, tabel_nama, record_id, aksi, data_lama, data_baru]
     */
    public function logAudit(array $entry): void
    {
        $encode = static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                // Bila sudah berupa JSON string valid, pakai apa adanya
                if (is_array($decoded)) {
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }

                $value = ['value' => $value];
            }

            $json = json_encode($value, JSON_UNESCAPED_UNICODE);

            // Kolom audit_log ber-CONSTRAINT json_valid — fallback bila encoding gagal
            return $json === false ? '{"error":"gagal-encode"}' : $json;
        };

        $this->db->insert('audit_log', [
            'id_pengguna' => $entry['id_pengguna'] ?? null,
            'tabel_nama'  => $entry['tabel_nama'] ?? 'pengguna',
            'record_id'   => isset($entry['record_id']) ? (string) $entry['record_id'] : null,
            'aksi'        => $entry['aksi'] ?? 'VIEW',
            'data_lama'   => $encode($entry['data_lama'] ?? null),
            'data_baru'   => $encode($entry['data_baru'] ?? null),
            'ip_address'  => $this->clientIp(),
            'user_agent'  => isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
                : null,
            'waktu'       => date('Y-m-d H:i:s'),
        ]);
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return $ip !== null ? mb_substr((string) $ip, 0, 45) : null;
    }
}
