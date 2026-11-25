<?php
// WP16 helpers for the dashboard. Names start with "dash".
// Needs includes/report_helpers.php (rptQuery, rptEsc, rptIn, rptPct).
// There is no chart library on purpose: the charts are plain HTML and SVG drawn by PHP,
// so they work offline, print cleanly, and nothing can fail to download during the defense.

// A KPI card that links to the page with the full detail.
function dashCard($label, $value, $sub, $href, $tone = '')
{
    return '<a class="dash-card ' . rptEsc($tone) . '" href="' . rptEsc($href) . '">'
        . '<span class="dash-value">' . rptEsc($value) . '</span>'
        . '<span class="dash-label">' . rptEsc($label) . '</span>'
        . '<span class="dash-sub">' . rptEsc($sub) . '</span></a>';
}

// Horizontal bars. rows: [label, number, text shown at the end, color class]
function dashBars($title, $rows, $href = '', $empty = 'Nothing to show yet.')
{
    $max = 0;
    foreach ($rows as $r) $max = max($max, (float) $r[1]);
    $h = '<section class="chart-box"><h2>' . rptEsc($title) . '</h2>';
    if (!$rows) $h .= '<p class="chart-empty">' . rptEsc($empty) . '</p>';
    foreach ($rows as $r) {
        $w = $max > 0 ? round((float) $r[1] / $max * 100, 1) : 0;
        $h .= '<div class="bar-row"><span class="bar-label" title="' . rptEsc($r[0]) . '">' . rptEsc($r[0]) . '</span>'
            . '<span class="bar-track"><span class="bar-fill ' . rptEsc($r[3] ?? '') . '" style="width:' . $w . '%"></span></span>'
            . '<span class="bar-text">' . rptEsc($r[2]) . '</span></div>';
    }
    if ($href) $h .= '<p class="chart-link"><a href="' . rptEsc($href) . '">Open the full report</a></p>';
    return $h . '</section>';
}

// Two bars per row (for example received and distributed). rows: [label, a, b, text]
function dashDualBars($title, $rows, $nameA, $nameB, $href = '', $empty = 'Nothing to show yet.')
{
    $max = 0;
    foreach ($rows as $r) $max = max($max, (float) $r[1], (float) $r[2]);
    $h = '<section class="chart-box"><h2>' . rptEsc($title) . '</h2>';
    if (!$rows) $h .= '<p class="chart-empty">' . rptEsc($empty) . '</p>';
    else $h .= '<p class="chart-key"><span class="key c-grey"></span>' . rptEsc($nameA) . ' <span class="key c-blue"></span>' . rptEsc($nameB) . '</p>';
    foreach ($rows as $r) {
        $wa = $max > 0 ? round((float) $r[1] / $max * 100, 1) : 0;
        $wb = $max > 0 ? round((float) $r[2] / $max * 100, 1) : 0;
        $h .= '<div class="bar-row two"><span class="bar-label" title="' . rptEsc($r[0]) . '">' . rptEsc($r[0]) . '</span>'
            . '<span class="bar-pair"><span class="bar-track"><span class="bar-fill c-grey" style="width:' . $wa . '%"></span></span>'
            . '<span class="bar-track"><span class="bar-fill c-blue" style="width:' . $wb . '%"></span></span></span>'
            . '<span class="bar-text">' . rptEsc($r[3]) . '</span></div>';
    }
    if ($href) $h .= '<p class="chart-link"><a href="' . rptEsc($href) . '">Open the full report</a></p>';
    return $h . '</section>';
}

// Line chart in SVG. labels: month names, values: counts (same length).
function dashLine($title, $labels, $values, $href = '')
{
    $n = count($values);
    $h = '<section class="chart-box wide-chart"><h2>' . rptEsc($title) . '</h2>';
    if ($n === 0 || array_sum($values) === 0) {
        return $h . '<p class="chart-empty">No disasters were reported in this period.</p></section>';
    }
    $W = 640;
    $H = 230;
    $l = 36;
    $r = 14;
    $t = 22;
    $b = 36;
    $max = max(1, max($values));
    $step = $n > 1 ? ($W - $l - $r) / ($n - 1) : 0;
    $pts = [];
    $dots = '';
    $xl = '';
    foreach ($values as $i => $v) {
        $x = round($l + $i * $step, 1);
        $y = round($t + ($H - $t - $b) * (1 - $v / $max), 1);
        $pts[] = "$x,$y";
        $dots .= '<circle cx="' . $x . '" cy="' . $y . '" r="4" class="line-dot"><title>' . rptEsc($labels[$i] . ': ' . $v) . '</title></circle>';
        if ($v > 0) $dots .= '<text x="' . $x . '" y="' . ($y - 8) . '" class="line-val" text-anchor="middle">' . (int) $v . '</text>';
        $xl .= '<text x="' . $x . '" y="' . ($H - 12) . '" class="line-axis" text-anchor="middle">' . rptEsc($labels[$i]) . '</text>';
    }
    $y0 = $H - $b;
    $yTop = $t;
    $h .= '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="line-chart" role="img" aria-label="' . rptEsc($title) . '">'
        . '<line x1="' . $l . '" y1="' . $y0 . '" x2="' . ($W - $r) . '" y2="' . $y0 . '" class="line-grid"/>'
        . '<line x1="' . $l . '" y1="' . $yTop . '" x2="' . ($W - $r) . '" y2="' . $yTop . '" class="line-grid"/>'
        . '<text x="' . ($l - 6) . '" y="' . ($y0 + 4) . '" class="line-axis" text-anchor="end">0</text>'
        . '<text x="' . ($l - 6) . '" y="' . ($yTop + 4) . '" class="line-axis" text-anchor="end">' . (int) $max . '</text>'
        . '<polyline points="' . implode(' ', $pts) . '" class="line-path"/>' . $dots . $xl . '</svg>';
    if ($href) $h .= '<p class="chart-link"><a href="' . rptEsc($href) . '">Open the full report</a></p>';
    return $h . '</section>';
}

function dashStockClass($qty, $min)
{
    $qty = (int) $qty;
    $min = (int) $min;
    return $qty <= 0 ? 'c-bad' : ($qty <= $min ? 'c-warn' : 'c-ok');
}

// Reads everything the dashboard shows. The definitions are the same as in the reports (WP15).
function dashData($conn)
{
    $d = [];

    // Disasters
    $d['active_disasters'] = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM disasters WHERE COALESCE(status, '') <> 'Closed'")[0]['n'] ?? 0);
    $d['total_disasters'] = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM disasters")[0]['n'] ?? 0);
    $d['by_type'] = rptQuery(
        $conn,
        "SELECT COALESCE(NULLIF(TRIM(dt.type_name), ''), '(not set)') AS label, COUNT(*) AS n,
                SUM(CASE WHEN COALESCE(d.status, '') <> 'Closed' THEN 1 ELSE 0 END) AS active
         FROM disasters d LEFT JOIN disaster_types dt ON dt.disaster_type_id = d.disaster_type_id
         GROUP BY label ORDER BY n DESC, label"
    );

    // Monthly trend: the last 12 months, months with no disaster count as 0.
    $months = [];
    $labels = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-01') . " -$i month");
        $months[date('Y-m', $ts)] = 0;
        $labels[] = date('M y', $ts);
    }
    foreach (
        rptQuery(
            $conn,
            "SELECT DATE_FORMAT(COALESCE(date_reported, date_started), '%Y-%m') AS ym, COUNT(*) AS n FROM disasters
         WHERE COALESCE(date_reported, date_started) >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 11 MONTH)
         GROUP BY ym"
        ) as $r
    )
        if (isset($months[$r['ym']])) $months[$r['ym']] = (int) $r['n'];
    $d['trend_labels'] = $labels;
    $d['trend_values'] = array_values($months);

    // Affected residents: Active residents living in the area of a disaster that is not Closed (same rule as report 42).
    $hh = array_map('intval', array_column(rptQuery(
        $conn,
        "SELECT DISTINCT h.household_id FROM households h JOIN disasters d ON TRIM(h.barangay) = TRIM(d.area_affected)
         WHERE COALESCE(d.status, '') <> 'Closed'"
    ), 'household_id'));
    $d['affected'] = 0;
    $d['affected_by_area'] = [];
    if ($hh) {
        [$ph, $ty, $pa] = rptIn($hh);
        $d['affected'] = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM residents r WHERE r.status = 'Active' AND r.household_id IN ($ph)", $ty, $pa)[0]['n'] ?? 0);
        $d['affected_by_area'] = rptQuery(
            $conn,
            "SELECT COALESCE(NULLIF(TRIM(h.barangay), ''), '(no barangay)') AS label, COUNT(r.resident_id) AS n
             FROM households h LEFT JOIN residents r ON r.household_id = h.household_id AND r.status = 'Active'
             WHERE h.household_id IN ($ph) GROUP BY label ORDER BY n DESC, label LIMIT 10",
            $ty,
            $pa
        );
    }

    // Evacuation
    $d['evacuees'] = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM evacuees WHERE status = 'Checked-in'")[0]['n'] ?? 0);
    $d['centers'] = rptQuery($conn, "SELECT center_name, status, capacity, current_occupancy FROM evacuation_centers ORDER BY center_name");
    $d['evacuees_by_center'] = rptQuery(
        $conn,
        "SELECT c.center_name AS label, COUNT(e.evacuee_id) AS n FROM evacuation_centers c
         LEFT JOIN evacuees e ON e.center_id = c.center_id AND e.status = 'Checked-in'
         WHERE c.status = 'Active' GROUP BY c.center_id, c.center_name ORDER BY n DESC, c.center_name LIMIT 12"
    );

    // Resources
    $d['resources'] = rptQuery(
        $conn,
        "SELECT resource_name, unit, quantity_on_hand, min_stock_level FROM resources ORDER BY resource_name"
    );

    // Relief: received and really given out (date_given is set), per item.
    $d['relief_items'] = rptQuery(
        $conn,
        "SELECT i.item_name, i.unit,
                COALESCE((SELECT SUM(rr.quantity) FROM relief_receipts rr WHERE rr.relief_item_id = i.relief_item_id), 0) AS received,
                COALESCE((SELECT SUM(di.quantity) FROM distribution_items di WHERE di.relief_item_id = i.relief_item_id AND di.date_given IS NOT NULL), 0) AS given
         FROM relief_items i ORDER BY i.item_name"
    );
    $d['releases'] = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM relief_distributions WHERE status = 'Released'")[0]['n'] ?? 0);
    $d['hh_served_all'] = (int) (rptQuery($conn, "SELECT COUNT(DISTINCT household_id) AS n FROM distribution_items WHERE date_given IS NOT NULL AND household_id IS NOT NULL")[0]['n'] ?? 0);

    // Unserved beneficiaries: same definition as report 46 (all disasters).
    $u = rptQuery(
        $conn,
        "SELECT
          (SELECT COUNT(DISTINCT e.household_id) FROM evacuees e WHERE e.household_id IS NOT NULL) AS sheltered_all,
          (SELECT COUNT(*) FROM (SELECT DISTINCT e.disaster_id, e.household_id FROM evacuees e WHERE e.household_id IS NOT NULL) x) AS sheltered_pairs,
          (SELECT COUNT(*) FROM (SELECT DISTINCT rd.disaster_id, di.household_id FROM distribution_items di
              JOIN relief_distributions rd ON rd.distribution_id = di.distribution_id
              WHERE di.date_given IS NOT NULL AND di.household_id IN
                (SELECT e2.household_id FROM evacuees e2 WHERE e2.disaster_id = rd.disaster_id)) y) AS served_pairs"
    )[0]
        ?? ['sheltered_pairs' => 0, 'served_pairs' => 0];
    $d['sheltered_pairs'] = (int) $u['sheltered_pairs'];
    $d['served_pairs'] = (int) $u['served_pairs'];
    $d['unserved'] = max(0, $d['sheltered_pairs'] - $d['served_pairs']);
    return $d;
}
