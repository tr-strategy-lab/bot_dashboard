<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for deleteStrategy() and deletePrice() in includes/functions.php
 *
 * Run with: vendor/bin/phpunit tests/DeleteTest.php
 */
class DeleteTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE strategies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                strategy_name VARCHAR(100) UNIQUE NOT NULL,
                nav DECIMAL(20,8) NOT NULL,
                last_update DATETIME NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE prices_current (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coin VARCHAR(20) UNIQUE NOT NULL,
                price_usdt DECIMAL(20,8) NOT NULL,
                ct_exchange VARCHAR(50),
                timestamp DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    // --- deleteStrategy() ---

    public function testDeleteExistingStrategyReturnsTrue(): void
    {
        $this->pdo->exec("INSERT INTO strategies (strategy_name, nav, last_update) VALUES ('btc_strat', 10000, '2025-01-01 00:00:00')");
        $id = (int) $this->pdo->lastInsertId();

        $result = deleteStrategy($this->pdo, $id);

        $this->assertTrue($result);
    }

    public function testDeleteExistingStrategyRemovesRow(): void
    {
        $this->pdo->exec("INSERT INTO strategies (strategy_name, nav, last_update) VALUES ('eth_strat', 5000, '2025-01-01 00:00:00')");
        $id = (int) $this->pdo->lastInsertId();

        deleteStrategy($this->pdo, $id);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM strategies WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDeleteNonExistentStrategyReturnsFalse(): void
    {
        $result = deleteStrategy($this->pdo, 99999);

        $this->assertFalse($result);
    }

    public function testDeleteStrategyDoesNotAffectOtherRows(): void
    {
        $this->pdo->exec("INSERT INTO strategies (strategy_name, nav, last_update) VALUES ('strat_a', 1000, '2025-01-01 00:00:00')");
        $idA = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO strategies (strategy_name, nav, last_update) VALUES ('strat_b', 2000, '2025-01-01 00:00:00')");

        deleteStrategy($this->pdo, $idA);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM strategies');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // --- deletePrice() ---

    public function testDeleteExistingPriceReturnsTrue(): void
    {
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('BTC', 50000, '2025-01-01 00:00:00')");

        $result = deletePrice($this->pdo, 'BTC');

        $this->assertTrue($result);
    }

    public function testDeleteExistingPriceRemovesRow(): void
    {
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('ETH', 3000, '2025-01-01 00:00:00')");

        deletePrice($this->pdo, 'ETH');

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM prices_current WHERE coin = 'ETH'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDeleteNonExistentPriceReturnsFalse(): void
    {
        $result = deletePrice($this->pdo, 'XYZ');

        $this->assertFalse($result);
    }

    public function testDeletePriceDoesNotAffectOtherCoins(): void
    {
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('BTC', 50000, '2025-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO prices_current (coin, price_usdt, timestamp) VALUES ('ETH', 3000, '2025-01-01 00:00:00')");

        deletePrice($this->pdo, 'BTC');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM prices_current');
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query("SELECT coin FROM prices_current");
        $this->assertSame('ETH', $stmt->fetchColumn());
    }
}
