<?php
// WP17 (Lab 49): the 12 business rules in one place.
// For each rule this page shows (1) where the system enforces it and (2) a live check of your real data.
// It is read-only. It never changes anything. Admin only (the page name is not in the permission list, so it counts as admin).
require_once 'config/db.php';
require_once 'includes/errors.php';
require_once 'includes/crud.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Each rule: number, text, where it is enforced, and live checks.
// A live check is [label, SQL returning bad rows with a "detail" column, level]. 0 rows = pass.
$rules = [
    [
        1,
        'Every disaster must have a valid disaster type.',
        'Disaster form (type is a dropdown, checked against the table) and a database link (foreign key).',
        [[
            'Disasters with a missing disaster type',
            "SELECT d.disaster_number AS detail FROM disasters d
     LEFT JOIN disaster_types t ON t.disaster_type_id = d.disaster_type_id WHERE t.disaster_type_id IS NULL",
            'fail'
        ]]
    ],
    [
        2,
        'Disaster numbers must be unique.',
        'Disaster form checks for a duplicate before saving. wp17_setup.sql adds a database unique key as a second lock.',
        [[
            'Disaster numbers used more than once',
            "SELECT CONCAT(disaster_number, ' x', COUNT(*)) AS detail FROM disasters GROUP BY disaster_number HAVING COUNT(*) > 1",
            'fail'
        ]]
    ],
    [
        3,
        'A resident cannot be in two active evacuation centers at the same time.',
        'Check-in form refuses a resident who is already checked in.',
        [[
            'Residents checked in more than once',
            "SELECT CONCAT('resident #', resident_id, ' x', COUNT(*)) AS detail FROM evacuees
     WHERE status = 'Checked-in' GROUP BY resident_id HAVING COUNT(*) > 1",
            'fail'
        ]]
    ],
    [
        4,
        'Evacuee registration must reference a valid disaster.',
        'Check-in form (disaster is a dropdown of open disasters) and a database link (foreign key).',
        [[
            'Evacuees with no disaster or a missing disaster',
            "SELECT CONCAT('evacuee #', e.evacuee_id) AS detail FROM evacuees e
     LEFT JOIN disasters d ON d.disaster_id = e.disaster_id WHERE d.disaster_id IS NULL",
            'fail'
        ]]
    ],
    [
        5,
        'Evacuation center assignment cannot exceed capacity unless authorized.',
        'Check-in form compares the occupancy with the capacity. Only an authorized user can override it.',
        [[
            'Centers over capacity',
            "SELECT CONCAT(center_name, ' ', current_occupancy, '/', capacity) AS detail FROM evacuation_centers
     WHERE current_occupancy > capacity",
            'warn'
        ]]
    ],
    [
        6,
        'Distribution cannot exceed available inventory.',
        'Approve and Release pages check the stock that is not expired and not already promised to other requests.',
        [[
            'Approved requests promising more than the usable stock',
            "SELECT CONCAT(i.item_name, ' stock ', x.stock, ' promised ', x.reserved) AS detail FROM relief_items i
     JOIN (SELECT i2.relief_item_id,
             COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i2.relief_item_id), 0) -
             COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i2.relief_item_id AND date_given IS NOT NULL), 0) AS stock,
             COALESCE((SELECT SUM(di.quantity) FROM distribution_items di JOIN relief_distributions d ON d.distribution_id = di.distribution_id
                       WHERE di.relief_item_id = i2.relief_item_id AND d.status = 'Approved'), 0) AS reserved
           FROM relief_items i2) x ON x.relief_item_id = i.relief_item_id
     WHERE x.reserved > x.stock",
            'fail'
        ]]
    ],
    [
        7,
        'Only approved distribution requests can be released.',
        'Release page refuses any request that is not Approved, and locks the request so it can only be released once.',
        [
            [
                'Released requests with no approver or approval date',
                "SELECT reference_number AS detail FROM relief_distributions
     WHERE status = 'Released' AND (approved_by IS NULL OR approval_date IS NULL)",
                'fail'
            ],
            [
                'Items marked as given on a request that is not Released',
                "SELECT DISTINCT d.reference_number AS detail FROM relief_distributions d
     JOIN distribution_items di ON di.distribution_id = d.distribution_id
     WHERE d.status <> 'Released' AND di.date_given IS NOT NULL",
                'fail'
            ]
        ]
    ],
    [
        8,
        'Every inventory movement must generate a transaction.',
        'Resource stock only changes through the Receive/Release form, which writes a transaction with a reference number and an audit entry. Relief stock is calculated from receipts and given-out lines, so it cannot move without a record.',
        [
            [
                'Resource transactions with no reference number',
                "SELECT CONCAT('transaction #', transaction_id) AS detail FROM resource_transactions
     WHERE reference_number IS NULL OR reference_number = ''",
                'fail'
            ],
            [
                'Resources whose saved stock does not match their transactions',
                "SELECT CONCAT(r.resource_code, ' saved ', r.quantity_on_hand, ' but real ', COALESCE(t.total, 0)) AS detail
     FROM resources r
     LEFT JOIN (SELECT resource_id, SUM(CASE WHEN transaction_type = 'Receive' THEN quantity
                                             WHEN transaction_type = 'Release' THEN -quantity ELSE 0 END) AS total
                FROM resource_transactions GROUP BY resource_id) t ON t.resource_id = r.resource_id
     WHERE COALESCE(r.quantity_on_hand, 0) <> COALESCE(t.total, 0)",
                'fail'
            ]
        ]
    ],
    [
        9,
        'Negative inventory is not allowed.',
        'Release forms refuse a quantity bigger than the stock on hand. The release runs inside a locked transaction so two people cannot take the same stock.',
        [
            [
                'Resources with negative stock',
                "SELECT CONCAT(resource_code, ' = ', quantity_on_hand) AS detail FROM resources WHERE quantity_on_hand < 0",
                'fail'
            ],
            [
                'Relief items with negative stock',
                "SELECT CONCAT(i.item_name, ' = ', x.stock) AS detail FROM relief_items i
     JOIN (SELECT i2.relief_item_id,
             COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i2.relief_item_id), 0) -
             COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i2.relief_item_id AND date_given IS NOT NULL), 0) AS stock
           FROM relief_items i2) x ON x.relief_item_id = i.relief_item_id
     WHERE x.stock < 0",
                'fail'
            ]
        ]
    ],
    [
        10,
        'Expired relief items cannot be distributed.',
        'Receipt form refuses stock that is already expired. Request, Approve and Release pages count only stock that is not expired (oldest stock is assumed to leave first).',
        []
    ],   // checked in PHP below, because it uses the helper functions
    [
        11,
        'Only authorized personnel can adjust inventory.',
        'Role permissions (WP14): only Administrator and Resource Officer can change resources, and only Administrator and Relief Officer can change relief stock. Everyone else is blocked, and every blocked try is written to the audit trail.',
        []
    ],   // checked in PHP below
    [
        12,
        'Closed disaster records cannot be modified by ordinary users.',
        'Relief request, Approve and Release pages refuse a Closed disaster. For the Disaster and Evacuee pages see "Closed disaster" in the WP17 guide (includes/rules.php).',
        [
            [
                'Open relief requests under a Closed disaster',
                "SELECT d.reference_number AS detail FROM relief_distributions d
     JOIN disasters ds ON ds.disaster_id = d.disaster_id
     WHERE d.status IN ('Requested', 'Approved') AND ds.status = 'Closed'",
                'warn'
            ],
            [
                'People still checked in under a Closed disaster',
                "SELECT CONCAT('evacuee #', e.evacuee_id, ' in ', d.disaster_number) AS detail FROM evacuees e
     JOIN disasters d ON d.disaster_id = e.disaster_id WHERE e.status = 'Checked-in' AND d.status = 'Closed'",
                'warn'
            ]
        ]
    ],
];

// Runs one SQL check and returns [count, sample list, error text].
function brRun($conn, $sql)
{
    try {
        $res = mysqli_query($conn, $sql);
        if ($res === false) throw new Exception(mysqli_error($conn));
        $found = mysqli_fetch_all($res, MYSQLI_ASSOC);
        return [count($found), array_slice(array_column($found, 'detail'), 0, 5), ''];
    } catch (Throwable $e) {
        return [0, [], 'Could not run this check.'];
    }
}

// results[ruleNumber] = list of [label, level, count, sample, error]
$results = [];
foreach ($rules as [$no, $text, $how, $checks]) {
    foreach ($checks as [$label, $sql, $level]) {
        [$n, $sample, $err] = brRun($conn, $sql);
        $results[$no][] = [$label, $level, $n, $sample, $err];
    }
}

// Rule 10: expired stock still on the shelf. It is not a break of the rule (it cannot be given out),
// but the officer should know it is there.
$expiredRows = [];
foreach (dbGetAll($conn, 'relief_items', 'item_name') as $it) {
    $left = relExpired($conn, (int) $it['relief_item_id']);
    if ($left > 0) $expiredRows[] = $it['item_name'] . ' ' . $left . ' ' . $it['unit'];
}
$results[10][] = ['Expired stock still on the shelf (blocked from release)', 'warn', count($expiredRows), $expiredRows, ''];

// Rule 11: show who may adjust inventory, and how many blocked attempts were logged in the last 30 days.
$adjusters = [];
$deniedCount = 0;
try {
    $adjusters = relQuery(
        $conn,
        "SELECT u.username, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.status = 'Active' AND LOWER(r.role_name) IN ('administrator', 'admin', 'resource officer', 'relief officer')
         ORDER BY r.role_name, u.username"
    );
    $denied = relQuery(
        $conn,
        "SELECT COUNT(*) AS n FROM audit_logs WHERE activity = 'Access denied' AND log_datetime >= (NOW() - INTERVAL 30 DAY)"
    );
    $deniedCount = (int) ($denied[0]['n'] ?? 0);
} catch (Throwable $e) {
    errLog(errReference(), $e);
}

$bad = 0;
$warn = 0;
foreach ($results as $list) foreach ($list as [$l, $lv, $n, $s, $e]) {
    if ($e || $n > 0) {
        if ($lv === 'fail' || $e) $bad++;
        else $warn++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Business Rules</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>

<body>
    <?php include 'includes/header.php'; ?>
    <main class="wide">
        <h1>Business Rules</h1>
        <p>The 12 rules of the system. For each one you can see where it is enforced and whether your real data follows it.
            This page only reads data.</p>

        <?php if ($bad === 0): ?>
            <div class="success-box">No rule is broken in the data<?= $warn ? ', with ' . $warn . ' warning(s) to look at' : '' ?>.</div>
        <?php else: ?>
            <div class="error-box"><?= $bad ?> check(s) found a broken rule. Fix those first.</div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Rule</th>
                    <th>Where it is enforced</th>
                    <th>Live check</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as [$no, $text, $how, $checks]): ?>
                    <tr>
                        <td><?= (int) $no ?></td>
                        <td><?= relEsc($text) ?></td>
                        <td><?= relEsc($how) ?></td>
                        <td>
                            <?php foreach ($results[$no] ?? [] as [$label, $level, $n, $sample, $err]):
                                if ($err) {
                                    $cls = 'check-fail';
                                    $word = 'Error';
                                } elseif ($n === 0) {
                                    $cls = 'check-pass';
                                    $word = 'Pass';
                                } elseif ($level === 'warn') {
                                    $cls = 'check-warn';
                                    $word = 'Warning';
                                } else {
                                    $cls = 'check-fail';
                                    $word = 'Fail';
                                } ?>
                                <div><span class="<?= $cls ?>"><?= $word ?></span> <?= relEsc($label) ?>
                                    <?php if ($err): ?>: <?= relEsc($err) ?>
                                    <?php elseif ($n > 0): ?>: <?= (int) $n ?> (<?= relEsc(implode('; ', $sample)) ?><?= $n > 5 ? ' ...' : '' ?>)<?php endif; ?></div>
                            <?php endforeach; ?>
                            <?php if ($no === 11): ?>
                                <div>Users who can adjust inventory: <?= $adjusters ? relEsc(implode(', ', array_map(fn($a) => $a['username'] . ' (' . $a['role_name'] . ')', $adjusters))) : 'none' ?></div>
                                <div>Blocked attempts in the last 30 days: <strong><?= $deniedCount ?></strong>
                                    (<a href="audit_trail.php">see the audit trail</a>)</div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>