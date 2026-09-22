<p class="muted"><a href="<?= h(url('home')) ?>">← Sites</a></p>
<h1><?= h($site['name']) ?></h1>
<p class="muted"><?= h($site['base_url']) ?></p>

<div class="row">
  <form method="post" action="<?= h(url('sites/' . $site['id'] . '/scan')) ?>">
    <button class="btn btn-primary" type="submit">Scan / Rescan</button>
  </form>
  <a class="btn" href="<?= h(url('sites/' . $site['id'] . '/entities')) ?>">Review entities</a>
  <form method="post" action="<?= h(url('sites/' . $site['id'] . '/publish')) ?>">
    <button class="btn btn-success" type="submit">Publish live to web root</button>
  </form>
</div>

<div class="card">
  <h2>Counts</h2>
  <p class="muted">
    Approved: <strong><?= (int) ($counts['approved'] ?? 0) ?></strong> ·
    Pending: <strong><?= (int) ($counts['pending'] ?? 0) ?></strong> ·
    Total: <strong><?= array_sum($counts) ?></strong>
  </p>
</div>

<div class="card">
  <h2>Site &amp; live publish settings</h2>
  <form method="post" action="<?= h(url('sites/' . $site['id'] . '/settings')) ?>">
    <label>Name</label>
    <input name="name" value="<?= h($site['name']) ?>" required />
    <label>Base URL</label>
    <input name="base_url" value="<?= h($site['base_url']) ?>" required />
    <label>Max pages</label>
    <input name="max_pages" type="number" value="<?= (int) $site['max_pages'] ?>" />
    <label>Web root path on this server</label>
    <input id="publish_root" name="publish_root"
           value="<?= h((string) ($site['publish_root'] ?: ($best_publish_path ?? ''))) ?>"
           placeholder="Paste path from list below or File Manager" />
    <p class="muted" style="margin-top:-0.35rem;font-size:.85rem">
      <strong>Do not use</strong> <code>/home/user/public_html</code> — that is only an example.
      On cPanel, open <strong>File Manager</strong>, go to the folder that contains your live website
      (<code>public_html</code> for the main domain), and copy the <strong>full path</strong> from the top bar
      (it looks like <code>/home/yourcpanelname/public_html</code>).
    </p>
    <?php if (!empty($publish_candidates)): ?>
    <p class="muted" style="margin:0.5rem 0 0.25rem;font-size:.85rem"><strong>Paths detected on this server</strong> (click to use):</p>
    <ul class="muted" style="margin:0 0 0.75rem;padding-left:1.1rem;font-size:.85rem">
      <?php foreach ($publish_candidates as $c): ?>
        <li style="margin-bottom:0.35rem">
          <button type="button" class="btn btn-sm" style="margin-right:0.35rem"
                  onclick="document.getElementById('publish_root').value=<?= h(json_encode($c['path'])) ?>">
            Use
          </button>
          <code><?= h($c['path']) ?></code>
          <span> — <?= h($c['label']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <label><input type="checkbox" name="auto_publish" value="1" <?= !empty($site['auto_publish']) ? 'checked' : '' ?> /> Prefer auto-publish</label>
    <p class="muted" style="margin:0.75rem 0 0.35rem"><strong>AI preferences (AIPREF)</strong> — all off by default. Opt in before a Content-Usage line is written into the managed robots block.</p>
    <label><input type="checkbox" name="aipref_search" value="1" <?= !empty($site['aipref_search']) ? 'checked' : '' ?> /> Allow search indexing (<code>search</code>)</label>
    <label><input type="checkbox" name="aipref_ai_input" value="1" <?= !empty($site['aipref_ai_input']) ? 'checked' : '' ?> /> Allow use as generative AI input (<code>ai-input</code>)</label>
    <label><input type="checkbox" name="aipref_train_ai" value="1" <?= !empty($site['aipref_train_ai']) ? 'checked' : '' ?> /> Allow AI training (<code>train-ai</code>)</label>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
</div>

<div class="card" id="machine-layers">
  <h2>Machine layers</h2>
  <p class="muted">Live origin URLs after publish. This edition cannot inject JSON-LD into your HTML — copy the snippet into your theme <code>&lt;head&gt;</code>.</p>
  <ul class="muted" style="font-size:.9rem">
    <?php foreach (($live_urls ?? []) as $pair): ?>
      <li><a href="<?= h($pair[1]) ?>" target="_blank" rel="noopener"><?= h($pair[1]) ?></a> <span>(<?= h($pair[0]) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
  <label for="jsonld-snippet">JSON-LD snippet (script-safe)</label>
  <textarea id="jsonld-snippet" rows="6" readonly><?= h($jsonld_snippet ?? '') ?></textarea>
  <p><button class="btn btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('jsonld-snippet').value)">Copy snippet</button></p>
  <p class="muted">JSON-LD inject: <strong>not available here</strong> (WordPress edition has a homepage checkbox, default off).</p>
  <label>Robots merge preview (always includes <code># END BKBS</code>)</label>
  <pre style="white-space:pre-wrap;font-size:.85rem"><?= h($robots_preview ?? '') ?></pre>
</div>

<div class="card" id="manual">
  <h2>Manual entity</h2>
  <form method="post" action="<?= h(url('manual')) ?>">
    <input type="hidden" name="site_id" value="<?= h($site['id']) ?>" />
    <label>Type</label>
    <select name="entity_type">
      <?php foreach (entity_types() as $k => $lab): ?>
        <option value="<?= h($k) ?>"><?= h($lab) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Name</label>
    <input name="name" required />
    <label>Description</label>
    <textarea name="description" rows="3"></textarea>
    <label><input type="checkbox" name="approve_immediately" value="1" /> Approve immediately</label>
    <button class="btn" type="submit">Add entity</button>
  </form>
</div>

<div class="card">
  <h2>Recent scans</h2>
  <?php if (empty($jobs)): ?>
    <p class="muted">No scans yet.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Status</th><th>Pages</th><th>Entities</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><a href="<?= h(url('scans/' . $j['id'])) ?>"><span class="pill pill-<?= h($j['status']) ?>"><?= h($j['status']) ?></span></a></td>
        <td><?= (int) $j['pages_fetched'] ?></td>
        <td><?= (int) $j['entities_found'] ?></td>
        <td class="muted"><?= h($j['created_at']) ?></td>
      </tr>
      <?php if (!empty($j['error'])): ?>
        <tr><td colspan="4" class="muted"><?= h($j['error']) ?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card" style="border-color:rgba(243,18,96,.35)">
  <h2 style="color:var(--err)">Delete site</h2>
  <form method="post" action="<?= h(url('sites/' . $site['id'] . '/delete')) ?>" onsubmit="return confirm('Delete permanently?');">
    <label>Type site name: <strong><?= h($site['name']) ?></strong></label>
    <input name="confirm_name" required autocomplete="off" />
    <button class="btn btn-danger" type="submit">Delete</button>
  </form>
</div>
