<?php
/**
 * controllers/MahasiswaController.php
 * Logika CRUD & upload foto mahasiswa.
 *
 * Pembatasan akses berdasarkan role (kode_role):
 *   ADMIN          -> full akses (baca + tulis)
 *   OPERATOR_PRODI -> data pada prodi miliknya saja (baca + tulis)
 *   DEKANAT        -> baca saja
 *   REKTORAT       -> baca saja
 *   MAHASISWA      -> hanya data miliknya sendiri (baca saja)
 *
 * Catatan upload foto:
 *   - Browser tidak mem-parse body PUT, jadi update multipart dikirim sebagai
 *     POST /api/v1/mahasiswa/{id} dengan field "_method=PUT" (sudah ditangani index.php).
 *   - File lama otomatis dihapus saat diganti (update) atau saat data dihapus.
 */

declare(strict_types=1);

class MahasiswaController
{
    /** Role yang boleh menambah/mengubah/menghapus data. */
    private const WRITE_ROLES = ['ADMIN', 'OPERATOR_PRODI'];

    /** Role yang boleh melihat seluruh data (tanpa scope). */
    private const READ_ALL_ROLES = ['ADMIN', 'DEKANAT', 'REKTORAT'];

    private const STATUS_MAHASISWA = ['Aktif', 'Cuti', 'Lulus', 'Mengundurkan Diri', 'Drop Out', 'Tidak Aktif'];

    private MahasiswaModel $mahasiswa;
    private PenggunaModel $pengguna;

    public function __construct()
    {
        $this->mahasiswa = new MahasiswaModel();
        $this->pengguna  = new PenggunaModel();
    }

    // =====================================================================
    // GET /api/v1/mahasiswa  (list + search + pagination)
    // =====================================================================
    public function index(?array $user): void
    {
        $query   = req_query();
        $filters = [];

        if (!empty($query['q'])) {
            $filters['q'] = (string) $query['q'];
        }
        if (!empty($query['status_mahasiswa'])) {
            $filters['status_mahasiswa'] = (string) $query['status_mahasiswa'];
        }
        if (!empty($query['jenis_kelamin'])) {
            $filters['jenis_kelamin'] = (string) $query['jenis_kelamin'];
        }
        if (!empty($query['id_program_studi'])) {
            $filters['id_program_studi'] = (int) $query['id_program_studi'];
        }

        // Scope berdasarkan role
        $role = (string) ($user['kode_role'] ?? '');

        if ($role === 'OPERATOR_PRODI') {
            // Paksa hanya prodi miliknya sendiri
            $filters['id_program_studi'] = (int) ($user['id_program_studi'] ?? 0);
        } elseif ($role === 'MAHASISWA') {
            $own = $this->mahasiswa->findByPengguna((int) $user['id_pengguna']);
            if ($own === null) {
                ResponseHelper::success(
                    ['items' => [], 'pagination' => ['page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1]],
                    'Data mahasiswa berhasil diambil.'
                );
            }
            $filters['id_mahasiswa'] = (int) $own['id_mahasiswa'];
        }

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 10));

        $result = $this->mahasiswa->paginate($filters, $page, $perPage);

        ResponseHelper::success($result, 'Data mahasiswa berhasil diambil.');
    }

    // =====================================================================
    // GET /api/v1/mahasiswa/{id}
    // =====================================================================
    public function show(?array $user, int $id): void
    {
        $data = $this->mahasiswa->findById($id);

        if ($data === null) {
            ResponseHelper::notFound('Mahasiswa tidak ditemukan.');
        }

        $this->assertReadable($user, $data);

        ResponseHelper::success($data, 'Data mahasiswa berhasil diambil.');
    }

    // =====================================================================
    // POST /api/v1/mahasiswa  (multipart/form-data, field "foto" opsional)
    // =====================================================================
    public function store(?array $user): void
    {
        AuthMiddleware::requireRole($user, self::WRITE_ROLES);

        $body   = req_body();
        $errors = $this->validate($body);

        $idProgramStudi = (int) ($body['id_program_studi'] ?? 0);

        // Operator prodi hanya boleh menambahkan mahasiswa di prodinya sendiri
        if (($user['kode_role'] ?? '') === 'OPERATOR_PRODI'
            && (int) ($user['id_program_studi'] ?? 0) !== $idProgramStudi) {
            ResponseHelper::forbidden('Anda hanya dapat menambahkan mahasiswa pada program studi Anda sendiri.');
        }

        if ($idProgramStudi > 0 && !$this->mahasiswa->prodiExists($idProgramStudi)) {
            $errors['id_program_studi'] = 'Program studi tidak ditemukan.';
        }

        if (!isset($errors['npm']) && $this->mahasiswa->findByNpm((string) ($body['npm'] ?? '')) !== null) {
            $errors['npm'] = 'NPM sudah terdaftar.';
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        // Upload foto (dilakukan setelah validasi agar tidak ada file sampah)
        $foto = null;
        if (self::hasUploadedFoto()) {
            $foto = UploadHelper::upload($_FILES['foto'], 'mahasiswa');
        }

        $data = $this->mapPayload($body);
        $data['foto'] = $foto;

        $newId = $this->mahasiswa->create($data);

        $created = $this->mahasiswa->findById($newId);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'mahasiswa',
            'record_id'   => (string) $newId,
            'aksi'        => 'INSERT',
            'data_baru'   => $created,
        ]);

        ResponseHelper::success($created, 'Mahasiswa berhasil ditambahkan.', 201);
    }

    // =====================================================================
    // PUT /api/v1/mahasiswa/{id}  (JSON, atau multipart + _method=PUT)
    // =====================================================================
    public function update(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, self::WRITE_ROLES);

        $existing = $this->mahasiswa->findById($id);

        if ($existing === null) {
            ResponseHelper::notFound('Mahasiswa tidak ditemukan.');
        }

        $this->assertWritable($user, $existing);

        $body   = req_body();
        // Partial update: field yang tidak dikirim diambil dari data lama (tetap tervalidasi)
        $errors = $this->validate(array_merge($existing, $body));

        $idProgramStudi = (int) ($body['id_program_studi'] ?? $existing['id_program_studi']);

        if ($idProgramStudi > 0 && !$this->mahasiswa->prodiExists($idProgramStudi)) {
            $errors['id_program_studi'] = 'Program studi tidak ditemukan.';
        }

        if (!isset($errors['npm'])) {
            $dup = $this->mahasiswa->findByNpm((string) ($body['npm'] ?? $existing['npm']), $id);
            if ($dup !== null) {
                $errors['npm'] = 'NPM sudah dipakai mahasiswa lain.';
            }
        }

        if ($errors !== []) {
            ResponseHelper::error('Data tidak valid.', 422, $errors);
        }

        $data  = $this->mapPayload($body, true);
        $oldFoto = (string) ($existing['foto'] ?? '');
        $newFoto = null;

        // Foto baru diupload?
        if (self::hasUploadedFoto()) {
            $newFoto = UploadHelper::upload($_FILES['foto'], 'mahasiswa');
            $data['foto'] = $newFoto;
        } elseif (filter_var(req_input('hapus_foto', false), FILTER_VALIDATE_BOOLEAN)) {
            // Perintah eksplisit menghapus foto tanpa mengganti
            $data['foto'] = null;
        }

        if ($data === []) {
            ResponseHelper::error('Tidak ada perubahan yang dikirim.', 400);
        }

        $this->mahasiswa->update($id, $data);

        // Hapus file fisik lama setelah DB berhasil diperbarui
        $fotoAfter = array_key_exists('foto', $data) ? $data['foto'] : $oldFoto;
        if ($oldFoto !== '' && $fotoAfter !== $oldFoto) {
            UploadHelper::delete($oldFoto);
        }

        $updated = $this->mahasiswa->findById($id);

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'mahasiswa',
            'record_id'   => (string) $id,
            'aksi'        => 'UPDATE',
            'data_lama'   => $existing,
            'data_baru'   => $updated,
        ]);

        ResponseHelper::success($updated, 'Data mahasiswa berhasil diperbarui.');
    }

    // =====================================================================
    // DELETE /api/v1/mahasiswa/{id}
    // =====================================================================
    public function destroy(?array $user, int $id): void
    {
        AuthMiddleware::requireRole($user, self::WRITE_ROLES);

        $existing = $this->mahasiswa->findById($id);

        if ($existing === null) {
            ResponseHelper::notFound('Mahasiswa tidak ditemukan.');
        }

        $this->assertWritable($user, $existing);

        $this->mahasiswa->delete($id);

        // Hapus file foto fisik setelah record sukses dihapus
        if (!empty($existing['foto'])) {
            UploadHelper::delete((string) $existing['foto']);
        }

        $this->pengguna->logAudit([
            'id_pengguna' => (int) $user['id_pengguna'],
            'tabel_nama'  => 'mahasiswa',
            'record_id'   => (string) $id,
            'aksi'        => 'DELETE',
            'data_lama'   => $existing,
        ]);

        ResponseHelper::success(['id_mahasiswa' => $id], 'Mahasiswa berhasil dihapus.');
    }

    // =====================================================================
    // Internal
    // =====================================================================

    /** Validasi payload create/update. Mengembalikan array error (kosong = valid). */
    private function validate(array $body): array
    {
        $errors = [];

        $npm = trim((string) ($body['npm'] ?? ''));
        if ($npm === '') {
            $errors['npm'] = 'NPM wajib diisi.';
        } elseif (strlen($npm) > 30) {
            $errors['npm'] = 'NPM maksimal 30 karakter.';
        }

        $nama = trim((string) ($body['nama_mahasiswa'] ?? ''));
        if ($nama === '') {
            $errors['nama_mahasiswa'] = 'Nama mahasiswa wajib diisi.';
        } elseif (mb_strlen($nama) > 200) {
            $errors['nama_mahasiswa'] = 'Nama mahasiswa maksimal 200 karakter.';
        }

        if (empty($body['id_program_studi']) || !is_numeric($body['id_program_studi'])) {
            $errors['id_program_studi'] = 'Program studi wajib dipilih.';
        }

        if (!in_array((string) ($body['jenis_kelamin'] ?? ''), ['L', 'P'], true)) {
            $errors['jenis_kelamin'] = 'Jenis kelamin harus "L" atau "P".';
        }

        if (empty($body['tanggal_masuk'])) {
            $errors['tanggal_masuk'] = 'Tanggal masuk wajib diisi.';
        } elseif (!$this->isValidDate((string) $body['tanggal_masuk'])) {
            $errors['tanggal_masuk'] = 'Format tanggal masuk harus YYYY-MM-DD.';
        }

        if (!empty($body['tanggal_lahir']) && !$this->isValidDate((string) $body['tanggal_lahir'])) {
            $errors['tanggal_lahir'] = 'Format tanggal lahir harus YYYY-MM-DD.';
        }

        if (isset($body['status_mahasiswa'])
            && !in_array((string) $body['status_mahasiswa'], self::STATUS_MAHASISWA, true)) {
            $errors['status_mahasiswa'] = 'Status tidak dikenal. Pilihan: ' . implode(', ', self::STATUS_MAHASISWA) . '.';
        }

        return $errors;
    }

    /** Pilih field yang boleh ditulis (create) / ikut diperbarui (update). */
    private function mapPayload(array $body, bool $partial = false): array
    {
        $map = [
            'npm'               => ['key' => 'npm', 'type' => 'string', 'trim' => true],
            'nama_mahasiswa'    => ['key' => 'nama_mahasiswa', 'type' => 'string', 'trim' => true],
            'id_program_studi'  => ['key' => 'id_program_studi', 'type' => 'int'],
            'jenis_kelamin'     => ['key' => 'jenis_kelamin', 'type' => 'string'],
            'tempat_lahir'      => ['key' => 'tempat_lahir', 'type' => 'string', 'trim' => true],
            'tanggal_lahir'     => ['key' => 'tanggal_lahir', 'type' => 'string'],
            'tanggal_masuk'     => ['key' => 'tanggal_masuk', 'type' => 'string'],
            'alamat'            => ['key' => 'alamat', 'type' => 'string'],
            'status_mahasiswa'  => ['key' => 'status_mahasiswa', 'type' => 'string'],
        ];

        $data = [];

        foreach ($map as $field => $spec) {
            if (!array_key_exists($field, $body)) {
                // Saat update: field tidak dikirim = tidak diubah
                continue;
            }

            $value = $body[$field];

            if ($spec['type'] === 'int') {
                $data[$spec['key']] = (int) $value;
                continue;
            }

            $value = (string) $value;
            if (!empty($spec['trim'])) {
                $value = trim($value);
            }

            // Kolom NOT NULL tanpa default -> jangan kirim string kosong
            if ($value === '' && in_array($field, ['tanggal_lahir', 'tempat_lahir', 'alamat'], true)) {
                $value = null;
            }

            $data[$spec['key']] = $value;
        }

        // Create: isi default agar tidak melanggar constraint NOT NULL
        if (!$partial) {
            $data['status_mahasiswa'] = $data['status_mahasiswa'] ?? 'Aktif';
            $data['tempat_lahir']     = $data['tempat_lahir'] ?? null;
            $data['tanggal_lahir']    = $data['tanggal_lahir'] ?? null;
            $data['alamat']           = $data['alamat'] ?? null;
        }

        return $data;
    }

    /** Akses baca: semua role terautentikasi, dengan scope per role. */
    private function assertReadable(?array $user, array $row): void
    {
        $role = (string) ($user['kode_role'] ?? '');

        if (in_array($role, self::READ_ALL_ROLES, true)) {
            return;
        }

        if ($role === 'OPERATOR_PRODI') {
            if ((int) ($row['id_program_studi'] ?? 0) === (int) ($user['id_program_studi'] ?? 0)) {
                return;
            }
            ResponseHelper::forbidden('Data mahasiswa berada di luar program studi Anda.');
        }

        if ($role === 'MAHASISWA') {
            if ((int) ($row['id_pengguna'] ?? 0) === (int) ($user['id_pengguna'] ?? 0)) {
                return;
            }
            ResponseHelper::forbidden('Anda hanya dapat melihat data mahasiswa milik Anda sendiri.');
        }

        ResponseHelper::forbidden();
    }

    /** Akses tulis: hanya role dalam WRITE_ROLES dan sesuai scope prodi. */
    private function assertWritable(?array $user, array $row): void
    {
        $role = (string) ($user['kode_role'] ?? '');

        if ($role === 'ADMIN') {
            return;
        }

        if ($role === 'OPERATOR_PRODI'
            && (int) ($row['id_program_studi'] ?? 0) === (int) ($user['id_program_studi'] ?? 0)) {
            return;
        }

        ResponseHelper::forbidden('Anda tidak memiliki akses mengubah data mahasiswa ini.');
    }

    /** Apakah ada file foto yang benar-benar dikirim? */
    private static function hasUploadedFoto(): bool
    {
        return isset($_FILES['foto'])
            && is_array($_FILES['foto'])
            && (int) ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
