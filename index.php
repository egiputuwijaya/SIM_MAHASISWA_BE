<?php
/**
 * index.php
 * Entry point utama & Routing pusat API (Front Controller).
 *
 * Endpoint:
 *   POST   /api/v1/login                    -> AuthController@login       (publik)
 *   GET    /api/v1/verify                   -> AuthController@verify      (publik, untuk SSO Client)
 *   POST   /api/v1/logout                   -> AuthController@logout      (terproteksi)
 *
 *   GET    /api/v1/mahasiswa                -> MahasiswaController@index  (terproteksi)
 *   GET    /api/v1/mahasiswa/{id}           -> MahasiswaController@show   (terproteksi)
 *   POST   /api/v1/mahasiswa                -> MahasiswaController@store  (terproteksi, multipart)
 *   PUT    /api/v1/mahasiswa/{id}           -> MahasiswaController@update (terproteksi, multipart)
 *   DELETE /api/v1/mahasiswa/{id}           -> MahasiswaController@destroy(terproteksi)
 *
 *   GET    /api/v1/fakultas                 -> ProdiController@fakultas   (terproteksi)
 *   GET    /api/v1/program-studi            -> ProdiController@index      (terproteksi)
 *   GET    /api/v1/program-studi/{id}       -> ProdiController@show       (terproteksi)
 *
 * Catatan upload (multipart):
 *   Browser/HTTP tidak mem-parse body PUT, jadi kirim sebagai POST dengan
 *   field tambahan `_method=PUT` (method override sudah ditangani di bawah).
 */

declare(strict_types=1);

define('BASE_PATH', __DIR__);

// --- Bootstrap -------------------------------------------------------------
require BASE_PATH . '/config/app.php';
require BASE_PATH . '/config/database.php';
require BASE_PATH . '/helpers/ResponseHelper.php';
require BASE_PATH . '/helpers/UploadHelper.php';
require BASE_PATH . '/models/Database.php';
require BASE_PATH . '/models/PenggunaModel.php';
require BASE_PATH . '/models/MahasiswaModel.php';
require BASE_PATH . '/middleware/CorsMiddleware.php';
require BASE_PATH . '/middleware/AuthMiddleware.php';
require BASE_PATH . '/controllers/AuthController.php';
require BASE_PATH . '/controllers/MahasiswaController.php';
require BASE_PATH . '/controllers/ProdiController.php';

// --- Request helpers -------------------------------------------------------

/** Body JSON (application/json) atau form ($_POST) sebagai array. */
function req_body(): array
{
    static $body = null;

    if ($body !== null) {
        return $body;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw  = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            ResponseHelper::error('Body JSON tidak valid.', 400);
        }

        $body = $data;
    } else {
        $body = $_POST;
    }

    return $body;
}

/** Query string sebagai array. */
function req_query(): array
{
    return $_GET;
}

/** Nilai request (query > body) — berguna untuk method override DELETE. */
function req_input(string $key, $default = null)
{
    $body = req_body();

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $body[$key] ?? $default;
}

// --- Method override (multipart PUT/PATCH/DELETE) --------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// HEAD diperlakukan seperti GET (header saja, body dibuang oleh PHP)
if ($method === 'HEAD') {
    $method = 'GET';
}

if ($method === 'POST') {
    $override = strtoupper((string) ($_POST['_method'] ?? ''));
    if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
        $method = $override;
    }
}

// --- CORS (termasuk preflight OPTIONS) -------------------------------------
CorsMiddleware::handle();

// --- Routing ---------------------------------------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? rawurldecode($uri) : '/';
$uri = '/' . ltrim($uri, '/');
if ($uri !== '/') {
    $uri = rtrim($uri, '/');
}

$routes = [
    // Auth / SSO
    ['POST',   '#^/api/v1/login$#',                          [AuthController::class, 'login'],      false],
    ['GET',    '#^/api/v1/verify$#',                         [AuthController::class, 'verify'],     false],
    ['POST',   '#^/api/v1/logout$#',                         [AuthController::class, 'logout'],     true],

    // Mahasiswa (CRUD + foto)
    ['GET',    '#^/api/v1/mahasiswa$#',                      [MahasiswaController::class, 'index'], true],
    ['POST',   '#^/api/v1/mahasiswa$#',                      [MahasiswaController::class, 'store'], true],
    ['GET',    '#^/api/v1/mahasiswa/(\d+)$#',                [MahasiswaController::class, 'show'],  true],
    ['PUT',    '#^/api/v1/mahasiswa/(\d+)$#',                [MahasiswaController::class, 'update'], true],
    ['PATCH',  '#^/api/v1/mahasiswa/(\d+)$#',                [MahasiswaController::class, 'update'], true],
    ['DELETE', '#^/api/v1/mahasiswa/(\d+)$#',                [MahasiswaController::class, 'destroy'], true],

    // Program studi & fakultas
    ['GET',    '#^/api/v1/fakultas$#',                       [ProdiController::class, 'fakultas'],  true],
    ['GET',    '#^/api/v1/program-studi$#',                  [ProdiController::class, 'index'],     true],
    ['GET',    '#^/api/v1/program-studi/(\d+)$#',            [ProdiController::class, 'show'],      true],
];

$pathMatched  = false;
$allowedVerbs = [];

try {
    foreach ($routes as $route) {
        [$verb, $pattern, $handler, $protected] = $route;

        if (!preg_match($pattern, $uri, $matches)) {
            continue;
        }

        $pathMatched = true;
        $allowedVerbs[] = $verb;

        if ($verb !== $method) {
            continue;
        }

        // Cocok: jalankan handler
        array_shift($matches); // buang seluruh cocokan, sisakan grup

        $params = array_map(static function ($value) {
            return is_string($value) && ctype_digit($value) ? (int) $value : $value;
        }, $matches);

        $user = null;
        if ($protected) {
            $user = AuthMiddleware::requireAuth(); // 401 + exit bila token tidak valid
        }

        // Instansiasi controller, lalu panggil method handler-nya
        [$class, $action] = $handler;
        $controller = new $class();
        $controller->{$action}(...array_merge([$user], $params));
        exit;
    }

    if ($pathMatched) {
        $allowedVerbs = array_values(array_unique($allowedVerbs));
        header('Allow: ' . implode(', ', $allowedVerbs));
        ResponseHelper::error('Metode ' . $method . ' tidak diizinkan untuk resource ini.', 405);
    }

    ResponseHelper::error('Endpoint tidak ditemukan: ' . $method . ' ' . $uri, 404);
} catch (UploadException $e) {
    ResponseHelper::error($e->getMessage(), 422);
} catch (PDOException $e) {
    // Pelanggaran unique constraint (npm/username ganda)
    if ($e->getCode() === '23000') {
        ResponseHelper::error('Data sudah ada (duplikat). Periksa NPM/username.', 409);
    }

    if (app_config('debug')) {
        ResponseHelper::error('Database error: ' . $e->getMessage(), 500);
    }

    error_log('[SIM-MHS] PDOException: ' . $e->getMessage());
    ResponseHelper::error('Terjadi kesalahan pada server database.', 500);
} catch (RuntimeException $e) {
    ResponseHelper::error($e->getMessage(), 400);
} catch (Throwable $e) {
    if (app_config('debug')) {
        ResponseHelper::error('Server error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 500);
    }

    error_log('[SIM-MHS] Throwable: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ResponseHelper::error('Terjadi kesalahan pada server.', 500);
}
