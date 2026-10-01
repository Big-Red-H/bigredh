<?php
require __DIR__ . '/inc/common.php';

const PER_PAGE = 100;

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$key = isset($_GET['s']) ? (string) $_GET['s'] : '';
$page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);

$db = open_db();
$rows = array();
$error = '';
$more = false;

if ($q !== '' && $db) {
    // Every word must appear, each as the start of a word in the name: "kata wall" finds
    // "Katamari Wallpapers".
    preg_match_all('/[\p{L}\p{N}]+/u', $q, $m);
    $words = array_slice($m[0], 0, 8);
    $offset = ($page - 1) * PER_PAGE;
    $where = $key !== '' ? ' AND f.server = :server' : '';
    $params = array();
    if ($key !== '') {
        $params[':server'] = $key;
    }
    if ($words) {
        try {
            $match = implode(' ', array_map(function ($w) { return '"' . $w . '"*'; }, $words));
            $st = $db->prepare('SELECT f.*, s.name AS server_name FROM names JOIN files f ON f.id = names.rowid'
                . ' JOIN servers s ON s.server = f.server WHERE names MATCH :match' . $where
                . ' ORDER BY f.folder DESC, f.name COLLATE NOCASE LIMIT ' . (PER_PAGE + 1) . ' OFFSET ' . $offset);
            $st->execute(array_merge($params, array(':match' => $match)));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // PHP's SQLite without full-text search: a slower plain match.
            $sql = 'SELECT f.*, s.name AS server_name FROM files f JOIN servers s ON s.server = f.server WHERE 1' . $where;
            foreach ($words as $i => $w) {
                $sql .= " AND f.name LIKE :w$i";
                $params[":w$i"] = '%' . $w . '%';
            }
            $st = $db->prepare($sql . ' ORDER BY f.folder DESC, f.name COLLATE NOCASE LIMIT ' . (PER_PAGE + 1) . ' OFFSET ' . $offset);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    $more = count($rows) > PER_PAGE;
    $rows = array_slice($rows, 0, PER_PAGE);
} elseif ($q !== '') {
    $error = 'The file index isn\'t available right now.';
}

page_header($q === '' ? 'File Search' : $q, $q);
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>File Search</b></font></p>
<form action="search.php" method="get">
<font size="2">
<input type="text" name="q" size="30" value="<?php echo h($q); ?>">
<?php if ($key !== '') { ?>
<input type="hidden" name="s" value="<?php echo h($key); ?>"> on <?php echo h($key); ?> (<a href="<?php echo h(page_url('search.php', array('q' => $q))); ?>">all servers</a>)
<?php } ?>
<input type="submit" value="Search">
</font>
</form>
<?php if ($error) { ?>
<p><font size="2"><?php echo h($error); ?></font></p>
<?php } elseif ($q === '') { ?>
<p><font size="2">Search the names of files and folders on every indexed server. Every word you type has to be in the name.</font></p>
<?php } else { ?>
<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">
<tr bgcolor="#DDDDDD">
<th><font size="2">Name</font></th>
<th class="num"><font size="2">Size</font></th>
<th><font size="2">Where</font></th>
</tr>
<?php foreach ($rows as $r) {
    $folderLink = page_url('files.php', array('s' => $r['server'], 'p' => $r['parent']));
    ?>
<tr>
<?php if ($r['folder']) { ?>
<td><font size="2"><a href="<?php echo h(page_url('files.php', array('s' => $r['server'], 'p' => $r['parent'] . $r['name'] . '/'))); ?>">[<?php echo h($r['name']); ?>]</a></font></td>
<td class="num"><font size="2"><?php echo number_format((int) $r['size']); ?> items</font></td>
<?php } else { ?>
<td><font size="2"><?php echo h($r['name']); ?></font></td>
<td class="num"><font size="2"><?php echo h(format_bytes($r['size'])); ?></font></td>
<?php } ?>
<td><font size="1"><a href="<?php echo h(page_url('server.php', array('s' => $r['server']))); ?>"><?php echo h($r['server_name']); ?></a><br><a href="<?php echo h($folderLink); ?>"><?php echo h($r['parent']); ?></a></font></td>
</tr>
<?php } ?>
<?php if (!$rows) { ?>
<tr><td colspan="3"><font size="2">Nothing found.</font></td></tr>
<?php } ?>
</table>
<p><font size="2">
<?php if ($page > 1) { ?><a href="<?php echo h(page_url('search.php', array('q' => $q, 's' => $key, 'page' => $page - 1))); ?>">&lt; Previous</a> &nbsp; <?php } ?>
<?php if ($more) { ?><a href="<?php echo h(page_url('search.php', array('q' => $q, 's' => $key, 'page' => $page + 1))); ?>">Next &gt;</a><?php } ?>
</font></p>
<?php } ?>
<?php
page_footer();
