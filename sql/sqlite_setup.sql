CREATE TABLE IF NOT EXISTS strategies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    strategy_name VARCHAR(100) UNIQUE NOT NULL,
    nav DECIMAL(20,8) NOT NULL,
    transfers DECIMAL(20,8) DEFAULT 0,
    system_token VARCHAR(20),
    fee_currency_balance DECIMAL(20,8),
    fee_currency_balance_usd DECIMAL(20,8),
    last_trade DATETIME,
    last_trade_attempt DATETIME,
    last_update DATETIME NOT NULL,
    source VARCHAR(10) DEFAULT 'bot'
);

CREATE INDEX IF NOT EXISTS idx_strategy_name ON strategies(strategy_name);
CREATE INDEX IF NOT EXISTS idx_last_update ON strategies(last_update);

CREATE TABLE IF NOT EXISTS prices_current (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin VARCHAR(20) UNIQUE NOT NULL,
    price_usdt DECIMAL(20,8) NOT NULL,
    ct_exchange VARCHAR(50),
    timestamp DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(10) DEFAULT 'bot'
);

CREATE INDEX IF NOT EXISTS idx_prices_coin ON prices_current(coin);

CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    strategy_name VARCHAR(100) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT 0,
    traded_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (strategy_name) REFERENCES strategies(strategy_name) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_trades_strategy_traded ON trades(strategy_name, traded_at);

CREATE TABLE IF NOT EXISTS portfolio_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account VARCHAR(100) NOT NULL,
    asset VARCHAR(20) NOT NULL,
    quantity DECIMAL(20,8) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_portfolio_assets_asset ON portfolio_assets(asset);
