<?php
/**
 * Helpers for the evacuee module.
 * Requires $conn (a mysqli connection) to already exist.
 */

/**
 * Runs any SELECT with optional parameters and returns every row.
 * Handy when a page builds its query piece by piece based on filters.
 *
 * Usage: dbQueryAll($conn, "SELECT * FROM evacuees WHERE status = ?", 's', ['Checked-in']);
 */
function dbQueryAll($conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** How many people are really checked in at a center right now (counted, not cached). */
function countCheckedIn($conn, $centerId) {
    $rows = dbQueryAll($conn,
        "SELECT COUNT(*) AS total FROM evacuees WHERE center_id = ? AND status = 'Checked-in'",
        'i', [$centerId]);
    return (int) $rows[0]['total'];
}

/**
 * Re-counts the people checked in and saves that number as the center's current_occupancy.
 * Recounting (instead of adding 1 or subtracting 1) means the cached number can never drift.
 * Call this after every check-in and check-out.
 */
function syncCenterOccupancy($conn, $centerId) {
    $sql = "UPDATE evacuation_centers
            SET current_occupancy = (SELECT COUNT(*) FROM evacuees WHERE center_id = ? AND status = 'Checked-in')
            WHERE center_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $centerId, $centerId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Returns the resident's current checked-in stay (with the center name), or null if they have none. */
function getActiveStay($conn, $residentId) {
    $rows = dbQueryAll($conn,
        "SELECT e.*, c.center_name FROM evacuees e
         LEFT JOIN evacuation_centers c ON e.center_id = c.center_id
         WHERE e.resident_id = ? AND e.status = 'Checked-in' LIMIT 1",
        'i', [$residentId]);
    return $rows[0] ?? null;
}
