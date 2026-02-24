<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for upsertStrategy() in includes/functions.php
 *
 * Run with: vendor/bin/phpunit tests/StrategyManualAddTest.php
 */
class StrategyManualAddTest extends TestCase
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
                nav_btc DECIMAL(20,8),
                nav_eth DECIMAL(20,8),
                system_token VARCHAR(20),
                fee_currency_balance DECIMAL(20,8),
                fee_currency_balance_usd DECIMAL(20,8),
                last_trade DATETIME,
                last_trade_attempt DATETIME,
                last_update DATETIME NOT NULL,
                source VARCHAR(10) DEFAULT \'bot\'
            )
        ');
    }

    public function testInsertNewStrategyReturnsInserted(): void
    {
        $result = upsertStrategy($this->pdo, 'btc_strat', 10000.0, null, null, null, '2025-10-22 14:00:00');

        $this->assertSame('inserted', $result);
    }

    public function testInsertNewStrategyCreatesRow(): void
    {
        upsertStrategy($this->pdo, 'eth_strat', 5000.0, 'ETH', 10.5, 20000.0, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query("SELECT * FROM strategies WHERE strategy_name = 'eth_strat'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('eth_strat', $row['strategy_name']);
        $this->assertEquals(5000.0, floatval($row['nav']));
        $this->assertSame('ETH', $row['system_token']);
        $this->assertEquals(10.5, floatval($row['fee_currency_balance']));
        $this->assertEquals(20000.0, floatval($row['fee_currency_balance_usd']));
    }

    public function testUpdateExistingStrategyReturnsUpdated(): void
    {
        upsertStrategy($this->pdo, 'sol_strat', 1000.0, null, null, null, '2025-10-22 14:00:00');

        $result = upsertStrategy($this->pdo, 'sol_strat', 1200.0, null, null, null, '2025-10-22 15:00:00');

        $this->assertSame('updated', $result);
    }

    public function testUpdateExistingStrategyChangesNav(): void
    {
        upsertStrategy($this->pdo, 'sol_strat', 1000.0, null, null, null, '2025-10-22 14:00:00');
        upsertStrategy($this->pdo, 'sol_strat', 1200.0, 'SOL', null, null, '2025-10-22 15:00:00');

        $stmt = $this->pdo->query("SELECT nav, system_token, last_update FROM strategies WHERE strategy_name = 'sol_strat'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1200.0, floatval($row['nav']));
        $this->assertSame('SOL', $row['system_token']);
        $this->assertSame('2025-10-22 15:00:00', $row['last_update']);
    }

    public function testUpdateDoesNotCreateDuplicateRows(): void
    {
        upsertStrategy($this->pdo, 'btc_strat', 10000.0, null, null, null, '2025-10-22 14:00:00');
        upsertStrategy($this->pdo, 'btc_strat', 11000.0, null, null, null, '2025-10-22 15:00:00');

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM strategies WHERE strategy_name = 'btc_strat'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testInsertWithNullOptionalFields(): void
    {
        upsertStrategy($this->pdo, 'bare_strat', 500.0, null, null, null, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query("SELECT system_token, fee_currency_balance, fee_currency_balance_usd FROM strategies WHERE strategy_name = 'bare_strat'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNull($row['system_token']);
        $this->assertNull($row['fee_currency_balance']);
        $this->assertNull($row['fee_currency_balance_usd']);
    }

    public function testManualSourceIsStoredByDefault(): void
    {
        upsertStrategy($this->pdo, 'manual_strat', 1000.0, null, null, null, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query("SELECT source FROM strategies WHERE strategy_name = 'manual_strat'");
        $this->assertSame('manual', $stmt->fetchColumn());
    }

    public function testBotSourceCanBeStored(): void
    {
        upsertStrategy($this->pdo, 'bot_strat', 1000.0, null, null, null, '2025-10-22 14:00:00', 'bot');

        $stmt = $this->pdo->query("SELECT source FROM strategies WHERE strategy_name = 'bot_strat'");
        $this->assertSame('bot', $stmt->fetchColumn());
    }

    public function testMultipleDistinctStrategies(): void
    {
        upsertStrategy($this->pdo, 'strat_a', 1000.0, null, null, null, '2025-10-22 14:00:00');
        upsertStrategy($this->pdo, 'strat_b', 2000.0, null, null, null, '2025-10-22 14:00:00');
        upsertStrategy($this->pdo, 'strat_c', 3000.0, null, null, null, '2025-10-22 14:00:00');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM strategies');
        $this->assertSame(3, (int) $stmt->fetchColumn());
    }
}
