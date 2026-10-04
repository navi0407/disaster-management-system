<?php
/**
 * Generic audit logger — call this after any insert/update/delete that matters.
 * Requires $conn (a mysqli connection) to already exist.
 *
 * Usage:
 *   logAudit($conn, $userId, 'Added disaster type', 'disaster_types', null, 'Typhoon');
 *   logAudit($conn, $userId, 'Updated disaster severity', 'disasters', 'Moderate', 'Severe', 'DST-2026-0042');
 */
function logAudit($conn, $userId, $activity, $affectedTable, $oldValue = null, $newValue = null, $referenceNumber = null) {
    $sql = "INSERT INTO audit_logs (user_id, activity, affected_table, old_value, new_value, reference_number, log_datetime)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'isssss', $userId, $activity, $affectedTable, $oldValue, $newValue, $referenceNumber);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
