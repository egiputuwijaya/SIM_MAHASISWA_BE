<?php
/**
 * controllers/ProdiController.php
 * Logika data program studi & fakultas.
 *
 * Endpoint:
 *   GET /api/v1/fakultas            -> daftar fakultas (opsional ?id_universitas=&q=)
 *   GET /api/v1/program-studi       -> daftar prodi + fakultas + universitas (opsional ?id_fakultas=&q=&status_aktif=)
 *   GET /api/v1/program-studi/{id}  -> detail satu program studi
 */

declare(strict_types=1);

class ProdiController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
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
}
