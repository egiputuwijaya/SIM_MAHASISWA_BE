<?php
/**
 * controllers/MasterController.php
 * Logika CRUD data `universitas`.
 *
 * Akses:
 *   GET    (list/detail) -> semua role terautentikasi
 *   POST/PUT/DELETE      -> ADMIN saja
 */

declare(strict_types=1);

class MasterController
{
    private MasterModel $master;
    private PenggunaModel $pengguna;

    public function __construct()
    {
        $this->master   = new MasterModel();
        $this->pengguna = new PenggunaModel();
    }

    // =====================================================================
    // GET /api/v1/universitas  (list + search q)
    // =====================================================================
    public function index(?array $user): void
    {
        $q     = trim((string) (req_query()['q'] ?? ''));
        $items = $this->master->allUniversitas($q);

        ResponseHelper::success(
            ['items' => $items, 'total' => count($items)],
            'Data universitas berhasil diambil.'
        );
    }

    // =====================================================================
    // GET /api/v1/universitas/{id}
    // =====================================================================
    public function show(?array $user, int $id): void
    {
        $row = $this->master->findUniversitas($id);

        if ($row === null) {
            ResponseHelper::notFound('Universitas tidak ditemukan.');
        }

        ResponseHelper::success($row, 'Data universitas berhasil diambil.');
    }

    // =====================================================================
    // POST /api/v1/universitas  (ADMIN)
    // =====================================================================
    public function store(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $body   = req_body();
        $errors = $this->validate($body);

        if (!isset($errors['kode_universitas'])) {
            $kode = trim((string) ($body['kode_universitas'] ?? ''));

            if ($kode !== '' && $this->master->universitasByKode($kode) !== null) {
                $errors['kode_universitas'] = 'Kode universitas sudah terdaftar.';
            }
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $id      = $this->master->createUniversitas($this->mapPayload($body));
        $created = $this->master->findUniversitas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'universitas',
            'record_id'   => (string) $id,
            'aksi'        => 'INSERT',
            'data_baru'   => $created,
        ]);

        ResponseHelper::success($created, 'Universitas berhasil ditambahkan.', 201);
    }

    // =====================================================================
    // PUT /api/v1/universitas/{id}  (ADMIN, partial update)
    // =====================================================================
    public function update(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findUniversitas($id);

        if ($existing === null) {
            ResponseHelper::notFound('Universitas tidak ditemukan.');
        }

        $body   = req_body();
        $errors = $this->validate(array_merge($existing, $body));

        if (!isset($errors['kode_universitas'])) {
            $kode = trim((string) ($body['kode_universitas'] ?? $existing['kode_universitas']));

            if ($kode !== '') {
                $dup = $this->master->universitasByKode($kode, $id);

                if ($dup !== null) {
                    $errors['kode_universitas'] = 'Kode universitas sudah dipakai universitas lain.';
                }
            }
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data = $this->mapPayload($body, true);

        if ($data === []) {
            ResponseHelper::error('Tidak ada perubahan yang dikirim.', 400);
        }

        $this->master->updateUniversitas($id, $data);
        $updated = $this->master->findUniversitas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'universitas',
            'record_id'   => (string) $id,
            'aksi'        => 'UPDATE',
            'data_lama'   => $existing,
            'data_baru'   => $updated,
        ]);

        ResponseHelper::success($updated, 'Data universitas berhasil diperbarui.');
    }

    // =====================================================================
    // DELETE /api/v1/universitas/{id}  (ADMIN)
    // =====================================================================
    public function destroy(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->master->findUniversitas($id);

        if ($existing === null) {
            ResponseHelper::notFound('Universitas tidak ditemukan.');
        }

        // Cegah pelanggaran FK: masih ada fakultas / pengguna yang menunjuk ke sini
        $jmlFakultas = $this->master->countFakultasByUniversitas($id);
        $jmlPengguna = $this->master->countPenggunaByUniversitas($id);

        if ($jmlFakultas > 0 || $jmlPengguna > 0) {
            ResponseHelper::error(
                'Universitas tidak dapat dihapus karena masih digunakan oleh '
                . $jmlFakultas . ' fakultas dan ' . $jmlPengguna . ' pengguna.',
                422
            );
        }

        $this->master->deleteUniversitas($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'universitas',
            'record_id'   => (string) $id,
            'aksi'        => 'DELETE',
            'data_lama'   => $existing,
        ]);

        ResponseHelper::success(['id_universitas' => $id], 'Universitas berhasil dihapus.');
    }

    // =====================================================================
    // Internal
    // =====================================================================

    /** Validasi payload create/update (gabungan existing+body saat update). */
    private function validate(array $body): array
    {
        $errors = [];

        $kode = trim((string) ($body['kode_universitas'] ?? ''));
        if ($kode === '') {
            $errors['kode_universitas'] = 'Kode universitas wajib diisi.';
        } elseif (strlen($kode) > 20) {
            $errors['kode_universitas'] = 'Kode universitas maksimal 20 karakter.';
        }

        $nama = trim((string) ($body['nama_universitas'] ?? ''));
        if ($nama === '') {
            $errors['nama_universitas'] = 'Nama universitas wajib diisi.';
        } elseif (mb_strlen($nama) > 200) {
            $errors['nama_universitas'] = 'Nama universitas maksimal 200 karakter.';
        }

        // Field opsional ber-panjang tetap dibatasi agar tidak melanggar kolom
        $limits = [
            'slogan'    => 255,
            'kota'      => 100,
            'provinsi'  => 100,
            'kode_pos'  => 10,
            'website'   => 255,
            'email'     => 150,
            'telepon'   => 50,
        ];

        foreach ($limits as $field => $max) {
            $value = (string) ($body[$field] ?? '');

            if ($value !== '' && mb_strlen($value) > $max) {
                $errors[$field] = 'Maksimal ' . $max . ' karakter.';
            }
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Format email tidak valid.';
        }

        return $errors;
    }

    /**
     * Pilih field yang boleh ditulis (create) / ikut diperbarui (update).
     * String kosong pada field opsional -> null (kolom nullable).
     */
    private function mapPayload(array $body, bool $partial = false): array
    {
        $nullable = ['slogan', 'alamat', 'kota', 'provinsi', 'kode_pos', 'website', 'email', 'telepon'];
        $data     = [];

        foreach (MasterModel::columnsFor('universitas') as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $value = trim((string) $body[$field]);

            if ($value === '' && in_array($field, $nullable, true)) {
                $value = null;
            }

            $data[$field] = $value;
        }

        return $data;
    }
}
