<?php
/**
 * models/Database.php
 * Wrapper class PDO (Singleton) dengan helper Prepared Statement.
 *
 * Semua query lewat method di bawah memakai Prepared Statement sehingga
 * aman dari SQL Injection. Identifier kolom/tabel divalidasi karena
 * tidak bisa di-bind sebagai parameter.
 */

declare(strict_types=1);

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;

    private function __construct()
    {
        /** @var array $config */
        $config = require BASE_PATH . '/config/database.php';

        $this->pdo = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            $config['options']
        );
    }

    /** Ambil instance tunggal. */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Objek PDO asli (butuh untuk fitur khusus). */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ---------------------------------------------------------------------
    // Helper query
    // ---------------------------------------------------------------------

    /** Ambil banyak baris. Contoh: $db->fetchAll('SELECT * FROM mahasiswa WHERE id_program_studi = ?', [1]); */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Ambil satu baris atau null. */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Ambil satu nilai (kolom pertama) atau null. */
    public function fetchColumn(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Jalankan INSERT/UPDATE/DELETE, kembalikan jumlah baris terpengaruh. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** INSERT generik. Mengembalikan ID baris baru. */
    public function insert(string $table, array $data): int
    {
        $table   = $this->identifier($table);
        $columns = [];
        $holders = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->identifier($column);
            $holders[] = ':' . $this->identifier($column);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $holders)
        );

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $data, true);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE generik. Contoh: $db->update('pengguna', ['last_login' => $now], 'id_pengguna = :id', ['id' => 6]); */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $table   = $this->identifier($table);
        $sets    = [];

        foreach ($data as $column => $value) {
            $sets[] = $this->identifier($column) . ' = :' . $this->identifier($column);
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $data, true);
        $this->bind($stmt, $whereParams, true);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** DELETE generik. */
    public function delete(string $table, string $where, array $params = []): int
    {
        $table = $this->identifier($table);
        $sql   = sprintf('DELETE FROM %s WHERE %s', $table, $where);

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params, true);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false;
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /**
     * Bind parameter: mendukung placeholder bernama (:x) maupun posisional (?).
     *
     * @param array $params  [':nama' => nilai] atau [0 => nilai, 1 => nilai]
     * @param bool  $named   true bila placeholder memakai tanda ':' (insert/update)
     */
    private function bind(PDOStatement $stmt, array $params, bool $named = false): void
    {
        foreach ($params as $key => $value) {
            $isNamedKey = is_string($key) && strlen($key) > 0 && $key[0] === ':';
            $placeholder = $isNamedKey ? $key : ($named ? ':' . $key : $key);
            $type = $this->pdoType($value);

            $stmt->bindValue($placeholder, $value, $type);
        }
    }

    private function pdoType($value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return PDO::PARAM_STR;
    }

    /**
     * Validasi identifier (tabel/kolom) — tidak bisa di-bind sebagai parameter.
     * Hanya huruf, angka, dan underscore yang diizinkan.
     */
    private function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Identifier tidak valid: ' . $name);
        }

        return $name;
    }
}
