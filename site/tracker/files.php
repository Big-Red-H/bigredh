<?php
require __DIR__ . '/inc/common.php';

$key = isset($_GET['s']) ? (string) $_GET['s'] : '';
$path = isset($_GET['p']) ? (string) $_GET['p'] : '/';
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}
if (substr($path, -1) !== '/') {
    $path .= '/';
}

$db = open_db();
$server = null;
$rows = array();
if ($db && $key !== '') {
    $st = $db->prepare('SELECT * FROM servers WHERE server = ?');
    $st->execute(array($key));
    $server = $st->fetch(PDO::FETCH_ASSOC);
    if ($server) {
        $st = $db->prepare('SELECT name, folder, size, type, creator FROM files WHERE server = ? AND parent = ? ORDER BY folder DESC, name COLLATE NOCASE');
        $st->execute(array($key, $path));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!$server) {
    header('HTTP/1.0 404 Not Found');
    page_header('Not found');
    echo '<p>That server isn\'t in the file index. <a href="./">Back to the server list</a>.</p>';
    page_footer();
    exit;
}

page_header($server['name']);
?>
<h2><?php echo h($server['name']); ?></h2>
<p><font size="1">
<a href="hotline://<?php echo h($key); ?>/"><?php echo h($key); ?></a> |
<?php echo number_format((int) $server['files']); ?> files, <?php echo number_format((int) $server['folders']); ?> folders,
<?php echo h(format_bytes($server['bytes'])); ?> | indexed <?php echo h(format_ago($server['indexed_at'])); ?>
<?php if ($server['note']) { ?><br><span class="dim"><?php echo h($server['note']); ?></span><?php } ?>
</font></p>

<form action="search.php" method="get">
<font size="2">Search this server: <input type="hidden" name="s" value="<?php echo h($key); ?>">
<input type="text" name="q" size="24"> <input type="submit" value="Search"></font>
</form>

<p><font size="2"><b>
<a href="<?php echo h(page_url('files.php', array('s' => $key))); ?>">/</a><?php
$so_far = '/';
foreach (array_filter(explode('/', $path), 'strlen') as $part) {
    $so_far .= $part . '/';
    echo '<a href="' . h(page_url('files.php', array('s' => $key, 'p' => $so_far))) . '">' . h($part) . '</a>/';
}
?>
</b></font></p>

<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">
<tr>
<th><font size="2">Name</font></th>
<th class="num"><font size="2">Size</font></th>
<th><font size="2">Kind</font></th>
</tr>
<?php if ($path !== '/') {
    $up = substr($path, 0, strrpos(rtrim($path, '/'), '/') + 1);
    ?>
<tr><td colspan="3"><font size="2"><a href="<?php echo h(page_url('files.php', array('s' => $key, 'p' => $up))); ?>">[Up one folder]</a></font></td></tr>
<?php } ?>
<?php foreach ($rows as $r) { ?>
<tr>
<?php if ($r['folder']) { ?>
<td><font size="2"><a href="<?php echo h(page_url('files.php', array('s' => $key, 'p' => $path . $r['name'] . '/'))); ?>">[<?php echo h($r['name']); ?>]</a></font></td>
<td class="num"><font size="2"><?php echo number_format((int) $r['size']); ?> items</font></td>
<td><font size="2" class="dim">folder</font></td>
<?php } else { ?>
<td><font size="2"><?php echo h($r['name']); ?></font></td>
<td class="num"><font size="2"><?php echo h(format_bytes($r['size'])); ?></font></td>
<td><font size="1" class="dim"><?php echo h(trim($r['type'] . ' ' . $r['creator'])); ?></font></td>
<?php } ?>
</tr>
<?php } ?>
<?php if (!$rows) { ?>
<tr><td colspan="3"><font size="2">This folder was empty, or a guest couldn't open it.</font></td></tr>
<?php } ?>
</table>
<p><font size="1" class="dim">To download, connect to the server with a Hotline client.</font></p>
<?php
page_footer();
