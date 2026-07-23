<p class="muted"><a href="<?= h(url('sites/' . $site['id'])) ?>">← <?= h($site['name']) ?></a></p>
<h1>Entity review</h1>

<form class="card row" method="get" action="<?= h(url('sites/' . $site['id'] . '/entities')) ?>" style="align-items:end">
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All</option>
      <?php foreach (['pending','approved','rejected','needs_edit'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
</form>

<?php if (empty($entities)): ?>
  <div class="card muted">No entities. Run a scan first.</div>
<?php else: ?>
<form method="post" action="<?= h(url('entities/bulk')) ?>">
  <input type="hidden" name="site_id" value="<?= h($site['id']) ?>" />
  <div class="row">
    <button class="btn btn-success btn-sm" name="action" value="approve" type="submit">Approve selected</button>
    <button class="btn btn-danger btn-sm" name="action" value="reject" type="submit">Reject selected</button>
  </div>
  <div class="card" style="padding:0;overflow:auto">
    <table>
      <thead>
        <tr><th></th><th>Status</th><th>Type</th><th>Name</th><th>Description</th><th>Quick</th></tr>
      </thead>
      <tbody>
      <?php foreach ($entities as $e): ?>
        <?php
          $desc = (string) ($e['description'] ?? '');
          $desc = function_exists('mb_substr') ? mb_substr($desc, 0, 120) : substr($desc, 0, 120);
        ?>
        <tr>
          <td><input type="checkbox" name="entity_ids[]" value="<?= h($e['id']) ?>" /></td>
          <td><span class="pill pill-<?= h($e['status']) ?>"><?= h($e['status']) ?></span></td>
          <td class="muted"><?= h($types[$e['entity_type']] ?? $e['entity_type']) ?></td>
          <td><strong><?= h($e['name']) ?></strong></td>
          <td class="muted"><?= h($desc) ?></td>
          <td class="muted" style="white-space:nowrap">
            <!-- use formaction via separate mini forms outside bulk would be cleaner; use GET-less POST buttons via JS-free approach: status change only via bulk or dedicated rows below -->
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</form>
<p class="muted">Tip: select rows and use Approve/Reject selected. Or open each site scan after enabling LLM in Settings for richer results.</p>
<?php endif; ?>
