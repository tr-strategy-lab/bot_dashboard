<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the upsertPrice() function in includes/functions.php
 *
 * Uses an in-memory SQLite database – no external dependencies required.
 * Run with: vendor/bin/phpunit tests/PricesManualAddTest.php
 */
class PricesManualAddTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE prices_current (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coin VARCHAR(20) UNIQUE NOT NULL,
                price_usdt DECIMAL(20,8) NOT NULL,
                ct_exchange VARCHAR(50),
                timestamp DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(10) DEFAULT \'bot\'
            )
        ');
    }

    public function testInsertNewCoinReturnsInserted(): void
    {
        $result = upsertPrice($this->pdo, 'BTC', 50000.0, 'binance', '2025-10-22 14:30:00');

        $this->assertSame('inserted', $result);
    }

    public function testInsertNewCoinCreatesRowInDatabase(): void
    {
        upsertPrice($this->pdo, 'ETH', 3000.5, null, '2025-10-22 14:30:00');

        $stmt = $this->pdo->prepare('SELECT * FROM prices_current WHERE coin = ?');
        $stmt->execute(['ETH']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Row should exist after insert');
        $this->assertSame('ETH', $row['coin']);
        $this->assertEquals(3000.5, floatval($row['price_usdt']));
        $this->assertNull($row['ct_exchange']);
        $this->assertSame('2025-10-22 14:30:00', $row['timestamp']);
    }

    public function testUpdateExistingCoinReturnsUpdated(): void
    {
        upsertPrice($this->pdo, 'SOL', 100.0, null, '2025-10-22 14:00:00');

        $result = upsertPrice($this->pdo, 'SOL', 110.0, 'kraken', '2025-10-22 15:00:00');

        $this->assertSame('updated', $result);
    }

    public function testUpdateExistingCoinChangesPrice(): void
    {
        upsertPrice($this->pdo, 'SOL', 100.0, null, '2025-10-22 14:00:00');
        upsertPrice($this->pdo, 'SOL', 110.0, 'kraken', '2025-10-22 15:00:00');

        $stmt = $this->pdo->prepare('SELECT price_usdt, ct_exchange, timestamp FROM prices_current WHERE coin = ?');
        $stmt->execute(['SOL']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(110.0, floatval($row['price_usdt']));
        $this->assertSame('kraken', $row['ct_exchange']);
        $this->assertSame('2025-10-22 15:00:00', $row['timestamp']);
    }

    public function testUpdateDoesNotCreateDuplicateRows(): void
    {
        upsertPrice($this->pdo, 'BTC', 50000.0, null, '2025-10-22 14:00:00');
        upsertPrice($this->pdo, 'BTC', 51000.0, null, '2025-10-22 15:00:00');

        $stmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM prices_current WHERE coin = \'BTC\'');
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(1, $count, 'Only one row should exist for BTC after upsert');
    }

    public function testInsertWithNullExchange(): void
    {
        $result = upsertPrice($this->pdo, 'XRP', 0.5678, null, '2025-10-22 14:30:00');

        $this->assertSame('inserted', $result);

        $stmt = $this->pdo->prepare('SELECT ct_exchange FROM prices_current WHERE coin = ?');
        $stmt->execute(['XRP']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNull($row['ct_exchange']);
    }

    public function testManualSourceIsStoredByDefault(): void
    {
        upsertPrice($this->pdo, 'BTC', 50000.0, null, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query("SELECT source FROM prices_current WHERE coin = 'BTC'");
        $this->assertSame('manual', $stmt->fetchColumn());
    }

    public function testBotSourceCanBeStored(): void
    {
        upsertPrice($this->pdo, 'ETH', 3000.0, null, '2025-10-22 14:00:00', 'bot');

        $stmt = $this->pdo->query("SELECT source FROM prices_current WHERE coin = 'ETH'");
        $this->assertSame('bot', $stmt->fetchColumn());
    }

    public function testMultipleDistinctCoins(): void
    {
        upsertPrice($this->pdo, 'BTC', 50000.0, null, '2025-10-22 14:00:00');
        upsertPrice($this->pdo, 'ETH', 3000.0, null, '2025-10-22 14:00:00');
        upsertPrice($this->pdo, 'SOL', 100.0, null, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM prices_current');
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(3, $count);
    }
}
