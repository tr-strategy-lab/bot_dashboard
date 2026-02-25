<?php

/**
 * API Endpoint for recording individual trade results
 *
 * POST /api/trade.php
 * Content-Type: application/json
 *
 * Request body:
 * {
 *   "api_key": "your-api-key",
 *   "strategy_name": "Katana",
 *   "timestamp": "2026-02-25 14:30:00",
 *   "success": true
 * }
 *
 * success = true:  swap executed on-chain, slippage within bounds
 * success = false: slippage too high, timeout, or exception
 *
 * Also updates last_trade_attempt (always) and last_trade (on success)
 * in the strategies table.
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$config = include __DIR__ . '/../config/config.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if ($input === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON format']);
    exit;
}

// Validate required fields
$requiredFields = ['api_key', 'strategy_name', 'timestamp', 'success'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

// Validate API key
if (!validateApiKey($input['api_key'], $config['api_key'])) {
    logMessage('api', 'Invalid API key attempt on trade endpoint');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Validate strategy name
$nameValidation = validateStrategyName($input['strategy_name']);
if (!$nameValidation['valid']) {
    http_response_code(400);
    echo json_encode(['error' => $nameValidation['error']]);
    exit;
}

// Validate success (boolean)
if (!is_bool($input['success']) && !in_array($input['success'], [0, 1, '0', '1'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'success must be a boolean or 0/1']);
    exit;
}
$success = filter_var($input['success'], FILTER_VALIDATE_BOOLEAN);

// Validate timestamp
$dtValidation = validateDatetime($input['timestamp']);
if (!$dtValidation['valid']) {
    http_response_code(400);
    echo json_encode(['error' => $dtValidation['error']]);
    exit;
}

$strategyName = trim($input['strategy_name']);
$timestamp = $input['timestamp'];

try {
    $pdo = getPDO();

    // Verify strategy exists
    $checkStmt = $pdo->prepare('SELECT id FROM strategies WHERE strategy_name = ?');
    $checkStmt->execute([$strategyName]);
    if (!$checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => "Strategy not found: {$strategyName}"]);
        exit;
    }

    // Insert trade record
    insertTrade($pdo, $strategyName, $success, $timestamp);

    // Update last_trade_attempt (always) and last_trade (on success)
    if ($success) {
        $stmt = $pdo->prepare('UPDATE strategies SET last_trade = ?, last_trade_attempt = ? WHERE strategy_name = ?');
        $stmt->execute([$timestamp, $timestamp, $strategyName]);
    } else {
        $stmt = $pdo->prepare('UPDATE strategies SET last_trade_attempt = ? WHERE strategy_name = ?');
        $stmt->execute([$timestamp, $strategyName]);
    }

    logMessage('api', "Trade recorded: strategy={$strategyName}, success=" . ($success ? 'true' : 'false') . ", timestamp={$timestamp}");

    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (Exception $e) {
    logMessage('error', 'trade.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
