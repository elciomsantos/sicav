<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ];

        // PHP 8.5 renomeou PDO::MYSQL_ATTR_INIT_COMMAND para
        // Pdo\Mysql::ATTR_INIT_COMMAND. Usa o novo quando disponivel para
        // evitar a deprecation, mantendo compatibilidade com PHP 8.4.
        $initCommand = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
            ? (int) constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
            : PDO::MYSQL_ATTR_INIT_COMMAND;
        $options[$initCommand] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";

        $this->pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );

        // Alinha o timezone da sessao MySQL ao do PHP para que NOW()/CURRENT_TIMESTAMP
        // sejam coerentes com date()/strtotime() do PHP. Sem isso, datas escritas por
        // PHP (ex.: lock_until do brute force) e comparadas com NOW() no banco ficam
        // deslocadas pelo offset de fuso, quebrando logicas de expiracao/bloqueio.
        $offset = date('P');
        $this->pdo->exec("SET time_zone = " . $this->pdo->quote($offset));
    }

    public function setUsuarioAuditoria(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("SET @usuario_id = :usuario_id");
        $stmt->execute(['usuario_id' => $usuarioId]);
    }

    public function select(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll();
            return [
                'success' => true,
                'status' => 'success',
                'data' => $data,
                'count' => count($data)
            ];
        } catch (PDOException $err) {
            return $this->error($err);
        }
    }

    public function insert(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return [
                'success' => true,
                'status' => 'success',
                'insert_id' => $this->pdo->lastInsertId()
            ];
        } catch (PDOException $err) {
            return $this->error($err);
        }
    }

    public function update(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return [
                'success' => true,
                'status' => 'success',
                'affected_rows' => $stmt->rowCount()
            ];
        } catch (PDOException $err) {
            return $this->error($err);
        }
    }

    public function delete(string $sql, array $params = []): array
    {
        return $this->update($sql, $params);
    }

    public function query(string $sql, array $params = []): array
    {
        $command = strtolower(explode(' ', trim($sql))[0]);

        return match ($command) {
            'select' => $this->select($sql, $params),
            'insert' => $this->insert($sql, $params),
            'update' => $this->update($sql, $params),
            'delete' => $this->delete($sql, $params),
            default => $this->select($sql, $params),
        };
    }

    private function error(PDOException $err): array
    {
        error_log('DB query error: ' . $err->getMessage());
        return [
            'success' => false,
            'status' => 'error',
            'error' => 'Erro interno de banco de dados',
            'error_code' => $err->getCode()
        ];
    }
}
