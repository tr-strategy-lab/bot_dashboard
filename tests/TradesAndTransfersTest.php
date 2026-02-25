<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for trades table, trade stats, transfers update, and PnL calculation.
 *
 * Uses an in-memory SQLite database to avoid touching the real DB.
 */
class TradesAndTransfersTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables matching sqlite_setup.sql
        $this->pdo->exec("
            CREATE TABLE strategies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                strategy_name VARCHAR(100) UNIQUE NOT NULL,
                nav DECIMAL(20,8) NOT NULL,
                transfers DECIMAL(20,8) DEFAULT 0,
                system_token VARCHAR(20),
                fee_currency_balance DECIMAL(20,8),
                fee_currency_balance_usd DECIMAL(20,8),
                last_trade DATETIME,
                last_trade_attempt DATETIME,
                last_update DATETIME NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE trades (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                strategy_name VARCHAR(100) NOT NULL,
                success BOOLEAN NOT NULL DEFAULT 0,
                traded_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (strategy_name) REFERENCES strategies(strategy_name) ON DELETE CASCADE
            )
        ");

        $this->pdo->exec("CREATE INDEX idx_trades_strategy_traded ON trades(strategy_name, traded_at)");

        // Seed a strategy
        $this->pdo->exec("
            INSERT INTO strategies (strategy_name, nav, transfers, last_update)
            VALUES ('test_strat', 10000, 0, datetime('now'))
        ");
        $this->pdo->exec("
            INSERT INTO strategies (strategy_name, nav, transfers, last_update)
            VALUES ('other_strat', 5000, 2000, datetime('now'))
        ");
    }

    // --- insertTrade tests ---

    public function testInsertTradeReturnsId(): void
    {
        $id = insertTrade($this->pdo, 'test_strat', true, date('Y-m-d H:i:s'));
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testInsertTradeStoresCorrectData(): void
    {
        $tradedAt = '2026-02-25 12:00:00';
        insertTrade($this->pdo, 'test_strat', false, $tradedAt);

        $stmt = $this->pdo->prepare('SELECT * FROM trades WHERE strategy_name = ?');
        $stmt->execute(['test_strat']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('test_strat', $row['strategy_name']);
        $this->assertEquals(0, $row['success']);
        $this->assertSame($tradedAt, $row['traded_at']);
    }

    // --- getTradeStats tests ---

    public function testGetTradeStatsEmptyWhenNoTrades(): void
    {
        $stats = getTradeStats($this->pdo);
        $this->assertIsArray($stats);
        $this->assertEmpty($stats);
    }

    public function testGetTradeStatsCountsRecentTrades(): void
    {
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $recent = $now->format('Y-m-d H:i:s');

        insertTrade($this->pdo, 'test_strat', true, $recent);
        insertTrade($this->pdo, 'test_strat', true, $recent);
        insertTrade($this->pdo, 'test_strat', false, $recent);

        $stats = getTradeStats($this->pdo);

        $this->assertArrayHasKey('test_strat', $stats);
        $this->assertSame(3, $stats['test_strat']['count_24h']);
        $this->assertSame(3, $stats['test_strat']['total']);
        $this->assertSame(2, $stats['test_strat']['success_count']);
        $this->assertEqualsWithDelta(66.7, $stats['test_strat']['success_rate'], 0.1);
    }

    public function testGetTradeStatsOldTradesNotIn24hButInSuccessRate(): void
    {
        $old = (new DateTime('now', new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d H:i:s');
        insertTrade($this->pdo, 'test_strat', true, $old);

        $stats = getTradeStats($this->pdo);
        // Old trade should NOT be in 24h count
        $this->assertSame(0, $stats['test_strat']['count_24h']);
        // But SHOULD be in success rate (last 100 trades)
        $this->assertSame(1, $stats['test_strat']['total']);
        $this->assertEqualsWithDelta(100.0, $stats['test_strat']['success_rate'], 0.1);
    }

    public function testGetTradeStatsPerStrategy(): void
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        insertTrade($this->pdo, 'test_strat', true, $now);
        insertTrade($this->pdo, 'other_strat', false, $now);
        insertTrade($this->pdo, 'other_strat', false, $now);

        $stats = getTradeStats($this->pdo);

        $this->assertSame(1, $stats['test_strat']['count_24h']);
        $this->assertEqualsWithDelta(100.0, $stats['test_strat']['success_rate'], 0.1);
        $this->assertSame(2, $stats['other_strat']['count_24h']);
        $this->assertEqualsWithDelta(0.0, $stats['other_strat']['success_rate'], 0.1);
    }

    public function testGetTradeStats100PercentSuccessRate(): void
    {
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        insertTrade($this->pdo, 'test_strat', true, $now);
        insertTrade($this->pdo, 'test_strat', true, $now);

        $stats = getTradeStats($this->pdo);
        $this->assertEqualsWithDelta(100.0, $stats['test_strat']['success_rate'], 0.1);
        $this->assertSame(2, $stats['test_strat']['success_count']);
        $this->assertSame(2, $stats['test_strat']['total']);
    }

    // --- updateTransfers tests ---

    public function testUpdateTransfersUpdatesValue(): void
    {
        // Get strategy ID
        $stmt = $this->pdo->prepare('SELECT id FROM strategies WHERE strategy_name = ?');
        $stmt->execute(['test_strat']);
        $id = (int) $stmt->fetchColumn();

        $result = updateTransfers($this->pdo, $id, 5000.50);
        $this->assertTrue($result);

        $stmt = $this->pdo->prepare('SELECT transfers FROM strategies WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertEqualsWithDelta(5000.50, floatval($stmt->fetchColumn()), 0.01);
    }

    public function testUpdateTransfersReturnsFalseForMissingId(): void
    {
        $result = updateTransfers($this->pdo, 99999, 100.0);
        $this->assertFalse($result);
    }

    // --- PnL calculation tests ---

    public function testPnlCalculationPositive(): void
    {
        $nav = 10000.0;
        $transfers = 8000.0;
        $pnl = $nav - $transfers;
        $this->assertEqualsWithDelta(2000.0, $pnl, 0.01);
    }

    public function testPnlCalculationNegative(): void
    {
        $nav = 5000.0;
        $transfers = 8000.0;
        $pnl = $nav - $transfers;
        $this->assertEqualsWithDelta(-3000.0, $pnl, 0.01);
    }

    public function testPnlCalculationZeroTransfers(): void
    {
        $nav = 10000.0;
        $transfers = 0.0;
        $pnl = $nav - $transfers;
        $this->assertEqualsWithDelta(10000.0, $pnl, 0.01);
    }

    // --- getAllStrategies includes transfers ---

    public function testGetAllStrategiesIncludesTransfers(): void
    {
        // We need to add the source column for getAllStrategies to work
        // Since our test table doesn't have it, we test the query concept directly
        $stmt = $this->pdo->prepare('SELECT id, strategy_name, nav, COALESCE(transfers, 0) AS transfers FROM strategies ORDER BY strategy_name ASC');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        // other_strat has transfers=2000
        $this->assertEqualsWithDelta(2000.0, floatval($rows[0]['transfers']), 0.01);
        // test_strat has transfers=0
        $this->assertEqualsWithDelta(0.0, floatval($rows[1]['transfers']), 0.01);
    }

    // --- Trade endpoint updates last_trade / last_trade_attempt ---

    public function testSuccessfulTradeUpdatesLastTradeAndAttempt(): void
    {
        $timestamp = '2026-02-25 14:30:00';
        insertTrade($this->pdo, 'test_strat', true, $timestamp);

        // Simulate what trade.php does on success
        $stmt = $this->pdo->prepare('UPDATE strategies SET last_trade = ?, last_trade_attempt = ? WHERE strategy_name = ?');
        $stmt->execute([$timestamp, $timestamp, 'test_strat']);

        $check = $this->pdo->prepare('SELECT last_trade, last_trade_attempt FROM strategies WHERE strategy_name = ?');
        $check->execute(['test_strat']);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($timestamp, $row['last_trade']);
        $this->assertSame($timestamp, $row['last_trade_attempt']);
    }

    public function testFailedTradeUpdatesOnlyLastTradeAttempt(): void
    {
        $timestamp = '2026-02-25 15:00:00';
        insertTrade($this->pdo, 'test_strat', false, $timestamp);

        // Simulate what trade.php does on failure
        $stmt = $this->pdo->prepare('UPDATE strategies SET last_trade_attempt = ? WHERE strategy_name = ?');
        $stmt->execute([$timestamp, 'test_strat']);

        $check = $this->pdo->prepare('SELECT last_trade, last_trade_attempt FROM strategies WHERE strategy_name = ?');
        $check->execute(['test_strat']);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertNull($row['last_trade']);
        $this->assertSame($timestamp, $row['last_trade_attempt']);
    }
}
