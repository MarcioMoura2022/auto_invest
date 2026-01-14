<?php
// Web Installer
if (file_exists(__DIR__ . '/../config/config.php')) {
    die('Já instalado. Remova a pasta /install para segurança.');
}
$error = null;
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '5432');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = trim($_POST['admin_pass'] ?? '');

    $apiKey = trim($_POST['api_key'] ?? '');
    $apiSecret = trim($_POST['api_secret'] ?? '');

    $encKey = trim($_POST['enc_key'] ?? '');
    if (strlen($encKey) < 32) {
        throw new Exception('ENCRYPTION_KEY deve ter pelo menos 32 caracteres.');
    }

    try {
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Criar tabelas
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($sql);

        // Criar usuário admin
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users(username, password_hash) VALUES(?, ?)");
        $stmt->execute([$adminUser, $hash]);

        // Preparar criptografia (AES-256-GCM)
        $encKeyBin = hash('sha256', $encKey, true);
        $iv = random_bytes(12);
        $tag = '';
        $apiKeyCipher = openssl_encrypt($apiKey, 'aes-256-gcm', $encKeyBin, OPENSSL_RAW_DATA, $iv, $tag);
        if ($apiKeyCipher === false) {
            throw new Exception('Falha ao criptografar API Key.');
        }
        $apiKeyEnc = 'gcm:' . base64_encode($iv . $tag . $apiKeyCipher);

        $iv = random_bytes(12);
        $tag = '';
        $apiSecretCipher = openssl_encrypt($apiSecret, 'aes-256-gcm', $encKeyBin, OPENSSL_RAW_DATA, $iv, $tag);
        if ($apiSecretCipher === false) {
            throw new Exception('Falha ao criptografar API Secret.');
        }
        $apiSecretEnc = 'gcm:' . base64_encode($iv . $tag . $apiSecretCipher);

        // Salvar API
        $stmt = $pdo->prepare("INSERT INTO api_keys(exchange, api_key, api_secret) VALUES('binance', ?, ?)");
        $stmt->execute([$apiKeyEnc, $apiSecretEnc]);

        // Criar config.php a partir do sample
        $tpl = file_get_contents(__DIR__ . '/../config/config.sample.php');
        $repl = str_replace(
            ['%DB_HOST%','%DB_PORT%','%DB_NAME%','%DB_USER%','%DB_PASS%','%ENCRYPTION_KEY%'],
            [$dbHost, $dbPort, $dbName, $dbUser, $dbPass, $encKey],
            $tpl
        );
        file_put_contents(__DIR__ . '/../config/config.php', $repl);

        $ok = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Auto-Invest - Instalador</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">
<h3>Instalador - Auto-Invest</h3>
<?php if($ok): ?>
<div class="alert alert-success">
    Instalação concluída! <strong>Recomenda-se apagar a pasta /install</strong>.<br>
    Acesse o dashboard em <code>/public/login.php</code>.
</div>
<?php else: ?>
<?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" class="row g-3">
  <div class="col-12"><h5>Banco de Dados</h5></div>
  <div class="col-md-6"><label class="form-label">Host</label><input name="db_host" class="form-control" value="localhost"></div>
  <div class="col-md-2"><label class="form-label">Porta</label><input name="db_port" class="form-control" value="5432"></div>
  <div class="col-md-4"><label class="form-label">Database</label><input name="db_name" class="form-control" required></div>
  <div class="col-md-6"><label class="form-label">Usuário</label><input name="db_user" class="form-control" required></div>
  <div class="col-md-6"><label class="form-label">Senha</label><input name="db_pass" type="password" class="form-control" required></div>

  <div class="col-12"><h5 class="mt-3">Administrador</h5></div>
  <div class="col-md-6"><label class="form-label">Usuário</label><input name="admin_user" class="form-control" value="admin"></div>
  <div class="col-md-6"><label class="form-label">Senha</label><input name="admin_pass" type="password" class="form-control" required></div>

  <div class="col-12"><h5 class="mt-3">Binance API</h5></div>
  <div class="col-md-6"><label class="form-label">API Key</label><input name="api_key" class="form-control" required></div>
  <div class="col-md-6"><label class="form-label">API Secret</label><input name="api_secret" class="form-control" required></div>

  <div class="col-12"><h5 class="mt-3">Criptografia</h5></div>
  <div class="col-md-12">
    <label class="form-label">ENCRYPTION_KEY (32+ chars)</label>
    <input name="enc_key" class="form-control" placeholder="chave super secreta (32+ caracteres)" required>
  </div>

  <div class="col-12">
    <button class="btn btn-primary">Instalar</button>
  </div>
</form>
<?php endif; ?>
</body>
</html>
