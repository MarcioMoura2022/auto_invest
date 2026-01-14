<?php
require_once __DIR__ . '/../config/config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            error_log("Erro de conexão com banco: " . $e->getMessage());
            throw new Exception("Erro de conexão com banco de dados");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->pdo;
    }

    // Método seguro para queries simples
    public static function query($sql, $params = []) {
        $db = self::getInstance();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Erro na query: " . $e->getMessage());
            throw new Exception("Erro ao executar consulta no banco");
        }
    }

    // Método para inserções
    public static function insert($table, $data) {
        $db = self::getInstance();
        try {
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) RETURNING id";
            $stmt = $db->prepare($sql);
            $stmt->execute($data);
            
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erro na inserção: " . $e->getMessage());
            throw new Exception("Erro ao inserir dados no banco");
        }
    }

    // Método para atualizações
    public static function update($table, $data, $where, $whereParams = []) {
        $db = self::getInstance();
        try {
            $setClause = implode(' = ?, ', array_keys($data)) . ' = ?';
            $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
            
            $stmt = $db->prepare($sql);
            $params = array_values($data);
            $params = array_merge($params, $whereParams);
            $stmt->execute($params);
            
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Erro na atualização: " . $e->getMessage());
            throw new Exception("Erro ao atualizar dados no banco");
        }
    }

    // Método para transações
    public static function transaction($callback) {
        $db = self::getInstance();
        try {
            $db->beginTransaction();
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
