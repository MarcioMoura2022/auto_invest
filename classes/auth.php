<?php
require_once __DIR__ . '/db.php';

class Auth {
    private static $maxLoginAttempts = 5;
    private static $lockoutTime = 900; // 15 minutos

    public static function isInstalled() {
        return defined('APP_INSTALLED') && APP_INSTALLED === true;
    }

    public static function startSecureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            session_start();
        }
    }

    public static function generateCSRFToken() {
        self::startSecureSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token) {
        self::startSecureSession();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function isRateLimited($ip) {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempts, MAX(created_at) as last_attempt 
            FROM login_attempts 
            WHERE ip_address = ? AND created_at > NOW() - INTERVAL '15 minutes'
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();

        if ($result['attempts'] >= self::$maxLoginAttempts) {
            $lastAttempt = strtotime($result['last_attempt']);
            if (time() - $lastAttempt < self::$lockoutTime) {
                return true; // Rate limited
            }
        }
        return false;
    }

    public static function recordLoginAttempt($ip, $username, $success) {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO login_attempts (ip_address, username, success, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$ip, $username, $success ? 1 : 0]);
    }

    public static function login($username, $password) {
        // Validar entrada
        if (empty($username) || empty($password)) {
            return false;
        }

        // Verificar rate limiting
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (self::isRateLimited($ip)) {
            error_log("Tentativa de login bloqueada por rate limiting: {$ip}");
            return false;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = ? AND active = 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if (!$row) {
                self::recordLoginAttempt($ip, $username, false);
                return false;
            }

            if (password_verify($password, $row['password_hash'])) {
                // Login bem-sucedido
                self::recordLoginAttempt($ip, $username, true);
                
                // Iniciar sessão segura
                self::startSecureSession();
                
                // Regenerar ID da sessão para prevenir session fixation
                session_regenerate_id(true);
                
                $_SESSION['user'] = [
                    'id' => $row['id'],
                    'username' => $row['username'],
                    'login_time' => time(),
                    'last_activity' => time()
                ];
                
                return true;
            } else {
                self::recordLoginAttempt($ip, $username, false);
                return false;
            }
        } catch (Exception $e) {
            error_log("Erro no login: " . $e->getMessage());
            return false;
        }
    }

    public static function isLoggedIn() {
        self::startSecureSession();
        
        if (!isset($_SESSION['user'])) {
            return false;
        }

        // Verificar timeout da sessão (8 horas)
        if (time() - $_SESSION['user']['last_activity'] > 28800) {
            self::logout();
            return false;
        }

        // Atualizar última atividade
        $_SESSION['user']['last_activity'] = time();
        return true;
    }

    public static function logout() {
        self::startSecureSession();
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function getCurrentUser() {
        return $_SESSION['user'] ?? null;
    }

    public static function changePassword($userId, $currentPassword, $newPassword) {
        // Validar nova senha
        if (strlen($newPassword) < 8) {
            throw new Exception("A nova senha deve ter pelo menos 8 caracteres");
        }

        try {
            $db = Database::getInstance();
            
            // Verificar senha atual
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            
            if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
                throw new Exception("Senha atual incorreta");
            }

            // Atualizar senha
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newHash, $userId]);
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao alterar senha: " . $e->getMessage());
            throw $e;
        }
    }
}
