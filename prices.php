<?php

/**
 * Prices page – shows all current coin prices from prices_current table
 * Also handles manual price entry via POST form (PRG pattern)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$config = include __DIR__ . '/config/config.php';
date_default_timezone_set($config['timezone']);

// --- POST handler (PRG pattern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_price') {
    $coin = strtoupper(trim($_POST['coin'] ?? ''));
    if (preg_match('/^[A-Z0-9]{1,20}$/', $coin)) {
        $pdo = getPDO();
        $deleted = deletePrice($pdo, $coin);
        $msg = $deleted ? 'success' : 'error';
        $detail = $deleted ? '' : urlencode('Coin not found');
        $deletedCoin = $deleted ? urlencode($coin) : '';
        header('Location: prices.php?msg=' . $msg . '&action=deleted&coin=' . $deletedCoin . ($detail ? '&detail=' . $detail : ''));
    } else {
        header('Location: prices.php?msg=error&detail=' . urlencode('Invalid coin symbol'));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    $coin = strtoupper(trim($_POST['coin'] ?? ''));
    $priceRaw = trim($_POST['price_usdt'] ?? '');
    $ctExchange = trim($_POST['ct_exchange'] ?? '');
    $timestampRaw = trim($_POST['timestamp'] ?? '');

    // Validate coin
    if (!preg_match('/^[A-Z0-9]{1,20}$/', $coin)) {
        $errors[] = 'Coin must be 1-20 uppercase alphanumeric characters (e.g. BTC, ETH)';
    }

    // Validate price
    $priceValidation = validateNumeric($priceRaw, 'price_usdt');
    if (!$priceValidation['valid']) {
        $errors[] = $priceValidation['error'];
    } else {
        $priceUsdt = floatval($priceRaw);
        if ($priceUsdt <= 0) {
            $errors[] = 'Price must be greater than 0';
        }
    }

    // Validate ct_exchange (optional)
    $ctExchangeValue = null;
    if ($ctExchange !== '') {
        if (strlen($ctExchange) > 50) {
            $errors[] = 'Exchange name must be max 50 characters';
        } else {
            $ctExchangeValue = $ctExchange;
        }
    }

    // Validate timestamp (default to now if empty)
    if ($timestampRaw === '') {
        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
    } else {
        // Convert datetime-local format (YYYY-MM-DDTHH:MM) to YYYY-MM-DD HH:MM:SS
        $timestampNormalized = str_replace('T', ' ', $timestampRaw);
        if (strlen($timestampNormalized) === 16) {
            $timestampNormalized .= ':00';
        }
        $datetimeValidation = validateDatetime($timestampNormalized);
        if (!$datetimeValidation['valid']) {
            $errors[] = $datetimeValidation['error'];
        } else {
            $timestamp = $timestampNormalized;
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getPDO();
            $action = upsertPrice($pdo, $coin, $priceUsdt, $ctExchangeValue, $timestamp);
            logMessage('api', "prices.php manual add - Coin '{$coin}' {$action}. Price: {$priceUsdt} USDT");
            header('Location: prices.php?msg=success&action=' . urlencode($action) . '&coin=' . urlencode($coin));
        } catch (Exception $e) {
            logMessage('error', 'prices.php manual add - DB error: ' . $e->getMessage());
            header('Location: prices.php?msg=error&detail=' . urlencode('Database error'));
        }
    } else {
        header('Location: prices.php?msg=error&detail=' . urlencode(implode('; ', $errors)));
    }
    exit;
}

// --- GET: load data ---
$pdo = getPDO();
$prices = getAllPrices($pdo);

$currentTimeFormatted = (new DateTime())->format('d.m.Y H:i:s');

// Flash message from redirect
$flashMsg = $_GET['msg'] ?? null;
$flashAction = $_GET['action'] ?? null;
$flashCoin = $_GET['coin'] ?? null;
$flashDetail = $_GET['detail'] ?? null;

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Current Prices – <?php echo safeOutput($config['dashboard_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container-fluid py-5">

        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-8 d-flex align-items-center gap-3">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">&#8592; Dashboard</a>
                <h1 class="mb-0">Current Prices</h1>
            </div>
            <div class="col-md-4 text-end">
                <div class="dashboard-info d-flex flex-column align-items-end gap-2">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPriceModal">
                        + Add Price
                    </button>
                    <p class="mb-0">
                        <small class="text-muted">
                            Coins tracked: <strong><?php echo count($prices); ?></strong>
                        </small>
                    </p>
                    <p class="mb-0">
                        <small class="text-muted">
                            Page loaded: <strong id="updateTime"><?php echo $currentTimeFormatted; ?></strong>
                        </small>
                    </p>
                </div>
            </div>
        </div>

        <!-- Flash message -->
        <?php if ($flashMsg === 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                $coinLabel = $flashCoin ? safeOutput(strtoupper($flashCoin)) : 'Coin';
                $verb = $flashAction === 'updated' ? 'updated' : 'added';
                echo "<strong>{$coinLabel}</strong> successfully {$verb}.";
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($flashMsg === 'error'): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error:</strong> <?php echo $flashDetail ? safeOutput($flashDetail) : 'An unknown error occurred.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Price table -->
        <?php if (empty($prices)): ?>
            <div class="alert alert-info" role="alert">
                No prices yet. Use the <strong>+ Add Price</strong> button or send prices via <code>POST /api/prices.php</code>.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Coin</th>
                            <th>Price (USDT)</th>
                            <th>Exchange</th>
                            <th>Source Timestamp</th>
                            <th>Age</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prices as $row):
                            $isManual = ($row['source'] ?? 'bot') === 'manual';
                            if ($isManual) {
                                $statusClass = 'status-unknown';
                                $status = null;
                            } else {
                                $status = getDataStatus($row['timestamp']);
                                $statusClass = 'status-' . $status['status'];
                            }
                        ?>
                            <tr class="<?php echo $statusClass; ?>">
                                <td>
                                    <strong><?php echo safeOutput($row['coin']); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo number_format(floatval($row['price_usdt']), 2, ',', '.'); ?></code>
                                    <small class="text-muted ms-1">USDT</small>
                                </td>
                                <td>
                                    <?php echo $row['ct_exchange'] ? safeOutput($row['ct_exchange']) : '<span class="text-muted">–</span>'; ?>
                                </td>
                                <td>
                                    <small><?php echo safeOutput(formatTimestamp($row['timestamp'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($isManual): ?>
                                        <span class="text-muted">–</span>
                                    <?php else: ?>
                                        <span class="status-indicator"><?php echo $status['indicator']; ?></span>
                                        <small class="text-muted"><?php echo $status['time_diff']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="prices.php"
                                          onsubmit="return confirm('Delete price for <?php echo safeOutput($row['coin']); ?>?')">
                                        <input type="hidden" name="action" value="delete_price">
                                        <input type="hidden" name="coin" value="<?php echo safeOutput($row['coin']); ?>">
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

    <!-- Add Price Modal -->
    <div class="modal fade" id="addPriceModal" tabindex="-1" aria-labelledby="addPriceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="prices.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addPriceModalLabel">Add / Update Price</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="coin" class="form-label">Coin <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="coin" name="coin"
                                   placeholder="BTC" maxlength="20" required
                                   oninput="this.value = this.value.toUpperCase()">
                            <div class="form-text">1–20 uppercase alphanumeric characters</div>
                        </div>
                        <div class="mb-3">
                            <label for="price_usdt" class="form-label">Price (USDT) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="price_usdt" name="price_usdt"
                                   placeholder="50000.00" step="any" min="0.00000001" required>
                        </div>
                        <div class="mb-3">
                            <label for="ct_exchange" class="form-label">Exchange <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="ct_exchange" name="ct_exchange"
                                   placeholder="binance" maxlength="50">
                        </div>
                        <div class="mb-3">
                            <label for="timestamp" class="form-label">Timestamp <span class="text-muted">(optional, default: now)</span></label>
                            <input type="datetime-local" class="form-control" id="timestamp" name="timestamp">
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
        // Pre-fill timestamp with current local time when modal opens
        document.getElementById('addPriceModal').addEventListener('show.bs.modal', function () {
            const now = new Date();
            // Format: YYYY-MM-DDTHH:MM
            const pad = n => String(n).padStart(2, '0');
            const local = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
                        + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
            const tsInput = document.getElementById('timestamp');
            if (!tsInput.value) {
                tsInput.value = local;
            }
        });
    </script>
</body>
</html>
