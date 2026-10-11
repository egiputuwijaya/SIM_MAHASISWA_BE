<?php
/**
 * controllers/AuditController.php
 * Logika membaca tabel `audit_log` (riwayat aktivitas).
 *
 * Endpoint:
 *   GET /api/v1/audit-log  -> daftar log (ADMIN)
 *     Query opsional: aksi, tabel_nama, id_pengguna, dari (Y-m-d),
 *                     sampai (Y-m-d), page, per_page
 */

declare(strict_types=1);

class AuditController
{
    private const AKSI_LIST = ['LOGIN', 'LOGOUT', 'INSERT', 'UPDATE', 'DELETE', 'VIEW'];

    private PenggunaModel $pengguna;

    public function __construct()
    {
        $this->pengguna = new PenggunaModel();
    }

    /** GET /api/v1/audit-log  (ADMIN) */
    public function index(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $query   = req_query();
        $filters = [];

        if (!empty($query['aksi'])) {
            $aksi = strtoupper((string) $query['aksi']);

            if (!in_array($aksi, self::AKSI_LIST, true)) {
                ResponseHelper::error(
                    'Aksi tidak dikenal. Pilihan: ' . implode(', ', self::AKSI_LIST) . '.',
                    422,
                    ['aksi' => 'Nilai aksi tidak valid.']
                );
            }

            $filters['aksi'] = $aksi;
        }

        if (!empty($query['tabel_nama'])) {
            $filters['tabel_nama'] = (string) $query['tabel_nama'];
        }

        if (!empty($query['id_pengguna'])) {
            $filters['id_pengguna'] = (int) $query['id_pengguna'];
        }

        if (!empty($query['dari']) && !$this->isValidDate((string) $query['dari'])) {
            ResponseHelper::error('Format tanggal "dari" harus YYYY-MM-DD.', 422, ['dari' => 'Format tanggal tidak valid.']);
        }

        if (!empty($query['sampai']) && !$this->isValidDate((string) $query['sampai'])) {
            ResponseHelper::error('Format tanggal "sampai" harus YYYY-MM-DD.', 422, ['sampai' => 'Format tanggal tidak valid.']);
        }

        if (!empty($query['dari'])) {
            $filters['dari'] = (string) $query['dari'];
        }

        if (!empty($query['sampai'])) {
            $filters['sampai'] = (string) $query['sampai'];
        }

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 20));

        $result = $this->pengguna->paginateAudit($filters, $page, $perPage);

        ResponseHelper::success($result, 'Data audit log berhasil diambil.');
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
