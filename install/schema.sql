-- Schema para Auto-Invest
CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    exchange VARCHAR(50) NOT NULL,
    api_key TEXT NOT NULL,
    api_secret TEXT NOT NULL,
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS portfolio_assets (
    id SERIAL PRIMARY KEY,
    symbol VARCHAR(20) NOT NULL,
    target_percentage NUMERIC(5,2) NOT NULL,
    type VARCHAR(20) NOT NULL,
    min_amount NUMERIC(30,8) DEFAULT 0,
    priority INTEGER DEFAULT 0,
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS portfolio_balances (
    id SERIAL PRIMARY KEY,
    symbol VARCHAR(20) NOT NULL,
    quantity NUMERIC(30,8) NOT NULL,
    price_usdt NUMERIC(30,8) NOT NULL,
    balance_usdt NUMERIC(30,8) NOT NULL,
    execution_time TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rebalance_logs (
    id SERIAL PRIMARY KEY,
    total_capital_usdt NUMERIC(30,8) NOT NULL,
    executed_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    status VARCHAR(20) NOT NULL,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS orders_executed (
    id SERIAL PRIMARY KEY,
    rebalance_id INT REFERENCES rebalance_logs(id),
    order_id VARCHAR(50),
    symbol VARCHAR(20) NOT NULL,
    side VARCHAR(10) NOT NULL,
    type VARCHAR(20) NOT NULL,
    quantity NUMERIC(30,8) NOT NULL,
    price NUMERIC(30,8) NOT NULL,
    status VARCHAR(20) NOT NULL,
    response JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS logs_general (
    id SERIAL PRIMARY KEY,
    log_type VARCHAR(50),
    message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email VARCHAR(255),
    active BOOLEAN DEFAULT true,
    last_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Nova tabela para tentativas de login
CREATE TABLE IF NOT EXISTS login_attempts (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    success BOOLEAN NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Nova tabela para configurações do sistema
CREATE TABLE IF NOT EXISTS system_config (
    id SERIAL PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Nova tabela para notificações
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id),
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    read_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Nova tabela para histórico de preços
CREATE TABLE IF NOT EXISTS price_history (
    id SERIAL PRIMARY KEY,
    symbol VARCHAR(20) NOT NULL,
    price_usdt NUMERIC(30,8) NOT NULL,
    volume_24h NUMERIC(30,8),
    change_24h_percent NUMERIC(10,4),
    recorded_at TIMESTAMP DEFAULT NOW()
);

-- Índices para melhor performance
CREATE INDEX IF NOT EXISTS idx_portfolio_balances_symbol ON portfolio_balances(symbol);
CREATE INDEX IF NOT EXISTS idx_portfolio_balances_execution_time ON portfolio_balances(execution_time);
CREATE INDEX IF NOT EXISTS idx_orders_executed_symbol ON orders_executed(symbol);
CREATE INDEX IF NOT EXISTS idx_orders_executed_created_at ON orders_executed(created_at);
CREATE INDEX IF NOT EXISTS idx_rebalance_logs_executed_at ON rebalance_logs(executed_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_created ON login_attempts(ip_address, created_at);
CREATE INDEX IF NOT EXISTS idx_price_history_symbol_recorded ON price_history(symbol, recorded_at);

-- Inserir configurações padrão
INSERT INTO system_config (config_key, config_value, description) VALUES
('max_login_attempts', '5', 'Máximo de tentativas de login antes do bloqueio'),
('lockout_duration', '900', 'Duração do bloqueio em segundos (15 minutos)'),
('session_timeout', '28800', 'Timeout da sessão em segundos (8 horas)'),
('min_order_amount', '10', 'Valor mínimo para ordens em USDT'),
('price_cache_ttl', '10', 'TTL do cache de preços em segundos'),
('max_retries', '3', 'Máximo de tentativas para operações da API'),
('retry_delay', '5', 'Delay entre tentativas em segundos')
ON CONFLICT (config_key) DO NOTHING;

-- Inserir alguns ativos padrão para teste
INSERT INTO portfolio_assets (symbol, target_percentage, type, min_amount, priority) VALUES
('BTCUSDT', 40.00, 'crypto', 50.00, 1),
('ETHUSDT', 30.00, 'crypto', 30.00, 2),
('BNBUSDT', 20.00, 'crypto', 20.00, 3),
('ADAUSDT', 10.00, 'crypto', 10.00, 4)
ON CONFLICT DO NOTHING;
