<?php

/**
 * Hummingbot Dashboard
 * Main dashboard page showing strategy monitoring
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

// --- POST handler: add/update strategy (PRG pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upsert_strategy') {
    $errors = [];

    $strategyName = trim($_POST['strategy_name'] ?? '');
    $navRaw       = trim($_POST['nav'] ?? '');
    $systemToken  = trim($_POST['system_token'] ?? '');
    $feeBalRaw    = trim($_POST['fee_currency_balance'] ?? '');
    $feeUsdRaw    = trim($_POST['fee_currency_balance_usd'] ?? '');
    $timestampRaw = trim($_POST['last_update'] ?? '');

    // Validate strategy name
    $nameValidation = validateStrategyName($strategyName);
    if (!$nameValidation['valid']) {
        $errors[] = $nameValidation['error'];
    }

    // Validate NAV
    $navValidation = validateNumeric($navRaw, 'NAV');
    if (!$navValidation['valid']) {
        $errors[] = $navValidation['error'];
    } else {
        $nav = floatval($navRaw);
        if ($nav < 0) {
            $errors[] = 'NAV must be 0 or greater';
        }
    }

    // Validate system_token (optional)
    $systemTokenValue = null;
    if ($systemToken !== '') {
        if (strlen($systemToken) > 20) {
            $errors[] = 'System token must be max 20 characters';
        } else {
            $systemTokenValue = $systemToken;
        }
    }

    // Validate fee_currency_balance (optional)
    $feeCurrencyBalance = null;
    if ($feeBalRaw !== '') {
        $feeBalValidation = validateNumeric($feeBalRaw, 'Fee currency balance');
        if (!$feeBalValidation['valid']) {
            $errors[] = $feeBalValidation['error'];
        } else {
            $feeCurrencyBalance = floatval($feeBalRaw);
        }
    }

    // Validate fee_currency_balance_usd (optional)
    $feeCurrencyBalanceUsd = null;
    if ($feeUsdRaw !== '') {
        $feeUsdValidation = validateNumeric($feeUsdRaw, 'Fee balance USD');
        if (!$feeUsdValidation['valid']) {
            $errors[] = $feeUsdValidation['error'];
        } else {
            $feeCurrencyBalanceUsd = floatval($feeUsdRaw);
        }
    }

    // Validate last_update (default: now)
    if ($timestampRaw === '') {
        $lastUpdate = (new DateTime())->format('Y-m-d H:i:s');
    } else {
        $tsNormalized = str_replace('T', ' ', $timestampRaw);
        if (strlen($tsNormalized) === 16) {
            $tsNormalized .= ':00';
        }
        $dtValidation = validateDatetime($tsNormalized);
        if (!$dtValidation['valid']) {
            $errors[] = $dtValidation['error'];
        } else {
            $lastUpdate = $tsNormalized;
        }
    }

    if (empty($errors)) {
        try {
            $action = upsertStrategy($pdo, $strategyName, $nav, $systemTokenValue, $feeCurrencyBalance, $feeCurrencyBalanceUsd, $lastUpdate);
            logMessage('api', "index.php manual upsert - Strategy '{$strategyName}' {$action}.");
            header('Location: index.php?msg=success&action=' . urlencode($action) . '&name=' . urlencode($strategyName));
        } catch (Exception $e) {
            logMessage('error', 'index.php manual upsert - DB error: ' . $e->getMessage());
            header('Location: index.php?msg=error&detail=' . urlencode('Database error'));
        }
    } else {
        header('Location: index.php?msg=error&detail=' . urlencode(implode('; ', $errors)));
    }
    exit;
}

// --- POST handler: delete strategy (PRG pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_strategy') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $deleted = deleteStrategy($pdo, $id);
        $msg = $deleted ? 'success' : 'error';
        $detail = $deleted ? '' : urlencode('Strategy not found');
    } else {
        $msg = 'error';
        $detail = urlencode('Invalid strategy ID');
    }
    header('Location: index.php?msg=' . $msg . ($detail ? '&detail=' . $detail : ''));
    exit;
}

// Flash message
$flashMsg    = $_GET['msg']    ?? null;
$flashAction = $_GET['action'] ?? null;
$flashName   = $_GET['name']   ?? null;
$flashDetail = $_GET['detail'] ?? null;

// Get all strategies
$strategies = getAllStrategies($pdo);

// Get current coin prices for NAV calculations
$coinPrices = getCurrentPrices($pdo);
$btcPrice = $coinPrices['BTC'] ?? null;
$ethPrice = $coinPrices['ETH'] ?? null;
$eurPrice = $coinPrices['EUR'] ?? null;

// Get trade statistics (last 24h)
$tradeStats = getTradeStats($pdo);

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
$totalTransfers = 0;
$totalPnl = 0;
$totalTrades24h = 0;
$oldestUpdate = null;
$oldestAttempt = null;
$oldestTrade = null;
$lowestFee = null;
foreach ($strategies as $strategy) {
    $isBot = ($strategy['source'] ?? 'bot') !== 'manual';
    $navUsd = floatval($strategy['nav']);
    $totalNav += $navUsd;
    $totalTransfers += floatval($strategy['transfers']);
    $totalPnl += ($navUsd - floatval($strategy['transfers']));
    if ($btcPrice !== null) {
        $totalNavBtc += calcNavInCoin($navUsd, $btcPrice);
    }
    if ($ethPrice !== null) {
        $totalNavEth += calcNavInCoin($navUsd, $ethPrice);
    }
    if ($eurPrice !== null) {
        $totalNavEur += calcNavInCoin($navUsd, $eurPrice);
    }
    $totalTrades24h += ($tradeStats[$strategy['strategy_name']]['count_24h'] ?? 0);

    // Track oldest/worst entries (only bot strategies)
    if ($isBot) {
        if ($strategy['last_update'] && ($oldestUpdate === null || $strategy['last_update'] < $oldestUpdate['last_update'])) {
            $oldestUpdate = $strategy;
        }
        if (!empty($strategy['last_trade_attempt']) && ($oldestAttempt === null || $strategy['last_trade_attempt'] < $oldestAttempt['last_trade_attempt'])) {
            $oldestAttempt = $strategy;
        }
        if (!empty($strategy['last_trade']) && ($oldestTrade === null || $strategy['last_trade'] < $oldestTrade['last_trade'])) {
            $oldestTrade = $strategy;
        }
        $feeUsd = (isset($strategy['fee_currency_balance_usd']) && $strategy['fee_currency_balance_usd'] !== null && $strategy['fee_currency_balance_usd'] !== '')
            ? floatval($strategy['fee_currency_balance_usd']) : null;
        if ($feeUsd !== null && ($lowestFee === null || $feeUsd < $lowestFee['fee_currency_balance_usd'])) {
            $lowestFee = $strategy;
        }
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
    <title><?php echo safeOutput($config['dashboard_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container-fluid py-5">
        <div class="row mb-4">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <h1 class="mb-0"><?php echo safeOutput($config['dashboard_title']); ?></h1>
                <a href="portfolio.php" class="btn btn-outline-primary btn-sm">Portfolio</a>
                <a href="prices.php" class="btn btn-outline-primary btn-sm">Prices</a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStrategyModal">+ Add Strategy</button>
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

        <!-- Flash message -->
        <?php if ($flashMsg === 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php if ($flashAction === 'deleted'): ?>
                    Strategy successfully deleted.
                <?php elseif ($flashAction === 'inserted'): ?>
                    Strategy <strong><?php echo safeOutput($flashName); ?></strong> successfully added.
                <?php elseif ($flashAction === 'updated'): ?>
                    Strategy <strong><?php echo safeOutput($flashName); ?></strong> successfully updated.
                <?php else: ?>
                    Operation successful.
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($flashMsg === 'error'): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error:</strong> <?php echo $flashDetail ? safeOutput($flashDetail) : 'An unknown error occurred.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (DEBUG_MODE): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <strong>🐛 Debug Mode Aktiv</strong>
                    <ul class="mb-0 mt-2" style="font-size: 0.9em;">
                        <li>Strategien geladen: <strong><?php echo $totalStrategies; ?></strong></li>
                        <li>Total NAV (USD): <strong><?php echo formatNav($totalNav); ?></strong></li>
                        <li>Total NAV (BTC): <strong><?php echo $btcPrice !== null ? formatNav($totalNavBtc, 6) . ' (BTC @ ' . number_format($btcPrice, 2, ',', '.') . ' USDT)' : 'n/a (kein BTC-Preis)'; ?></strong></li>
                        <li>Total NAV (ETH): <strong><?php echo $ethPrice !== null ? formatNav($totalNavEth, 4) . ' (ETH @ ' . number_format($ethPrice, 2, ',', '.') . ' USDT)' : 'n/a (kein ETH-Preis)'; ?></strong></li>
                        <li>Total NAV (EUR): <strong><?php echo $eurPrice !== null ? formatNav($totalNavEur) . ' (EUR/USD @ ' . number_format($eurPrice, 4, ',', '.') . ')' : 'n/a (kein EUR-Preis)'; ?></strong></li>
                        <li>Timezone: <strong><?php echo $config['timezone']; ?></strong></li>
                        <li>Refresh-Interval: <strong><?php echo $config['refresh_interval']; ?></strong>s</li>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

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
            <div class="row mb-3">
                <div class="col-12">
                    <div style="background: #e8f4fd; border-radius: 8px; padding: 12px 20px; display: flex; gap: 32px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <small class="text-muted">Strategies</small>
                            <div class="fw-bold"><?php echo $totalStrategies; ?></div>
                        </div>
                        <div>
                            <small class="text-muted">Total NAV</small>
                            <div class="fw-bold"><?php echo formatNav($totalNav); ?></div>
                        </div>
                        <div>
                            <small class="text-muted">Total Transfers</small>
                            <div class="fw-bold"><?php echo formatNav($totalTransfers); ?></div>
                        </div>
                        <div>
                            <small class="text-muted">Total PnL</small>
                            <div class="fw-bold <?php echo $totalPnl >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatNav($totalPnl); ?></div>
                        </div>
                        <div>
                            <small class="text-muted"># Trades 24h</small>
                            <div class="fw-bold"><?php echo $totalTrades24h; ?></div>
                        </div>
                        <div>
                            <small class="text-muted">Success Rate</small>
                            <div class="fw-bold text-muted">–</div>
                        </div>
                        <div style="border-left: 1px solid #b8d4e8; padding-left: 32px;">
                            <small class="text-muted">Oldest Update</small>
                            <div class="fw-bold">
                                <?php if ($oldestUpdate):
                                    $ouStatus = getDataStatus($oldestUpdate['last_update']);
                                    echo $ouStatus['indicator'] . ' ' . safeOutput($oldestUpdate['strategy_name']);
                                else:
                                    echo '–';
                                endif; ?>
                            </div>
                        </div>
                        <div>
                            <small class="text-muted">Oldest Attempt</small>
                            <div class="fw-bold">
                                <?php if ($oldestAttempt):
                                    $oaStatus = getTradeStatusWithCustomThresholds($oldestAttempt['last_trade_attempt'], $config['trade_status_thresholds']);
                                    echo $oaStatus['indicator'] . ' ' . safeOutput($oldestAttempt['strategy_name']);
                                else:
                                    echo '–';
                                endif; ?>
                            </div>
                        </div>
                        <div>
                            <small class="text-muted">Oldest Trade</small>
                            <div class="fw-bold">
                                <?php if ($oldestTrade):
                                    $otStatus = getTradeStatusWithCustomThresholds($oldestTrade['last_trade'], $config['trade_status_thresholds']);
                                    echo $otStatus['indicator'] . ' ' . safeOutput($oldestTrade['strategy_name']);
                                else:
                                    echo '–';
                                endif; ?>
                            </div>
                        </div>
                        <div>
                            <small class="text-muted">Buy Fees</small>
                            <div class="fw-bold">
                                <?php if ($lowestFee):
                                    $lfStatus = getFeeBalanceStatus(floatval($lowestFee['fee_currency_balance_usd']), $config['fee_balance_thresholds']);
                                    $lfIndicator = $lfStatus['indicator'] !== '' ? $lfStatus['indicator'] . ' ' : '';
                                    echo $lfIndicator . safeOutput($lowestFee['strategy_name']) . ' (USD ' . round(floatval($lowestFee['fee_currency_balance_usd'])) . ')';
                                else:
                                    echo '–';
                                endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Strategy</th>
                            <th>NAV</th>
                            <th>Transfers</th>
                            <th>PnL Total</th>
                            <th>Fee Currency</th>
                            <th>Last Trade</th>
                            <th>Last Attempt</th>
                            <th>LAST UPDATE</th>
                            <th># Trades 24h</th>
                            <th>Success Rate</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($strategies as $strategy): ?>
                            <?php
                            $isManual = ($strategy['source'] ?? 'bot') === 'manual';
                            $navUsd = floatval($strategy['nav']);
                            $transfersVal = floatval($strategy['transfers']);
                            $pnlVal = $navUsd - $transfersVal;
                            $stratStats = $tradeStats[$strategy['strategy_name']] ?? null;

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
                                    <input type="number" step="any"
                                           class="form-control form-control-sm transfers-input"
                                           data-strategy-id="<?php echo (int) $strategy['id']; ?>"
                                           value="<?php echo $transfersVal; ?>"
                                           style="width: 110px; font-size: 0.85em;">
                                </td>
                                <td>
                                    <code class="<?php echo $pnlVal >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatNav($pnlVal); ?>
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
                                <td class="text-center">
                                    <?php echo $stratStats ? $stratStats['count_24h'] : '0'; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                        if ($stratStats && $stratStats['total'] > 0) {
                                            echo $stratStats['success_rate'] . '% (' . $stratStats['success_count'] . '/' . $stratStats['total'] . ')';
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <form method="post" action="index.php"
                                          onsubmit="return confirm('Delete strategy «<?php echo safeOutput($strategy['strategy_name']); ?>»?')">
                                        <input type="hidden" name="action" value="delete_strategy">
                                        <input type="hidden" name="id" value="<?php echo (int) $strategy['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">&#128465;</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Strategy Modal -->
    <div class="modal fade" id="addStrategyModal" tabindex="-1" aria-labelledby="addStrategyModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="upsert_strategy">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addStrategyModalLabel">Add / Update Strategy</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="strategy_name" class="form-label">Strategy Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="strategy_name" name="strategy_name"
                                   placeholder="btc_usdt_market_making" maxlength="100" required>
                            <div class="form-text">Alphanumeric, underscore, dash, space – max 100 characters</div>
                        </div>
                        <div class="mb-3">
                            <label for="nav" class="form-label">NAV (USD) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="nav" name="nav"
                                   placeholder="10000.00" step="any" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="system_token" class="form-label">System Token <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="system_token" name="system_token"
                                   placeholder="ETH" maxlength="20">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fee_currency_balance" class="form-label">Fee Balance <span class="text-muted">(optional)</span></label>
                                <input type="number" class="form-control" id="fee_currency_balance" name="fee_currency_balance"
                                       placeholder="10.5" step="any" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="fee_currency_balance_usd" class="form-label">Fee Balance (USD) <span class="text-muted">(optional)</span></label>
                                <input type="number" class="form-control" id="fee_currency_balance_usd" name="fee_currency_balance_usd"
                                       placeholder="20000" step="any" min="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="last_update" class="form-label">Last Update <span class="text-muted">(optional, default: now)</span></label>
                            <input type="datetime-local" class="form-control" id="last_update" name="last_update">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/dashboard.js" data-refresh-interval="<?php echo $config['refresh_interval']; ?>"></script>
    <script>
        document.getElementById('addStrategyModal').addEventListener('show.bs.modal', function () {
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const local = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
                        + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
            const tsInput = document.getElementById('last_update');
            if (!tsInput.value) {
                tsInput.value = local;
            }
        });
    </script>
</body>
</html>
