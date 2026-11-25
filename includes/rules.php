<?php
// WP17 (Lab 49): small reusable rule checks you can call from any page.
// Each function returns an error message, or '' when the rule is satisfied.
// That is the same style as includes/validate.php, so you can drop them into vCollect([...]).

// Rule 12: a Closed disaster cannot be changed by ordinary users. Only an Administrator may.
//   Use it on the disaster edit page and on anything that changes data under a disaster:
//     $errors = vCollect([ ruleDisasterOpen($conn, $disasterId) ]);
function ruleDisasterOpen($conn, $disasterId)
{
    $stmt = mysqli_prepare($conn, "SELECT status FROM disasters WHERE disaster_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $disasterId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return 'That disaster does not exist.';
    if (strtolower((string) $row['status']) === 'closed' && !authIsAdmin())
        return 'This disaster is Closed. Only an administrator can change its records.';
    return '';
}

// Rule 5: a center cannot take more people than its capacity unless the user is allowed to override.
//   $adding = how many people are being checked in (usually 1).
function ruleCenterHasRoom($conn, $centerId, $adding = 1, $override = false)
{
    $stmt = mysqli_prepare($conn, "SELECT center_name, capacity,
        (SELECT COUNT(*) FROM evacuees e WHERE e.center_id = evacuation_centers.center_id AND e.status = 'Checked-in') AS inside
        FROM evacuation_centers WHERE center_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $centerId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return 'That evacuation center does not exist.';
    if ((int) $row['inside'] + $adding > (int) $row['capacity'] && !$override)
        return $row['center_name'] . ' is full (' . (int) $row['inside'] . ' of ' . (int) $row['capacity'] . '). An authorized user must approve going over capacity.';
    return '';
}

// Rule 3: a resident cannot be checked in at two places at once.
function ruleResidentNotCheckedIn($conn, $residentId)
{
    $stmt = mysqli_prepare($conn, "SELECT c.center_name FROM evacuees e JOIN evacuation_centers c ON c.center_id = e.center_id
                                   WHERE e.resident_id = ? AND e.status = 'Checked-in' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $residentId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? 'This resident is already checked in at ' . $row['center_name'] . '. Check them out first.' : '';
}
