<?php

/**
 * API Endpoint for updating strategy data
 *
 * POST /api/update.php
 * Content-Type: application/json
 *
 * Request body:
 * {
 *   "api_key": "your_secret_api_key_here",
 *   "strategy_name": "btc_usdt_strategy_1",
 *   "nav": 10250.45678900,
 *   "nav_btc": 0.25678900,
 *   "nav_eth": 5.12345678,
 *   "system_token": "ETH",
 *   "fee_currency_balance": 10,
 *   "fee_currency_balance_usd": 20000,
 *   "last_trade": 1729609530,
 *   "timestamp": "2025-10-22 14:30:00"
 * }
 *
 * Note: nav_btc, nav_eth, system_token, fee_currency_balance, fee_currency_balance_usd, and last_trade are optional
 * Note: last_trade accepts Unix timestamp (integer or string)
 */

// Set headers
header('Content-Type: application/json; charset=utf-8');

// Enable error logging but disable output
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Debug mode - set to false in production
define('DEBUG_MODE', true);

// Requires
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

// Get configuration
$config = include __DIR__ . '/../config/config.php';

// Parse JSON input
$rawInput = file_get_contents('php://input');
if (DEBUG_MODE) {
    logMessage('api', 'Raw input received: ' . substr($rawInput, 0, 500));
}
$input = json_decode($rawInput, true);

if ($input === null) {
    logMessage('api', 'Invalid JSON received: ' . json_last_error_msg());
    $response = [
        'status' => 'error',
        'message' => 'Invalid JSON format'
    ];
    if (DEBUG_MODE) {
        $response['debug'] = 'JSON Error: ' . json_last_error_msg();
    }
    sendJsonResponse(400, $response);
}

// Validate required parameters
$required = ['api_key', 'strategy_name', 'nav', 'timestamp'];
$validation = validateRequired($input, $required);

if (DEBUG_MODE) {
    logMessage('api', 'Parameter validation: ' . json_encode($validation));
}

if (!$validation['valid']) {
    logMessage('api', 'Missing parameters: ' . implode(', ', $validation['missing']));
    $response = [
        'status' => 'error',
        'message' => 'Missing required parameters: ' . implode(', ', $validation['missing'])
    ];
    if (DEBUG_MODE) {
        $response['debug'] = $validation;
    }
    sendJsonResponse(400, $response);
}

// Validate API key
if (!validateApiKey($input['api_key'], $config['api_key'])) {
    logMessage('api', 'Invalid API key attempt');
    sendJsonResponse(401, [
        'status' => 'error',
        'message' => 'Invalid API key'
    ]);
}

// Validate strategy name
$nameValidation = validateStrategyName($input['strategy_name']);
if (!$nameValidation['valid']) {
    logMessage('api', 'Invalid strategy name: ' . $nameValidation['error']);
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => $nameValidation['error']
    ]);
}

// Validate NAV
$navValidation = validateNumeric($input['nav'], 'NAV');
if (!$navValidation['valid']) {
    logMessage('api', $navValidation['error']);
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => $navValidation['error']
    ]);
}

// Validate NAV-BTC (optional)
$navBtc = null;
if (isset($input['nav_btc']) && $input['nav_btc'] !== null && $input['nav_btc'] !== '') {
    $navBtcValidation = validateNumeric($input['nav_btc'], 'NAV-BTC');
    if (!$navBtcValidation['valid']) {
        logMessage('api', $navBtcValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $navBtcValidation['error']
        ]);
    }
    $navBtc = floatval($input['nav_btc']);
}

// Validate NAV-ETH (optional)
$navEth = null;
if (isset($input['nav_eth']) && $input['nav_eth'] !== null && $input['nav_eth'] !== '') {
    $navEthValidation = validateNumeric($input['nav_eth'], 'NAV-ETH');
    if (!$navEthValidation['valid']) {
        logMessage('api', $navEthValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $navEthValidation['error']
        ]);
    }
    $navEth = floatval($input['nav_eth']);
}

// Validate System Token (optional)
$systemToken = null;
if (isset($input['system_token']) && $input['system_token'] !== null && $input['system_token'] !== '') {
    $systemToken = trim($input['system_token']);
    // Simple validation: max 20 characters, alphanumeric
    if (strlen($systemToken) > 20 || !preg_match('/^[a-zA-Z0-9]+$/', $systemToken)) {
        logMessage('api', 'Invalid system token: must be max 20 alphanumeric characters');
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => 'System token must be max 20 alphanumeric characters'
        ]);
    }
}

// Validate Fee Currency Balance (optional)
$feeCurrencyBalance = null;
if (isset($input['fee_currency_balance']) && $input['fee_currency_balance'] !== null && $input['fee_currency_balance'] !== '') {
    $feeCurrencyValidation = validateNumeric($input['fee_currency_balance'], 'Fee Currency Balance');
    if (!$feeCurrencyValidation['valid']) {
        logMessage('api', $feeCurrencyValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $feeCurrencyValidation['error']
        ]);
    }
    $feeCurrencyBalance = floatval($input['fee_currency_balance']);
}

// Validate Fee Currency Balance USD (optional)
$feeCurrencyBalanceUsd = null;
if (isset($input['fee_currency_balance_usd']) && $input['fee_currency_balance_usd'] !== null && $input['fee_currency_balance_usd'] !== '') {
    $feeCurrencyUsdValidation = validateNumeric($input['fee_currency_balance_usd'], 'Fee Currency Balance USD');
    if (!$feeCurrencyUsdValidation['valid']) {
        logMessage('api', $feeCurrencyUsdValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $feeCurrencyUsdValidation['error']
        ]);
    }
    $feeCurrencyBalanceUsd = floatval($input['fee_currency_balance_usd']);
}

// Validate Last Trade (optional, Unix timestamp format)
$lastTrade = null;
if (isset($input['last_trade']) && $input['last_trade'] !== null && $input['last_trade'] !== '') {
    $lastTradeValidation = validateUnixTimestamp($input['last_trade']);
    if (!$lastTradeValidation['valid']) {
        logMessage('api', $lastTradeValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $lastTradeValidation['error']
        ]);
    }
    $lastTrade = $lastTradeValidation['datetime'];
}

// Validate Last Trade Attempt (optional, Unix timestamp format)
$lastTradeAttempt = null;
if (isset($input['last_trade_attempt']) && $input['last_trade_attempt'] !== null && $input['last_trade_attempt'] !== '') {
    $lastTradeAttemptValidation = validateUnixTimestamp($input['last_trade_attempt']);
    if (!$lastTradeAttemptValidation['valid']) {
        logMessage('api', $lastTradeAttemptValidation['error']);
        sendJsonResponse(400, [
            'status' => 'error',
            'message' => $lastTradeAttemptValidation['error']
        ]);
    }
    $lastTradeAttempt = $lastTradeAttemptValidation['datetime'];
}



// Validate coin_prices (optional) – expects {"BTC": 95000.0, "ETH": 2500.0, ...}
$coinPrices = [];
if (isset($input['coin_prices']) && $input['coin_prices'] !== null) {
    if (!is_array($input['coin_prices'])) {
        sendJsonResponse(400, ['status' => 'error', 'message' => 'coin_prices must be a JSON object']);
    }
    foreach ($input['coin_prices'] as $coin => $price) {
        $coin = strtoupper(trim((string) $coin));
        if (!preg_match('/^[A-Z0-9]{1,20}$/', $coin)) {
            sendJsonResponse(400, ['status' => 'error', 'message' => "coin_prices: invalid coin symbol '{$coin}'"]);
        }
        if (!is_numeric($price) || floatval($price) <= 0) {
            sendJsonResponse(400, ['status' => 'error', 'message' => "coin_prices: price for '{$coin}' must be a positive number"]);
        }
        $coinPrices[$coin] = floatval($price);
    }
}

// Validate datetime
$datetimeValidation = validateDatetime($input['timestamp']);
if (!$datetimeValidation['valid']) {
    logMessage('api', $datetimeValidation['error']);
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => $datetimeValidation['error']
    ]);
}

try {
    // Get database connection
    $pdo = getPDO();

    // Sanitize inputs
    $strategyName = trim($input['strategy_name']);
    $nav = floatval($input['nav']);
    $timestamp = $input['timestamp'];

    if (DEBUG_MODE) {
        logMessage('api', "Processing: Strategy=$strategyName, NAV=$nav, NAV_BTC=$navBtc, Fee=$feeCurrencyBalance");
        logMessage('api', "Fee Currency Balance received: " . (isset($input['fee_currency_balance']) ? $input['fee_currency_balance'] : 'NOT SET'));
        logMessage('api', "Full input data: " . json_encode($input));
    }

    // UPSERT logic: Try INSERT, if strategy_name exists, UPDATE
    $stmt = $pdo->prepare('
        INSERT INTO strategies (strategy_name, nav, nav_btc, last_update)
        VALUES (:strategy_name, :nav, :nav_btc, :timestamp)
        ON CONFLICT(strategy_name) DO UPDATE SET
            nav = :nav,
            nav_btc = :nav_btc,
            last_update = :timestamp
    ');

    // For SQLite compatibility, we use a different approach
    // First check if strategy exists
    $checkStmt = $pdo->prepare('SELECT id FROM strategies WHERE strategy_name = ?');
    $checkStmt->execute([$strategyName]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        // UPDATE existing strategy
        $updateStmt = $pdo->prepare('
            UPDATE strategies
            SET nav = ?, nav_btc = ?, nav_eth = ?, system_token = ?, fee_currency_balance = ?, fee_currency_balance_usd = ?, last_trade = ?, last_trade_attempt = ?, last_update = ?, source = ?
            WHERE strategy_name = ?
        ');
        $updateStmt->execute([$nav, $navBtc, $navEth, $systemToken, $feeCurrencyBalance, $feeCurrencyBalanceUsd, $lastTrade, $lastTradeAttempt, $timestamp, 'bot', $strategyName]);
    } else {
        // INSERT new strategy
        $insertStmt = $pdo->prepare('
            INSERT INTO strategies (strategy_name, nav, nav_btc, nav_eth, system_token, fee_currency_balance, fee_currency_balance_usd, last_trade, last_trade_attempt, last_update, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insertStmt->execute([$strategyName, $nav, $navBtc, $navEth, $systemToken, $feeCurrencyBalance, $feeCurrencyBalanceUsd, $lastTrade, $lastTradeAttempt, $timestamp, 'bot']);
    }

    // UPSERT coin prices into prices_current
    $savedPrices = [];
    if (!empty($coinPrices)) {
        $checkPrice = $pdo->prepare('SELECT id FROM prices_current WHERE coin = ?');
        $updatePrice = $pdo->prepare('UPDATE prices_current SET price_usdt = ?, ct_exchange = ?, timestamp = ?, source = ? WHERE coin = ?');
        $insertPrice = $pdo->prepare('INSERT INTO prices_current (coin, price_usdt, ct_exchange, timestamp, source) VALUES (?, ?, ?, ?, ?)');

        foreach ($coinPrices as $coin => $price) {
            $checkPrice->execute([$coin]);
            if ($checkPrice->fetch()) {
                $updatePrice->execute([$price, $strategyName, $timestamp, 'bot', $coin]);
            } else {
                $insertPrice->execute([$coin, $price, $strategyName, $timestamp, 'bot']);
            }
            $savedPrices[] = $coin;
        }
        logMessage('api', "Prices saved for: " . implode(', ', $savedPrices));
    }

    $logMsg = "Strategy '{$strategyName}' updated successfully. NAV: {$nav}";
    if ($navBtc !== null) {
        $logMsg .= ", NAV-BTC: {$navBtc}";
    }
    if ($navEth !== null) {
        $logMsg .= ", NAV-ETH: {$navEth}";
    }
    if ($systemToken !== null) {
        $logMsg .= ", System Token: {$systemToken}";
    }
    if ($feeCurrencyBalance !== null) {
        $logMsg .= ", Fee Currency Balance: {$feeCurrencyBalance}";
    }
    if ($feeCurrencyBalanceUsd !== null) {
        $logMsg .= ", Fee Currency Balance USD: {$feeCurrencyBalanceUsd}";
    }
    if ($lastTrade !== null) {
        $logMsg .= ", Last Trade: {$lastTrade}";
    }
    $logMsg .= ", Timestamp: {$timestamp}";
    logMessage('api', $logMsg);

    $response = [
        'status' => 'success',
        'message' => 'Data updated successfully',
        'strategy' => $strategyName
    ];
    if (!empty($savedPrices)) {
        $response['prices_saved'] = $savedPrices;
    }
    sendJsonResponse(200, $response);

} catch (Exception $e) {
    logMessage('error', 'Database operation error: ' . $e->getMessage());
    sendJsonResponse(500, [
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
}
