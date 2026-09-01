<p class="muted"><a href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">← Review inbox</a></p>
<h1>Review claim diffs before bulk approve</h1>
<p class="muted">These items already have live approved facts. Confirming publishes every pending attribute below.</p>

<?php foreach ($entities as $e): ?>
  <?php $d = $diffs[$e['id']] ?? []; ?>
  <div class="card">
    <h2><?= h($d['display_name'] ?? $e['name']) ?> <span class="pill pill-<?= h($e['status']) ?>"><?= h($e['status']) ?></span></h2>
    <p class="muted"><?= h($d['summary'] ?? '') ?></p>
    <?php if (!empty($d['changes'])): ?>
    <ul>
      <?php foreach ($d['changes'] as $c): ?>
        <li>
          <strong><?= h($c['label']) ?></strong>
          <?php if (($c['kind'] ?? '') === 'changed'): ?>
            · live: <code><?= h(substr((string) ($c['old_display'] ?? ''), 0, 80)) ?></code>
            → proposed: <code><?= h(substr((string) ($c['new_display'] ?? ''), 0, 80)) ?></code>
          <?php else: ?>
            · new: <code><?= h(substr((string) ($c['new_display'] ?? ''), 0, 80)) ?></code>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <a class="btn btn-sm" href="<?= h(url('entities/' . $e['id'])) ?>">Review this entity</a>
  </div>
<?php endforeach; ?>

<form method="post" action="<?= h(url('entities/bulk')) ?>">
  <input type="hidden" name="site_id" value="<?= h($site['id']) ?>" />
  <input type="hidden" name="confirm_diffs" value="1" />
  <?php foreach ($entities as $e): ?>
    <input type="hidden" name="entity_ids[]" value="<?= h($e['id']) ?>" />
  <?php endforeach; ?>
  <div class="row">
    <button class="btn btn-success" name="action" value="approve" type="submit">Approve all listed diffs</button>
    <a class="btn" href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">Cancel</a>
  </div>
</form>
