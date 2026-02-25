<?php

/**
 * API Endpoint for updating strategy transfers value (inline edit)
 *
 * POST /api/transfers.php
 * Content-Type: application/json
 *
 * Request body:
 * {
 *   "id": 1,
 *   "transfers": 5000.00
 * }
 *
 * Note: This endpoint is used by the dashboard inline-edit and does not require
 * an API key (same trust model as the delete form on the dashboard).
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if ($input === null) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Invalid JSON format']);
}

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'Valid strategy id required']);
}

if (!isset($input['transfers']) || !is_numeric($input['transfers'])) {
    sendJsonResponse(400, ['status' => 'error', 'message' => 'transfers must be numeric']);
}

$transfers = floatval($input['transfers']);

try {
    $pdo = getPDO();
    $updated = updateTransfers($pdo, $id, $transfers);

    if ($updated) {
        sendJsonResponse(200, ['status' => 'success', 'message' => 'Transfers updated']);
    } else {
        sendJsonResponse(404, ['status' => 'error', 'message' => 'Strategy not found']);
    }
} catch (Exception $e) {
    logMessage('error', 'transfers.php error: ' . $e->getMessage());
    sendJsonResponse(500, ['status' => 'error', 'message' => 'Internal server error']);
}
