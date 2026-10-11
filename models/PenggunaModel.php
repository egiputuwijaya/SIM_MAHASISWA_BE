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

    // =====================================================================
    // Manajemen pengguna (API /pengguna — ADMIN)
    // =====================================================================

    /**
     * Daftar pengguna + pencarian + pagination (tanpa password_hash).
     *
     * @param array $filters ['q' => username/nama/email, 'status_aktif' => .., 'id_role' => int]
     * @return array{items: array, pagination: array}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($filters);

        $countSql = 'SELECT COUNT(*) FROM pengguna p INNER JOIN roles r ON r.id_role = p.id_role' . $where;
        $total    = (int) ($this->db->fetchColumn($countSql, $params) ?? 0);

        $sql = 'SELECT p.id_pengguna, p.id_role, p.id_universitas, p.id_fakultas, p.id_program_studi,
                       p.username, p.nama_lengkap, p.email, p.no_hp,
                       p.status_aktif, p.last_login, p.created_at, p.updated_at,
                       r.kode_role, r.nama_role
                FROM pengguna p
                INNER JOIN roles r ON r.id_role = p.id_role'
             . $where . '
                ORDER BY p.nama_lengkap ASC
                LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        $items = $this->db->fetchAll($sql, $params);

        return [
            'items'      => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** Username sudah dipakai pengguna lain? */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT 1 FROM pengguna WHERE username = :username';
        $params = [':username' => $username];

        if ($excludeId !== null) {
            $sql .= ' AND id_pengguna <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        return $this->db->fetchColumn($sql . ' LIMIT 1', $params) !== null;
    }

    /** Insert pengguna baru (password_hash sudah di-hash pemanggil). */
    public function create(array $data): int
    {
        return $this->db->insert('pengguna', $this->filterColumns($data));
    }

    /** Update pengguna (partial — hanya field yang ada di $data). */
    public function update(int $id, array $data): int
    {
        return $this->db->update(
            'pengguna',
            $this->filterColumns($data),
            'id_pengguna = :id_pengguna',
            [':id_pengguna' => $id]
        );
    }

    /** Hapus pengguna. */
    public function delete(int $id): int
    {
        return $this->db->delete('pengguna', 'id_pengguna = :id_pengguna', [':id_pengguna' => $id]);
    }

    // =====================================================================
    // Audit log (API /audit-log — ADMIN)
    // =====================================================================

    /**
     * Daftar audit_log + join pengguna + pencarian + pagination.
     *
     * @param array $filters ['aksi' => .., 'tabel_nama' => .., 'id_pengguna' => int,
     *                        'dari' => Y-m-d, 'sampai' => Y-m-d]
     * @return array{items: array, pagination: array}
     */
    public function paginateAudit(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $conds  = [];
        $params = [];

        if (!empty($filters['aksi'])) {
            $conds[]            = 'a.aksi = :aksi';
            $params[':aksi']    = (string) $filters['aksi'];
        }
        if (!empty($filters['tabel_nama'])) {
            $conds[]                = 'a.tabel_nama = :tabel_nama';
            $params[':tabel_nama']  = (string) $filters['tabel_nama'];
        }
        if (!empty($filters['id_pengguna'])) {
            $conds[]                 = 'a.id_pengguna = :id_pengguna';
            $params[':id_pengguna']  = (int) $filters['id_pengguna'];
        }
        if (!empty($filters['dari'])) {
            $conds[]          = 'a.waktu >= :dari';
            $params[':dari']  = (string) $filters['dari'] . ' 00:00:00';
        }
        if (!empty($filters['sampai'])) {
            $conds[]            = 'a.waktu <= :sampai';
            $params[':sampai']  = (string) $filters['sampai'] . ' 23:59:59';
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        $countSql = 'SELECT COUNT(*) FROM audit_log a' . $where;
        $total    = (int) ($this->db->fetchColumn($countSql, $params) ?? 0);

        $sql = 'SELECT a.id_audit, a.id_pengguna, a.tabel_nama, a.record_id, a.aksi,
                       a.data_lama, a.data_baru, a.ip_address, a.waktu,
                       p.username, p.nama_lengkap
                FROM audit_log a
                LEFT JOIN pengguna p ON p.id_pengguna = a.id_pengguna'
             . $where . '
                ORDER BY a.waktu DESC, a.id_audit DESC
                LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        $items = array_map(static function (array $row): array {
            // Kolom JSON -> decode agar enak dibaca; bila bukan JSON valid, biarkan string
            foreach (['data_lama', 'data_baru'] as $col) {
                if (!empty($row[$col]) && is_string($row[$col])) {
                    $decoded = json_decode($row[$col], true);
                    $row[$col] = is_array($decoded) ? $decoded : $row[$col];
                }
            }

            return $row;
        }, $this->db->fetchAll($sql, $params));

        return [
            'items'      => $items,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return array{0: string, 1: array} */
    private function buildWhere(array $filters): array
    {
        $conds  = [];
        $params = [];

        if (!empty($filters['q'])) {
            $keyword          = '%' . trim((string) $filters['q']) . '%';
            $conds[]          = '(p.username LIKE :q_un OR p.nama_lengkap LIKE :q_nama OR p.email LIKE :q_email)';
            $params[':q_un']  = $keyword;
            $params[':q_nama'] = $keyword;
            $params[':q_email'] = $keyword;
        }

        if (!empty($filters['status_aktif'])) {
            $conds[]                 = 'p.status_aktif = :status';
            $params[':status']       = (string) $filters['status_aktif'];
        }

        if (!empty($filters['id_role'])) {
            $conds[]               = 'p.id_role = :id_role';
            $params[':id_role']    = (int) $filters['id_role'];
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        return [$where, $params];
    }

    /** Hanya kolom tabel pengguna yang boleh ditulis (whitelist). */
    private function filterColumns(array $data): array
    {
        $allowed = [
            'id_role', 'id_universitas', 'id_fakultas', 'id_program_studi',
            'username', 'password_hash', 'nama_lengkap', 'email', 'no_hp', 'status_aktif',
        ];

        return array_intersect_key($data, array_flip($allowed));
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
