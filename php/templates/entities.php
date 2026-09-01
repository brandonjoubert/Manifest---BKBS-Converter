<p class="muted"><a href="<?= h(url('sites/' . $site['id'])) ?>">← <?= h($site['name']) ?></a></p>
<h1>Review inbox</h1>
<p class="muted">Default view is pending and needs edit. Review claim diffs, then approve only what is true.</p>

<form class="card row" method="get" action="<?= h(url('sites/' . $site['id'] . '/entities')) ?>" style="align-items:end">
  <div>
    <label>Status</label>
    <select name="status">
      <option value="inbox" <?= ($status ?? 'inbox') === 'inbox' ? 'selected' : '' ?>>Inbox (pending + needs edit)</option>
      <option value="all" <?= ($status ?? '') === 'all' ? 'selected' : '' ?>>All</option>
      <?php foreach (['pending','approved','rejected','needs_edit'] as $s): ?>
        <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
  <a class="btn" href="<?= h(url('sites/' . $site['id'])) ?>#manual">+ Manual</a>
</form>

<?php if (empty($entities)): ?>
  <div class="card muted">No entities in this view. Run a scan, add a manual entry, or choose All.</div>
<?php else: ?>
<form method="post" action="<?= h(url('entities/bulk')) ?>">
  <input type="hidden" name="site_id" value="<?= h($site['id']) ?>" />
  <div class="row">
    <button class="btn btn-success btn-sm" name="action" value="approve" type="submit">Approve selected (new only)</button>
    <button class="btn btn-danger btn-sm" name="action" value="reject" type="submit">Reject selected</button>
  </div>
  <p class="muted">Bulk approve is for new entities. Items with live facts and pending diffs open a review list first.</p>
  <div class="card" style="padding:0;overflow:auto">
<table>
      <thead>
        <tr><th></th><th>Status</th><th>Type</th><th>Name</th><th>Changes</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($entities as $e): ?>
        <?php $d = $diffs[$e['id']] ?? []; ?>
        <tr>
          <td><input type="checkbox" name="entity_ids[]" value="<?= h($e['id']) ?>" /></td>
          <td><span class="pill pill-<?= h($e['status']) ?>"><?= h($e['status']) ?></span></td>
          <td class="muted"><?= h($types[$e['entity_type']] ?? $e['entity_type']) ?></td>
          <td><strong><?= h($d['display_name'] ?? $e['name']) ?></strong></td>
          <td class="muted"><?= h($d['summary'] ?? '—') ?></td>
          <td class="muted" style="white-space:nowrap">
            <a class="btn btn-sm btn-primary" href="<?= h(url('entities/' . $e['id'])) ?>">Review changes</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>
<?php endif; ?>
