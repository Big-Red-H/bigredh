<?php
require __DIR__ . '/inc/common.php';

$filter = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$live = load_json('live.json');
$servers = load_servers();
$files = file_summary();

$listed = array_filter($servers, function ($s) { return $s['listed']; });
$answering = count(array_filter($listed, function ($s) { return $s['online']; }));
$totalFiles = 0;
$totalFolders = 0;
$lastIndex = '';
foreach ($files as $f) {
    $totalFiles += (int) $f['files'];
    $totalFolders += (int) $f['folders'];
    if ($f['indexed_at'] > $lastIndex) {
        $lastIndex = $f['indexed_at'];
    }
}

if ($filter !== '') {
    $listed = array_filter($listed, function ($s) use ($filter) {
        return stripos($s['name'] . ' ' . $s['description'] . ' ' . $s['key'], $filter) !== false;
    });
}
uasort($listed, function ($a, $b) {
    if ($a['online'] !== $b['online']) {
        return $a['online'] ? -1 : 1;
    }
    if ($a['users'] !== $b['users']) {
        return $b['users'] - $a['users'];
    }
    return strcasecmp($a['name'], $b['name']);
});

page_header('');
?>
<table class="stats" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#1a1a1a"><tr><td>
<font size="1">
<b><?php echo count(array_filter($servers, function ($s) { return $s['listed']; })); ?></b> servers listed,
<b><?php echo $answering; ?></b> answering |
<b><?php echo number_format($totalFiles); ?></b> files and <b><?php echo number_format($totalFolders); ?></b> folders indexed |
Server check: <?php echo h(format_ago(isset($live['checked_at']) ? $live['checked_at'] : '')); ?> |
File index: <?php echo h(format_ago($lastIndex)); ?>
</font>
</td></tr></table>

<p><font size="1" class="dim">Servers are checked every hour and their files are indexed once a month.
To keep your server out of the file index, make a folder named <b>noindex</b> anywhere on it.</font></p>

<form action="./" method="get">
<font size="2">Filter servers: <input type="text" name="q" size="24" value="<?php echo h($filter); ?>">
<input type="submit" value="Filter"><?php if ($filter !== '') { ?> <a href="./">Show all</a><?php } ?></font>
</form>

<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">
<tr>
<th><font size="2">Server</font></th>
<th><font size="2">Address</font></th>
<th class="desc"><font size="2">Description</font></th>
<th class="num"><font size="2">Users</font></th>
<th class="num"><font size="2">Files</font></th>
</tr>
<?php foreach ($listed as $s) {
    $f = isset($files[$s['key']]) ? $files[$s['key']] : null;
    $color = $s['online'] ? '#00FF00' : '#008800';
    ?>
<tr<?php echo $s['online'] ? '' : ' class="offline"'; ?>>
<td><font size="2" color="<?php echo $color; ?>"><b><?php echo h($s['name']); ?></b><?php echo $s['online'] ? '' : ' <i>(not answering)</i>'; ?></font></td>
<td><font size="2"><a href="hotline://<?php echo h($s['key']); ?>/"><?php echo h($s['key']); ?></a></font></td>
<td class="desc"><font size="1" color="<?php echo $color; ?>"><?php echo h($s['description']); ?></font></td>
<td class="num"><font size="2" color="<?php echo $color; ?>"><?php echo (int) $s['users']; ?></font></td>
<td class="num"><font size="2"><?php
    if ($f && $f['status'] === 'opted out') {
        echo '<span class="dim">opted out</span>';
    } elseif ($f && ((int) $f['files'] + (int) $f['folders']) > 0) {
        echo '<a href="' . h(page_url('files.php', array('s' => $s['key']))) . '">View (' . number_format((int) $f['files']) . ')</a>';
    } else {
        echo '<span class="dim">0</span>';
    }
?></font></td>
</tr>
<?php } ?>
<?php if (!$listed) { ?>
<tr><td colspan="5"><font size="2"><?php echo $filter === '' ? 'No servers yet.' : 'No servers match that.'; ?></font></td></tr>
<?php } ?>
</table>

<h2>Where the list comes from</h2>
<p><font size="1">
<?php
$trackers = isset($live['trackers']) ? $live['trackers'] : array();
$parts = array();
foreach ($trackers as $name => $t) {
    $parts[] = h($name) . ' (' . (!empty($t['ok']) ? (int) $t['servers'] . ' listed' : 'not answering') . ')';
}
echo $parts ? implode(', ', $parts) : 'No trackers checked yet.';
?>.
The raw list is at <a href="data/servers.json">servers.json</a> and <a href="data/live.json">live.json</a>.
</font></p>
<?php
page_footer();
