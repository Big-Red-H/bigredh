<?php
require __DIR__ . '/inc/common.php';

// One server: what it is, who's on it, and its files.
$key = isset($_GET['s']) ? (string) $_GET['s'] : '';
$servers = load_servers();
$files = file_summary();
$pop = load_json('population.json');
$icons = population_icons($pop);
$server = isset($servers[$key]) ? $servers[$key] : null;
$indexed = isset($files[$key]) ? $files[$key] : null;

if (!$server && !$indexed) {
    header('HTTP/1.0 404 Not Found');
    page_header('Not found');
    echo '<p>That server hasn\'t been seen in the last 30 days. <a href="./">Back to the server list</a>.</p>';
    page_footer();
    exit;
}

$name = $server ? $server['name'] : $indexed['name'];
page_header($name);
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b><?php echo h($name); ?></b></font></p>
<table class="stats" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#EEF1F5"><tr><td>
<font size="2"><a href="hotline://<?php echo h($key); ?>/"><b><?php echo h($key); ?></b></a></font>
<font size="1">
<?php if ($server) { ?>
| <?php echo $server['listed'] ? ($server['online'] ? 'answering' : '<b>not answering</b>') : 'not on the trackers right now'; ?> <?php
if ($server['listed']) { ?>| <b><?php echo (int) $server['users']; ?></b> user<?php echo (int) $server['users'] === 1 ? '' : 's'; ?> on the trackers <?php } ?>| first seen <?php echo h(format_ago($server['first_seen'])); ?>
<?php if (!empty($server['listed_by'])) { ?><br>Listed by <?php echo h(implode(', ', $server['listed_by'])); ?><?php } ?>
<?php } else { ?>
| not on the trackers in the last 30 days
<?php } ?>
</font>
</td></tr></table>
<?php if ($server && $server['description'] !== '') { ?>
<p><font size="2"><?php echo h($server['description']); ?></font></p>
<?php } ?>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Users</b></font></p>
<?php
$checked = isset($pop['checked'][$key]) ? $pop['checked'][$key] : null;
$here = isset($pop['online'][$key]) ? $pop['online'][$key] : array();
$when = isset($pop['checked_at']) ? format_ago($pop['checked_at']) : 'not yet';
if ($here) {
    echo '<p><font size="1">Online at the last check (' . h($when) . '):</font></p>';
    echo '<table cellpadding="0" cellspacing="1" border="0">';
    foreach ($here as $u) {
        echo '<tr><td>' . user_tag($u['name'], $u['icon'], $icons, page_url('population.php', array('u' => $u['name']))) . '</td></tr>';
    }
    echo '</table>';
} elseif ($checked && empty($checked['ok'])) {
    echo '<p><font size="2">The last check couldn\'t see who was on (' . h($checked['error']) . ').</font></p>';
} elseif ($checked) {
    echo '<p><font size="2">Nobody was on at the last check (' . h($when) . ').</font></p>';
} elseif ($server && $server['listed'] && (int) $server['users'] === 0) {
    echo '<p><font size="2">The trackers show nobody on, so it wasn\'t checked (' . h($when) . ').</font></p>';
} else {
    echo '<p><font size="2">This server isn\'t included in Population.</font></p>';
}

// Everyone seen here lately, newest first.
$seen = array();
foreach (isset($pop['people']) ? $pop['people'] : array() as $person => $p) {
    if (isset($p['servers'][$key])) {
        $seen[$person] = $p;
    }
}
uasort($seen, function ($a, $b) use ($key) {
    return strcmp($b['servers'][$key], $a['servers'][$key]);
});
if ($seen) {
    $keepDays = isset($pop['keep_days']) ? (int) $pop['keep_days'] : 30;
    echo '<p><font size="1">Seen here in the last ' . $keepDays . ' days:</font></p>';
    echo '<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">'
        . '<tr bgcolor="#DDDDDD"><th width="236"><font size="2">Name</font></th><th class="num"><font size="2">Last seen here</font></th></tr>';
    foreach ($seen as $person => $p) {
        echo '<tr><td>' . user_tag($person, $p['icon'], $icons, page_url('population.php', array('u' => $person))) . '</td>'
            . '<td class="num"><font size="1">' . h(format_ago($p['servers'][$key])) . '</font></td></tr>';
    }
    echo '</table>';
}
?>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Files</b></font></p>
<?php
if (!$indexed) {
    echo '<p><font size="2">Not in the file index yet. Servers are indexed about every two months.</font></p>';
} elseif ($indexed['status'] === 'opted out') {
    echo '<p><font size="2">This server asked not to be indexed.</font></p>';
} elseif ((int) $indexed['files'] + (int) $indexed['folders'] === 0) {
    echo '<p><font size="2">Nothing a guest could see when it was indexed (' . h(format_ago($indexed['indexed_at'])) . ').'
        . ($indexed['note'] ? ' <span class="dim">' . h($indexed['note']) . '</span>' : '') . '</font></p>';
} else {
    echo '<p><font size="2"><b>' . number_format((int) $indexed['files']) . '</b> files in <b>' . number_format((int) $indexed['folders'])
        . '</b> folders, ' . h(format_bytes($indexed['bytes'])) . ', indexed ' . h(format_ago($indexed['indexed_at'])) . '.'
        . ' <a href="' . h(page_url('files.php', array('s' => $key))) . '"><b>Browse all files</b></a></font></p>';
    ?>
<form action="search.php" method="get">
<font size="2">Search this server: <input type="hidden" name="s" value="<?php echo h($key); ?>">
<input type="text" name="q" size="24"> <input type="submit" value="Search"></font>
</form>
<?php
    // The top folder, right here; the rest is in the file browser.
    $db = open_db();
    $rows = array();
    if ($db) {
        $st = $db->prepare("SELECT name, folder, size, type, creator FROM files WHERE server = ? AND parent = '/' ORDER BY folder DESC, name COLLATE NOCASE LIMIT 101");
        $st->execute(array($key));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $more = count($rows) > 100;
    $rows = array_slice($rows, 0, 100);
    echo '<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">'
        . '<tr bgcolor="#DDDDDD"><th><font size="2">Name</font></th><th class="num"><font size="2">Size</font></th><th><font size="2">Kind</font></th></tr>';
    foreach ($rows as $r) {
        if ($r['folder']) {
            echo '<tr><td><font size="2"><a href="' . h(page_url('files.php', array('s' => $key, 'p' => '/' . $r['name'] . '/'))) . '">['
                . h($r['name']) . ']</a></font></td><td class="num"><font size="2">' . number_format((int) $r['size']) . ' items</font></td>'
                . '<td><font size="2" class="dim">folder</font></td></tr>';
        } else {
            echo '<tr><td><font size="2">' . h($r['name']) . '</font></td><td class="num"><font size="2">' . h(format_bytes($r['size'])) . '</font></td>'
                . '<td><font size="1" class="dim">' . h(trim($r['type'] . ' ' . $r['creator'])) . '</font></td></tr>';
        }
    }
    echo '</table>';
    if ($more) {
        echo '<p><font size="2"><a href="' . h(page_url('files.php', array('s' => $key))) . '">More in the file browser...</a></font></p>';
    }
}
?>
<p><font size="1" class="dim">To download, connect to the server with a Hotline client.</font></p>
<?php
page_footer();
