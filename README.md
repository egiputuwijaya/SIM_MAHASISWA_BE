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
├── .env          DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_KEY, CORS_ALLOWED_ORIGINS, dll. (TIDAK ikut repo — buat manual, lihat .env.example)
├── .env.example  Template semua kunci konfigurasi
└── index.php     Entry point & routing pusat
```

### Konfigurasi `.env`

File `.env` **tidak ikut di-commit/di-upload** (ada di `.gitignore`). Di hosting, salin
`.env.example` menjadi `.env` lalu isi nilainya:

```bash
cp .env.example .env
```

Daftar kunci lengkap (default dari `config/app.php` & `config/database.php`):

| Kunci | Default | Keterangan |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | Host MySQL hosting (biasanya `localhost`) |
| `DB_PORT` | `3306` | Port MySQL |
| `DB_NAME` | `sim_mahasiswa` | Nama database (di cPanel sering di-prefix: `userxxx_sim_mahasiswa`) |
| `DB_USER` | `root` | User database (di cPanel sering di-prefix: `userxxx_`) |
| `DB_PASS` | *(kosong)* | Password database |
| `APP_NAME` | `SIM Mahasiswa API` | Nama aplikasi (dipakai di token `iss`) |
| `APP_ENV` | `production` | `production` / `development` |
| `APP_DEBUG` | `false` | **Wajib `false` di hosting** — `true` membocorkan pesan error DB ke client |
| `APP_TIMEZONE` | `Asia/Jakarta` | Timezone (IANA) |
| `APP_BASE_URL` | *(kosong)* | **Wajib diisi di hosting**, tanpa `/api/v1`, contoh: `https://domain-anda.com` — dipakai membangun `foto_url` di response |
| `APP_KEY` | *(kosong)* | **Wajib diganti** — string acak minimal 32 karakter (tanda tangan HMAC token) |
| `AUTH_TOKEN_TTL` | `3600` | Masa berlaku token dalam detik |
| `CORS_ALLOWED_ORIGINS` | `*` | Origin frontend yang diizinkan, dipisah koma, contoh: `https://frontend-anda.com,https://appanda.com` — **ganti `*` di produksi** |
| `UPLOAD_MAX_SIZE` | `2048` | Ukuran maksimal foto dalam KB (2048 = 2 MB) |
| `UPLOAD_ALLOWED_EXT` | `jpg,jpeg,png` | Ekstensi foto yang diizinkan |

### Setup lokal (development)

1. Import `if0_43136934_sim_mahasiswa.sql` ke MySQL/MariaDB (`mysql -u root -p < if0_43136934_sim_mahasiswa.sql` atau phpMyAdmin).
2. `cp .env.example .env`, lalu isi `DB_*` (lokal: `DB_HOST=127.0.0.1`, `DB_USER=root`) dan **`APP_KEY` wajib diganti** dengan string acak minimal 32 karakter.
3. Pastikan `uploads/mahasiswa/` writable oleh web server.
4. Jalankan server uji cepat:

   ```bash
   php -S localhost:8000 -t .
   ```

   API bisa diakses di `http://localhost:8000/api/v1`.

### Setup hosting (production)

Contoh langkah di shared hosting cPanel/LiteSpeed:

1. **Import database** — buka **phpMyAdmin** di cPanel, pilih database (di cPanel biasanya ber-prefix username, mis. `userxxx_sim_mahasiswa`), tab **Import**, pilih file `if0_43136934_sim_mahasiswa.sql`, lalu **Go**.
2. **Upload file** — upload seluruh folder isi proyek ke `public_html/` (atau subfolder, mis. `public_html/api/`). Upload juga `.env` (File Manager kadang menyembunyikan file berawalan titik — aktifkan *Show Hidden Files*).
3. **Buat `.env`** — salin `.env.example` jadi `.env`, lalu isi:
   - `DB_HOST=localhost`, `DB_NAME`/`DB_USER` sesuai nama database ber-prefix di cPanel, `DB_PASS`;
   - **`APP_BASE_URL=https://domain-anda.com`** (tanpa `/api/v1`; jika di subfolder: `https://domain-anda.com/api`) — nilai ini dipakai membangun `foto_url`, jika salah semua URL foto akan 404;
   - **`APP_KEY`** string acak ≥32 karakter;
   - `CORS_ALLOWED_ORIGINS=https://frontend-anda.com` (default `*` hanya untuk development);
   - `APP_DEBUG=false`.
4. **Izin folder upload** — di File Manager: klik kanan `uploads/` → **Change Permissions** → **755** (atau 775). Jangan 777. Folder `uploads/mahasiswa/` dibuat otomatis saat upload pertama.
5. **`.htaccess` / mod_rewrite** — shared hosting (Apache/LiteSpeed) biasanya sudah mengaktifkan rewrite otomatis; tidak perlu edit config server. Catatan:
   - **Muncul 500 Internal Server Error?** Sebagian host menolak direktif `Options -Indexes` — hapus/baris tersebut dari `.htaccess`.
   - **Di subfolder** (`public_html/api/`)? Tambahkan `RewriteBase /api/` di dalam blok `<IfModule mod_rewrite.c>`.
   - Pastikan `AllowOverride` mengizinkan `.htaccess` (default hampir semua hosting sudah).
6. **HTTPS** — aktifkan SSL gratis (Let's Encrypt/SSL cPanel) di panel hosting. Token dikirim lewat header `Authorization: Bearer`, jadi produksi wajib `https://`.
7. **Uji** — buka `https://domain-anda.com/api/v1/fakultas` di browser (tanpa token akan balas `401` JSON — itu tanda API hidup), lalu lanjutkan testing lengkap lihat [POSTMAN_TESTING.md](POSTMAN_TESTING.md).

> Alternatif tanpa rewrite: jika host melarang `.htaccess`, semua endpoint tetap bisa
> dipanggil langsung lewat `index.php`, mis. `https://domain-anda.com/index.php/api/v1/login`.

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

Ganti `https://domain-anda.com` dengan domain hosting kamu; untuk lokal pakai
`http://localhost:8000`.

```bash
BASE=https://domain-anda.com/api/v1

# Login
curl -X POST "$BASE/login" \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}'

# Akses endpoint terproteksi
curl "$BASE/mahasiswa" \
  -H 'Authorization: Bearer <token>'

# Tambah mahasiswa + foto (multipart)
curl -X POST "$BASE/mahasiswa" \
  -H 'Authorization: Bearer <token>' \
  -F npm=20260099 -F nama_mahasiswa='Mahasiswa Baru' \
  -F jenis_kelamin=L -F id_program_studi=1 -F tanggal_masuk=2026-09-01 \
  -F foto=@foto.jpg

# Update foto (browser tidak mem-parse body PUT -> pakai _method override)
curl -X POST "$BASE/mahasiswa/1" \
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
- Di hosting, pastikan `uploads/` writable (755) — lihat Setup hosting langkah 4.

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
- `APP_DEBUG=false` di produksi agar pesan error internal tidak bocor ke client.
- Wajib HTTPS di hosting (token dikirim lewat header `Authorization`).
- Aktivitas `LOGIN`/`LOGOUT`/`INSERT`/`UPDATE`/`DELETE` dicatat ke tabel `audit_log`.
