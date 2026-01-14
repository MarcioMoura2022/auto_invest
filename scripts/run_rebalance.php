<?php
/**
 * Script de Rebalanceamento Automático
 * Executar via CRON: 0 0 1 * * php /caminho/auto_invest/scripts/run_rebalance.php >> /var/log/auto_invest.log 2>&1
 */

// Configurações
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Definir timezone
date_default_timezone_set('America/Sao_Paulo');

// Verificar se está sendo executado via CLI
if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado via linha de comando.\n");
}

// Verificar argumentos da linha de comando
$options = getopt('', ['aporte:', 'dry-run', 'help', 'verbose']);

if (isset($options['help'])) {
    echo "Uso: php run_rebalance.php [opções]\n";
    echo "Opções:\n";
    echo "  --aporte=N    Aporte em USDT (padrão: 1000)\n";
    echo "  --dry-run     Executar sem fazer ordens reais\n";
    echo "  --verbose     Mostrar informações detalhadas\n";
    echo "  --help        Mostrar esta ajuda\n";
    echo "\nExemplo:\n";
    echo "  php run_rebalance.php --aporte=500 --dry-run\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Função de log
function logMessage($message, $type = 'INFO') {
    global $verbose;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$type}] {$message}\n";
    
    if ($verbose) {
        echo $logMessage;
    }
    
    // Sempre escrever no log
    error_log($logMessage);
}

try {
    logMessage("Iniciando script de rebalanceamento automático");
    
    // Verificar se o projeto está instalado
    if (!file_exists(__DIR__ . '/../config/config.php')) {
        throw new Exception("Projeto não está instalado. Execute o instalador primeiro.");
    }
    
    // Carregar configurações
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../helpers/crypto.php';
    require_once __DIR__ . '/../classes/db.php';
    require_once __DIR__ . '/../classes/logs.php';
    require_once __DIR__ . '/../classes/rebalance.php';
    require_once __DIR__ . '/../classes/binance.php';
    
    logMessage("Configurações carregadas com sucesso");
    
    // Verificar conexão com banco
    $db = Database::getInstance();
    logMessage("Conexão com banco estabelecida");
    
    // Buscar configurações da API
    $apiConfig = Database::query("SELECT api_key, api_secret FROM api_keys WHERE exchange = 'binance' AND active = 1 LIMIT 1")->fetch();
    
    if (!$apiConfig) {
        throw new Exception("Nenhuma API da Binance configurada ou ativa");
    }
    
    // Descriptografar credenciais
    $apiKey = ai_decrypt($apiConfig['api_key']);
    $apiSecret = ai_decrypt($apiConfig['api_secret']);
    
    if (!$apiKey || !$apiSecret) {
        throw new Exception("Erro ao descriptografar credenciais da API");
    }
    
    logMessage("Credenciais da API obtidas com sucesso");
    
    // Verificar se há ativos configurados
    $assets = Database::query("SELECT COUNT(*) as total FROM portfolio_assets WHERE active = 1")->fetch();
    
    if ($assets['total'] == 0) {
        throw new Exception("Nenhum ativo configurado para rebalanceamento");
    }
    
    logMessage("Encontrados {$assets['total']} ativos configurados");
    
    // Determinar aporte
    $aporte = 1000.0; // Padrão
    
    if (isset($options['aporte'])) {
        $aporte = floatval($options['aporte']);
        if ($aporte < 0) {
            throw new Exception("Aporte não pode ser negativo");
        }
    } else {
        // Tentar obter da variável de ambiente
        $envAporte = getenv('APORTE_USDT');
        if ($envAporte !== false) {
            $aporte = floatval($envAporte);
        }
    }
    
    logMessage("Aporte definido: {$aporte} USDT");
    
    if ($dryRun) {
        logMessage("MODO DRY-RUN: Nenhuma ordem será executada");
    }
    
    // Inicializar classes
    $binance = new Binance($apiKey, $apiSecret);
    $rebalance = new Rebalance($binance);
    
    logMessage("Classes inicializadas");
    
    // Verificar saldo disponível na Binance (se não for dry-run)
    if (!$dryRun && $aporte > 0) {
        try {
            $balances = $binance->getBalances();
            $usdtBalance = 0;
            
            foreach ($balances as $balance) {
                if ($balance['asset'] === 'USDT') {
                    $usdtBalance = $balance['free'];
                    break;
                }
            }
            
            if ($usdtBalance < $aporte) {
                logMessage("AVISO: Saldo USDT insuficiente. Disponível: {$usdtBalance}, Necessário: {$aporte}", 'WARN');
                
                // Ajustar aporte para o saldo disponível
                $aporte = $usdtBalance;
                logMessage("Aporte ajustado para: {$aporte} USDT");
            }
        } catch (Exception $e) {
            logMessage("AVISO: Não foi possível verificar saldo da Binance: " . $e->getMessage(), 'WARN');
        }
    }
    
    // Executar rebalanceamento
    logMessage("Iniciando rebalanceamento...");
    
    if ($dryRun) {
        // Simular rebalanceamento
        logMessage("Simulando rebalanceamento com aporte de {$aporte} USDT");
        
        // Buscar ativos configurados
        $targetAssets = Database::query("
            SELECT symbol, target_percentage, min_amount 
            FROM portfolio_assets 
            WHERE active = 1 
            ORDER BY priority DESC, id
        ")->fetchAll();
        
        $totalAllocated = 0;
        foreach ($targetAssets as $asset) {
            $targetValue = $aporte * (floatval($asset['target_percentage']) / 100.0);
            $minAmount = floatval($asset['min_amount'] ?? 0);
            
            if ($targetValue >= $minAmount) {
                $totalAllocated += $targetValue;
                logMessage("  {$asset['symbol']}: {$targetValue} USDT ({$asset['target_percentage']}%)");
            }
        }
        
        logMessage("Total alocado: {$totalAllocated} USDT");
        logMessage("Rebalanceamento simulado concluído com sucesso");
        
    } else {
        // Executar rebalanceamento real
        $rebalanceId = $rebalance->execute($aporte);
        logMessage("Rebalanceamento executado com sucesso. ID: {$rebalanceId}");
        
        // Verificar ordens pendentes
        logMessage("Verificando status das ordens pendentes...");
        $rebalance->checkPendingOrders();
        
        // Obter estatísticas
        $stats = $rebalance->getRebalanceStats(1);
        logMessage("Estatísticas do dia: {$stats['successful']} rebalanceamentos bem-sucedidos");
    }
    
    logMessage("Script de rebalanceamento concluído com sucesso");
    
} catch (Exception $e) {
    $errorMsg = "ERRO CRÍTICO: " . $e->getMessage();
    logMessage($errorMsg, 'ERROR');
    
    // Registrar no banco se possível
    try {
        if (isset($db)) {
            Database::query("
                INSERT INTO logs_general (log_type, message, created_at) 
                VALUES (?, ?, NOW())
            ", ['error', $errorMsg]);
        }
    } catch (Exception $logError) {
        // Se não conseguir logar no banco, pelo menos no error_log
        error_log("Erro ao registrar log no banco: " . $logError->getMessage());
    }
    
    exit(1);
} catch (Error $e) {
    $errorMsg = "ERRO FATAL: " . $e->getMessage();
    logMessage($errorMsg, 'FATAL');
    exit(1);
}
