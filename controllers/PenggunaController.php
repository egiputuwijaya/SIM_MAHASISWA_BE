<?php
/**
 * controllers/PenggunaController.php
 * Logika manajemen pengguna (tabel `pengguna`) & daftar role.
 *
 * Akses: ADMIN saja untuk semua endpoint di sini.
 * Catatan: password disimpan sebagai bcrypt hash; hash tidak pernah
 * dikirim ke response (disensor via publicRow()).
 */

declare(strict_types=1);

class PenggunaController
{
    private PenggunaModel $pengguna;
    private MasterModel $master;

    public function __construct()
    {
        $this->pengguna = new PenggunaModel();
        $this->master   = new MasterModel();
    }

    // =====================================================================
    // GET /api/v1/pengguna  (list + search + pagination)
    // =====================================================================
    public function index(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $query   = req_query();
        $filters = [];

        if (!empty($query['q'])) {
            $filters['q'] = (string) $query['q'];
        }
        if (!empty($query['status_aktif'])) {
            $filters['status_aktif'] = (string) $query['status_aktif'];
        }
        if (!empty($query['id_role'])) {
            $filters['id_role'] = (int) $query['id_role'];
        }

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 10));

        $result = $this->pengguna->paginate($filters, $page, $perPage);

        ResponseHelper::success($result, 'Data pengguna berhasil diambil.');
    }

    // =====================================================================
    // GET /api/v1/pengguna/{id}
    // =====================================================================
    public function show(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $row = $this->pengguna->findById($id);

        if ($row === null) {
            ResponseHelper::notFound('Pengguna tidak ditemukan.');
        }

        ResponseHelper::success(self::publicRow($row), 'Data pengguna berhasil diambil.');
    }

    // =====================================================================
    // POST /api/v1/pengguna  (ADMIN)
    // =====================================================================
    public function store(?array $user): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $body   = req_body();
        $errors = $this->validate($body);

        $username = trim((string) ($body['username'] ?? ''));

        if (!isset($errors['username']) && $username !== ''
            && $this->pengguna->usernameExists($username)) {
            $errors['username'] = 'Username sudah terdaftar.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data = $this->mapPayload($body);
        $id   = $this->pengguna->create($data);

        $created = $this->pengguna->findById($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'pengguna',
            'record_id'   => (string) $id,
            'aksi'        => 'INSERT',
            'data_baru'   => self::publicRow($created),
        ]);

        ResponseHelper::success(self::publicRow($created), 'Pengguna berhasil ditambahkan.', 201);
    }

    // =====================================================================
    // PUT /api/v1/pengguna/{id}  (ADMIN, partial update)
    // =====================================================================
    public function update(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->pengguna->findById($id);

        if ($existing === null) {
            ResponseHelper::notFound('Pengguna tidak ditemukan.');
        }

        $body   = req_body();
        $errors = $this->validate(array_merge($existing, $body), true);

        $username = trim((string) ($body['username'] ?? $existing['username']));

        if (!isset($errors['username']) && $username !== ''
            && $this->pengguna->usernameExists($username, $id)) {
            $errors['username'] = 'Username sudah dipakai pengguna lain.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data = $this->mapPayload($body, true);

        if ($data === []) {
            ResponseHelper::error('Tidak ada perubahan yang dikirim.', 400);
        }

        $this->pengguna->update($id, $data);
        $updated = $this->pengguna->findById($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'pengguna',
            'record_id'   => (string) $id,
            'aksi'        => 'UPDATE',
            'data_lama'   => self::publicRow($existing),
            'data_baru'   => self::publicRow($updated),
        ]);

        ResponseHelper::success(self::publicRow($updated), 'Data pengguna berhasil diperbarui.');
    }

    // =====================================================================
    // DELETE /api/v1/pengguna/{id}  (ADMIN)
    // =====================================================================
    public function destroy(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, ['ADMIN']);

        $existing = $this->pengguna->findById($id);

        if ($existing === null) {
            ResponseHelper::notFound('Pengguna tidak ditemukan.');
        }

        // Cegah admin menghapus akunnya sendiri
        if ((int) $user['id_pengguna'] === $id) {
            ResponseHelper::forbidden('Anda tidak dapat menghapus akun sendiri.');
        }

        $this->pengguna->delete($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'pengguna',
            'record_id'   => (string) $id,
            'aksi'        => 'DELETE',
            'data_lama'   => self::publicRow($existing),
        ]);

        ResponseHelper::success(['id_pengguna' => $id], 'Pengguna berhasil dihapus.');
    }

    // =====================================================================
    // GET /api/v1/roles  (daftar role — semua role terautentikasi)
    // =====================================================================
    public function roles(?array $user): void
    {
        $items = $this->master->allRoles();

        ResponseHelper::success(['items' => $items, 'total' => count($items)], 'Data role berhasil diambil.');
    }

    // =====================================================================
    // Internal
    // =====================================================================

    /** Validasi payload create (atau gabungan existing+body saat update). */
    private function validate(array $body, bool $partial = false): array
    {
        $errors = [];

        $username = trim((string) ($body['username'] ?? ''));
        if ($username === '') {
            $errors['username'] = 'Username wajib diisi.';
        } elseif (strlen($username) > 100) {
            $errors['username'] = 'Username maksimal 100 karakter.';
        }

        // Password: wajib saat create; opsional saat update (kosong = tidak diganti)
        $password = (string) ($body['password'] ?? '');
        if (!$partial && $password === '') {
            $errors['password'] = 'Password wajib diisi.';
        } elseif ($password !== '' && strlen($password) < 6) {
            $errors['password'] = 'Password minimal 6 karakter.';
        }

        $nama = trim((string) ($body['nama_lengkap'] ?? ''));
        if ($nama === '') {
            $errors['nama_lengkap'] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nama) > 200) {
            $errors['nama_lengkap'] = 'Nama lengkap maksimal 200 karakter.';
        }

        if (empty($body['id_role']) || !is_numeric($body['id_role'])) {
            $errors['id_role'] = 'Role wajib dipilih.';
        } elseif (!$this->master->roleExists((int) $body['id_role'])) {
            $errors['id_role'] = 'Role tidak ditemukan.';
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Format email tidak valid.';
            } elseif (strlen($email) > 150) {
                $errors['email'] = 'Email maksimal 150 karakter.';
            }
        }

        if (isset($body['no_hp']) && mb_strlen((string) $body['no_hp']) > 30) {
            $errors['no_hp'] = 'No. HP maksimal 30 karakter.';
        }

        if (isset($body['status_aktif'])
            && !in_array((string) $body['status_aktif'], ['Aktif', 'Tidak Aktif'], true)) {
            $errors['status_aktif'] = 'Status harus "Aktif" atau "Tidak Aktif".';
        }

        return $errors;
    }

    /**
     * Pilih field yang boleh ditulis. Password di-hash di sini.
     * Partial update: password kosong/tidak dikirim = tidak diganti.
     */
    private function mapPayload(array $body, bool $partial = false): array
    {
        $map = ['username', 'nama_lengkap', 'email', 'no_hp', 'status_aktif',
                'id_role', 'id_universitas', 'id_fakultas', 'id_program_studi'];

        $data = [];

        foreach ($map as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $value = trim((string) $body[$field]);

            // Scope opsional: string kosong -> null (kolom nullable)
            if ($value === '' && in_array($field, ['email', 'no_hp', 'id_universitas', 'id_fakultas', 'id_program_studi'], true)) {
                $value = null;
            }

            if ($value !== null && in_array($field, ['id_role', 'id_universitas', 'id_fakultas', 'id_program_studi'], true)) {
                $value = (int) $value;
            }

            $data[$field] = $value;
        }

        // Password: create wajib ada; update hanya bila diisi
        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        } elseif (!$partial) {
            $data['password_hash'] = password_hash((string) ($body['password'] ?? ''), PASSWORD_BCRYPT);
        }

        if (!$partial && !isset($data['status_aktif'])) {
            $data['status_aktif'] = 'Aktif';
        }

        return $data;
    }

    /** Baris pengguna tanpa password_hash (untuk response & audit). */
    private static function publicRow(array $row): array
    {
        unset($row['password_hash']);

        return $row;
    }
}
