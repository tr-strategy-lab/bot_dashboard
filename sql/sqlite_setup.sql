CREATE TABLE IF NOT EXISTS strategies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    strategy_name VARCHAR(100) UNIQUE NOT NULL,
    nav DECIMAL(20,8) NOT NULL,
    nav_btc DECIMAL(20,8),
    nav_eth DECIMAL(20,8),
    system_token VARCHAR(20),
    fee_currency_balance DECIMAL(20,8),
    fee_currency_balance_usd DECIMAL(20,8),
    last_trade DATETIME,
    last_trade_attempt DATETIME,
    last_update DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_strategy_name ON strategies(strategy_name);
CREATE INDEX IF NOT EXISTS idx_last_update ON strategies(last_update);

CREATE TABLE IF NOT EXISTS prices_current (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin VARCHAR(20) UNIQUE NOT NULL,
    price_usdt DECIMAL(20,8) NOT NULL,
    ct_exchange VARCHAR(50),
    timestamp DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_prices_coin ON prices_current(coin);
