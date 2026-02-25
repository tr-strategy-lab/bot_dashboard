<?php

/**
 * Portfolio page
 * NAV overview with BTC/ETH/EUR valuations (read-only)
 */

// Requires
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Get configuration
$config = include __DIR__ . '/config/config.php';

// Set timezone
date_default_timezone_set($config['timezone']);

// Debug mode
define('DEBUG_MODE', true);

// Get database connection
$pdo = getPDO();

// Get all strategies
$strategies = getAllStrategies($pdo);

// Get current coin prices for NAV calculations
$coinPrices = getCurrentPrices($pdo);
$btcPrice = $coinPrices['BTC'] ?? null;
$ethPrice = $coinPrices['ETH'] ?? null;
$eurPrice = $coinPrices['EUR'] ?? null;  // EUR/USD rate (price_usdt = how many USD per 1 EUR)

if (DEBUG_MODE) {
    error_log('Dashboard loaded. Strategies count: ' . count($strategies));
    error_log('BTC price: ' . ($btcPrice ?? 'n/a') . ', ETH price: ' . ($ethPrice ?? 'n/a') . ', EUR/USD: ' . ($eurPrice ?? 'n/a'));
}

// Calculate total strategies
$totalStrategies = count($strategies);

// Calculate total NAV, NAV-BTC, NAV-ETH, NAV-EUR from live prices
$totalNav = 0;
$totalNavBtc = 0;
$totalNavEth = 0;
$totalNavEur = 0;
foreach ($strategies as $strategy) {
    $navUsd = floatval($strategy['nav']);
    $totalNav += $navUsd;
    if ($btcPrice !== null) {
        $totalNavBtc += calcNavInCoin($navUsd, $btcPrice);
    }
    if ($ethPrice !== null) {
        $totalNavEth += calcNavInCoin($navUsd, $ethPrice);
    }
    if ($eurPrice !== null) {
        $totalNavEur += calcNavInCoin($navUsd, $eurPrice);
    }
}

// Get current time for display
$currentTime = new DateTime();
$currentTimeFormatted = $currentTime->format('d.m.Y H:i:s');

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio - <?php echo safeOutput($config['dashboard_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container-fluid py-5">
        <div class="row mb-4">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <h1 class="mb-0">Portfolio</h1>
                <a href="index.php" class="btn btn-outline-primary btn-sm">Dashboard</a>
                <a href="prices.php" class="btn btn-outline-primary btn-sm">Prices</a>
            </div>
            <div class="col-md-4 text-end">
                <div class="dashboard-info">
                    <p class="mb-0">
                        <small class="text-muted">
                            Active Strategies: <strong><?php echo $totalStrategies; ?></strong>
                        </small>
                    </p>
                    <p class="mb-0">
                        <small class="text-muted">
                            Last Update: <strong id="updateTime"><?php echo $currentTimeFormatted; ?></strong>
                        </small>
                    </p>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-9">
                <div class="total-nav-box">
                    <div class="total-nav-item">
                        <div class="total-nav-label">Total NAV (USD)</div>
                        <div class="total-nav-value" id="totalNav"><?php echo formatNav($totalNav); ?></div>
                    </div>
                    <div class="total-nav-item">
                        <div class="total-nav-label">Total NAV (BTC)</div>
                        <div class="total-nav-value" id="totalNavBtc"><?php echo $btcPrice !== null ? formatNav($totalNavBtc, 6) : '–'; ?></div>
                    </div>
                    <div class="total-nav-item">
                        <div class="total-nav-label">Total NAV (ETH)</div>
                        <div class="total-nav-value" id="totalNavEth"><?php echo $ethPrice !== null ? formatNav($totalNavEth, 4) : '–'; ?></div>
                    </div>
                    <div class="total-nav-item">
                        <div class="total-nav-label">Total NAV (EUR)</div>
                        <div class="total-nav-value" id="totalNavEur"><?php echo $eurPrice !== null ? formatNav($totalNavEur) : '–'; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="price-ticker-box">
                    <div class="price-ticker-item">
                        <div class="price-ticker-label">BTC / USDT</div>
                        <div class="price-ticker-value">
                            <?php echo $btcPrice !== null
                                ? number_format($btcPrice, 2, ',', '.')
                                : '<span class="price-ticker-na">n/a</span>'; ?>
                        </div>
                    </div>
                    <div class="price-ticker-divider"></div>
                    <div class="price-ticker-item">
                        <div class="price-ticker-label">ETH / USDT</div>
                        <div class="price-ticker-value">
                            <?php echo $ethPrice !== null
                                ? number_format($ethPrice, 2, ',', '.')
                                : '<span class="price-ticker-na">n/a</span>'; ?>
                        </div>
                    </div>
                    <div class="price-ticker-divider"></div>
                    <div class="price-ticker-item">
                        <div class="price-ticker-label">EUR / USD</div>
                        <div class="price-ticker-value">
                            <?php echo $eurPrice !== null
                                ? number_format($eurPrice, 4, ',', '.')
                                : '<span class="price-ticker-na">n/a</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <?php if (empty($strategies)): ?>
            <div class="alert alert-info" role="alert">
                No strategies available yet. Use the API to add strategy data.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Strategy</th>
                            <th>NAV</th>
                            <th>NAV-BTC</th>
                            <th>NAV-ETH</th>
                            <th>NAV-EUR</th>
                            <th>Fee Currency</th>
                            <th>Last Trade</th>
                            <th>Last Attempt</th>
                            <th>LAST UPDATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($strategies as $strategy): ?>
                            <?php
                            $isManual = ($strategy['source'] ?? 'bot') === 'manual';
                            $navUsd = floatval($strategy['nav']);
                            $navBtcCalc = calcNavInCoin($navUsd, $btcPrice);
                            $navEthCalc = calcNavInCoin($navUsd, $ethPrice);
                            $navEurCalc = calcNavInCoin($navUsd, $eurPrice);

                            if ($isManual) {
                                $statusClass = 'status-unknown';
                                $status = null;
                            } else {
                                $status = getDataStatus($strategy['last_update']);
                                $rowStatuses = [$status['status']];

                                if ($strategy['last_trade'] !== null && $strategy['last_trade'] !== '') {
                                    $rowStatuses[] = getTradeStatusWithCustomThresholds($strategy['last_trade'], $config['trade_status_thresholds'])['status'];
                                }
                                if ($strategy['last_trade_attempt'] !== null && $strategy['last_trade_attempt'] !== '') {
                                    $rowStatuses[] = getTradeStatusWithCustomThresholds($strategy['last_trade_attempt'], $config['trade_status_thresholds'])['status'];
                                }

                                $feeUsdForRow = (isset($strategy['fee_currency_balance_usd']) && $strategy['fee_currency_balance_usd'] !== null && $strategy['fee_currency_balance_usd'] !== '')
                                    ? floatval($strategy['fee_currency_balance_usd']) : null;
                                if ($feeUsdForRow !== null) {
                                    $feeStatusForRow = getFeeBalanceStatus($feeUsdForRow, $config['fee_balance_thresholds']);
                                    if ($feeStatusForRow['status'] !== 'none') {
                                        $rowStatuses[] = $feeStatusForRow['status'];
                                    }
                                }

                                $statusClass = 'status-' . worstStatus($rowStatuses);
                            }
                            ?>
                            <tr class="<?php echo $statusClass; ?>">
                                <td><?php echo safeOutput($strategy['strategy_name']); ?></td>
                                <td>
                                    <code><?php echo formatNav($strategy['nav']); ?></code>
                                </td>
                                <td>
                                    <code>
                                        <?php echo $navBtcCalc !== null ? formatNav($navBtcCalc, 6) : '-'; ?>
                                    </code>
                                </td>
                                <td>
                                    <code>
                                        <?php echo $navEthCalc !== null ? formatNav($navEthCalc, 4) : '-'; ?>
                                    </code>
                                </td>
                                <td>
                                    <code>
                                        <?php echo $navEurCalc !== null ? formatNav($navEurCalc) : '-'; ?>
                                    </code>
                                </td>
                                <td>
                                    <code>
                                        <?php
                                            $systemToken = (isset($strategy['system_token']) && $strategy['system_token'] !== null && $strategy['system_token'] !== '') ? trim($strategy['system_token']) : null;
                                            $feeCurrencyBalance = (isset($strategy['fee_currency_balance']) && $strategy['fee_currency_balance'] !== null && $strategy['fee_currency_balance'] !== '') ? $strategy['fee_currency_balance'] : null;
                                            $feeCurrencyBalanceUsd = (isset($strategy['fee_currency_balance_usd']) && $strategy['fee_currency_balance_usd'] !== null && $strategy['fee_currency_balance_usd'] !== '') ? $strategy['fee_currency_balance_usd'] : null;

                                            // Debug
                                            if (DEBUG_MODE && !empty($strategy['system_token'])) {
                                                error_log('DEBUG: system_token = ' . var_export($strategy['system_token'], true));
                                            }

                                            $hasFeeData = $feeCurrencyBalance !== null || $feeCurrencyBalanceUsd !== null;

                                            if ($hasFeeData) {
                                                $feeIndicator = '';
                                                if ($feeCurrencyBalanceUsd !== null) {
                                                    $feeStatus = getFeeBalanceStatus(floatval($feeCurrencyBalanceUsd), $config['fee_balance_thresholds']);
                                                    $feeIndicator = $feeStatus['indicator'] !== '' ? $feeStatus['indicator'] . ' ' : '';
                                                }
                                                $output = $feeIndicator;
                                                if ($systemToken !== null) {
                                                    $output .= safeOutput($systemToken);
                                                }
                                                if ($feeCurrencyBalance !== null) {
                                                    if ($output !== $feeIndicator) {
                                                        $output .= ' ';
                                                    }
                                                    $output .= rtrim(number_format($feeCurrencyBalance, 5, '.', ''), '0');
                                                }
                                                if ($feeCurrencyBalanceUsd !== null) {
                                                    $output .= ' (USD ' . round($feeCurrencyBalanceUsd) . ')';
                                                }
                                                echo $output;
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </code>
                                </td>
                                <td>
                                    <?php
                                        if ($strategy['last_trade'] !== null && $strategy['last_trade'] !== '') {
                                            $tradeStatusForDisplay = getTradeStatusWithCustomThresholds($strategy['last_trade'], $config['trade_status_thresholds']);
                                            echo '<span class="status-indicator">' . $tradeStatusForDisplay['indicator'] . '</span>';
                                            echo '<small class="text-muted">' . $tradeStatusForDisplay['time_diff'] . '</small>';
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        if ($strategy['last_trade_attempt'] !== null && $strategy['last_trade_attempt'] !== '') {
                                            $attemptStatus = getTradeStatusWithCustomThresholds($strategy['last_trade_attempt'], $config['trade_status_thresholds']);
                                            echo '<span class="status-indicator">' . $attemptStatus['indicator'] . '</span>';
                                            echo '<small class="text-muted">' . $attemptStatus['time_diff'] . '</small>';
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <span class="text-muted">–</span>
                                    <?php else: ?>
                                        <span class="status-indicator"><?php echo $status['indicator']; ?></span>
                                        <small class="text-muted"><?php echo $status['time_diff']; ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/dashboard.js" data-refresh-interval="<?php echo $config['refresh_interval']; ?>"></script>
</body>
</html>
