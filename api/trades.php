<?php

/**
 * API Endpoint for logging trades
 *
 * POST /api/trades.php
 * Content-Type: application/json
 *
 * Request body:
 * {
 *   "api_key": "your_secret_api_key_here",
 *   "strategy_name": "btc_usdt_strategy_1",
 *   "success": true,
 *   "traded_at": "2025-10-22 14:30:00"
 * }
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, [
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$config = include __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if ($input === null) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON format']);
}

// Validate required parameters
$required = ['api_key', 'strategy_name', 'success', 'traded_at'];
$validation = validateRequired($input, $required);

if (!$validation['valid']) {
    sendJsonResponse(400, [
        'status' => 'error',
        'message' => 'Missing required parameters: ' . implode(', ', $validation['missing'])
    ]);
}

// Validate API key
if (!validateApiKey($input['api_key'], $config['api_key'])) {
    logMessage('api', 'Invalid API key attempt on trades endpoint');
    sendJsonResponse(401, ['status' => 'error', 'message' => 'Invalid API key']);
}

// Validate strategy name
$nameValidation = validateStrategyName($input['strategy_name']);
if (!$nameValidation['valid']) {
    sendJsonResponse(400, ['status' => 'error', 'message' => $nameValidation['error']]);
}

// Validate success (boolean)
if (!is_bool($input['success']) && !in_array($input['success'], [0, 1, '0', '1'], true)) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'success must be a boolean or 0/1']);
}
$success = filter_var($input['success'], FILTER_VALIDATE_BOOLEAN);

// Validate traded_at datetime
$dtValidation = validateDatetime($input['traded_at']);
if (!$dtValidation['valid']) {
    sendJsonResponse(400, ['status' => 'error', 'message' => $dtValidation['error']]);
}

try {
    $pdo = getPDO();

    // Verify strategy exists
    $checkStmt = $pdo->prepare('SELECT id FROM strategies WHERE strategy_name = ?');
    $checkStmt->execute([trim($input['strategy_name'])]);
    if (!$checkStmt->fetch()) {
        sendJsonResponse(404, ['status' => 'error', 'message' => 'Strategy not found']);
    }

    $tradeId = insertTrade($pdo, trim($input['strategy_name']), $success, $input['traded_at']);

    logMessage('api', "Trade logged: strategy={$input['strategy_name']}, success=" . ($success ? 'true' : 'false') . ", traded_at={$input['traded_at']}");

    sendJsonResponse(201, [
        'status' => 'success',
        'message' => 'Trade logged successfully',
        'trade_id' => $tradeId
    ]);
} catch (Exception $e) {
    logMessage('error', 'trades.php error: ' . $e->getMessage());
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Internal server error']);
}
