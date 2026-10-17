<?php
// WP10 helpers: shared by the relief pages.
// Names start with "rel" so they never clash with the WP8 or WP9 helpers.

// Picks style.css next to the pages (hosted site) or assets/css/style.css (laptop).
function relCssPath() {
    return file_exists(__DIR__ . '/../style.css') ? 'style.css' : 'assets/css/style.css';
}

function relEsc($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function relCurrentUserId() {
    return (int) ($_SESSION['user_id'] ?? 1);
}

// Runs a prepared SELECT and returns all rows.
function relQuery($conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

// Stock is calculated, not stored: everything received minus everything given out.
// distribution_items is empty until WP12, so for now stock = total received.
function relStock($conn, $itemId) {
    $rows = relQuery($conn,
        "SELECT
            COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = ?), 0) -
            COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = ?), 0) AS stock",
        'ii', [$itemId, $itemId]);
    return (int) $rows[0]['stock'];
}

// Out of Stock at 0, Low Stock at or below the minimum, otherwise In Stock.
function relStockLevel($qty, $min) {
    $qty = (int) $qty;
    $min = (int) $min;
    if ($qty <= 0) return 'Out of Stock';
    if ($qty <= $min) return 'Low Stock';
    return 'In Stock';
}

function relStockClass($level) {
    if ($level === 'Out of Stock') return 'stock-out';
    if ($level === 'Low Stock') return 'stock-low';
    return 'stock-in';
}

// RCP-2026-0001, RCP-2026-0002, ... (the number restarts each year)
function relGenerateReceiptNumber($conn) {
    $prefix = 'RCP-' . date('Y') . '-';
    $rows = relQuery($conn,
        "SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_number, ?) AS UNSIGNED)), 0) AS last_no
         FROM relief_receipts WHERE receipt_number LIKE ?",
        'is', [strlen($prefix) + 1, $prefix . '%']);
    return $prefix . str_pad((string) ((int) $rows[0]['last_no'] + 1), 4, '0', STR_PAD_LEFT);
}

function relValidDate($value) {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}
