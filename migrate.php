<?php

/**
 * ONE-TIME database migration script
 *
 * Run once in the browser after uploading new files.
 * DELETE THIS FILE immediately after successful migration!
 *
 * Access: https://yourdomain.com/migrate.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$pdo = getPDO();
$results = [];

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("PRAGMA table_info($table)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $column) return true;
    }
    return false;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return $stmt->fetch() !== false;
}

// --- strategies table columns ---
$strategyColumns = [
    'nav_btc'                  => 'DECIMAL(20,8)',
    'nav_eth'                  => 'DECIMAL(20,8)',
    'system_token'             => 'VARCHAR(20)',
    'fee_currency_balance'     => 'DECIMAL(20,8)',
    'fee_currency_balance_usd' => 'DECIMAL(20,8)',
    'last_trade'               => 'DATETIME',
    'last_trade_attempt'       => 'DATETIME',
    'source'                   => "VARCHAR(10) DEFAULT 'bot'",
];

foreach ($strategyColumns as $column => $type) {
    if (columnExists($pdo, 'strategies', $column)) {
        $results[] = ['ok', "strategies.$column already exists – skipped"];
    } else {
        try {
            $pdo->exec("ALTER TABLE strategies ADD COLUMN $column $type");
            $results[] = ['added', "strategies.$column added"];
        } catch (Exception $e) {
            $results[] = ['error', "strategies.$column – " . $e->getMessage()];
        }
    }
}

// --- prices_current: source column ---
if (tableExists($pdo, 'prices_current')) {
    if (columnExists($pdo, 'prices_current', 'source')) {
        $results[] = ['ok', 'prices_current.source already exists – skipped'];
    } else {
        try {
            $pdo->exec("ALTER TABLE prices_current ADD COLUMN source VARCHAR(10) DEFAULT 'bot'");
            $results[] = ['added', 'prices_current.source added'];
        } catch (Exception $e) {
            $results[] = ['error', 'prices_current.source – ' . $e->getMessage()];
        }
    }
}

// --- prices_current table (create if missing) ---
if (tableExists($pdo, 'prices_current')) {
    $results[] = ['ok', 'prices_current table already exists – skipped'];
} else {
    try {
        $pdo->exec('
            CREATE TABLE prices_current (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coin VARCHAR(20) UNIQUE NOT NULL,
                price_usdt DECIMAL(20,8) NOT NULL,
                ct_exchange VARCHAR(50),
                timestamp DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $pdo->exec('CREATE INDEX idx_prices_coin ON prices_current(coin)');
        $results[] = ['added', 'prices_current table created'];
    } catch (Exception $e) {
        $results[] = ['error', 'prices_current – ' . $e->getMessage()];
    }
}

$hasErrors = !empty(array_filter($results, fn($r) => $r[0] === 'error'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DB Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
    <h2>Database Migration</h2>

    <div class="mt-4">
        <?php foreach ($results as [$status, $msg]): ?>
            <?php
                $cls  = match($status) { 'added' => 'success', 'error' => 'danger', default => 'secondary' };
                $icon = match($status) { 'added' => '✓', 'error' => '✗', default => '–' };
            ?>
            <div class="alert alert-<?php echo $cls; ?> py-2 mb-2">
                <?php echo $icon; ?> <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($hasErrors): ?>
        <div class="alert alert-danger mt-3">
            <strong>Some steps failed.</strong> Check the errors above.
        </div>
    <?php else: ?>
        <div class="alert alert-success mt-3">
            <strong>Migration complete!</strong><br>
            <span class="text-danger fw-bold">⚠ DELETE migrate.php from the server now via CyberDuck!</span>
        </div>
        <a href="index.php" class="btn btn-primary">Go to Dashboard</a>
    <?php endif; ?>
</body>
</html>
