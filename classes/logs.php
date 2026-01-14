<?php
require_once __DIR__ . '/db.php';

class Logs {
    public static function add($type, $message) {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO logs_general(log_type, message, created_at) VALUES(?, ?, NOW())");
        $stmt->execute([$type, $message]);
    }
}
