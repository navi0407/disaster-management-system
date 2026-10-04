<?php
/**
 * Generic status-lifecycle rules — reusable anywhere a record moves through
 * an ordered set of statuses (disasters now, relief_distributions later).
 *
 * Add a new flow by adding one line to $STATUS_FLOWS below, no other code changes needed.
 */

$STATUS_FLOWS = [
    'disasters' => ['Reported', 'Monitoring', 'Active', 'Response', 'Recovery', 'Closed'],
];

/**
 * Returns the statuses this record is allowed to move to next.
 * Strict forward progression only: you can stay where you are, or move
 * exactly one step forward, never backward and never skip a step.
 */
function getNextAllowedStatuses($flowKey, $currentStatus) {
    global $STATUS_FLOWS;
    $flow = $STATUS_FLOWS[$flowKey] ?? [];
    $idx = array_search($currentStatus, $flow);
    if ($idx === false) return $flow; // unrecognized status, show the full list as a fallback
    $next = [];
    if (isset($flow[$idx])) $next[] = $flow[$idx];     // allow staying the same
    if (isset($flow[$idx + 1])) $next[] = $flow[$idx + 1]; // allow exactly one step forward
    return $next;
}

/** Once a record reaches the last step in its flow, it's locked from further changes. */
function isStatusLocked($flowKey, $currentStatus) {
    global $STATUS_FLOWS;
    $flow = $STATUS_FLOWS[$flowKey] ?? [];
    return !empty($flow) && end($flow) === $currentStatus;
}
