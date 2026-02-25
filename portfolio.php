<?php

/**
 * Portfolio page
 * Shows aggregated bot strategies + manually added asset positions.
 * Asset NAV is calculated from prices_current × quantity.
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

// Get database connection
$pdo = getPDO();

// --- POST handler: add portfolio asset (PRG pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_asset') {
    $errors = [];

    $account  = trim($_POST['account'] ?? '');
    $asset    = strtoupper(trim($_POST['asset'] ?? ''));
    $qtyRaw   = trim($_POST['quantity'] ?? '');

    if ($account === '' || strlen($account) > 100) {
        $errors[] = 'Account name is required (max 100 characters)';
    }
    if ($asset === '' || strlen($asset) > 20) {
        $errors[] = 'Asset symbol is required (max 20 characters)';
    }
    $qtyValidation = validateNumeric($qtyRaw, 'Quantity');
    if (!$qtyValidation['valid']) {
        $errors[] = $qtyValidation['error'];
    } else {
        $quantity = floatval($qtyRaw);
        if ($quantity <= 0) {
            $errors[] = 'Quantity must be greater than 0';
        }
    }

    if (empty($errors)) {
        try {
            insertPortfolioAsset($pdo, $account, $asset, $quantity);
            logMessage('api', "portfolio.php - Asset position added: {$account} / {$asset} × {$quantity}");
            header('Location: portfolio.php?msg=success&action=inserted&name=' . urlencode($account . ' / ' . $asset));
        } catch (Exception $e) {
            logMessage('error', 'portfolio.php add_asset - DB error: ' . $e->getMessage());
            header('Location: portfolio.php?msg=error&detail=' . urlencode('Database error'));
        }
    } else {
        header('Location: portfolio.php?msg=error&detail=' . urlencode(implode('; ', $errors)));
    }
    exit;
}

// --- POST handler: delete portfolio asset (PRG pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_asset') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $deleted = deletePortfolioAsset($pdo, $id);
        $msg = $deleted ? 'success' : 'error';
        $detail = $deleted ? '' : urlencode('Position not found');
    } else {
        $msg = 'error';
        $detail = urlencode('Invalid position ID');
    }
    header('Location: portfolio.php?msg=' . $msg . ($detail ? '&detail=' . $detail : '&action=deleted'));
    exit;
}

// Flash message
$flashMsg    = $_GET['msg']    ?? null;
$flashAction = $_GET['action'] ?? null;
$flashName   = $_GET['name']   ?? null;
$flashDetail = $_GET['detail'] ?? null;

// Get all strategies (for bot aggregation)
$strategies = getAllStrategies($pdo);

// Get prices with timestamps (for NAV calc + color coding)
$pricesData = getPricesWithTimestamps($pdo);
$coinPrices = getCurrentPrices($pdo);
$btcPrice = $coinPrices['BTC'] ?? null;
$ethPrice = $coinPrices['ETH'] ?? null;
$eurPrice = $coinPrices['EUR'] ?? null;

// Get portfolio asset positions
$portfolioAssets = getAllPortfolioAssets($pdo);

// Separate bot strategies
$botStrategies = [];
foreach ($strategies as $strategy) {
    if (($strategy['source'] ?? 'bot') !== 'manual') {
        $botStrategies[] = $strategy;
    }
}

// Aggregate bot strategies into one "multicoin" row
$botCount = count($botStrategies);
$botNavUsd = 0;
$botNavBtc = 0;
$botNavEth = 0;
$botNavEur = 0;
$botStatuses = [];
foreach ($botStrategies as $strategy) {
    $navUsd = floatval($strategy['nav']);
    $botNavUsd += $navUsd;
    if ($btcPrice !== null) { $botNavBtc += calcNavInCoin($navUsd, $btcPrice); }
    if ($ethPrice !== null) { $botNavEth += calcNavInCoin($navUsd, $ethPrice); }
    if ($eurPrice !== null) { $botNavEur += calcNavInCoin($navUsd, $eurPrice); }

    $botStatuses[] = getDataStatus($strategy['last_update'])['status'];
    if (!empty($strategy['last_trade'])) {
        $botStatuses[] = getTradeStatusWithCustomThresholds($strategy['last_trade'], $config['trade_status_thresholds'])['status'];
    }
    if (!empty($strategy['last_trade_attempt'])) {
        $botStatuses[] = getTradeStatusWithCustomThresholds($strategy['last_trade_attempt'], $config['trade_status_thresholds'])['status'];
    }
    $feeUsd = (isset($strategy['fee_currency_balance_usd']) && $strategy['fee_currency_balance_usd'] !== null && $strategy['fee_currency_balance_usd'] !== '')
        ? floatval($strategy['fee_currency_balance_usd']) : null;
    if ($feeUsd !== null) {
        $feeStatus = getFeeBalanceStatus($feeUsd, $config['fee_balance_thresholds']);
        if ($feeStatus['status'] !== 'none') {
            $botStatuses[] = $feeStatus['status'];
        }
    }
}
$botWorstStatus = !empty($botStatuses) ? worstStatus($botStatuses) : 'none';

// Calculate overall totals (bot + asset positions)
$totalNav = $botNavUsd;
$totalNavBtc = $botNavBtc;
$totalNavEth = $botNavEth;
$totalNavEur = $botNavEur;
foreach ($portfolioAssets as $pa) {
    $assetPrice = $coinPrices[$pa['asset']] ?? null;
    if ($assetPrice !== null) {
        $posNav = floatval($pa['quantity']) * $assetPrice;
        $totalNav += $posNav;
        if ($btcPrice !== null) { $totalNavBtc += calcNavInCoin($posNav, $btcPrice); }
        if ($ethPrice !== null) { $totalNavEth += calcNavInCoin($posNav, $ethPrice); }
        if ($eurPrice !== null) { $totalNavEur += calcNavInCoin($posNav, $eurPrice); }
    }
}

// Get current time for display
$currentTime = new DateTime();
$currentTimeFormatted = $currentTime->format('d.m.Y H:i:s');

$hasRows = ($botCount > 0 || !empty($portfolioAssets));

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
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAssetModal">+ Add Position</button>
            </div>
            <div class="col-md-4 text-end">
                <div class="dashboard-info">
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
                    Position successfully deleted.
                <?php elseif ($flashAction === 'inserted'): ?>
                    Position <strong><?php echo safeOutput($flashName); ?></strong> successfully added.
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

        <?php if (!$hasRows): ?>
            <div class="alert alert-info" role="alert">
                No positions available yet. Use "+ Add Position" to add one.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Position</th>
                            <th>Asset</th>
                            <th># Asset</th>
                            <th>NAV</th>
                            <th>NAV-BTC</th>
                            <th>NAV-ETH</th>
                            <th>NAV-EUR</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($botCount > 0): ?>
                            <tr class="status-<?php echo $botWorstStatus; ?>">
                                <td><strong>#<?php echo $botCount; ?> multicoin</strong></td>
                                <td></td>
                                <td></td>
                                <td><code><?php echo formatNav($botNavUsd); ?></code></td>
                                <td><code><?php echo $btcPrice !== null ? formatNav($botNavBtc, 6) : '-'; ?></code></td>
                                <td><code><?php echo $ethPrice !== null ? formatNav($botNavEth, 4) : '-'; ?></code></td>
                                <td><code><?php echo $eurPrice !== null ? formatNav($botNavEur) : '-'; ?></code></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($portfolioAssets as $pa):
                            $assetSymbol = $pa['asset'];
                            $qty = floatval($pa['quantity']);
                            $assetPriceData = $pricesData[$assetSymbol] ?? null;
                            $assetPrice = $assetPriceData ? $assetPriceData['price_usdt'] : null;
                            $posNav = $assetPrice !== null ? $qty * $assetPrice : null;

                            // Color coding: use price timestamp freshness
                            if ($assetPriceData !== null) {
                                $priceStatus = getDataStatus($assetPriceData['timestamp']);
                                $statusClass = 'status-' . $priceStatus['status'];
                            } else {
                                $statusClass = 'status-unknown';
                            }

                            $navBtcCalc = $posNav !== null ? calcNavInCoin($posNav, $btcPrice) : null;
                            $navEthCalc = $posNav !== null ? calcNavInCoin($posNav, $ethPrice) : null;
                            $navEurCalc = $posNav !== null ? calcNavInCoin($posNav, $eurPrice) : null;
                        ?>
                            <tr class="<?php echo $statusClass; ?>">
                                <td><?php echo safeOutput($pa['account']); ?></td>
                                <td><code><?php echo safeOutput($assetSymbol); ?></code></td>
                                <td><code><?php echo rtrim(rtrim(number_format($qty, 8, '.', ''), '0'), '.'); ?></code></td>
                                <td><code><?php echo $posNav !== null ? formatNav($posNav) : '<span class="text-muted">no price</span>'; ?></code></td>
                                <td><code><?php echo $navBtcCalc !== null ? formatNav($navBtcCalc, 6) : '-'; ?></code></td>
                                <td><code><?php echo $navEthCalc !== null ? formatNav($navEthCalc, 4) : '-'; ?></code></td>
                                <td><code><?php echo $navEurCalc !== null ? formatNav($navEurCalc) : '-'; ?></code></td>
                                <td>
                                    <form method="post" action="portfolio.php"
                                          onsubmit="return confirm('Delete «<?php echo safeOutput($pa['account'] . ' / ' . $assetSymbol); ?>»?')">
                                        <input type="hidden" name="action" value="delete_asset">
                                        <input type="hidden" name="id" value="<?php echo (int) $pa['id']; ?>">
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

    <!-- Add Position Modal -->
    <div class="modal fade" id="addAssetModal" tabindex="-1" aria-labelledby="addAssetModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="portfolio.php">
                    <input type="hidden" name="action" value="add_asset">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addAssetModalLabel">Add Position</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="account" class="form-label">Account <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="account" name="account"
                                   placeholder="Binance, Ledger, Bank..." maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label for="asset" class="form-label">Asset <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="asset" name="asset"
                                   placeholder="BTC, ETH, USDT..." maxlength="20" required>
                            <div class="form-text">Coin symbol as it appears in the price table</div>
                        </div>
                        <div class="mb-3">
                            <label for="quantity" class="form-label"># Asset <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity"
                                   placeholder="1.5" step="any" min="0" required>
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
</body>
</html>
