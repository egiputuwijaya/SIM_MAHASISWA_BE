## SIM MAHASISWA BE

Backend RESTful API untuk proyek **SIM Mahasiswa** — PHP Native (OOP + PDO), Prepared Statements (anti SQL Injection), token SSO berbasis HMAC-SHA256, dan upload foto profil.

### Struktur

```
sim_mahasiswa_be/
├── config/       database.php (PDO, utf8mb4), app.php (loader .env, timezone, CORS, upload)
├── controllers/  AuthController, MahasiswaController, ProdiController
├── models/       Database (wrapper PDO), PenggunaModel, MahasiswaModel
├── middleware/   AuthMiddleware (token), CorsMiddleware (header CORS + preflight)
├── helpers/      ResponseHelper (format JSON baku), UploadHelper (validasi & simpan foto)
├── uploads/      mahasiswa/ (file fisik foto profil)
├── .htaccess     URL rewriting / front controller (Apache)
├── .env          DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_KEY, CORS_ALLOWED_ORIGINS, dll.
└── index.php     Entry point & routing pusat
```

### Setup

1. Import `sim_mahasiswa.sql` ke MySQL/MariaDB.
2. Salin folder ke web root, lalu sesuaikan `.env` (`DB_*`, `APP_BASE_URL`, dan **`APP_KEY` wajib diganti** dengan string acak minimal 32 karakter).
3. Pastikan `uploads/mahasiswa/` writable oleh web server.
4. Apache: aktifkan `mod_rewrite` dan `AllowOverride All`. Atau uji cepat dengan `php -S localhost:8000 -t sim_mahasiswa_be`.

### Endpoint

| Method | Endpoint | Auth | Keterangan |
|---|---|---|---|
| POST | `/api/v1/login` | publik | `{username, password}` -> token + profil |
| GET | `/api/v1/verify` | token | Verifikasi token untuk SSO Client (`Authorization: Bearer` atau `?token=`) |
| POST | `/api/v1/logout` | token | Catat logout ke `audit_log` |
| GET | `/api/v1/mahasiswa` | token | List + search (`q`, `status_mahasiswa`, `jenis_kelamin`, `id_program_studi`, `page`, `per_page`) |
| POST | `/api/v1/mahasiswa` | ADMIN/OPERATOR_PRODI | Tambah mahasiswa (multipart, `foto` opsional) |
| GET | `/api/v1/mahasiswa/{id}` | token | Detail mahasiswa |
| PUT | `/api/v1/mahasiswa/{id}` | ADMIN/OPERATOR_PRODI | Update (JSON atau multipart + `_method=PUT`) |
| DELETE | `/api/v1/mahasiswa/{id}` | ADMIN/OPERATOR_PRODI | Hapus data + file foto fisik |
| GET | `/api/v1/fakultas` | token | Daftar fakultas (`?q=`, `?id_universitas=`) |
| GET | `/api/v1/program-studi` | token | Daftar prodi (`?q=`, `?id_fakultas=`, `?status_aktif=`) |
| GET | `/api/v1/program-studi/{id}` | token | Detail prodi |

### Format response

```json
{ "status": true,  "message": "Login berhasil.", "data": { ... } }
{ "status": false, "message": "Data tidak valid.", "errors": { "npm": "NPM wajib diisi." } }
```

### Contoh

```bash
# Login
curl -X POST http://localhost:8000/api/v1/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}'

# Akses endpoint terproteksi
curl http://localhost:8000/api/v1/mahasiswa \
  -H 'Authorization: Bearer <token>'

# Tambah mahasiswa + foto (multipart)
curl -X POST http://localhost:8000/api/v1/mahasiswa \
  -H 'Authorization: Bearer <token>' \
  -F npm=20260099 -F nama_mahasiswa='Mahasiswa Baru' \
  -F jenis_kelamin=L -F id_program_studi=1 -F tanggal_masuk=2026-09-01 \
  -F foto=@foto.jpg

# Update foto (browser tidak mem-parse body PUT -> pakai _method override)
curl -X POST http://localhost:8000/api/v1/mahasiswa/1 \
  -H 'Authorization: Bearer <token>' \
  -F _method=PUT -F npm=20260099 -F nama_mahasiswa='Mahasiswa Baru' \
  -F jenis_kelamin=L -F id_program_studi=1 -F tanggal_masuk=2026-09-01 \
  -F foto=@foto-baru.jpg
```

### Aturan upload foto

- Ekstensi `jpg`/`jpeg`/`png` dan MIME type asli diverifikasi via `finfo` (file tebal disamarkan jadi `.png` ditolak).
- Maksimal 2 MB (`UPLOAD_MAX_SIZE` di `.env`).
- File di-rename unik, folder dibuat otomatis, path disimpan ke kolom `foto`.
- File lama otomatis dihapus saat foto diganti (`update`), saat `hapus_foto=1`, dan saat data dihapus.

### Hak akses (role)

| Role | Akses |
|---|---|
| ADMIN | baca + tulis seluruh data |
| OPERATOR_PRODI | baca + tulis hanya pada program studinya |
| DEKANAT / REKTORAT | baca saja |
| MAHASISWA | baca hanya data miliknya sendiri |

### Keamanan

- Semua query memakai PDO Prepared Statements; identifier kolom/tabel divalidasi.
- Token = `base64url(payload) + "." + HMAC-SHA256(payload, APP_KEY)` — stateless, tanpa tabel tambahan, kadaluarsa `AUTH_TOKEN_TTL`.
- CORS dikontrol lewat `CORS_ALLOWED_ORIGINS` (jangan pakai `*` di produksi bila perlu).
- `.htaccess` menutup akses langsung ke `.env` dan file backup.
- Aktivitas `LOGIN`/`LOGOUT`/`INSERT`/`UPDATE`/`DELETE` dicatat ke tabel `audit_log`.
