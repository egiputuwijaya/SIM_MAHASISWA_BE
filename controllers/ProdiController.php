<?php
/**
 * controllers/ProdiController.php
 * Logika data program studi & fakultas (read + CRUD).
 *
 * Endpoint:
 *   GET    /api/v1/fakultas              -> daftar fakultas (opsional ?id_universitas=&q=)
 *   GET    /api/v1/fakultas/{id}         -> detail satu fakultas
 *   POST   /api/v1/fakultas              -> tambah fakultas (ADMIN)
 *   PUT    /api/v1/fakultas/{id}         -> ubah fakultas (ADMIN)
 *   DELETE /api/v1/fakultas/{id}         -> hapus fakultas (ADMIN)
 *   GET    /api/v1/program-studi         -> daftar prodi + fakultas + universitas (opsional ?id_fakultas=&q=&status_aktif=)
 *   GET    /api/v1/program-studi/{id}    -> detail satu program studi
 *   POST   /api/v1/program-studi         -> tambah prodi (ADMIN)
 *   PUT    /api/v1/program-studi/{id}    -> ubah prodi (ADMIN)
 *   DELETE /api/v1/program-studi/{id}    -> hapus prodi (ADMIN)
 */

declare(strict_types=1);

class ProdiController
{
    private Database $db;
    private MasterModel $master;
    private PenggunaModel $pengguna;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->master  = new MasterModel();
        $this->pengguna = new PenggunaModel();
    }

    /** GET /api/v1/fakultas */
    public function fakultas(?array $user = null): void
    {
        $query  = req_query();
        $conds  = [];
        $params = [];

        if (!empty($query['id_universitas'])) {
            $conds[]             = 'f.id_universitas = :id_universitas';
            $params[':id_universitas'] = (int) $query['id_universitas'];
        }

        if (!empty($query['q'])) {
            $keyword = '%' . trim((string) $query['q']) . '%';
            $conds[]               = '(f.nama_fakultas LIKE :q_nama OR f.kode_fakultas LIKE :q_kode)';
            $params[':q_nama']     = $keyword;
            $params[':q_kode']     = $keyword;
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        $sql = 'SELECT f.id_fakultas, f.id_universitas, f.kode_fakultas, f.nama_fakultas,
                       f.created_at, f.updated_at,
                       u.kode_universitas, u.nama_universitas
                FROM fakultas f
                INNER JOIN universitas u ON u.id_universitas = f.id_universitas'
               . $where . '
                ORDER BY f.nama_fakultas ASC';

        $rows = $this->db->fetchAll($sql, $params);

        ResponseHelper::success([
            'items' => $rows,
            'total' => count($rows),
        ], 'Data fakultas berhasil diambil.');
    }

    /** GET /api/v1/program-studi */
    public function index(?array $user = null): void
    {
        $query  = req_query();
        $conds  = [];
        $params = [];

        if (!empty($query['id_fakultas'])) {
            $conds[]                   = 'ps.id_fakultas = :id_fakultas';
            $params[':id_fakultas']    = (int) $query['id_fakultas'];
        }

        if (!empty($query['status_aktif'])) {
            $conds[]                   = 'ps.status_aktif = :status_aktif';
            $params[':status_aktif']   = (string) $query['status_aktif'];
        }

        if (!empty($query['q'])) {
            $keyword = '%' . trim((string) $query['q']) . '%';
            $conds[]               = '(ps.nama_program_studi LIKE :q_nama OR ps.kode_program_studi LIKE :q_kode)';
            $params[':q_nama']     = $keyword;
            $params[':q_kode']     = $keyword;
        }

        $where = $conds !== [] ? ' WHERE ' . implode(' AND ', $conds) : '';

        $sql = 'SELECT ps.id_program_studi, ps.id_fakultas, ps.kode_program_studi,
                       ps.nama_program_studi, ps.jenjang, ps.status_aktif,
                       ps.created_at, ps.updated_at,
                       f.kode_fakultas, f.nama_fakultas,
                       u.nama_universitas
                FROM program_studi ps
                INNER JOIN fakultas f    ON f.id_fakultas    = ps.id_fakultas
                INNER JOIN universitas u ON u.id_universitas = f.id_universitas'
               . $where . '
                ORDER BY f.nama_fakultas ASC, ps.nama_program_studi ASC';

        $rows = $this->db->fetchAll($sql, $params);

        ResponseHelper::success([
            'items' => $rows,
            'total' => count($rows),
        ], 'Data program studi berhasil diambil.');
    }

    /** GET /api/v1/program-studi/{id} */
    public function show(?array $user, int $id): void
    {
        $sql = 'SELECT ps.id_program_studi, ps.id_fakultas, ps.kode_program_studi,
                       ps.nama_program_studi, ps.jenjang, ps.status_aktif,
                       ps.created_at, ps.updated_at,
                       f.kode_fakultas, f.nama_fakultas,
                       u.kode_universitas, u.nama_universitas
                FROM program_studi ps
                INNER JOIN fakultas f    ON f.id_fakultas    = ps.id_fakultas
                INNER JOIN universitas u ON u.id_universitas = f.id_universitas
                WHERE ps.id_program_studi = :id
                LIMIT 1';

        $row = $this->db->fetchOne($sql, [':id' => $id]);

        if ($row === null) {
            ResponseHelper::notFound('Program studi tidak ditemukan.');
        }

        ResponseHelper::success($row, 'Data program studi berhasil diambil.');
    }

    // =====================================================================
    // Fakultas CRUD (ADMIN)
    // =====================================================================

    /** GET /api/v1/fakultas/{id} */
    public function showFakultas(?array $user, int $id): void
    {
        $row = $this->master->findFakultas($id);

        if ($row === null) {
            ResponseHelper::notFound('Fakultas tidak ditemukan.');
        }

        ResponseHelper::success($row, 'Data fakultas berhasil diambil.');
    }

    /** POST /api/v1/fakultas (ADMIN) */
    public function storeFakultas(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $body   = req_body();
        $errors = $this->validateFakultas($body);

        $idUniversitas = (int) ($body['id_universitas'] ?? 0);
        $kode          = trim((string) ($body['kode_fakultas'] ?? ''));

        if (!isset($errors['id_universitas']) && $idUniversitas > 0
            && $this->master->findUniversitas($idUniversitas) === null) {
            $errors['id_universitas'] = 'Universitas tidak ditemukan.';
        }

        if (!isset($errors['kode_fakultas']) && $kode !== ''
            && $this->master->fakultasByKode($idUniversitas, $kode) !== null) {
            $errors['kode_fakultas'] = 'Kode fakultas sudah terdaftar pada universitas tersebut.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $id      = $this->master->createFakultas($this->mapFakultas($body));
        $created = $this->master->findFakultas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'fakultas',
            'record_id'   => (string) $id,
            'aksi'        => 'INSERT',
            'data_baru'   => $created,
        ]);

        ResponseHelper::success($created, 'Fakultas berhasil ditambahkan.', 201);
    }

    /** PUT /api/v1/fakultas/{id} (ADMIN, partial) */
    public function updateFakultas(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findFakultas($id);

        if ($existing === null) {
            ResponseHelper::notFound('Fakultas tidak ditemukan.');
        }

        $body   = req_body();
        $errors = $this->validateFakultas(array_merge($existing, $body));

        $idUniversitas = (int) ($body['id_universitas'] ?? $existing['id_universitas']);
        $kode          = trim((string) ($body['kode_fakultas'] ?? $existing['kode_fakultas']));

        if (!isset($errors['id_universitas']) && $idUniversitas > 0
            && $this->master->findUniversitas($idUniversitas) === null) {
            $errors['id_universitas'] = 'Universitas tidak ditemukan.';
        }

        if (!isset($errors['kode_fakultas']) && $kode !== ''
            && $this->master->fakultasByKode($idUniversitas, $kode, $id) !== null) {
            $errors['kode_fakultas'] = 'Kode fakultas sudah dipakai fakultas lain pada universitas tersebut.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data = $this->mapFakultas($body, true);

        if ($data === []) {
            ResponseHelper::error('Tidak ada perubahan yang dikirim.', 400);
        }

        $this->master->updateFakultas($id, $data);
        $updated = $this->master->findFakultas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'fakultas',
            'record_id'   => (string) $id,
            'aksi'        => 'UPDATE',
            'data_lama'   => $existing,
            'data_baru'   => $updated,
        ]);

        ResponseHelper::success($updated, 'Data fakultas berhasil diperbarui.');
    }

    /** DELETE /api/v1/fakultas/{id} (ADMIN) */
    public function destroyFakultas(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findFakultas($id);

        if ($existing === null) {
            ResponseHelper::notFound('Fakultas tidak ditemukan.');
        }

        $jmlProdi    = $this->master->countProdiByFakultas($id);
        $jmlPengguna = $this->master->countPenggunaByFakultas($id);

        if ($jmlProdi > 0 || $jmlPengguna > 0) {
            ResponseHelper::error(
                'Fakultas tidak dapat dihapus karena masih digunakan oleh '
                . $jmlProdi . ' program studi dan ' . $jmlPengguna . ' pengguna.',
                422
            );
        }

        $this->master->deleteFakultas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'fakultas',
            'record_id'   => (string) $id,
            'aksi'        => 'DELETE',
            'data_lama'   => $existing,
        ]);

        ResponseHelper::success(['id_fakultas' => $id], 'Fakultas berhasil dihapus.');
    }

    // =====================================================================
    // Program studi CRUD (ADMIN)
    // =====================================================================

    /** POST /api/v1/program-studi (ADMIN) */
    public function storeProdi(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $body   = req_body();
        $errors = $this->validateProdi($body);

        $idFakultas = (int) ($body['id_fakultas'] ?? 0);
        $kode       = trim((string) ($body['kode_program_studi'] ?? ''));

        if (!isset($errors['id_fakultas']) && $idFakultas > 0
            && $this->master->findFakultas($idFakultas) === null) {
            $errors['id_fakultas'] = 'Fakultas tidak ditemukan.';
        }

        if (!isset($errors['kode_program_studi']) && $kode !== ''
            && $this->master->prodiByKode($idFakultas, $kode) !== null) {
            $errors['kode_program_studi'] = 'Kode program studi sudah terdaftar pada fakultas tersebut.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $id      = $this->master->createProdi($this->mapProdi($body));
        $created = $this->master->findProdi($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'program_studi',
            'record_id'   => (string) $id,
            'aksi'        => 'INSERT',
            'data_baru'   => $created,
        ]);

        ResponseHelper::success($created, 'Program studi berhasil ditambahkan.', 201);
    }

    /** PUT /api/v1/program-studi/{id} (ADMIN, partial) */
    public function updateProdi(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findProdi($id);

        if ($existing === null) {
            ResponseHelper::notFound('Program studi tidak ditemukan.');
        }

        $body   = req_body();
        $errors = $this->validateProdi(array_merge($existing, $body));

        $idFakultas = (int) ($body['id_fakultas'] ?? $existing['id_fakultas']);
        $kode       = trim((string) ($body['kode_program_studi'] ?? $existing['kode_program_studi']));

        if (!isset($errors['id_fakultas']) && $idFakultas > 0
            && $this->master->findFakultas($idFakultas) === null) {
            $errors['id_fakultas'] = 'Fakultas tidak ditemukan.';
        }

        if (!isset($errors['kode_program_studi']) && $kode !== ''
            && $this->master->prodiByKode($idFakultas, $kode, $id) !== null) {
            $errors['kode_program_studi'] = 'Kode program studi sudah dipakai prodi lain pada fakultas tersebut.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data = $this->mapProdi($body, true);

        if ($data === []) {
            ResponseHelper::error('Tidak ada perubahan yang dikirim.', 400);
        }

        $this->master->updateProdi($id, $data);
        $updated = $this->master->findProdi($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'program_studi',
            'record_id'   => (string) $id,
            'aksi'        => 'UPDATE',
            'data_lama'   => $existing,
            'data_baru'   => $updated,
        ]);

        ResponseHelper::success($updated, 'Data program studi berhasil diperbarui.');
    }

    /** DELETE /api/v1/program-studi/{id} (ADMIN) */
    public function destroyProdi(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findProdi($id);

        if ($existing === null) {
            ResponseHelper::notFound('Program studi tidak ditemukan.');
        }

        $jmlMhs      = $this->master->countMahasiswaByProdi($id);
        $jmlPengguna = $this->master->countPenggunaByProdi($id);

        if ($jmlMhs > 0 || $jmlPengguna > 0) {
            ResponseHelper::error(
                'Program studi tidak dapat dihapus karena masih digunakan oleh '
                . $jmlMhs . ' mahasiswa dan ' . $jmlPengguna . ' pengguna.',
                422
            );
        }

        $this->master->deleteProdi($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'program_studi',
            'record_id'   => (string) $id,
            'aksi'        => 'DELETE',
            'data_lama'   => $existing,
        ]);

        ResponseHelper::success(['id_program_studi' => $id], 'Program studi berhasil dihapus.');
    }

    // =====================================================================
    // Internal
    // =====================================================================

    private function validateFakultas(array $body): array
    {
        $errors = [];

        if (empty($body['id_universitas']) || !is_numeric($body['id_universitas'])) {
            $errors['id_universitas'] = 'Universitas wajib dipilih.';
        }

        $kode = trim((string) ($body['kode_fakultas'] ?? ''));
        if ($kode === '') {
            $errors['kode_fakultas'] = 'Kode fakultas wajib diisi.';
        } elseif (strlen($kode) > 20) {
            $errors['kode_fakultas'] = 'Kode fakultas maksimal 20 karakter.';
        }

        $nama = trim((string) ($body['nama_fakultas'] ?? ''));
        if ($nama === '') {
            $errors['nama_fakultas'] = 'Nama fakultas wajib diisi.';
        } elseif (mb_strlen($nama) > 200) {
            $errors['nama_fakultas'] = 'Nama fakultas maksimal 200 karakter.';
        }

        return $errors;
    }

    private function mapFakultas(array $body, bool $partial = false): array
    {
        $data = [];

        if (array_key_exists('id_universitas', $body)) {
            $data['id_universitas'] = (int) $body['id_universitas'];
        }
        if (array_key_exists('kode_fakultas', $body)) {
            $data['kode_fakultas'] = trim((string) $body['kode_fakultas']);
        }
        if (array_key_exists('nama_fakultas', $body)) {
            $data['nama_fakultas'] = trim((string) $body['nama_fakultas']);
        }

        return $data;
    }

    private function validateProdi(array $body): array
    {
        $errors = [];

        if (empty($body['id_fakultas']) || !is_numeric($body['id_fakultas'])) {
            $errors['id_fakultas'] = 'Fakultas wajib dipilih.';
        }

        $kode = trim((string) ($body['kode_program_studi'] ?? ''));
        if ($kode === '') {
            $errors['kode_program_studi'] = 'Kode program studi wajib diisi.';
        } elseif (strlen($kode) > 20) {
            $errors['kode_program_studi'] = 'Kode program studi maksimal 20 karakter.';
        }

        $nama = trim((string) ($body['nama_program_studi'] ?? ''));
        if ($nama === '') {
            $errors['nama_program_studi'] = 'Nama program studi wajib diisi.';
        } elseif (mb_strlen($nama) > 200) {
            $errors['nama_program_studi'] = 'Nama program studi maksimal 200 karakter.';
        }

        if (isset($body['jenjang']) && mb_strlen((string) $body['jenjang']) > 20) {
            $errors['jenjang'] = 'Jenjang maksimal 20 karakter.';
        }

        if (isset($body['status_aktif'])
            && !in_array((string) $body['status_aktif'], ['Aktif', 'Tidak Aktif'], true)) {
            $errors['status_aktif'] = 'Status harus "Aktif" atau "Tidak Aktif".';
        }

        return $errors;
    }

    private function mapProdi(array $body, bool $partial = false): array
    {
        $data = [];

        if (array_key_exists('id_fakultas', $body)) {
            $data['id_fakultas'] = (int) $body['id_fakultas'];
        }
        if (array_key_exists('kode_program_studi', $body)) {
            $data['kode_program_studi'] = trim((string) $body['kode_program_studi']);
        }
        if (array_key_exists('nama_program_studi', $body)) {
            $data['nama_program_studi'] = trim((string) $body['nama_program_studi']);
        }
        if (array_key_exists('jenjang', $body)) {
            $data['jenjang'] = trim((string) $body['jenjang']);
        }
        if (array_key_exists('status_aktif', $body)) {
            $data['status_aktif'] = (string) $body['status_aktif'];
        }

        // Create: default agar tidak melanggar kolom NOT NULL tanpa default
        if (!$partial) {
            $data['jenjang']      = $data['jenjang'] ?? 'S1';
            $data['status_aktif'] = $data['status_aktif'] ?? 'Aktif';
        }

        return $data;
    }
}
