<?php
require __DIR__ . '/inc/common.php';

$pop = load_json('population.json');
$people = isset($pop['people']) ? $pop['people'] : array();
$online = isset($pop['online']) ? $pop['online'] : array();
$serverNames = isset($pop['server_names']) ? $pop['server_names'] : array();
$icons = population_icons($pop);
$keepDays = isset($pop['keep_days']) ? (int) $pop['keep_days'] : 30;
$filter = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$only = isset($_GET['s']) ? (string) $_GET['s'] : '';

function server_name($key, $serverNames)
{
    return isset($serverNames[$key]) ? $serverNames[$key] : $key;
}

// One name's own page: the icons it's been seen with lately, and where.
$who = isset($_GET['u']) ? (string) $_GET['u'] : '';
if ($who !== '') {
    page_header($who);
    echo '<p class="heading"><font ' . FONT . ' size="3" color="#8C1021"><b>' . h($who) . '</b></font></p>';
    if (!isset($people[$who])) {
        echo '<p><font size="2">Nobody by that name has been seen in the last ' . $keepDays . ' days.</font></p>';
    } else {
        $p = $people[$who];
        $link = page_url('population.php', array('u' => $who));
        echo user_tag($who, $p['icon'], $icons, $link);
        echo '<p><font size="2">Last seen ' . h(format_ago($p['last_seen'])) . ', first seen '
            . h(format_ago(isset($p['first_seen']) ? $p['first_seen'] : $p['last_seen'])) . '.</font></p>';

        // Newest first. Names seen before icons were recorded only have their current one.
        $history = isset($p['icons']) && $p['icons'] ? $p['icons'] : array((string) $p['icon'] => $p['last_seen']);
        arsort($history);
        echo '<p class="heading"><font ' . FONT . ' size="3" color="#8C1021"><b>Icons seen with lately</b></font></p>';
        echo '<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">'
            . '<tr bgcolor="#DDDDDD"><th width="236"><font size="2">Icon</font></th><th><font size="2">Number</font></th>'
            . '<th class="num"><font size="2">Last used</font></th></tr>';
        foreach ($history as $iconId => $when) {
            echo '<tr><td>' . user_tag($who, (int) $iconId, $icons, $link) . '</td>'
                . '<td><font size="2"><a href="http://hlwiki.com/ik0ns/' . (int) $iconId . '.png">#' . (int) $iconId . '</a>'
                . ((int) $iconId === (int) $p['icon'] ? ' <span class="dim">(current)</span>' : '') . '</font></td>'
                . '<td class="num"><font size="1">' . h(format_ago($when)) . '</font></td></tr>';
        }
        echo '</table>';

        $servers = $p['servers'];
        arsort($servers);
        echo '<p class="heading"><font ' . FONT . ' size="3" color="#8C1021"><b>Seen on</b></font></p>';
        echo '<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">'
            . '<tr bgcolor="#DDDDDD"><th><font size="2">Server</font></th><th class="num"><font size="2">Last seen</font></th></tr>';
        foreach ($servers as $key => $when) {
            echo '<tr><td><font size="2"><a href="' . h(page_url('server.php', array('s' => $key))) . '">'
                . h(server_name($key, $serverNames)) . '</a> <font size="1">(<a href="hotline://' . h($key) . '/">' . h($key) . '</a>)</font></font></td>'
                . '<td class="num"><font size="1">' . h(format_ago($when)) . '</font></td></tr>';
        }
        echo '</table>';
    }
    echo '<p><font size="2"><a href="population.php">Back to Population</a></font></p>';
    page_footer();
    exit;
}

$onlineCount = 0;
foreach ($online as $list) {
    $onlineCount += count($list);
}

if ($filter !== '') {
    $people = array_filter($people, function ($p, $name) use ($filter) {
        return stripos($name, $filter) !== false;
    }, ARRAY_FILTER_USE_BOTH);
}
if ($only !== '') {
    $people = array_filter($people, function ($p) use ($only) {
        return isset($p['servers'][$only]);
    });
}
uasort($people, function ($a, $b) {
    return strcmp($b['last_seen'], $a['last_seen']);
});

page_header('Population');
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Population</b></font></p>
<table class="stats" width="100%" cellpadding="4" cellspacing="0" border="0" bgcolor="#EEF1F5"><tr><td>
<font size="1">
<b><?php echo $onlineCount; ?></b> people online at the last check |
<b><?php echo count(isset($pop['people']) ? $pop['people'] : array()); ?></b> names seen in the last <?php echo $keepDays; ?> days |
Checked <?php echo h(format_ago(isset($pop['checked_at']) ? $pop['checked_at'] : '')); ?>
</font>
</td></tr></table>

<form action="population.php" method="get">
<font size="2">Find a name: <input type="text" name="q" size="20" value="<?php echo h($filter); ?>">
<?php if ($only !== '') { ?><input type="hidden" name="s" value="<?php echo h($only); ?>"><?php } ?>
<input type="submit" value="Find"><?php if ($filter !== '' || $only !== '') { ?> <a href="population.php">Show everyone</a><?php } ?></font>
</form>

<p><font size="1" class="dim">Every few hours the tracker looks at who's on each listed server. Names are
kept for <?php echo $keepDays; ?> days after they were last seen, and never stored anywhere else.
To be left off, send a <a href="<?php echo h(REMOVE_URL); ?>">removal request</a> or ask on the <a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>.</font></p>

<?php ob_start(); ?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Online now</b></font></p>
<?php
$any = false;
foreach ($online as $key => $list) {
    if (!$list || ($only !== '' && $key !== $only)) {
        continue;
    }
    $any = true;
    ?>
<p><font size="2"><b><a href="<?php echo h(page_url('server.php', array('s' => $key))); ?>"><?php echo h(server_name($key, $serverNames)); ?></a></b>
<font size="1">(<a href="hotline://<?php echo h($key); ?>/"><?php echo h($key); ?></a>)</font></font></p>
<table cellpadding="0" cellspacing="1" border="0">
<?php foreach ($list as $u) { ?>
<tr><td><?php echo user_tag($u['name'], $u['icon'], $icons, page_url('population.php', array('u' => $u['name']))); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php if (!$any) { ?>
<p><font size="2">Nobody was on at the last check.</font></p>
<?php } ?>

<?php $onlineHtml = ob_get_clean(); ob_start(); ?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Seen in the last <?php echo $keepDays; ?> days</b></font></p>
<table class="list" width="100%" cellpadding="3" cellspacing="0" border="0">
<tr bgcolor="#DDDDDD">
<th width="236"><font size="2">Name</font></th>
<th><font size="2">Seen on</font></th>
<th class="num"><font size="2">Last seen</font></th>
</tr>
<?php foreach ($people as $name => $p) { ?>
<tr>
<td><?php echo user_tag($name, $p['icon'], $icons, page_url('population.php', array('u' => $name))); ?></td>
<td><font size="1"><?php
    $where = array();
    foreach ($p['servers'] as $key => $seen) {
        $where[] = '<a href="' . h(page_url('server.php', array('s' => $key))) . '">' . h(server_name($key, $serverNames)) . '</a>';
    }
    echo implode(', ', $where);
?></font></td>
<td class="num"><font size="1"><?php echo h(format_ago($p['last_seen'])); ?></font></td>
</tr>
<?php } ?>
<?php if (!$people) { ?>
<tr><td colspan="3"><font size="2"><?php echo ($filter !== '' || $only !== '') ? 'Nobody matches that.' : 'Nobody yet.'; ?></font></td></tr>
<?php } ?>
</table>
<?php $seenHtml = ob_get_clean();
// A name search shows its results first; otherwise who is on right now comes first.
echo $filter !== '' ? $seenHtml . $onlineHtml : $onlineHtml . $seenHtml;
?>
<?php
page_footer();
