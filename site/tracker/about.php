<?php
require __DIR__ . '/inc/common.php';

page_header('About');
?>
<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>About the tracker</b></font></p>
<p>BigRedH doesn't run a tracker of its own. Every hour it asks the Hotline trackers below for
their lists, merges them, and checks that each server answers. Servers a tracker lists for
decoration (welcome lines and dividers) are left out.</p>
<ul>
<li>hltracker.com</li>
<li>tracker.preterhuman.net</li>
<li>hotline.kicks-ass.net</li>
<li>saddle.dyndns.org</li>
</ul>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>The file index</b></font></p>
<p>Once a month, BigRedH logs into each server it hasn't indexed before as a guest and writes
down the name, size and kind of each file and folder a guest can see. Nothing is downloaded. To download,
connect to the server with a Hotline client.</p>
<p>It logs in as <b>TrackerCheck</b> and opens one folder at a time with a short pause
in between.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Population</b></font></p>
<p>Every six hours, BigRedH logs into each listed server as <b>TrackerCheck</b>, reads the
user list, and leaves. The <a href="population.php">Population</a> page shows who was on and
the names seen in the last 30 days, with their icons from the
<a href="http://hlwiki.com/ik0ns/">Hotline Wiki's icon archive</a>. Names are only kept on this
site, for 30 days, and aren't saved anywhere else. To be left off, ask on the
<a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>. Servers that keep out of the file
index are skipped here too.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Keeping your server out</b></font></p>
<p>To take your server off the list, out of the file index or off the Population page, or your
name off Population, fill in the <a href="<?php echo h(REMOVE_URL); ?>"><b>removal request</b></a>
on GitHub (it needs a free GitHub account). You can also ask on the
<a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a>.</p>
<p>To keep a server out of the file index before it's indexed, make a folder named
<b>noindex</b> anywhere on it: when the indexer sees it, it stops and records nothing.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>Getting listed</b></font></p>
<p>Register your server with any of the trackers above and it shows up here within the hour.</p>

<p class="heading"><font <?php echo FONT; ?> size="3" color="#8C1021"><b>The data</b></font></p>
<p>Everything the tracker collects is saved to <a href="<?php echo h(GITHUB_URL); ?>">GitHub</a>
as JSON: the server list every day, and every server's files every month. This hour's list is
also at <a href="data/servers.json">servers.json</a> and <a href="data/live.json">live.json</a>.</p>
<?php
page_footer();
