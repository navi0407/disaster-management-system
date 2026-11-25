<?php
// WP15 helpers shared by the six reports. Names start with "rpt" so they never clash with other helpers.
// Every report builds its numbers first (KPI cards + tables), then rptPage() shows them as a web page
// or as a CSV file. One set of numbers, two outputs, so the page and the CSV can never disagree.

$GLOBALS['RPT_ERRORS'] = [];

function rptEsc($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function rptCssPath()
{
    return file_exists(__DIR__ . '/../style.css') ? 'style.css' : 'assets/css/style.css';
}

// Runs a prepared SELECT. If it fails, the message is shown on the report instead of a blank page.
function rptQuery($conn, $sql, $types = '', $params = [])
{
    try {
        $stmt = mysqli_prepare($conn, $sql);
        if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $rows;
    } catch (Throwable $e) {
        $GLOBALS['RPT_ERRORS'][] = $e->getMessage();
        return [];
    }
}

function rptValidDate($v)
{
    $d = DateTime::createFromFormat('Y-m-d', (string) $v);
    return $d && $d->format('Y-m-d') === $v;
}

// Builds "?,?,?" for an IN (...) list. Returns [placeholders, types, params].
function rptIn($ids)
{
    $ids = array_values(array_map('intval', $ids));
    return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids)), $ids];
}

// part / whole as a percent with 1 decimal, or null when the whole is 0 (a rate of nothing is not 0%).
function rptPct($part, $whole)
{
    return $whole > 0 ? round($part / $whole * 100, 1) : null;
}

function rptFmt($v)
{
    if ($v === null || $v === '') return '-';
    if (is_float($v)) return rtrim(rtrim(number_format($v, 1, '.', ','), '0'), '.');
    if (is_int($v)) return number_format($v);
    return (string) $v;
}

function rptDay($v)
{
    return $v ? substr((string) $v, 0, 10) : '';
}

// Hours between two date-times, never negative. Null when either is missing.
function rptHours($from, $to)
{
    if (!$from || !$to) return null;
    $a = strtotime($from);
    $b = strtotime($to);
    if ($a === false || $b === false) return null;
    return round(max(0, $b - $a) / 3600, 1);
}

function rptHoursText($h)
{
    if ($h === null) return '-';
    return $h >= 48 ? rptFmt(round($h / 24, 1)) . ' days' : rptFmt($h) . ' hours';
}

// SQL condition: the resident counts as vulnerable (any vulnerability type except none).
function rptVulnSql($alias = 'r')
{
    return "($alias.vulnerability_type IS NOT NULL AND TRIM($alias.vulnerability_type) <> ''
             AND LOWER(TRIM($alias.vulnerability_type)) NOT IN ('none', 'n/a', 'na', 'not applicable'))";
}

// Count rows by one field. Returns label => [total, active, closed] (Closed is the disaster status).
function rptCountBy($rows, $field, $empty = '(not set)')
{
    $out = [];
    foreach ($rows as $r) {
        $k = trim((string) ($r[$field] ?? '')) !== '' ? trim((string) $r[$field]) : $empty;
        if (!isset($out[$k])) $out[$k] = [0, 0, 0];
        $out[$k][0]++;
        if (($r['status'] ?? '') === 'Closed') $out[$k][2]++;
        else $out[$k][1]++;
    }
    uasort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return $out;
}

function rptSelect($name, $options, $current, $allLabel)
{
    $h = '<select name="' . rptEsc($name) . '"><option value="">' . rptEsc($allLabel) . '</option>';
    foreach ($options as $value => $label)
        $h .= '<option value="' . rptEsc($value) . '"' . ((string) $current === (string) $value ? ' selected' : '') . '>' . rptEsc($label) . '</option>';
    return $h . '</select>';
}

function rptDates($from, $to, $fromTitle = 'From date', $toTitle = 'To date')
{
    return '<input type="date" name="from" value="' . rptEsc($from) . '" title="' . rptEsc($fromTitle) . '">'
        . '<input type="date" name="to" value="' . rptEsc($to) . '" title="' . rptEsc($toTitle) . '">';
}

// A CSV cell that starts with = + - @ could run as a formula when opened in Excel, so it gets a ' in front.
function rptCsvCell($v)
{
    $v = (string) ($v ?? '');
    return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
}

function rptSendCsv($file, $title, $kpis, $sections)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                       // makes Excel read accents correctly
    fputcsv($out, [$title]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    foreach ($kpis as [$label, $value]) fputcsv($out, [rptCsvCell($label), rptCsvCell($value)]);
    foreach ($sections as $s) {
        fputcsv($out, []);
        fputcsv($out, [rptCsvCell($s['title'])]);
        fputcsv($out, array_map('rptCsvCell', $s['headers']));
        foreach ($s['rows'] as $row) fputcsv($out, array_map('rptCsvCell', $row));
    }
    fclose($out);
    exit;
}

// cfg: title, intro, filters (html for the inside of the filter form), kpis [[label, value]],
//      sections [['title','headers','rows','note']], csv (file name), notes [text]
function rptPage($cfg)
{
    $kpis = $cfg['kpis'] ?? [];
    $sections = $cfg['sections'] ?? [];
    if (($_GET['export'] ?? '') === 'csv' && !$GLOBALS['RPT_ERRORS'])
        rptSendCsv($cfg['csv'] ?? 'report', $cfg['title'], $kpis, $sections);

    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $csvLink = '?' . http_build_query(array_merge($_GET, ['export' => 'csv']));
    $u = function_exists('authUser') ? authUser() : null;
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title><?= rptEsc($cfg['title']) ?></title>
        <link rel="stylesheet" href="<?= rptEsc(rptCssPath()) ?>">
        <link rel="stylesheet" href="fonts.css?v=8">
        <link rel="stylesheet" href="theme.css?v=8">
    </head>

    <body>
        <?php include __DIR__ . '/header.php'; ?>
        <main class="wide report">
            <h1><?= rptEsc($cfg['title']) ?></h1>
            <p class="no-print"><a href="report_index.php">&larr; All reports</a></p>
            <?php if (!empty($cfg['intro'])): ?><p><?= rptEsc($cfg['intro']) ?></p><?php endif; ?>

            <?php if (!empty($cfg['filters'])): ?>
                <form method="get" class="filter-bar no-print">
                    <?= $cfg['filters'] ?>
                    <button type="submit">Show report</button>
                    <a href="<?= rptEsc($self) ?>">Clear</a>
                </form>
            <?php endif; ?>

            <p class="no-print">
                <button type="button" onclick="window.print()">Print</button>
                <a href="<?= rptEsc($csvLink) ?>">Download CSV (opens in Excel)</a>
            </p>
            <p class="print-only">Generated on <?= date('Y-m-d H:i') ?><?= $u ? ' by ' . rptEsc($u['username']) : '' ?></p>

            <?php foreach ($GLOBALS['RPT_ERRORS'] as $err): ?><div class="error-box">A query failed: <?= rptEsc($err) ?></div><?php endforeach; ?>

            <?php if ($kpis): ?>
                <div class="kpi-grid">
                    <?php foreach ($kpis as [$label, $value]): ?>
                        <div class="kpi">
                            <div class="kpi-value"><?= rptEsc($value) ?></div>
                            <div class="kpi-label"><?= rptEsc($label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($sections as $s): ?>
                <h2><?= rptEsc($s['title']) ?></h2>
                <?php if (!empty($s['note'])): ?><p><small><?= rptEsc($s['note']) ?></small></p><?php endif; ?>
                <table class="data-table">
                    <thead>
                        <tr><?php foreach ($s['headers'] as $h): ?><th><?= rptEsc($h) ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                        <?php if (!$s['rows']): ?><tr>
                                <td colspan="<?= count($s['headers']) ?>">No records found.</td>
                            </tr><?php endif; ?>
                        <?php foreach ($s['rows'] as $row): ?>
                            <tr><?php foreach ($row as $cell): ?><td><?= rptEsc($cell) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <?php foreach (($cfg['notes'] ?? []) as $n): ?><p><small><?= rptEsc($n) ?></small></p><?php endforeach; ?>
        </main>
        <?php include __DIR__ . '/footer.php'; ?>
    </body>

    </html>
<?php
}
