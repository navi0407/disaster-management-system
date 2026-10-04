<?php
/**
 * Generic search box + table renderer — reusable across every module's
 * "search and monitoring" page.
 *
 * Usage:
 *   renderSearchBox($_GET['q'] ?? '');
 *   renderTable(['ID', 'Type Name', 'Description'], $rows);
 */

function renderSearchBox($currentQuery = '') {
    echo '<form method="get" class="search-box">';
    echo '<input type="text" name="q" placeholder="Search..." value="' . htmlspecialchars($currentQuery) . '">';
    echo '<button type="submit">Search</button>';
    echo '</form>';
}

function renderTable($columns, $rows) {
    echo '<table class="data-table"><thead><tr>';
    foreach ($columns as $label) {
        echo '<th>' . htmlspecialchars($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($columns) . '">No records found.</td></tr>';
    }
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
