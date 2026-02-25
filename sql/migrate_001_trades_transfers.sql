-- Migration: Add trades table and transfers column
-- Run on existing databases to add new features

-- Add transfers column to strategies (SQLite-compatible)
ALTER TABLE strategies ADD COLUMN transfers DECIMAL(20,8) DEFAULT 0;

-- Remove obsolete columns (SQLite doesn't support DROP COLUMN before 3.35.0,
-- so for older SQLite versions, recreate the table instead)
-- For MySQL: ALTER TABLE strategies DROP COLUMN nav_btc, DROP COLUMN nav_eth;

-- Create trades table
CREATE TABLE IF NOT EXISTS trades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    strategy_name VARCHAR(100) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT 0,
    traded_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (strategy_name) REFERENCES strategies(strategy_name) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_trades_strategy_traded ON trades(strategy_name, traded_at);
