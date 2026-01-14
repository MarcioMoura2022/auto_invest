<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

class Rebalance {
    private $db;
    private $binance;
    private $maxRetries = 3;
    private $retryDelay = 5; // segundos
    private $lockKey = 910022; // chave para pg_advisory_lock

    public function __construct($binance) {
        $this->db = Database::getInstance();
        $this->binance = $binance;
    }

    public function execute($aporteUSDT = 0) {
        // Validar aporte
        if ($aporteUSDT < 0) {
            throw new Exception("Aporte não pode ser negativo");
        }

        if ($aporteUSDT == 0) {
            Logs::add('info', 'Rebalanceamento executado sem aporte (apenas verificação)');
        }

        try {
            $this->acquireLock();
            return $this->executeRebalance($aporteUSDT);
        } catch (Exception $e) {
            Logs::add('error', 'Erro no rebalanceamento: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    private function executeRebalance($aporteUSDT) {
        // 1) Carregar carteira alvo
        $assets = $this->loadTargetPortfolio();
        if (empty($assets)) {
            throw new Exception("Nenhum ativo configurado em portfolio_assets.");
        }

        // 2) Criar registro do rebalanceamento
        $rebalanceId = $this->createRebalanceRecord($aporteUSDT);

        // 3) Obter saldos atuais da Binance
        try {
            $currentBalances = $this->getCurrentBalances();

            // 4) Calcular alocacoes necessarias
            $allocations = $this->calculateAllocations($assets, $aporteUSDT, $currentBalances);

            // 5) Executar ordens
            $result = $this->executeOrders($rebalanceId, $allocations);
            $executedOrders = $result['orders'];

            // 6) Atualizar status do rebalanceamento
            $status = 'success';
            if (empty($executedOrders)) {
                $status = 'failed';
            } elseif ($result['errors'] > 0) {
                $status = 'partial';
            }
            $this->updateRebalanceStatus($rebalanceId, $status, count($executedOrders) . ' ordens executadas');

            // 7) Log do resultado
            $totalExecuted = array_sum(array_column($executedOrders, 'value'));
            Logs::add('info', "Rebalanceamento {$rebalanceId} concluido. Total executado: {$totalExecuted} USDT");

            return $rebalanceId;
        } catch (Exception $e) {
            $this->updateRebalanceStatus($rebalanceId, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function loadTargetPortfolio() {
        $stmt = $this->db->prepare("
            SELECT symbol, target_percentage, type, min_amount 
            FROM portfolio_assets 
            WHERE active = 1 
            ORDER BY priority DESC, id
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function createRebalanceRecord($aporteUSDT) {
        $stmt = $this->db->prepare("
            INSERT INTO rebalance_logs(total_capital_usdt, executed_at, status, notes) 
            VALUES(?, NOW(), 'pending', ?) 
            RETURNING id
        ");
        $stmt->execute([$aporteUSDT, "Iniciando rebalanceamento com aporte de {$aporteUSDT} USDT"]);
        return $stmt->fetchColumn();
    }

    private function getCurrentBalances() {
        try {
            return $this->binance->getBalances();
        } catch (Exception $e) {
            Logs::add('warning', 'Não foi possível obter saldos atuais: ' . $e->getMessage());
            return [];
        }
    }

    private function calculateAllocations($assets, $aporteUSDT, $currentBalances) {
        $allocations = [];
        $totalTarget = array_sum(array_column($assets, 'target_percentage'));

        if ($totalTarget == 0) {
            throw new Exception("Total das porcentagens alvo deve ser maior que zero");
        }

        foreach ($assets as $asset) {
            $symbol = $asset['symbol'];
            $targetPercentage = floatval($asset['target_percentage']) / 100.0;
            $minAmount = floatval($asset['min_amount'] ?? 0);

            // Calcular valor alvo
            $targetValue = $aporteUSDT * $targetPercentage;

            // Verificar se atende ao valor mínimo
            if ($targetValue < $minAmount && $aporteUSDT > 0) {
                Logs::add('warning', "Valor alvo para {$symbol} ({$targetValue} USDT) abaixo do mínimo ({$minAmount} USDT)");
                continue;
            }

            if ($targetValue > 0) {
                $allocations[] = [
                    'symbol' => $symbol,
                    'target_value' => $targetValue,
                    'target_percentage' => $targetPercentage,
                    'type' => $asset['type']
                ];
            }
        }

        return $allocations;
    }

    private function executeOrders($rebalanceId, $allocations) {
        $executedOrders = [];
        $errors = 0;

        foreach ($allocations as $allocation) {
            $symbol = $allocation['symbol'];
            $targetValue = $allocation['target_value'];

            try {
                // Obter preço atual com retry
                $price = $this->getPriceWithRetry($symbol);
                if (!$price) {
                    Logs::add('error', "Não foi possível obter preço para {$symbol}");
                    continue;
                }

                // Calcular quantidade
                $quantity = $this->calculateQuantity($symbol, $targetValue, $price);
                if ($quantity <= 0) {
                    continue;
                }

                // Executar ordem com retry
                $order = $this->executeOrderWithRetry($symbol, 'BUY', $quantity);

                if ($order && isset($order['orderId'])) {
                    $orderId = null;
                    Database::transaction(function($db) use ($rebalanceId, $symbol, $quantity, $price, $order, &$orderId) {
                        // Registrar ordem
                        $orderId = $this->recordOrder($rebalanceId, $symbol, 'BUY', $quantity, $price, $order);

                        // Registrar saldo
                        $this->recordBalance($symbol, $quantity, $price, $quantity * $price);
                    });

                    $executedOrders[] = [
                        'symbol' => $symbol,
                        'quantity' => $quantity,
                        'price' => $price,
                        'value' => $quantity * $price,
                        'order_id' => $orderId
                    ];

                    Logs::add('info', "BUY {$quantity} {$symbol} @ {$price} USDT (Total: " . ($quantity * $price) . " USDT)");
                }

            } catch (Exception $e) {
                Logs::add('error', "Erro ao executar ordem para {$symbol}: " . $e->getMessage());
                $errors++;
                continue;
            }
        }

        return ['orders' => $executedOrders, 'errors' => $errors];
    }

    private function getPriceWithRetry($symbol) {
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $price = $this->binance->getPrice($symbol);
                if ($price) {
                    return floatval($price);
                }
            } catch (Exception $e) {
                Logs::add('warning', "Tentativa {$attempt} de obter preço para {$symbol} falhou: " . $e->getMessage());
            }

            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelay);
            }
        }
        return null;
    }

    private function executeOrderWithRetry($symbol, $side, $quantity) {
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->binance->marketOrder($symbol, $side, $quantity);
            } catch (Exception $e) {
                Logs::add('warning', "Tentativa {$attempt} de executar ordem para {$symbol} falhou: " . $e->getMessage());
                
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                }
            }
        }
        return null;
    }

    private function calculateQuantity($symbol, $targetValue, $price) {
        $filters = $this->binance->getSymbolFilters($symbol);
        $stepSize = $filters['stepSize'] ?? null;
        $minQty = floatval($filters['minQty'] ?? 0);
        $minNotional = floatval($filters['minNotional'] ?? 0);

        $quantity = $targetValue / $price;
        if ($stepSize) {
            $precision = $this->stepToPrecision($stepSize);
            $step = floatval($stepSize);
            if ($step > 0) {
                $quantity = floor($quantity / $step) * $step;
            }
            $quantity = round($quantity, $precision);
        } else {
            $quantity = round($quantity, 6);
        }

        if ($quantity < $minQty) {
            return 0;
        }

        if ($minNotional > 0 && ($quantity * $price) < $minNotional) {
            return 0;
        }

        return $quantity;
    }

    private function stepToPrecision($stepSize) {
        $step = rtrim($stepSize, '0');
        $step = rtrim($step, '.');
        $pos = strpos($step, '.');
        if ($pos === false) {
            return 0;
        }
        return strlen(substr($step, $pos + 1));
    }

    private function recordOrder($rebalanceId, $symbol, $side, $quantity, $price, $order) {
        $stmt = $this->db->prepare("
            INSERT INTO orders_executed(
                rebalance_id, order_id, symbol, side, type, quantity, price, status, response, created_at
            ) VALUES(?, ?, ?, ?, 'MARKET', ?, ?, ?, ?, NOW()) 
            RETURNING id
        ");
        $stmt->execute([
            $rebalanceId,
            $order['orderId'] ?? null,
            $symbol,
            $side,
            $quantity,
            $price,
            $order['status'] ?? 'UNKNOWN',
            json_encode($order)
        ]);
        return $stmt->fetchColumn();
    }

    private function recordBalance($symbol, $quantity, $price, $balanceUsdt) {
        $stmt = $this->db->prepare("
            INSERT INTO portfolio_balances(
                symbol, quantity, price_usdt, balance_usdt, execution_time
            ) VALUES(?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$symbol, $quantity, $price, $balanceUsdt]);
    }

    private function updateRebalanceStatus($rebalanceId, $status, $notes) {
        $stmt = $this->db->prepare("
            UPDATE rebalance_logs 
            SET status = ?, notes = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$status, $notes, $rebalanceId]);
    }

    private function acquireLock() {
        $stmt = $this->db->prepare("SELECT pg_try_advisory_lock(?) as locked");
        $stmt->execute([$this->lockKey]);
        $locked = $stmt->fetchColumn();
        if (!$locked) {
            throw new Exception("Rebalanceamento jÇ­ em execuÇõÇœo. Tente novamente mais tarde.");
        }
    }

    private function releaseLock() {
        $stmt = $this->db->prepare("SELECT pg_advisory_unlock(?)");
        $stmt->execute([$this->lockKey]);
    }

    // Método para verificar status das ordens pendentes
    public function checkPendingOrders() {
        $stmt = $this->db->prepare("
            SELECT id, symbol, order_id, status 
            FROM orders_executed 
            WHERE status IN ('NEW', 'PARTIALLY_FILLED') 
            AND created_at > NOW() - INTERVAL '1 hour'
        ");
        $stmt->execute();
        $pendingOrders = $stmt->fetchAll();

        foreach ($pendingOrders as $order) {
            try {
                $status = $this->binance->getOrderStatus($order['symbol'], $order['order_id']);
                if ($status) {
                    $this->updateOrderStatus($order['id'], $status['status']);
                }
            } catch (Exception $e) {
                Logs::add('warning', "Erro ao verificar status da ordem {$order['id']}: " . $e->getMessage());
            }
        }
    }

    private function updateOrderStatus($orderId, $status) {
        $stmt = $this->db->prepare("
            UPDATE orders_executed 
            SET status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$status, $orderId]);
    }

    // Método para obter estatísticas do rebalanceamento
    public function getRebalanceStats($days = 30) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_rebalances,
                AVG(total_capital_usdt) as avg_capital,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM rebalance_logs 
            WHERE executed_at > NOW() - INTERVAL '{$days} days'
        ");
        $stmt->execute();
        return $stmt->fetch();
    }
}



