<?php
/**
 * Generic CRUD engine — reusable across every module.
 * Requires $conn (a mysqli connection) to already exist, e.g. via require 'config/db.php'.
 *
 * Usage examples:
 *   dbInsert($conn, 'disaster_types', ['type_name' => 'Typhoon', 'description' => '...']);
 *   dbUpdate($conn, 'disaster_types', 'disaster_type_id', 5, ['type_name' => 'Flood']);
 *   dbDelete($conn, 'disaster_types', 'disaster_type_id', 5);
 *   dbGetAll($conn, 'disaster_types', 'type_name');
 *   dbGetById($conn, 'disaster_types', 'disaster_type_id', 5);
 *   dbSearch($conn, 'disaster_types', ['type_name', 'description'], 'flood');
 */

function dbInsert($conn, $table, $data) {
    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO $table (" . implode(',', $fields) . ") VALUES ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    $types = str_repeat('s', count($fields)); // MySQL casts strings to the right column type automatically
    mysqli_stmt_bind_param($stmt, $types, ...array_values($data));
    mysqli_stmt_execute($stmt);
    $insertId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $insertId;
}

function dbUpdate($conn, $table, $idField, $id, $data) {
    $set = implode(',', array_map(fn($f) => "$f = ?", array_keys($data)));
    $sql = "UPDATE $table SET $set WHERE $idField = ?";
    $stmt = mysqli_prepare($conn, $sql);
    $types = str_repeat('s', count($data)) . 'i';
    $values = array_values($data);
    $values[] = $id;
    mysqli_stmt_bind_param($stmt, $types, ...$values);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function dbDelete($conn, $table, $idField, $id) {
    $sql = "DELETE FROM $table WHERE $idField = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function dbGetAll($conn, $table, $orderBy = null) {
    $sql = "SELECT * FROM $table";
    if ($orderBy) $sql .= " ORDER BY $orderBy";
    $result = mysqli_query($conn, $sql);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function dbGetById($conn, $table, $idField, $id) {
    $sql = "SELECT * FROM $table WHERE $idField = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function dbSearch($conn, $table, $searchFields, $searchTerm) {
    if (empty($searchTerm)) return dbGetAll($conn, $table);
    $conditions = implode(' OR ', array_map(fn($f) => "$f LIKE ?", $searchFields));
    $sql = "SELECT * FROM $table WHERE $conditions";
    $stmt = mysqli_prepare($conn, $sql);
    $types = str_repeat('s', count($searchFields));
    $likeTerm = "%$searchTerm%";
    $values = array_fill(0, count($searchFields), $likeTerm);
    mysqli_stmt_bind_param($stmt, $types, ...$values);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}
