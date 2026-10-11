# Panduan Testing API dengan Postman

Panduan lengkap menguji seluruh endpoint **SIM Mahasiswa BE** menggunakan Postman.

---

## 1. Prasyarat

1. Database sudah di-import (`if0_43136934_sim_mahasiswa.sql`).
2. File `.env` sudah dikonfigurasi (`DB_*`, `APP_KEY`, `APP_BASE_URL`) — salin dari
   `.env.example` bila belum ada (lihat README § Konfigurasi `.env`).
3. API bisa diakses, salah satu:

   ```bash
   # Lokal — PHP built-in server (cepat untuk uji)
   php -S localhost:8000 -t .

   # Lokal — Apache (mod_rewrite aktif, AllowOverride All)

   # Hosting — API sudah online di https://domain-anda.com/api/v1
   # (tidak perlu menjalankan server apa pun; ikuti README § Setup hosting)
   ```

4. Base URL API:
   - **Lokal:** `http://localhost:8000/api/v1`
   - **Hosting:** `https://domain-anda.com/api/v1` (di subfolder: `https://domain-anda.com/api/api/v1` tidak umum — sesuaikan dengan path deploy kamu; yang penting diakhiri `/api/v1`)

### Akun default

| Role | Username | Catatan |
|---|---|---|
| ADMIN | `admin` | Full akses (baca + tulis) |
| OPERATOR_PRODI | `operator.ti` | Hanya prodi miliknya |
| DEKANAT | `dekanat.ft` | Baca saja |
| REKTORAT | `rektorat` | Baca saja |
| MAHASISWA | `20260001` | Baca data miliknya sendiri |

> Catatan: dump SQL hanya menyimpan hash password untuk akun `Admin`; akun lain
> berisi placeholder `REPLACE_WITH_PASSWORD_HASH`. Jika login akun lain gagal,
> generate hash baru lalu update kolom `password_hash`:
>
> ```bash
> php -r "echo password_hash('password_baru', PASSWORD_BCRYPT), PHP_EOL;"
> ```

---

## 2. Setup Postman

### 2.1. Environment

Buat environment (mis. `SIM Mahasiswa Local` / `SIM Mahasiswa Hosting`) dengan
variabel:

| Variable | Value |
|---|---|
| `base_url` | Lokal: `http://localhost:8000/api/v1` — Hosting: `https://domain-anda.com/api/v1` |
| `token` | *(diisi otomatis saat login — lihat §2.3)* |

> **Hosting:** gunakan **HTTPS** (`https://`), bukan `http://` — token dikirim lewat
> header `Authorization` dan sebagian host memblokir request plain-HTTP. Cek
> `APP_BASE_URL` di `.env` server untuk memastikan domain yang benar.
>
> **Subfolder:** bila API dipasang di subfolder (mis. `public_html/sim-mhs`),
> `base_url` = URL publik folder itu + `/api/v1`, contoh:
> `https://domain-anda.com/sim-mhs/api/v1`.

### 2.2. Collection-level Authorization

Buka **Collection → Authorization**:

- **Type:** `Bearer Token`
- **Token:** `{{token}}`

Semua request dalam collection otomatis memakai header `Authorization: Bearer {{token}}`.
Request login/verify tidak perlu token (endpoint publik — token kosong aman, asal
jangan kirim header `Authorization` kosong yang tidak valid).

### 2.3. Auto-save token setelah login

Buka request **Login** → tab **Tests**, tempel script ini:

```javascript
pm.test("Login berhasil (200)", function () {
    pm.response.to.have.status(200);
});

const json = pm.response.json();
pm.test("Response punya field token", function () {
    pm.expect(json.status).to.be.true;
    pm.expect(json.data).to.have.property("token");
});

// Simpan token untuk request lain
pm.environment.set("token", json.data.token);
pm.environment.set("token_exp", json.data.expires_at);
```

Setiap kali request **Login** dijalankan, `{{token}}` di environment ikut ter-update.

### 2.4. Validasi format response (opsional)

Tambahkan di tab **Tests** request mana pun:

```javascript
pm.test("Format JSON baku", function () {
    const json = pm.response.json();
    pm.expect(json).to.have.all.keys(["status", "message"]); // + data/errors
    pm.expect(json.status).to.be.a("boolean");
    pm.expect(json.message).to.be.a("string");
});
```

---

## 3. Daftar Request

### 3.1. Autentikasi

#### POST `{{base_url}}/login` — publik

- **Headers:** `Content-Type: application/json`
- **Body → raw → JSON:**

```json
{
    "username": "admin",
    "password": "admin123"
}
```

**Sukses (200):**

```json
{
    "status": true,
    "message": "Login berhasil.",
    "data": {
        "token": "eyJ1aWQiOjZ9.xxxxx.yyyyy",
        "token_type": "Bearer",
        "expires_in": 3600,
        "expires_at": "2026-10-10 15:00:00",
        "profil": { "id_pengguna": 6, "username": "Admin", "role": { "kode": "ADMIN", "nama": "Admin" } }
    }
}
```

**Gagal:** `401` (username/password salah), `403` (akun nonaktif), `422` (field kosong).

#### GET `{{base_url}}/verify` — publik (untuk cek token)

- **Params:** boleh kosong (pakai header Bearer), atau `?token=<token>`
- **Sukses (200):** `{ "status": true, "message": "Token valid.", "data": { "valid": true, "expires_at": "...", "profil": {...} } }`
- **Gagal:** `401` token tidak ada / tidak valid / kadaluarsa.

#### POST `{{base_url}}/logout` — terproteksi

- Tidak perlu body.
- **Sukses (200):** `{ "status": true, "message": "Logout berhasil. ...", "data": { "logged_out": true } }`
- Catatan: token bersifat stateless — server hanya mencatat ke `audit_log`; token
  tetap sah sampai `exp`. Hapus juga `{{token}}` di Postman jika ingin simulasi logout penuh:
  `pm.environment.unset("token");`

---

### 3.2. Mahasiswa (CRUD)

#### GET `{{base_url}}/mahasiswa` — list + search + pagination

**Params (semua opsional):**

| Key | Contoh | Keterangan |
|---|---|---|
| `q` | `contoh` | Pencarian bebas (npm/nama) |
| `status_mahasiswa` | `Aktif` | `Aktif`, `Cuti`, `Lulus`, `Mengundurkan Diri`, `Drop Out`, `Tidak Aktif` |
| `jenis_kelamin` | `L` | `L` / `P` |
| `id_program_studi` | `1` | Filter prodi (dipaksa untuk OPERATOR_PRODI) |
| `page` | `1` | Halaman (default 1) |
| `per_page` | `10` | Item per halaman (default 10) |

**Sukses (200):**

```json
{
    "status": true,
    "message": "Data mahasiswa berhasil diambil.",
    "data": {
        "items": [ { "id_mahasiswa": 1, "npm": "20260001", "nama_mahasiswa": "Contoh Mahasiswa", "...": "..." } ],
        "pagination": { "page": 1, "per_page": 10, "total": 1, "last_page": 1 }
    }
}
```

#### GET `{{base_url}}/mahasiswa/{{id_mahasiswa}}`

- Contoh: `{{base_url}}/mahasiswa/1`
- **200** = data ditemukan; **404** tidak ada; **403** di luar scope role.

#### POST `{{base_url}}/mahasiswa` — tambah (role: ADMIN / OPERATOR_PRODI)

- **Body → form-data** (karena ada upload foto opsional), atau raw JSON bila tanpa foto.

| Key | Tipe | Wajib | Nilai |
|---|---|---|---|
| `npm` | text | ✔ | maks 30 karakter, unik |
| `nama_mahasiswa` | text | ✔ | maks 200 karakter |
| `id_program_studi` | text | ✔ | angka, harus ada di DB |
| `jenis_kelamin` | text | ✔ | `L` / `P` |
| `tanggal_masuk` | text | ✔ | format `YYYY-MM-DD` |
| `tanggal_lahir` | text | – | format `YYYY-MM-DD` |
| `tempat_lahir` | text | – | |
| `alamat` | text | – | |
| `status_mahasiswa` | text | – | default `Aktif` |
| `foto` | **File** | – | `jpg/jpeg/png`, maks 2 MB |

**Sukses:** `201 Created` + data mahasiswa.
**Gagal:** `422` validasi (field `errors`), `409` NPM ganda, `403` role tanpa akses / prodi di luar scope.

#### PUT `{{base_url}}/mahasiswa/{{id}}` — update tanpa foto (JSON)

- **Headers:** `Content-Type: application/json`
- **Body → raw → JSON** (partial update — field yang tidak dikirim tidak diubah):

```json
{
    "nama_mahasiswa": "Nama Baru",
    "status_mahasiswa": "Cuti"
}
```

**Sukses (200):** data terbaru. **400** bila body kosong/tidak ada perubahan.

#### POST `{{base_url}}/mahasiswa/{{id}}` + `_method=PUT` — update DENGAN foto

> Alasan: PHP tidak mem-parse body `multipart/form-data` pada request PUT, jadi
> kirim sebagai **POST** dengan field tambahan **`_method=PUT`** (method override
> ditangani `index.php`).

- **Body → form-data:**

| Key | Tipe | Nilai |
|---|---|---|
| `_method` | text | `PUT` |
| `npm` | text | `20260001` |
| `nama_mahasiswa` | text | `Nama Baru` |
| `id_program_studi` | text | `1` |
| `jenis_kelamin` | text | `L` |
| `tanggal_masuk` | text | `2026-09-01` |
| `foto` | File | foto baru (file lama otomatis dihapus) |

- Hapus foto tanpa mengganti: kirim `_method=PUT` + `hapus_foto=1` (tanpa field `foto`).

> `PATCH {{base_url}}/mahasiswa/{{id}}` juga diterima sebagai alias dari `PUT` (lihat route di `index.php`).

#### DELETE `{{base_url}}/mahasiswa/{{id}}` — role: ADMIN / OPERATOR_PRODI

- Tidak perlu body.
- **Sukses (200):** `{ "status": true, "message": "Mahasiswa berhasil dihapus.", "data": { "id_mahasiswa": 1 } }`
- File foto fisik ikut dihapus dari `uploads/mahasiswa/`.

---

### 3.3. Fakultas & Program Studi

| Request | Params | Keterangan |
|---|---|---|
| GET `{{base_url}}/fakultas` | `q`, `id_universitas` | `{ items, total }` |
| GET `{{base_url}}/fakultas/{{id}}` | – | detail 1 fakultas; **404** bila tidak ada |
| POST `{{base_url}}/fakultas` | – | ADMIN — body: `id_universitas`, `kode_fakultas`, `nama_fakultas` |
| PUT `{{base_url}}/fakultas/{{id}}` | – | ADMIN — partial update |
| DELETE `{{base_url}}/fakultas/{{id}}` | – | ADMIN — **422** bila masih ada prodi/pengguna |
| GET `{{base_url}}/program-studi` | `q`, `id_fakultas`, `status_aktif` | `{ items, total }` |
| GET `{{base_url}}/program-studi/{{id}}` | – | detail 1 prodi; **404** bila tidak ada |
| POST `{{base_url}}/program-studi` | – | ADMIN — body: `id_fakultas`, `kode_program_studi`, `nama_program_studi`, `jenjang`, `status_aktif` |
| PUT `{{base_url}}/program-studi/{{id}}` | – | ADMIN — partial update |
| DELETE `{{base_url}}/program-studi/{{id}}` | – | ADMIN — **422** bila masih ada mahasiswa/pengguna |

### 3.4. Universitas (baru)

| Request | Params | Keterangan |
|---|---|---|
| GET `{{base_url}}/universitas` | `q` | `{ items, total }` + `jumlah_fakultas` |
| GET `{{base_url}}/universitas/{{id}}` | – | **404** bila tidak ada |
| POST `{{base_url}}/universitas` | – | ADMIN — body: `kode_universitas`, `nama_universitas`, opsional `slogan`, `alamat`, `kota`, `provinsi`, `kode_pos`, `website`, `email`, `telepon` |
| PUT `{{base_url}}/universitas/{{id}}` | – | ADMIN — partial update |
| DELETE `{{base_url}}/universitas/{{id}}` | – | ADMIN — **422** bila masih ada fakultas/pengguna |

Contoh tambah universitas (JSON):

```json
{
    "kode_universitas": "UMB2",
    "nama_universitas": "Universitas Uji Coba",
    "kota": "Bengkulu"
}
```

### 3.5. Pengguna & Roles (baru, ADMIN)

| Request | Params | Keterangan |
|---|---|---|
| GET `{{base_url}}/pengguna` | `q`, `status_aktif`, `id_role`, `page`, `per_page` | list + pagination; **403** bila bukan ADMIN |
| GET `{{base_url}}/pengguna/{{id}}` | – | detail tanpa `password_hash` |
| POST `{{base_url}}/pengguna` | – | body: `username`, `password` (min 6), `nama_lengkap`, `id_role`, opsional `email`, `no_hp`, `status_aktif`, `id_universitas`, `id_fakultas`, `id_program_studi` |
| PUT `{{base_url}}/pengguna/{{id}}` | – | partial; `password` kosong = tidak diganti |
| DELETE `{{base_url}}/pengguna/{{id}}` | – | **403** bila hapus akun sendiri |
| GET `{{base_url}}/roles` | – | semua role terautentikasi — `{ items, total }` + `jumlah_pengguna` |

Catatan:

- `password` dikirim plaintext, disimpan server sebagai bcrypt hash; hash tidak pernah muncul di response.
- Username duplikat -> **422** `errors.username`; role tidak ada -> **422** `errors.id_role`.

### 3.6. Audit Log (baru, ADMIN)

| Request | Params | Keterangan |
|---|---|---|
| GET `{{base_url}}/audit-log` | `aksi`, `tabel_nama`, `id_pengguna`, `dari`, `sampai`, `page`, `per_page` | riwayat aktivitas; `aksi` salah -> **422** |

`aksi` yang valid: `LOGIN`, `LOGOUT`, `INSERT`, `UPDATE`, `DELETE`, `VIEW`. Format tanggal `YYYY-MM-DD`.

### 3.7. Profil Mahasiswa — view (baru)

| Request | Params | Keterangan |
|---|---|---|
| GET `{{base_url}}/profil-mahasiswa` | `q`, `status_mahasiswa`, `page`, `per_page` | dari view `v_profil_mahasiswa` (data diri + prodi/fakultas/universitas), scope per role |
| GET `{{base_url}}/profil-mahasiswa/{{id}}` | – | **404** bila tidak ada; **403** di luar scope role |

---

## 4. Skenario Test (Checklist)

### Autentikasi

- [ ] Login dengan kredensial benar → `200` + token tersimpan otomatis.
- [ ] Login password salah → `401` + `"Username atau password salah."`
- [ ] Login tanpa `username` → `422` + `errors.username`.
- [ ] Akses `GET /mahasiswa` **tanpa** token → `401` `"Token tidak ditemukan..."`.
- [ ] Akses dengan token diubah 1 karakter → `401` `"Token tidak valid atau kadaluarsa."`.
- [ ] `GET /verify` dengan token valid → `200` `"Token valid."`.
- [ ] `POST /logout` → `200` dan muncul baris `LOGOUT` di tabel `audit_log`.

### CRUD Mahasiswa (login sebagai `admin`)

- [ ] `GET /mahasiswa` → `200` + `items` + `pagination`.
- [ ] `GET /mahasiswa?q=20260001` → hanya cocok NPM/nama tersebut.
- [ ] `POST /mahasiswa` lengkap + foto → `201`, file muncul di `uploads/mahasiswa/`.
- [ ] `POST /mahasiswa` tanpa `npm` → `422` `errors.npm = "NPM wajib diisi."`
- [ ] `POST /mahasiswa` dengan NPM sama → `409` atau `422` `"NPM sudah terdaftar."`
- [ ] `POST /mahasiswa` `tanggal_masuk=10-10-2026` → `422` (format harus `YYYY-MM-DD`).
- [ ] `POST /mahasiswa` foto `.txt` di-rename jadi `.png` → `422` (MIME check).
- [ ] `POST /mahasiswa` foto > 2 MB → `422` (ukuran maksimal).
- [ ] `GET /mahasiswa/{id}` → `200`.
- [ ] `PUT /mahasiswa/{id}` JSON partial → `200`, field tak dikirim tetap sama.
- [ ] `POST /mahasiswa/{id}` dengan `_method=PUT` + foto baru → `200`, file lama terhapus.
- [ ] `POST /mahasiswa/{id}` `_method=PUT` + `hapus_foto=1` → `200`, `foto` jadi `null`.
- [ ] `PUT` tanpa perubahan apa pun → `400` `"Tidak ada perubahan yang dikirim."`
- [ ] `DELETE /mahasiswa/{id}` → `200`, foto fisik ikut terhapus.
- [ ] `DELETE /mahasiswa/{id}` dua kali → `404`.

### Hak akses per role

- [ ] `OPERATOR_PRODI`: `GET /mahasiswa` hanya menampilkan prodi miliknya.
- [ ] `OPERATOR_PRODI`: tambah mahasiswa di prodi lain → `403`.
- [ ] `DEKANAT` / `REKTORAT`: `POST /mahasiswa` → `403` `"Role ... tidak memiliki akses"`.
- [ ] `MAHASISWA`: `GET /mahasiswa` hanya berisi datanya sendiri.
- [ ] `MAHASISWA`: `GET /mahasiswa/{id}` milik orang lain → `403`.

### Master data & pengguna (baru)

- [ ] `GET /universitas` → `200` + `jumlah_fakultas`.
- [ ] `POST /universitas` (ADMIN) → `201`; kode sama -> `422`/`409`.
- [ ] `DELETE /universitas/1` (punya fakultas) → `422`.
- [ ] `POST /fakultas` & `/program-studi` (ADMIN) → `201`; `DELETE` berpenghuni -> `422`.
- [ ] `GET /roles` (semua role) → `200` + `jumlah_pengguna`.
- [ ] `POST /pengguna` (ADMIN) → `201`, **tanpa** `password_hash` di response.
- [ ] `PUT /pengguna/{id}` tanpa `password` -> data terupdate, password lama tetap berlaku.
- [ ] `DELETE /pengguna/{id}` akun sendiri -> `403`.
- [ ] Non-ADMIN (mis. `operator.ti`) akses `/pengguna` atau `/audit-log` → `403`.
- [ ] `GET /audit-log?aksi=INSERT` → `200`; `aksi=SALAH` → `422`.
- [ ] `GET /profil-mahasiswa` → `200` + field prodi/fakultas/universitas.

### Routing & error

- [ ] `GET /api/v1/mahasiswa` (salah method, mis. `POST` tanpa data) → `405` + header `Allow`.
- [ ] `GET /api/v1/tidak-ada` → `404` `"Endpoint tidak ditemukan..."`.

---

## 5. Kode Error yang Dipakai

| Kode | Arti | Contoh kasus |
|---|---|---|
| 200 | OK | read/update sukses |
| 201 | Created | tambah mahasiswa |
| 400 | Request salah | body JSON invalid, tidak ada perubahan |
| 401 | Belum autentikasi | token hilang / tidak valid / kadaluarsa |
| 403 | Terlarang | role tidak berhak, scope prodi berbeda |
| 404 | Tidak ditemukan | id salah, endpoint salah |
| 405 | Method tidak diizinkan | method tidak sesuai route |
| 409 | Konflik | NPM / username duplikat |
| 422 | Validasi gagal | field wajib kosong, format tanggal salah, upload ditolak |
| 500 | Server error | APP_KEY kosong, DB error (bila `debug`) |

---

## 6. Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| `401 Token tidak ditemukan` padahal sudah isi token | Cek collection **Authorization** = Bearer `{{token}}`, atau environment `SIM Mahasiswa Local` belum dipilih (kanan atas Postman) |
| `401 Token tidak valid` | Token kadaluarsa (`AUTH_TOKEN_TTL`, default 3600 detik) — login ulang |
| `500 APP_KEY belum diatur` | Isi `APP_KEY` di `.env` (minimal 32 karakter acak) |
| `500 Terjadi kesalahan pada server database` | Cek `DB_*` di `.env` & DB sudah import |
| Foto tidak masuk, tapi field `foto` kosong | Pastikan body bertipe **form-data** dan key `foto` bertipe **File**, bukan text |
| Update dengan foto gagal / tidak terbaca | Harus POST + `_method=PUT` (bukan PUT multipart) |
| CORS error dari browser (bukan Postman) | Set `CORS_ALLOWED_ORIGINS` di `.env` |
| Server tidak merespons | Pastikan `php -S localhost:8000 -t .` berjalan dan `base_url` cocok dengan port |
