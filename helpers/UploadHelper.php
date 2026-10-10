<?php
/**
 * helpers/UploadHelper.php
 * Validasi & penyimpanan foto profil mahasiswa ke uploads/mahasiswa/.
 *
 * Fitur:
 *   - Validasi error PHP upload, ukuran (max_kb), ekstensi (jpg/png),
 *     dan MIME type asli file (finfo) agar tidak bisa diselipkan file berbahaya.
 *   - Membuat folder tujuan secara otomatis bila belum ada.
 *   - Rename file dengan nama unik (random hex) — nama lama tidak dipakai.
 *   - Mengembalikan path relatif untuk disimpan ke kolom `foto`
 *     (mis. "uploads/mahasiswa/20260001_a1b2c3d4e5f6g7h8.jpg").
 *   - delete() untuk menghapus file fisik lama saat update/hapus data.
 */

declare(strict_types=1);

/** Exception khusus validasi upload (di-catch index.php -> HTTP 422). */
class UploadException extends RuntimeException
{
}

class UploadHelper
{
    private const MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    /**
     * Proses upload satu file gambar.
     *
     * @param array       $file    Elemen dari $_FILES (error, tmp_name, size, name)
     * @param string|null $subfolder Sub-folder di dalam uploads/ (default: mahasiswa)
     * @return string Path relatif yang disimpan ke DB, contoh: uploads/mahasiswa/xxx.jpg
     * @throws UploadException Bila validasi gagal
     */
    public static function upload(array $file, ?string $subfolder = null): string
    {
        $subfolder = $subfolder ?: (string) app_config('upload.subdir', 'mahasiswa');
        $subfolder = trim($subfolder, '/');

        if ($subfolder === '' || preg_match('/[^A-Za-z0-9_\-]/', $subfolder)) {
            throw new UploadException('Sub-folder upload tidak valid.');
        }

        // 1. Error dari proses upload PHP
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Tidak ada file yang diupload.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('Ukuran file melebihi batas yang diizinkan server.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('Upload gagal (kode error: ' . $error . ').');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new UploadException('File upload tidak valid.');
        }

        // 2. Validasi ukuran (maksimal dari config UPLOAD_MAX_SIZE dalam KB)
        $maxBytes = ((int) app_config('upload.max_kb', 2048)) * 1024;
        $size     = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new UploadException('File kosong.');
        }

        if ($size > $maxBytes) {
            $maxKb = (int) round($maxBytes / 1024);
            throw new UploadException('Ukuran foto maksimal ' . $maxKb . ' KB.');
        }

        // 3. Validasi ekstensi (dari nama file)
        $originalName = (string) ($file['name'] ?? '');
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExt   = (array) app_config('upload.allowed_ext', ['jpg', 'jpeg', 'png']);

        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            throw new UploadException('Ekstensi file tidak diizinkan. Gunakan: ' . implode(', ', $allowedExt) . '.');
        }

        // 4. Validasi MIME type asli (bukan sekadar ekstensi)
        $mime = self::detectMime($tmpName);

        if ($mime === null || !isset(self::MIME_MAP[$mime])) {
            throw new UploadException('File bukan gambar JPEG/PNG yang valid.');
        }

        // Pakai ekstensi kanonik sesuai MIME agar nama & isi file selalu cocok
        $ext = self::MIME_MAP[$mime];

        // 5. Nama file unik (rename) — jangan pernah percaya nama file user
        $base = preg_replace('/[^A-Za-z0-9\-]/', '', pathinfo($originalName, PATHINFO_FILENAME));
        $base = $base !== '' && $base !== null ? substr($base, 0, 40) . '_' : '';
        $name = $base . bin2hex(random_bytes(8)) . '.' . $ext;

        // 6. Folder tujuan (buat otomatis bila belum ada)
        $destDir = rtrim(app_config('upload.base_dir'), '/') . '/' . $subfolder;

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new UploadException('Gagal membuat folder tujuan upload.');
        }

        $destPath = $destDir . '/' . $name;

        // 7. Pindahkan file
        if (!move_uploaded_file($tmpName, $destPath)) {
            throw new UploadException('Gagal menyimpan file ke server.');
        }

        @chmod($destPath, 0644);

        // 8. Path relatif untuk kolom `foto`
        return app_config('upload.public_path', 'uploads') . '/' . $subfolder . '/' . $name;
    }

    /**
     * Hapus file foto lama (dipanggil saat update/delete mahasiswa).
     * Hanya file di dalam folder uploads/ yang boleh dihapus (anti path traversal).
     */
    public static function delete(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        $base = realpath(app_config('upload.base_dir'));
        $file = realpath(rtrim(BASE_PATH, DIRECTORY_SEPARATOR) . '/' . ltrim($relativePath, '/'));

        if ($base === false || $file === false) {
            return false;
        }

        $uploadBase = rtrim($base, DIRECTORY_SEPARATOR);

        // Pastikan file berada tepat di dalam folder uploads/ (anti path traversal)
        if (strpos($file, $uploadBase . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        if (!is_file($file)) {
            return false;
        }

        return @unlink($file);
    }

    /** Deteksi MIME type asli via finfo (fallback: getimagesize). */
    private static function detectMime(string $path): ?string
    {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($path);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        $info = @getimagesize($path);

        if (is_array($info) && !empty($info['mime'])) {
            return (string) $info['mime'];
        }

        return null;
    }
}
