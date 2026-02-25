-- Migration 002: Add portfolio_assets table
-- Run this on existing databases to add the portfolio assets feature

CREATE TABLE IF NOT EXISTS portfolio_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account VARCHAR(100) NOT NULL,
    asset VARCHAR(20) NOT NULL,
    quantity DECIMAL(20,8) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_portfolio_assets_asset ON portfolio_assets(asset);
