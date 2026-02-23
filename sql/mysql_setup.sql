CREATE TABLE IF NOT EXISTS strategies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    strategy_name VARCHAR(100) UNIQUE NOT NULL,
    nav DECIMAL(20,8) NOT NULL,
    nav_btc DECIMAL(20,8),
    nav_eth DECIMAL(20,8),
    system_token VARCHAR(20),
    fee_currency_balance DECIMAL(20,8),
    fee_currency_balance_usd DECIMAL(20,8),
    last_trade DATETIME,
    last_trade_attempt DATETIME,
    last_update TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_strategy_name (strategy_name),
    INDEX idx_last_update (last_update)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prices_current (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coin VARCHAR(20) UNIQUE NOT NULL,
    price_usdt DECIMAL(20,8) NOT NULL,
    ct_exchange VARCHAR(50),
    timestamp DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prices_coin (coin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
