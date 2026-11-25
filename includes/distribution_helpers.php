<?php
// WP12 helpers. Names start with "dist" so they never clash with the rel* helpers.
// Needs includes/relief_helpers.php (relQuery, relEsc, relStock, ...).

// Households that are sheltered right now at a center for a disaster.
// One row per household, with its head resident (the person who receives the items).
function distBeneficiaries($conn, $disasterId, $centerId)
{
    return relQuery(
        $conn,
        "SELECT h.household_id, h.barangay, h.head_resident_id,
                CONCAT(r.first_name, ' ', r.last_name) AS head_name,
                COUNT(e.evacuee_id) AS members
         FROM evacuees e
         JOIN households h ON h.household_id = e.household_id
         LEFT JOIN residents r ON r.resident_id = h.head_resident_id
         WHERE e.disaster_id = ? AND e.center_id = ? AND e.status = 'Checked-in'
         GROUP BY h.household_id, h.barangay, h.head_resident_id, r.first_name, r.last_name
         ORDER BY h.barangay, head_name",
        'ii',
        [$disasterId, $centerId]
    );
}

// The lines that were asked for in the request (no household yet, nothing given yet).
function distRequestedLines($conn, $distId)
{
    return relQuery(
        $conn,
        "SELECT di.distribution_item_id, di.relief_item_id, di.quantity, ri.item_name, ri.unit
         FROM distribution_items di
         JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
         WHERE di.distribution_id = ? AND di.date_given IS NULL
         ORDER BY ri.item_name",
        'i',
        [$distId]
    );
}

// Splits $total into $parts whole numbers as evenly as possible (the first ones get the extra).
function distEvenSplit($total, $parts)
{
    if ($parts <= 0) return [];
    $base = intdiv($total, $parts);
    $extra = $total % $parts;
    $out = [];
    for ($i = 0; $i < $parts; $i++) $out[] = $base + ($i < $extra ? 1 : 0);
    return $out;
}
