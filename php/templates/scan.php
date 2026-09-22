<p class="muted"><a href="<?= h(url('sites/' . $site['id'])) ?>">← <?= h($site['name']) ?></a></p>
<?php
$status = (string) ($job['status'] ?? '');
if ($status === 'completed-with-warnings') {
    $heading = 'Scan completed with warnings';
} elseif ($status === 'completed') {
    $heading = 'Scan completed';
} else {
    $heading = 'Scan ' . $status;
}
?>
<h1><?= h($heading) ?></h1>
<p class="muted">Job <span class="mono"><?= h((string) $job['id']) ?></span></p>

<div class="stats">
  <div class="stat"><div class="n"><?= (int) ($job['pages_fetched'] ?? 0) ?></div><div class="l">Pages</div></div>
  <div class="stat"><div class="n"><?= (int) ($job['entities_found'] ?? 0) ?></div><div class="l">Entities touched</div></div>
  <div class="stat"><div class="n"><span class="pill pill-<?= h($status) ?>"><?= h($status) ?></span></div><div class="l">Status</div></div>
</div>

<?php if (!empty($job['error'])): ?>
  <div class="alert alert-err"><strong>Error:</strong> <?= h((string) $job['error']) ?></div>
<?php endif; ?>

<?php if (!empty($findings)): ?>
<div class="card" id="findings">
  <h2 style="margin-top:0">Findings</h2>
  <p class="muted">Origin audit — these checks do not fail the crawl.</p>
  <ul class="findings">
    <?php foreach ($findings as $f): ?>
      <?php
        $fStatus = (string) ($f['status'] ?? '');
        $fSev = (string) ($f['severity'] ?? '');
        $cta = trim((string) ($f['cta'] ?? ''));
        $ctaHref = trim((string) ($f['cta_href'] ?? ''));
      ?>
      <li class="finding finding-<?= h($fStatus) ?>">
        <span class="pill pill-<?= h($fStatus) ?>"><?= h($fStatus) ?></span>
        <span class="pill pill-sev-<?= h($fSev) ?>"><?= h($fSev) ?></span>
        <strong><?= h((string) ($f['title'] ?? '')) ?></strong>
        <p class="muted"><?= h((string) ($f['evidence'] ?? '')) ?></p>
        <?php if ($cta !== '' && $ctaHref !== ''): ?>
          <p><a class="btn btn-sm" href="<?= h($ctaHref) ?>"><?= h($cta) ?></a></p>
        <?php elseif ($cta !== ''): ?>
          <p class="muted"><?= h($cta) ?></p>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($stats)): ?>
<div class="card">
  <h2 style="margin-top:0">Stats</h2>
  <pre class="mono muted" style="white-space:pre-wrap;margin:0"><?= h((string) json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<div class="btn-row">
  <a class="btn btn-primary" href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">Review entities</a>
  <a class="btn" href="<?= h(url('sites/' . $site['id'])) ?>">Site overview</a>
  <?php if (in_array($status, ['completed', 'failed', 'completed-with-warnings'], true)): ?>
    <form method="post" action="<?= h(url('sites/' . $site['id'] . '/scan')) ?>" style="display:inline">
      <button class="btn" type="submit">Run again</button>
    </form>
  <?php endif; ?>
</div>
