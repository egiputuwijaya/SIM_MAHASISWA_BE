<?php
/**
 * models/MahasiswaModel.php
 * Query SQL terkait tabel `mahasiswa` (termasuk kolom `foto`).
 * Semua query memakai Prepared Statement.
 */

declare(strict_types=1);

class MahasiswaModel
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Daftar mahasiswa (join prodi/fakultas/universitas) + pencarian + pagination.
     *
     * @param array $filters ['q' => kata kunci npm/nama, 'status_mahasiswa' => ..,
     *                        'id_program_studi' => int, 'jenis_kelamin' => L/P]
     * @param int   $page    nomor halaman (mulai dari 1)
     * @param int   $perPage jumlah data per halaman
     * @return array{items: array, pagination: array}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($filters);

        $countSql = 'SELECT COUNT(*) AS total
                     FROM mahasiswa m
                     INNER JOIN program_studi ps ON ps.id_program_studi = m.id_program_studi'
                    . $where;

        $total = (int) ($this->db->fetchColumn($countSql, $params) ?? 0);

        $sql = 'SELECT m.id_mahasiswa, m.id_pengguna, m.id_program_studi, m.npm, m.nama_mahasiswa,
                       m.jenis_kelamin, m.tempat_lahir, m.tanggal_lahir, m.tanggal_masuk,
                       m.alamat, m.foto, m.status_mahasiswa, m.created_at, m.updated_at,
                       ps.kode_program_studi, ps.nama_program_studi, ps.jenjang,
                       f.id_fakultas, f.kode_fakultas, f.nama_fakultas,
                       u.nama_universitas
                FROM mahasiswa m
                INNER JOIN program_studi ps ON ps.id_program_studi = m.id_program_studi
                INNER JOIN fakultas f       ON f.id_fakultas       = ps.id_fakultas
                INNER JOIN universitas u    ON u.id_universitas    = f.id_universitas'
               . $where . '
                ORDER BY m.nama_mahasiswa ASC
                LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        $items = $this->db->fetchAll($sql, $params);

        return [
            'items'       => array_map([$this, 'withFotoUrl'], $items),
            'pagination'  => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'last_page'  => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** Detail satu mahasiswa berdasarkan ID. */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT m.*, ps.kode_program_studi, ps.nama_program_studi, ps.jenjang,
                       f.id_fakultas, f.kode_fakultas, f.nama_fakultas,
                       u.id_universitas, u.kode_universitas, u.nama_universitas
                FROM mahasiswa m
                INNER JOIN program_studi ps ON ps.id_program_studi = m.id_program_studi
                INNER JOIN fakultas f       ON f.id_fakultas       = ps.id_fakultas
                INNER JOIN universitas u    ON u.id_universitas    = f.id_universitas
                WHERE m.id_mahasiswa = :id
                LIMIT 1';

        $row = $this->db->fetchOne($sql, [':id' => $id]);

        return $row !== null ? $this->withFotoUrl($row) : null;
    }

    /** Cari mahasiswa berdasarkan NPM (untuk cek duplikat). */
    public function findByNpm(string $npm, ?int $excludeId = null): ?array
    {
        $sql    = 'SELECT * FROM mahasiswa WHERE npm = :npm';
        $params = [':npm' => $npm];

        if ($excludeId !== null) {
            $sql .= ' AND id_mahasiswa <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params);
    }

    /** Cari mahasiswa yang terhubung ke akun pengguna tertentu (SSO profile). */
    public function findByPengguna(int $idPengguna): ?array
    {
        $sql = 'SELECT * FROM mahasiswa WHERE id_pengguna = :id_pengguna LIMIT 1';

        return $this->db->fetchOne($sql, [':id_pengguna' => $idPengguna]);
    }

    /** Insert mahasiswa baru, kembalikan ID-nya. */
    public function create(array $data): int
    {
        return $this->db->insert('mahasiswa', $this->filterColumns($data));
    }

    /** Update mahasiswa, kembalikan jumlah baris terpengaruh. */
    public function update(int $id, array $data): int
    {
        return $this->db->update(
            'mahasiswa',
            $this->filterColumns($data),
            'id_mahasiswa = :id_mahasiswa',
            [':id_mahasiswa' => $id]
        );
    }

    /** Hapus mahasiswa, kembalikan jumlah baris terpengaruh. */
    public function delete(int $id): int
    {
        return $this->db->delete('mahasiswa', 'id_mahasiswa = :id_mahasiswa', [':id_mahasiswa' => $id]);
    }

    /** Simpan path foto (dipakai saat upload/update foto). */
    public function updateFoto(int $id, ?string $fotoPath): int
    {
        return $this->update($id, ['foto' => $fotoPath]);
    }

    // =====================================================================
    // Profil (view v_profil_mahasiswa: data diri + prodi/fakultas/universitas)
    // =====================================================================

    /**
     * Daftar profil dari view v_profil_mahasiswa + pencarian + pagination.
     *
     * @param array $filters ['q' => npm/nama, 'status_mahasiswa' => ..,
     *                        'id_mahasiswa' => int, 'kode_program_studi' => string]
     * @return array{items: array, pagination: array}
     */
    public function profilPaginate(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $conds  = [];
        $params = [];

        if (!empty($filters['q'])) {
            $keyword            = '%' . trim((string) $filters['q']) . '%';
            $conds[]            = '(v.npm LIKE :q_npm OR v.nama_mahasiswa LIKE :q_nama)';
            $params[':q_npm']   = $keyword;
            $params[':q_nama']  = $keyword;
        }

        if (!empty($filters['status_mahasiswa'])) {
            $conds[]                 = 'v.status_mahasiswa = :status';
            $params[':status']       = (string) $filters['status_mahasiswa'];
        }

        if (array_key_exists('id_mahasiswa', $filters)) {
            $conds[]                    = 'v.id_mahasiswa = :id_mahasiswa';
            $params[':id_mahasiswa']    = (int) $filters['id_mahasiswa'];
        }

        if (!empty($filters['kode_program_studi'])) {
            $conds[]                    = 'v.kode_program_studi = :kode_prodi';
            $params[':kode_prodi']      = (string) $filters['kode_program_studi'];
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        $total = (int) ($this->db->fetchColumn('SELECT COUNT(*) FROM v_profil_mahasiswa v' . $where, $params) ?? 0);

        $sql = 'SELECT v.* FROM v_profil_mahasiswa v'
             . $where . '
                ORDER BY v.nama_mahasiswa ASC
                LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        $items = $this->db->fetchAll($sql, $params);

        return [
            'items'      => array_map([$this, 'withFotoUrl'], $items),
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** Detail profil satu mahasiswa dari view. */
    public function profilById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT v.* FROM v_profil_mahasiswa v WHERE v.id_mahasiswa = :id LIMIT 1',
            [':id' => $id]
        );

        return $row !== null ? $this->withFotoUrl($row) : null;
    }

    /** Apakah program studi tersedia? (validasi FK) */
    public function prodiExists(int $idProgramStudi): bool
    {
        $sql = 'SELECT 1 FROM program_studi WHERE id_program_studi = :id LIMIT 1';

        return $this->db->fetchColumn($sql, [':id' => $idProgramStudi]) !== null;
    }

    /** Kode program studi (untuk filter view profil berbasis kode). */
    public function prodiKode(int $idProgramStudi): ?string
    {
        $kode = $this->db->fetchColumn(
            'SELECT kode_program_studi FROM program_studi WHERE id_program_studi = :id LIMIT 1',
            [':id' => $idProgramStudi]
        );

        return $kode !== null ? (string) $kode : null;
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /** @return array{0: string, 1: array} */
    private function buildWhere(array $filters): array
    {
        $conds  = [];
        $params = [];

        if (!empty($filters['q'])) {
            $keyword          = '%' . trim((string) $filters['q']) . '%';
            $conds[]          = '(m.npm LIKE :q_npm OR m.nama_mahasiswa LIKE :q_nama)';
            $params[':q_npm']  = $keyword;
            $params[':q_nama'] = $keyword;
        }

        if (!empty($filters['status_mahasiswa'])) {
            $conds[]                     = 'm.status_mahasiswa = :status';
            $params[':status']           = (string) $filters['status_mahasiswa'];
        }

        if (!empty($filters['jenis_kelamin'])) {
            $conds[]                     = 'm.jenis_kelamin = :jk';
            $params[':jk']               = (string) $filters['jenis_kelamin'];
        }

        // array_key_exists: id 0 = "tidak ada prodi" tetap harus memfilter (hasil kosong),
        // bukan dianggap tidak ada filter (celah scope keamanan role OPERATOR_PRODI).
        if (array_key_exists('id_program_studi', $filters)) {
            $conds[]                 = 'm.id_program_studi = :id_prodi';
            $params[':id_prodi']     = (int) $filters['id_program_studi'];
        }

        if (array_key_exists('id_mahasiswa', $filters)) {
            $conds[]                 = 'm.id_mahasiswa = :id_mahasiswa';
            $params[':id_mahasiswa'] = (int) $filters['id_mahasiswa'];
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        return [$where, $params];
    }

    /** Hanya kolom yang dikenal tabel mahasiswa yang boleh ditulis. */
    private function filterColumns(array $data): array
    {
        $allowed = [
            'id_pengguna', 'id_program_studi', 'npm', 'nama_mahasiswa', 'jenis_kelamin',
            'tempat_lahir', 'tanggal_lahir', 'tanggal_masuk', 'alamat', 'foto',
            'status_mahasiswa',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /** Sertakan URL lengkap foto bila path tersimpan. */
    private function withFotoUrl(array $row): array
    {
        $row['foto_url'] = null;

        if (!empty($row['foto'])) {
            $row['foto_url'] = rtrim((string) app_config('base_url'), '/') . '/' . ltrim((string) $row['foto'], '/');
        }

        return $row;
    }
}
