<?php
/**
 * helpers/ResponseHelper.php
 * Standarisasi format JSON response API:
 *   { "status": bool, "message": string, "data": ... }
 *   { "status": false, "message": string, "errors": ... }
 */

declare(strict_types=1);

class ResponseHelper
{
    /**
     * Response sukses.
     *
     * @param mixed  $data    Payload utama
     * @param string $message Pesan untuk client
     * @param int    $code    HTTP status code
     */
    public static function success($data = null, string $message = 'OK', int $code = 200): void
    {
        self::send([
            'status'  => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    /**
     * Response gagal.
     *
     * @param string      $message Pesan kesalahan
     * @param int         $code    HTTP status code
     * @param array|string|null $errors Detail error validasi (opsional)
     */
    public static function error(string $message, int $code = 400, $errors = null): void
    {
        $payload = [
            'status'  => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        self::send($payload, $code);
    }

    /** Response 401 — token tidak ada/tidak valid. */
    public static function unauthorized(string $message = 'Token tidak valid atau kadaluarsa.'): void
    {
        self::error($message, 401);
    }

    /** Response 403 — token valid tapi tidak punya akses. */
    public static function forbidden(string $message = 'Anda tidak memiliki akses ke resource ini.'): void
    {
        self::error($message, 403);
    }

    /** Response 404 — resource tidak ditemukan. */
    public static function notFound(string $message = 'Data tidak ditemukan.'): void
    {
        self::error($message, 404);
    }

    /**
     * Kirim payload JSON lalu hentikan eksekusi.
     *
     * @param array $payload
     */
    public static function send(array $payload, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        exit;
    }
}
