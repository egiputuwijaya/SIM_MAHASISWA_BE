<?php
/**
 * models/MasterModel.php
 * Query SQL untuk data master: `universitas`, `fakultas`, `program_studi`, `roles`.
 * Semua query memakai Prepared Statement (aman dari SQL Injection).
 */

declare(strict_types=1);

class MasterModel
{
    private Database $db;

    /** Kolom yang boleh ditulis per tabel (whitelist). */
    private const COLUMNS = [
        'universitas'  => ['kode_universitas', 'nama_universitas', 'slogan', 'alamat', 'kota',
                           'provinsi', 'kode_pos', 'website', 'email', 'telepon'],
        'fakultas'     => ['id_universitas', 'kode_fakultas', 'nama_fakultas'],
        'program_studi' => ['id_fakultas', 'kode_program_studi', 'nama_program_studi',
                            'jenjang', 'status_aktif'],
    ];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** Daftar kolom yang boleh ditulis untuk tabel tertentu (dipakai controller). */
    public static function columnsFor(string $table): array
    {
        return self::COLUMNS[$table] ?? [];
    }

    // =====================================================================
    // universitas
    // =====================================================================

    /** Daftar universitas (opsional pencarian `q`). */
    public function allUniversitas(?string $q = null): array
    {
        $conds  = [];
        $params = [];

        if ($q !== null && $q !== '') {
            $keyword          = '%' . $q . '%';
            $conds[]          = '(u.kode_universitas LIKE :q_kode OR u.nama_universitas LIKE :q_nama OR u.kota LIKE :q_kota)';
            $params[':q_kode'] = $keyword;
            $params[':q_nama'] = $keyword;
            $params[':q_kota'] = $keyword;
        }

        $sql = 'SELECT u.*, (' . $this->countFakultasSql() . ') AS jumlah_fakultas
                FROM universitas u'
             . ($conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '')
             . ' ORDER BY u.nama_universitas ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /** Detail satu universitas. */
    public function findUniversitas(int $id): ?array
    {
        $sql = 'SELECT u.*, (' . $this->countFakultasSql() . ') AS jumlah_fakultas
                FROM universitas u
                WHERE u.id_universitas = :id
                LIMIT 1';

        return $this->db->fetchOne($sql, [':id' => $id]);
    }

    /** Cari berdasarkan kode (cek duplikat). */
    public function universitasByKode(string $kode, ?int $excludeId = null): ?array
    {
        $sql    = 'SELECT * FROM universitas WHERE kode_universitas = :kode';
        $params = [':kode' => $kode];

        if ($excludeId !== null) {
            $sql .= ' AND id_universitas <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    public function createUniversitas(array $data): int
    {
        return $this->db->insert('universitas', $this->filterColumns('universitas', $data));
    }

    public function updateUniversitas(int $id, array $data): int
    {
        return $this->db->update(
            'universitas',
            $this->filterColumns('universitas', $data),
            'id_universitas = :id_universitas',
            [':id_universitas' => $id]
        );
    }

    public function deleteUniversitas(int $id): int
    {
        return $this->db->delete('universitas', 'id_universitas = :id_universitas', [':id_universitas' => $id]);
    }

    // =====================================================================
    // fakultas
    // =====================================================================

    public function findFakultas(int $id): ?array
    {
        $sql = 'SELECT f.*, u.kode_universitas, u.nama_universitas,
                       (' . $this->countProdiSql() . ') AS jumlah_program_studi
                FROM fakultas f
                INNER JOIN universitas u ON u.id_universitas = f.id_universitas
                WHERE f.id_fakultas = :id
                LIMIT 1';

        return $this->db->fetchOne($sql, [':id' => $id]);
    }

    /** Cek duplikat kode fakultas dalam satu universitas. */
    public function fakultasByKode(int $idUniversitas, string $kode, ?int $excludeId = null): ?array
    {
        $sql = 'SELECT * FROM fakultas
                WHERE id_universitas = :id_universitas AND kode_fakultas = :kode';
        $params = [':id_universitas' => $idUniversitas, ':kode' => $kode];

        if ($excludeId !== null) {
            $sql .= ' AND id_fakultas <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    public function createFakultas(array $data): int
    {
        return $this->db->insert('fakultas', $this->filterColumns('fakultas', $data));
    }

    public function updateFakultas(int $id, array $data): int
    {
        return $this->db->update(
            'fakultas',
            $this->filterColumns('fakultas', $data),
            'id_fakultas = :id_fakultas',
            [':id_fakultas' => $id]
        );
    }

    public function deleteFakultas(int $id): int
    {
        return $this->db->delete('fakultas', 'id_fakultas = :id_fakultas', [':id_fakultas' => $id]);
    }

    // =====================================================================
    // program_studi
    // =====================================================================

    public function findProdi(int $id): ?array
    {
        $sql = 'SELECT ps.*, f.kode_fakultas, f.nama_fakultas, u.nama_universitas,
                       (' . $this->countMahasiswaSql() . ') AS jumlah_mahasiswa
                FROM program_studi ps
                INNER JOIN fakultas f    ON f.id_fakultas    = ps.id_fakultas
                INNER JOIN universitas u ON u.id_universitas = f.id_universitas
                WHERE ps.id_program_studi = :id
                LIMIT 1';

        return $this->db->fetchOne($sql, [':id' => $id]);
    }

    /** Cek duplikat kode prodi dalam satu fakultas. */
    public function prodiByKode(int $idFakultas, string $kode, ?int $excludeId = null): ?array
    {
        $sql = 'SELECT * FROM program_studi
                WHERE id_fakultas = :id_fakultas AND kode_program_studi = :kode';
        $params = [':id_fakultas' => $idFakultas, ':kode' => $kode];

        if ($excludeId !== null) {
            $sql .= ' AND id_program_studi <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        return $this->db->fetchOne($sql . ' LIMIT 1', $params);
    }

    public function createProdi(array $data): int
    {
        return $this->db->insert('program_studi', $this->filterColumns('program_studi', $data));
    }

    public function updateProdi(int $id, array $data): int
    {
        return $this->db->update(
            'program_studi',
            $this->filterColumns('program_studi', $data),
            'id_program_studi = :id_program_studi',
            [':id_program_studi' => $id]
        );
    }

    public function deleteProdi(int $id): int
    {
        return $this->db->delete('program_studi', 'id_program_studi = :id_program_studi', [':id_program_studi' => $id]);
    }

    // =====================================================================
    // roles (read-only — CRUD role di luar scope)
    // =====================================================================

    /** Daftar role + jumlah pengguna yang memakainya. */
    public function allRoles(): array
    {
        $sql = 'SELECT r.*,
                       (SELECT COUNT(*) FROM pengguna p WHERE p.id_role = r.id_role) AS jumlah_pengguna
                FROM roles r
                ORDER BY r.id_role ASC';

        return $this->db->fetchAll($sql);
    }

    public function roleExists(int $idRole): bool
    {
        return $this->db->fetchColumn('SELECT 1 FROM roles WHERE id_role = :id LIMIT 1', [':id' => $idRole]) !== null;
    }

    // =====================================================================
    // Pengecekan FK untuk DELETE yang aman
    // (constraint tanpa ON DELETE -> RESTRICT; dicek dulu agar pesan jelas)
    // =====================================================================

    public function countFakultasByUniversitas(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM fakultas WHERE id_universitas = :id',
            [':id' => $id]
        ) ?? 0);
    }

    public function countProdiByFakultas(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM program_studi WHERE id_fakultas = :id',
            [':id' => $id]
        ) ?? 0);
    }

    public function countMahasiswaByProdi(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM mahasiswa WHERE id_program_studi = :id',
            [':id' => $id]
        ) ?? 0);
    }

    public function countPenggunaByUniversitas(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM pengguna WHERE id_universitas = :id',
            [':id' => $id]
        ) ?? 0);
    }

    public function countPenggunaByFakultas(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM pengguna WHERE id_fakultas = :id',
            [':id' => $id]
        ) ?? 0);
    }

    public function countPenggunaByProdi(int $id): int
    {
        return (int) ($this->db->fetchColumn(
            'SELECT COUNT(*) FROM pengguna WHERE id_program_studi = :id',
            [':id' => $id]
        ) ?? 0);
    }

    // =====================================================================
    // Internal
    // =====================================================================

    private function countFakultasSql(): string
    {
        return 'SELECT COUNT(*) FROM fakultas f2 WHERE f2.id_universitas = u.id_universitas';
    }

    private function countProdiSql(): string
    {
        return 'SELECT COUNT(*) FROM program_studi ps2 WHERE ps2.id_fakultas = f.id_fakultas';
    }

    private function countMahasiswaSql(): string
    {
        return 'SELECT COUNT(*) FROM mahasiswa m2 WHERE m2.id_program_studi = ps.id_program_studi';
    }

    /** Hanya kolom yang dikenal tabel (whitelist) yang boleh ditulis. */
    private function filterColumns(string $table, array $data): array
    {
        $allowed = self::COLUMNS[$table] ?? [];

        return array_intersect_key($data, array_flip($allowed));
    }
}
