<p class="muted"><a href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">← Entities</a> · <?= h($site['name']) ?></p>
<h1>Review & edit entity</h1>

<p class="subtitle">
  <span class="pill pill-<?= h($entity['status']) ?>"><?= h($entity['status']) ?></span>
  · <?= h($types[$entity['entity_type']] ?? $entity['entity_type']) ?>
  · source <?= h($entity['source']) ?>
  · v<?= h($entity['version']) ?>
</p>

<?php if (in_array($entity['status'], ['pending', 'needs_edit'], true)): ?>
<div class="alert alert-warn">
  This entry is not published yet. Edit the fields below, then <strong>Save</strong> and/or
  <strong>Save & approve</strong> when the fact is correct.
</div>
<?php endif; ?>

<form class="card" method="post" action="<?= h(url('entities/' . $entity['id'])) ?>">
  <div class="form-row">
    <div>
      <label for="name">Name</label>
      <input id="name" name="name" type="text" required value="<?= h($entity['name']) ?>" />
    </div>
    <div>
      <label for="entity_type">Entity type</label>
      <select id="entity_type" name="entity_type">
        <?php foreach ($types as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $entity['entity_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <label for="description">Description</label>
  <textarea id="description" name="description" class="tall" style="font-family:inherit"><?= h($entity['description'] ?? '') ?></textarea>

  <div class="form-row">
    <div>
      <label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'needs_edit' => 'Needs edit'] as $k => $lab): ?>
          <option value="<?= h($k) ?>" <?= $entity['status'] === $k ? 'selected' : '' ?>><?= h($lab) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="trust_level">Trust level</label>
      <select id="trust_level" name="trust_level">
        <?php foreach (['low', 'medium', 'high'] as $t): ?>
          <option value="<?= $t ?>" <?= $entity['trust_level'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <label for="notes">Reviewer notes</label>
  <textarea id="notes" name="notes" style="font-family:inherit"><?= h($entity['notes'] ?? '') ?></textarea>

  <label for="properties_json">Properties (JSON)</label>
  <textarea id="properties_json" name="properties_json" class="tall"><?= h(json_encode(json_decode($entity['properties'] ?? '{}', true), JSON_PRETTY_PRINT)) ?></textarea>

  <label for="relationships_json">Relationships (JSON array)</label>
  <textarea id="relationships_json" name="relationships_json" class="tall"><?= h(json_encode(json_decode($entity['relationships'] ?? '[]', true), JSON_PRETTY_PRINT)) ?></textarea>

  <label for="evidence_json">Evidence (JSON array)</label>
  <textarea id="evidence_json" name="evidence_json" class="tall"><?= h(json_encode(json_decode($entity['evidence'] ?? '[]', true), JSON_PRETTY_PRINT)) ?></textarea>

  <div class="btn-row">
    <button class="btn btn-primary" type="submit" name="intent" value="save">Save changes</button>
    <button class="btn btn-success" type="submit" name="intent" value="save_approve">Save & approve</button>
    <button class="btn btn-danger" type="submit" name="intent" value="save_reject">Save & reject</button>
    <a class="btn" href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">Back to list</a>
  </div>
</form>