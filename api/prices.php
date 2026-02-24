<?php

/**
 * API Endpoint for updating current coin prices
 *
 * POST /api/prices.php
 * Content-Type: application/json
 *
 * Request body:
 * {
 *   "api_key": "your_secret_api_key_here",
 *   "coin": "BTC",
 *   "price_usdt": 50000.12345678,
 *   "ct_exchange": "binance",
 *   "timestamp": "2025-10-22 14:30:00"
 * }
 *
 * A coin is stored only once (UNIQUE). Sending a price for an existing coin will UPDATE it.
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('DEBUG_MODE', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$config = include __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
if (DEBUG_MODE) {
    logMessage('api', 'prices.php - Raw input: ' . substr($rawInput, 0, 500));
}

$input = json_decode($rawInput, true);
if ($input === null) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON format']);
}

// Validate required parameters
$required = ['api_key', 'coin', 'price_usdt', 'timestamp'];
$validation = validateRequired($input, $required);
if (!$validation['valid']) {
    logMessage('api', 'prices.php - Missing parameters: ' . implode(', ', $validation['missing']));
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => 'Missing required parameters: ' . implode(', ', $validation['missing'])
    ]);
}

// Validate API key
if (!validateApiKey($input['api_key'], $config['api_key'])) {
    logMessage('api', 'prices.php - Invalid API key attempt');
    sendJsonResponse(401, ['status' => 'error', 'message' => 'Invalid API key']);
}

// Validate coin symbol: 1-20 uppercase alphanumeric characters
$coin = strtoupper(trim($input['coin']));
if (!preg_match('/^[A-Z0-9]{1,20}$/', $coin)) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Coin must be 1-20 uppercase alphanumeric characters (e.g. BTC, ETH)']);
}

// Validate price_usdt
$priceValidation = validateNumeric($input['price_usdt'], 'price_usdt');
if (!$priceValidation['valid']) {
    sendJsonResponse(400, ['status' => 'error', 'message' => $priceValidation['error']]);
}
$priceUsdt = floatval($input['price_usdt']);
if ($priceUsdt <= 0) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'price_usdt must be greater than 0']);
}

// Validate ct_exchange (optional)
$ctExchange = null;
if (isset($input['ct_exchange']) && $input['ct_exchange'] !== null && $input['ct_exchange'] !== '') {
    $ctExchange = trim($input['ct_exchange']);
    if (strlen($ctExchange) > 50) {
        sendJsonResponse(400, ['status' => 'error', 'message' => 'ct_exchange must be max 50 characters']);
    }
}

// Validate timestamp
$datetimeValidation = validateDatetime($input['timestamp']);
if (!$datetimeValidation['valid']) {
    sendJsonResponse(400, ['status' => 'error', 'message' => $datetimeValidation['error']]);
}
$timestamp = $input['timestamp'];

try {
    $pdo = getPDO();

    // Check if coin already exists
    $checkStmt = $pdo->prepare('SELECT id FROM prices_current WHERE coin = ?');
    $checkStmt->execute([$coin]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare('
            UPDATE prices_current
            SET price_usdt = ?, ct_exchange = ?, timestamp = ?
            WHERE coin = ?
        ');
        $stmt->execute([$priceUsdt, $ctExchange, $timestamp, $coin]);
        $action = 'updated';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO prices_current (coin, price_usdt, ct_exchange, timestamp)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$coin, $priceUsdt, $ctExchange, $timestamp]);
        $action = 'inserted';
    }

    logMessage('api', "prices.php - Coin '{$coin}' {$action}. Price: {$priceUsdt} USDT, Exchange: {$ctExchange}, Timestamp: {$timestamp}");

    sendJsonResponse(200, [
        'status' => 'success',
        'message' => "Price for {$coin} {$action} successfully",
        'coin' => $coin,
        'price_usdt' => $priceUsdt
    ]);

} catch (Exception $e) {
    logMessage('error', 'prices.php - Database error: ' . $e->getMessage());
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Internal server error']);
}
