<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Binance {
    private $apiKey;
    private $apiSecret;
    private $client;
    private $rateLimiter;
    private $cache;

    public function __construct($apiKey, $apiSecret) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->client = new Client([
            'base_uri' => 'https://api.binance.com',
            'headers' => ['X-MBX-APIKEY' => $this->apiKey],
            'timeout' => 30,
            'connect_timeout' => 10
        ]);
        
        $this->rateLimiter = new RateLimiter();
        $this->cache = new Cache();
    }

    private function sign($query) {
        return hash_hmac('sha256', $query, $this->apiSecret);
    }

    private function makeRequest($method, $endpoint, $params = [], $signed = false) {
        // Verificar rate limiting
        $this->rateLimiter->checkLimit($endpoint);
        
        try {
            $options = [];
            
            if ($signed) {
                $params['timestamp'] = round(microtime(true) * 1000);
                $query = http_build_query($params);
                $signature = $this->sign($query);
                $params['signature'] = $signature;
            }
            
            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['form_params'] = $params;
            }
            
            $response = $this->client->request($method, $endpoint, $options);
            $data = json_decode($response->getBody(), true);
            
            // Verificar se há erro na resposta da Binance
            if (isset($data['code']) && $data['code'] !== 0) {
                throw new Exception("Erro Binance: {$data['msg']} (código: {$data['code']})");
            }
            
            return $data;
            
        } catch (RequestException $e) {
            $this->handleRequestError($e);
        } catch (Exception $e) {
            error_log("Erro na API Binance: " . $e->getMessage());
            throw $e;
        }
    }

    private function handleRequestError($e) {
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
        
        switch ($statusCode) {
            case 429: // Rate limit exceeded
                $retryAfter = $e->getResponse()->getHeader('Retry-After')[0] ?? 60;
                error_log("Rate limit excedido. Aguardando {$retryAfter} segundos.");
                sleep($retryAfter);
                throw new Exception("Rate limit excedido. Tente novamente em alguns minutos.");
                
            case 418: // IP banned
                throw new Exception("IP temporariamente banido por muitas requisições.");
                
            case 403: // API key inválida
                throw new Exception("API key inválida ou sem permissões suficientes.");
                
            default:
                throw new Exception("Erro na requisição: " . $e->getMessage());
        }
    }

    public function getPrice($symbol) {
        // Verificar cache primeiro
        $cacheKey = "price_{$symbol}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        try {
            $data = $this->makeRequest('GET', '/api/v3/ticker/price', ['symbol' => $symbol]);
            $price = $data['price'] ?? null;
            
            if ($price) {
                // Cache por 10 segundos
                $this->cache->set($cacheKey, $price, 10);
            }
            
            return $price;
        } catch (Exception $e) {
            error_log("Erro ao obter preço para {$symbol}: " . $e->getMessage());
            return null;
        }
    }

    public function getAccountInfo() {
        return $this->makeRequest('GET', '/api/v3/account', [], true);
        '/api/v3/exchangeInfo' => ['requests' => 10, 'window' => 1], // 10 por segundo
    }

    public function getBalances() {
        $account = $this->getAccountInfo();
        $balances = [];
        
        foreach ($account['balances'] as $balance) {
            if (floatval($balance['free']) > 0 || floatval($balance['locked']) > 0) {
                $balances[] = [
                    'asset' => $balance['asset'],
                    'free' => floatval($balance['free']),
                    'locked' => floatval($balance['locked']),
                    'total' => floatval($balance['free']) + floatval($balance['locked'])
                ];
            }
        }
        
        return $balances;
    }

    public function marketOrder($symbol, $side, $quantity) {
        // Validar parâmetros
        if (!in_array(strtoupper($side), ['BUY', 'SELL'])) {
            throw new Exception("Lado da ordem deve ser BUY ou SELL");
        }
        
        if ($quantity <= 0) {
            throw new Exception("Quantidade deve ser maior que zero");
        }
        
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => 'MARKET',
                'quantity' => $quantity
            ];
            
            $result = $this->makeRequest('POST', '/api/v3/order', $params, true);
            
            // Log da ordem executada
            error_log("Ordem executada: {$side} {$quantity} {$symbol} - Status: {$result['status']}");
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erro ao executar ordem de mercado: " . $e->getMessage());
            throw $e;
        }
    }

    public function limitOrder($symbol, $side, $quantity, $price) {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'side' => strtoupper($side),
                'type' => 'LIMIT',
                'quantity' => $quantity,
                'price' => $price,
                'timeInForce' => 'GTC'
            ];
            
            return $this->makeRequest('POST', '/api/v3/order', $params, true);
            
        } catch (Exception $e) {
            error_log("Erro ao executar ordem limitada: " . $e->getMessage());
            throw $e;
        }
    }

    public function getOrderStatus($symbol, $orderId) {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'orderId' => $orderId
            ];
            
            return $this->makeRequest('GET', '/api/v3/order', $params, true);
            
        } catch (Exception $e) {
            error_log("Erro ao verificar status da ordem: " . $e->getMessage());
            return null;
        }
    }

    public function cancelOrder($symbol, $orderId) {
        try {
            $params = [
                'symbol' => strtoupper($symbol),
                'orderId' => $orderId
            ];
            
            return $this->makeRequest('DELETE', '/api/v3/order', $params, true);
            
        } catch (Exception $e) {
            error_log("Erro ao cancelar ordem: " . $e->getMessage());
            throw $e;
        }
    }

    public function get24hrTicker($symbol) {
        try {
            $data = $this->makeRequest('GET', '/api/v3/ticker/24hr', ['symbol' => $symbol]);
            return [
                'symbol' => $data['symbol'],
                'priceChange' => floatval($data['priceChange']),
                'priceChangePercent' => floatval($data['priceChangePercent']),
                'volume' => floatval($data['volume']),
                'quoteVolume' => floatval($data['quoteVolume']),
                'highPrice' => floatval($data['highPrice']),
                'lowPrice' => floatval($data['lowPrice'])
            ];
        } catch (Exception $e) {
            error_log("Erro ao obter ticker 24h: " . $e->getMessage());
            return null;
        }
    }

    public function getExchangeInfo() {
        $cacheKey = 'exchange_info';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->makeRequest('GET', '/api/v3/exchangeInfo', []);
        $this->cache->set($cacheKey, $data, 3600);
        return $data;
    }

    public function getSymbolFilters($symbol) {
        $symbol = strtoupper($symbol);
        $info = $this->getExchangeInfo();
        foreach ($info['symbols'] as $entry) {
            if ($entry['symbol'] !== $symbol) {
                continue;
            }
            $filters = [
                'stepSize' => null,
                'minQty' => null,
                'minNotional' => null
            ];
            foreach ($entry['filters'] as $filter) {
                if ($filter['filterType'] === 'LOT_SIZE') {
                    $filters['stepSize'] = $filter['stepSize'];
                    $filters['minQty'] = $filter['minQty'];
                }
                if ($filter['filterType'] === 'MIN_NOTIONAL') {
                    $filters['minNotional'] = $filter['minNotional'];
                }
            }
            return $filters;
        }
        return [];
    }
}

// Classe auxiliar para rate limiting
class RateLimiter {
    private $limits = [
        '/api/v3/ticker/price' => ['requests' => 1200, 'window' => 60], // 1200 por minuto
        '/api/v3/order' => ['requests' => 10, 'window' => 1], // 10 por segundo
        '/api/v3/account' => ['requests' => 10, 'window' => 1], // 10 por segundo
        '/api/v3/exchangeInfo' => ['requests' => 10, 'window' => 1], // 10 por segundo
        'default' => ['requests' => 1200, 'window' => 60] // padrão
    ];
    
    private $requests = [];
    
    public function checkLimit($endpoint) {
        $limit = $this->limits[$endpoint] ?? $this->limits['default'];
        $now = time();
        $key = $endpoint . '_' . floor($now / $limit['window']);
        
        if (!isset($this->requests[$key])) {
            $this->requests[$key] = 0;
        }
        
        if ($this->requests[$key] >= $limit['requests']) {
            $wait = $limit['window'] - ($now % $limit['window']);
            throw new Exception("Rate limit excedido para {$endpoint}. Aguarde {$wait} segundos.");
        }
        
        $this->requests[$key]++;
    }
}

// Classe auxiliar para cache simples
class Cache {
    private $data = [];
    private $expiry = [];
    
    public function get($key) {
        if (!isset($this->data[$key])) {
            return null;
        }
        
        if (isset($this->expiry[$key]) && time() > $this->expiry[$key]) {
            unset($this->data[$key], $this->expiry[$key]);
            return null;
        }
        
        return $this->data[$key];
    }
    
    public function set($key, $value, $ttl = 60) {
        $this->data[$key] = $value;
        $this->expiry[$key] = time() + $ttl;
    }
    
    public function delete($key) {
        unset($this->data[$key], $this->expiry[$key]);
    }
    
    public function clear() {
        $this->data = [];
        $this->expiry = [];
    }
}
