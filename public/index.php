<?php
require_once __DIR__ . '/../classes/auth.php';
require_once __DIR__ . '/../classes/db.php';

// Verificar autenticação
Auth::requireLogin();

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; base-uri 'self'; frame-ancestors 'none';");

$user = Auth::getCurrentUser();
$pdo = Database::getInstance();

// Gerar CSRF token para formulários
$csrfToken = Auth::generateCSRFToken();

// Processar ações
$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::validateCSRFToken($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'manual_rebalance':
                try {
                    $aporte = floatval($_POST['aporte'] ?? 0);
                    if ($aporte < 0) throw new Exception("Aporte não pode ser negativo");
                    
                    // Aqui você pode chamar o rebalanceamento manual
                    $message = "Rebalanceamento manual solicitado com aporte de {$aporte} USDT";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
                break;
                
            case 'change_password':
                try {
                    $currentPass = $_POST['current_password'] ?? '';
                    $newPass = $_POST['new_password'] ?? '';
                    $confirmPass = $_POST['confirm_password'] ?? '';
                    
                    if ($newPass !== $confirmPass) {
                        throw new Exception("As senhas não coincidem");
                    }
                    
                    if (strlen($newPass) < 8) {
                        throw new Exception("A nova senha deve ter pelo menos 8 caracteres");
                    }
                    
                    Auth::changePassword($user['id'], $currentPass, $newPass);
                    $message = "Senha alterada com sucesso!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro ao alterar senha: " . $e->getMessage();
                    $messageType = 'danger';
                }
                break;
        }
    }
}

// Buscar dados do dashboard
try {
    $balances = Database::query("
        SELECT symbol, SUM(quantity) AS qty, AVG(price_usdt) AS avg_price, 
               SUM(balance_usdt) AS total, MAX(execution_time) as last_update
        FROM portfolio_balances 
        GROUP BY symbol 
        ORDER BY total DESC
    ")->fetchAll();
    
    $orders = Database::query("
        SELECT id, symbol, side, type, quantity, price, status, created_at 
        FROM orders_executed 
        ORDER BY id DESC 
        LIMIT 20
    ")->fetchAll();
    
    $rebalances = Database::query("
        SELECT id, total_capital_usdt, executed_at, status, notes 
        FROM rebalance_logs 
        ORDER BY id DESC 
        LIMIT 10
    ")->fetchAll();
    
    // Estatísticas gerais
    $stats = Database::query("
        SELECT
            (SELECT COUNT(DISTINCT symbol) FROM portfolio_balances) as total_assets,
            (SELECT COALESCE(SUM(balance_usdt), 0) FROM portfolio_balances) as total_portfolio,
            (SELECT COUNT(*) FROM orders_executed) as total_orders,
            (SELECT COUNT(*) FROM rebalance_logs WHERE status = 'success') as successful_rebalances
    ")->fetch();
    
} catch (Exception $e) {
    error_log("Erro ao carregar dashboard: " . $e->getMessage());
    $balances = $orders = $rebalances = [];
    $stats = ['total_assets' => 0, 'total_portfolio' => 0, 'total_orders' => 0, 'successful_rebalances' => 0];
}

$labels = array_column($balances, 'symbol');
$data = array_map('floatval', array_column($balances, 'total'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Invest - Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            border-radius: 10px;
            margin: 2px 0;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
            border-radius: 15px 15px 0 0 !important;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white">🚀 Auto Invest</h4>
                        <small class="text-white-50">Dashboard</small>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#dashboard">
                                <i class="bi bi-speedometer2 me-2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#portfolio">
                                <i class="bi bi-pie-chart me-2"></i>
                                Portfólio
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#orders">
                                <i class="bi bi-list-ul me-2"></i>
                                Ordens
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#settings">
                                <i class="bi bi-gear me-2"></i>
                                Configurações
                            </a>
                        </li>
                    </ul>
                    
                    <hr class="text-white-50">
                    
                    <div class="text-center">
                        <small class="text-white-50">
                            Logado como: <strong><?= htmlspecialchars($user['username']) ?></strong>
                        </small>
                        <br>
                        <a href="logout.php" class="btn btn-outline-light btn-sm mt-2">
                            <i class="bi bi-box-arrow-right me-1"></i>
                            Sair
                        </a>
                    </div>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rebalanceModal">
                            <i class="bi bi-arrow-repeat me-1"></i>
                            Rebalancear
                        </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-white-50 text-uppercase mb-1">
                                            Total do Portfólio
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-white">
                                            $<?= number_format($stats['total_portfolio'] ?? 0, 2) ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-currency-dollar fa-2x text-white-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-white-50 text-uppercase mb-1">
                                            Ativos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-white">
                                            <?= $stats['total_assets'] ?? 0 ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-pie-chart fa-2x text-white-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-white-50 text-uppercase mb-1">
                                            Total de Ordens
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-white">
                                            <?= $stats['total_orders'] ?? 0 ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-list-ul fa-2x text-white-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-white-50 text-uppercase mb-1">
                                            Rebalanceamentos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-white">
                                            <?= $stats['successful_rebalances'] ?? 0 ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-arrow-repeat fa-2x text-white-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Gráfico da Carteira -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Composição da Carteira</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="pieChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de Ativos -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-coin me-2"></i>Ativos</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Ativo</th>
                                                <th>Qtd.</th>
                                                <th>Preço Médio</th>
                                                <th>Total (USDT)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($balances as $b): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($b['symbol']) ?></strong></td>
                                                    <td><?= number_format($b['qty'], 6, ',', '.') ?></td>
                                                    <td>$<?= number_format($b['avg_price'], 6, ',', '.') ?></td>
                                                    <td><strong>$<?= number_format($b['total'], 2, ',', '.') ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <!-- Últimos Rebalanceamentos -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Últimos Rebalanceamentos</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Capital (USDT)</th>
                                                <th>Data</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($rebalances as $r): ?>
                                                <tr>
                                                    <td>#<?= $r['id'] ?></td>
                                                    <td>$<?= number_format($r['total_capital_usdt'], 2, ',', '.') ?></td>
                                                    <td><?= date('d/m/Y H:i', strtotime($r['executed_at'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $r['status']==='success'?'success':'secondary' ?>">
                                                            <?= htmlspecialchars($r['status']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Últimas Ordens -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Últimas Ordens</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Símbolo</th>
                                                <th>Lado</th>
                                                <th>Qtd.</th>
                                                <th>Preço</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($orders as $o): ?>
                                                <tr>
                                                    <td>#<?= $o['id'] ?></td>
                                                    <td><strong><?= htmlspecialchars($o['symbol']) ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-<?= $o['side']==='BUY'?'success':'danger' ?>">
                                                            <?= htmlspecialchars($o['side']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= number_format($o['quantity'], 6, ',', '.') ?></td>
                                                    <td>$<?= number_format($o['price'], 6, ',', '.') ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $o['status']==='FILLED'?'success':'warning' ?>">
                                                            <?= htmlspecialchars($o['status']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal de Rebalanceamento -->
    <div class="modal fade" id="rebalanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rebalanceamento Manual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="manual_rebalance">
                        
                        <div class="mb-3">
                            <label for="aporte" class="form-label">Aporte em USDT</label>
                            <input type="number" class="form-control" id="aporte" name="aporte" 
                                   step="0.01" min="0" placeholder="0.00" required>
                            <div class="form-text">Deixe em 0 para apenas verificar o portfólio</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Executar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Alterar Senha -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Alterar Senha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Senha Atual</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Nova Senha</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   minlength="8" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirmar Nova Senha</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Alterar Senha</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de pizza
        const ctx = document.getElementById('pieChart');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                    data: <?= json_encode($data) ?>,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Validação de senha
        document.getElementById('new_password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password');
            if (this.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('As senhas não coincidem');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password');
            if (this.value !== newPassword.value) {
                this.setCustomValidity('As senhas não coincidem');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
