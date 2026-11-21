<?php
// WP17 (Lab 48): shared form checks, so every form says the same thing the same way.
//
// Each check returns an error message (a string) or '' when the value is fine.
// A page collects them like this:
//
//   $errors = vCollect([
//       vRequired('Item name', $name) ?: vMax('Item name', $name, 100),
//       vWholeNumber('Quantity', $qty, 1, 1000000),
//       vDate('Date received', $date),
//   ]);
//
// The "?:" means: use the first message, and only check the second thing if the first passed.

function vCollect($messages) {
    return array_values(array_filter($messages, fn($m) => $m !== '' && $m !== null));
}

function vRequired($label, $value) {
    return trim((string) $value) === '' ? "$label is required." : '';
}

function vMax($label, $value, $max) {
    return mb_strlen(trim((string) $value)) > $max ? "$label must be $max characters or less." : '';
}

function vMin($label, $value, $min) {
    return mb_strlen(trim((string) $value)) < $min ? "$label must be at least $min characters." : '';
}

// Whole number between $min and $max. Rejects letters, decimals, negatives and blanks.
function vWholeNumber($label, $value, $min = 0, $max = 1000000) {
    $v = trim((string) $value);
    if ($v === '' || !ctype_digit($v)) return "$label must be a whole number, $min or more.";
    if ((int) $v < $min) return "$label must be at least $min.";
    if ((int) $v > $max) return "$label is too large (the most allowed is $max).";
    return '';
}

// A real calendar date written as YYYY-MM-DD (2026-02-31 is rejected).
function vValidDate($value) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
    return $d && $d->format('Y-m-d') === $value;
}

function vDate($label, $value, $required = false) {
    $v = trim((string) $value);
    if ($v === '') return $required ? "$label is required." : '';
    return vValidDate($v) ? '' : "$label is not a valid date.";
}

// For a datetime-local box ("2026-11-21T08:30") or a full "2026-11-21 08:30:00".
function vDateTime($label, $value, $required = false) {
    $v = trim((string) $value);
    if ($v === '') return $required ? "$label is required." : '';
    $v = str_replace('T', ' ', $v);
    foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format($fmt) === $v) return '';
    }
    return "$label is not a valid date and time.";
}

// The end must not come before the start. Both can be dates or datetimes; blank end is fine.
function vNotBefore($labelEnd, $end, $labelStart, $start) {
    if (trim((string) $end) === '' || trim((string) $start) === '') return '';
    return strtotime(str_replace('T', ' ', $end)) < strtotime(str_replace('T', ' ', $start))
        ? "$labelEnd cannot be earlier than $labelStart." : '';
}

function vNotFuture($label, $value) {
    $v = trim((string) $value);
    if ($v === '') return '';
    return strtotime(str_replace('T', ' ', $v)) > time() ? "$label cannot be in the future." : '';
}

function vNotPast($label, $value) {
    $v = trim((string) $value);
    if ($v === '') return '';
    return substr(str_replace('T', ' ', $v), 0, 10) < date('Y-m-d') ? "$label cannot be in the past." : '';
}

// The value must be one of the allowed choices (stops a forged dropdown value).
function vOneOf($label, $value, array $allowed) {
    return in_array($value, $allowed, true) ? '' : "$label has an invalid choice.";
}

// Philippine style numbers: 09171234567, +639171234567, or a landline like 078-123-4567. Blank is fine.
function vPhone($label, $value) {
    $v = trim((string) $value);
    if ($v === '') return '';
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $v) || strlen(preg_replace('/\D/', '', $v)) < 7)
        return "$label must contain only digits, spaces, + or - (7 to 20 characters).";
    return '';
}

// Letters, spaces, dots, hyphens and apostrophes (Dela Cruz, Ma. Luisa, O'Brien).
function vPersonName($label, $value, $max = 50) {
    $v = trim((string) $value);
    if ($v === '') return "$label is required.";
    if (mb_strlen($v) > $max) return "$label must be $max characters or less.";
    if (!preg_match("/^[\p{L}][\p{L}\s.'\-]*$/u", $v)) return "$label may only have letters, spaces, dots, hyphens and apostrophes.";
    return '';
}

// A record id from a dropdown must point to a row that exists.
function vExists($conn, $label, $table, $idField, $id) {
    $allowed = ['disasters' => 'disaster_id', 'disaster_types' => 'disaster_type_id', 'residents' => 'resident_id',
                'households' => 'household_id', 'evacuation_centers' => 'center_id', 'resources' => 'resource_id',
                'resource_categories' => 'category_id', 'relief_items' => 'relief_item_id'];
    if (($allowed[$table] ?? null) !== $idField) return "$label could not be checked.";   // only known tables, never user text
    if ((int) $id <= 0) return "Please choose the $label.";
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM $table WHERE $idField = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $found = mysqli_stmt_get_result($stmt)->num_rows > 0;
    mysqli_stmt_close($stmt);
    return $found ? '' : "That $label does not exist.";
}
