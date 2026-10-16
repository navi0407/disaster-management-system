<?php
// WP9 helpers: shared by all the resource pages.
// Names start with "res" so they never clash with the WP8 helpers.

// Picks style.css next to the pages (hosted site) or assets/css/style.css (laptop).
function resCssPath() {
    return file_exists(__DIR__ . '/../style.css') ? 'style.css' : 'assets/css/style.css';
}

function resEsc($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function resCurrentUserId() {
    return (int) ($_SESSION['user_id'] ?? 1);
}

// Runs a prepared SELECT and returns all rows.
function resQuery($conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// The true stock: every Receive adds, every Release subtracts.
function resCalcQuantity($conn, $resourceId) {
    $rows = resQuery($conn,
        "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Receive' THEN quantity
                                  WHEN transaction_type = 'Release' THEN -quantity
                                  ELSE 0 END), 0) AS total
         FROM resource_transactions WHERE resource_id = ?", 'i', [$resourceId]);
    return (int) $rows[0]['total'];
}

// Recounts from the transactions and saves it in resources.quantity_on_hand.
function recountQuantity($conn, $resourceId) {
    $qty = resCalcQuantity($conn, $resourceId);
    $stmt = mysqli_prepare($conn, "UPDATE resources SET quantity_on_hand = ? WHERE resource_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $qty, $resourceId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $qty;
}

// Out of Stock at 0, Low Stock at or below the minimum, otherwise In Stock.
function getStockLevel($qty, $min) {
    $qty = (int) $qty;
    $min = (int) $min;
    if ($qty <= 0) return 'Out of Stock';
    if ($qty <= $min) return 'Low Stock';
    return 'In Stock';
}

function stockClass($level) {
    if ($level === 'Out of Stock') return 'stock-out';
    if ($level === 'Low Stock') return 'stock-low';
    return 'stock-in';
}

// RES-0001, RES-0002, ...
function generateResourceCode($conn) {
    $rows = resQuery($conn,
        "SELECT COALESCE(MAX(CAST(SUBSTRING(resource_code, 5) AS UNSIGNED)), 0) AS last_no
         FROM resources WHERE resource_code LIKE 'RES-%'");
    return 'RES-' . str_pad((string) ((int) $rows[0]['last_no'] + 1), 4, '0', STR_PAD_LEFT);
}

function resValidDate($value) {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}
