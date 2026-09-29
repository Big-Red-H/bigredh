<?php
require __DIR__ . '/inc/common.php';

page_header('About');
?>
<h2>About the tracker</h2>
<p>BigRedH doesn't run a tracker of its own. Every hour it asks the Hotline trackers below for
their lists, merges them, and checks that each server answers. Servers a tracker lists for
decoration (welcome lines and dividers) are left out.</p>
<ul>
<li>hltracker.com</li>
<li>tracker.preterhuman.net</li>
<li>hotline.kicks-ass.net</li>
<li>saddle.dyndns.org</li>
</ul>

<h2>The file index</h2>
<p>Once a month, BigRedH logs into every listed server as a guest and writes down the name,
size and kind of each file and folder a guest can see. Nothing is downloaded. To download,
connect to the server with a Hotline client.</p>
<p>It logs in as <b>BigRedH Indexer</b> and opens one folder at a time with a short pause
in between.</p>

<h2>Keeping your server out</h2>
<p>Make a folder named <b>noindex</b> anywhere on your server. The next time the indexer
sees it, it drops everything it had for your server and stops. You can also ask on the
<a href="https://discord.gg/vdxJHwzfrN">Hotline Discord</a> to be left off the list or the index entirely.</p>

<h2>Getting listed</h2>
<p>Register your server with any of the trackers above and it shows up here within the hour.</p>

<h2>The data</h2>
<p>Everything the tracker collects is saved to <a href="<?php echo h(GITHUB_URL); ?>">GitHub</a>
as JSON: the server list every day, and every server's files every month. This hour's list is
also at <a href="data/servers.json">servers.json</a> and <a href="data/live.json">live.json</a>.</p>
<?php
page_footer();
