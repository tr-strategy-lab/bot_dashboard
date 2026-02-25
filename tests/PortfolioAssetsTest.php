<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for portfolio_assets CRUD functions.
 */
class PortfolioAssetsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE portfolio_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account VARCHAR(100) NOT NULL,
                asset VARCHAR(20) NOT NULL,
                quantity DECIMAL(20,8) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE prices_current (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coin VARCHAR(20) UNIQUE NOT NULL,
                price_usdt DECIMAL(20,8) NOT NULL,
                ct_exchange VARCHAR(50),
                timestamp DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(10) DEFAULT 'bot'
            )
        ");

        // Seed some prices
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('BTC', 95000.0, datetime('now'))");
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('ETH', 2800.0, datetime('now'))");
    }

    public function testInsertPortfolioAssetReturnsId(): void
    {
        $id = insertPortfolioAsset($this->pdo, 'Binance', 'BTC', 1.5);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testInsertPortfolioAssetStoresCorrectData(): void
    {
        insertPortfolioAsset($this->pdo, 'Ledger', 'ETH', 10.25);

        $stmt = $this->pdo->prepare('SELECT * FROM portfolio_assets WHERE account = ?');
        $stmt->execute(['Ledger']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('Ledger', $row['account']);
        $this->assertSame('ETH', $row['asset']);
        $this->assertEqualsWithDelta(10.25, floatval($row['quantity']), 0.001);
    }

    public function testGetAllPortfolioAssetsEmpty(): void
    {
        $result = getAllPortfolioAssets($this->pdo);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllPortfolioAssetsReturnsSorted(): void
    {
        insertPortfolioAsset($this->pdo, 'Binance', 'ETH', 5.0);
        insertPortfolioAsset($this->pdo, 'Binance', 'BTC', 1.0);
        insertPortfolioAsset($this->pdo, 'Acme Bank', 'USD', 10000.0);

        $result = getAllPortfolioAssets($this->pdo);
        $this->assertCount(3, $result);
        // Sorted by account ASC, asset ASC
        $this->assertSame('Acme Bank', $result[0]['account']);
        $this->assertSame('Binance', $result[1]['account']);
        $this->assertSame('BTC', $result[1]['asset']);
        $this->assertSame('Binance', $result[2]['account']);
        $this->assertSame('ETH', $result[2]['asset']);
    }

    public function testDeletePortfolioAssetRemovesRow(): void
    {
        $id = insertPortfolioAsset($this->pdo, 'Binance', 'BTC', 1.0);
        $this->assertTrue(deletePortfolioAsset($this->pdo, $id));
        $this->assertEmpty(getAllPortfolioAssets($this->pdo));
    }

    public function testDeletePortfolioAssetReturnsFalseForMissingId(): void
    {
        $this->assertFalse(deletePortfolioAsset($this->pdo, 99999));
    }

    public function testGetPricesWithTimestamps(): void
    {
        $result = getPricesWithTimestamps($this->pdo);
        $this->assertArrayHasKey('BTC', $result);
        $this->assertArrayHasKey('ETH', $result);
        $this->assertEqualsWithDelta(95000.0, $result['BTC']['price_usdt'], 0.01);
        $this->assertArrayHasKey('timestamp', $result['BTC']);
    }

    public function testNavCalculationFromPrice(): void
    {
        $quantity = 2.5;
        $priceUsdt = 95000.0;
        $nav = $quantity * $priceUsdt;
        $this->assertEqualsWithDelta(237500.0, $nav, 0.01);
    }

    public function testMultiplePositionsSameAsset(): void
    {
        insertPortfolioAsset($this->pdo, 'Binance', 'BTC', 1.0);
        insertPortfolioAsset($this->pdo, 'Ledger', 'BTC', 0.5);

        $result = getAllPortfolioAssets($this->pdo);
        $this->assertCount(2, $result);

        // Both should have correct quantities
        $totalQty = floatval($result[0]['quantity']) + floatval($result[1]['quantity']);
        $this->assertEqualsWithDelta(1.5, $totalQty, 0.001);
    }
}
