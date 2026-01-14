<?php
require_once __DIR__ . '/../classes/auth.php';

Auth::startSecureSession();

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; base-uri 'self'; frame-ancestors 'none';");

// Verificar se já está logado
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF token
    if (!Auth::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Validação básica
        if (empty($username) || empty($password)) {
            $error = 'Usuário e senha são obrigatórios.';
        } elseif (strlen($username) > 50) {
            $error = 'Nome de usuário muito longo.';
        } else {
            try {
                if (Auth::login($username, $password)) {
                    $success = 'Login realizado com sucesso!';
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Usuário ou senha incorretos.';
                }
            } catch (Exception $e) {
                $error = 'Erro interno. Tente novamente.';
                error_log("Erro no login: " . $e->getMessage());
            }
        }
    }
}

// Gerar CSRF token
$csrfToken = Auth::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Invest - Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="text-primary mb-2">🚀</h2>
                            <h4 class="card-title">Auto Invest</h4>
                            <p class="text-muted">Faça login para acessar seu dashboard</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i>
                                <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i>
                                <?= htmlspecialchars($success) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="post" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Usuário</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-person"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control" 
                                           id="username" 
                                           name="username" 
                                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                           placeholder="Digite seu usuário"
                                           required 
                                           autocomplete="username">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">Senha</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-lock"></i>
                                    </span>
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           placeholder="Digite sua senha"
                                           required 
                                           autocomplete="current-password">
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    Entrar
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                Conexão segura via HTTPS
                            </small>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <small class="text-white-50">
                        &copy; <?= date('Y') ?> Auto Invest. Todos os direitos reservados.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validação do lado do cliente
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Por favor, preencha todos os campos.');
                return false;
            }
            
            if (username.length > 50) {
                e.preventDefault();
                alert('Nome de usuário muito longo.');
                return false;
            }
        });

        // Auto-focus no primeiro campo
        document.getElementById('username').focus();

        // Limpar mensagens de erro ao digitar
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                const alert = this.closest('.card-body').querySelector('.alert-danger');
                if (alert) {
                    alert.remove();
                }
            });
        });
    </script>
</body>
</html>
