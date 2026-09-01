<p class="muted"><a href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">← Review inbox</a> · <?= h($site['name']) ?></p>
<h1>Review changes</h1>

<p class="subtitle">
  <span class="pill pill-<?= h($entity['status']) ?>"><?= h($entity['status']) ?></span>
  · <?= h($types[$entity['entity_type']] ?? $entity['entity_type']) ?>
  · <?= h($diff['summary'] ?? '') ?>
</p>

<?php if (($entity['status'] ?? '') === 'needs_edit'): ?>
<div class="alert alert-ok">Live facts are still published. Approving replaces them; rejecting keeps the last approved snapshot live.</div>
<?php elseif (in_array($entity['status'], ['pending'], true)): ?>
<div class="alert alert-warn">This entity is not published yet. Approve only facts you have checked.</div>
<?php endif; ?>

<form class="card" method="post" action="<?= h(url('entities/' . $entity['id'] . '/review')) ?>">
  <h2>Changes</h2>
  <?php if (!empty($diff['changes'])): ?>
    <?php foreach ($diff['changes'] as $c): ?>
      <div class="diff-block">
        <h3><?= h($c['label']) ?> <span class="muted"><?= h($c['kind']) ?></span></h3>
        <?php if (($c['kind'] ?? '') === 'changed'): ?>
          <div class="diff-grid">
            <div>
              <div class="muted">Last approved (live)</div>
              <pre class="diff-old"><?= h((string) ($c['old_display'] ?? '')) ?></pre>
            </div>
            <div>
              <div class="muted">Proposed</div>
              <textarea name="claim:<?= h($c['attribute']) ?>"><?= h((string) ($c['new_display'] ?? '')) ?></textarea>
            </div>
          </div>
        <?php else: ?>
          <div class="muted">New fact (not published yet)</div>
          <textarea name="claim:<?= h($c['attribute']) ?>"><?= h((string) ($c['new_display'] ?? '')) ?></textarea>
        <?php endif; ?>
        <textarea hidden name="extract:<?= h($c['attribute']) ?>"><?= h((string) ($c['new'] ?? '')) ?></textarea>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">No pending claim diffs.</p>
  <?php endif; ?>

  <div class="btn-row">
    <button class="btn btn-success" type="submit" name="intent" value="save_approve">Approve</button>
    <button class="btn btn-primary" type="submit" name="intent" value="save">Save without approve</button>
    <button class="btn btn-danger" type="submit" name="intent" value="save_reject">Reject</button>
    <a class="btn" href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">Back to inbox</a>
  </div>

  <label for="notes">Reviewer notes</label>
  <textarea id="notes" name="notes"><?= h($entity['notes'] ?? '') ?></textarea>

  <?php if (!empty($diff['unchanged'])): ?>
  <details class="card">
    <summary>Unchanged / already live (<?= count($diff['unchanged']) ?>)</summary>
    <ul class="muted">
      <?php foreach ($diff['unchanged'] as $u): ?>
        <li><strong><?= h($u['label']) ?>:</strong> <?= h(substr((string) ($u['display'] ?? ''), 0, 240)) ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</form>
