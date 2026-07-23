<h1>Sites</h1>
<p class="muted">PHP edition for shared hosting. Scan, verify, publish <code>llms.txt</code> / <code>graph.json</code> to your web root.</p>

<?php if (empty($has_llm)): ?>
<div class="alert alert-warn">No LLM configured — scans use limited heuristics. <a href="<?= h(url('settings')) ?>">Add API key</a> for better extraction.</div>
<?php endif; ?>

<div class="grid2">
  <div class="card">
    <h2>Add website</h2>
    <form method="post" action="<?= h(url('sites/create')) ?>">
      <label>Display name</label>
      <input name="name" required placeholder="My Company" />
      <label>Website URL</label>
      <input name="base_url" type="url" required placeholder="https://example.com" />
      <label>Max pages</label>
      <input name="max_pages" type="number" value="40" min="1" max="200" />
      <label>Web root path (this server)</label>
      <input id="home_publish_root" name="publish_root"
             value="<?= h($best_publish_path ?? '') ?>"
             placeholder="e.g. path from File Manager to public_html" />
      <?php if (!empty($publish_candidates)): ?>
      <p class="muted" style="margin-top:-0.35rem;font-size:.8rem">
        Detected: <?php foreach ($publish_candidates as $i => $c): ?>
          <?php if ($i): ?> · <?php endif; ?>
          <a href="#" onclick="document.getElementById('home_publish_root').value=<?= h(json_encode($c['path'])) ?>;return false;"><code><?= h($c['path']) ?></code></a>
        <?php endforeach; ?>
      </p>
      <?php else: ?>
      <p class="muted" style="margin-top:-0.35rem;font-size:.8rem">
        Not <code>/home/user/…</code> — use your real cPanel path from File Manager.
      </p>
      <?php endif; ?>
      <label><input type="checkbox" name="auto_publish" value="1" checked /> Auto-publish (when you click Publish)</label>
      <div class="row"><button class="btn btn-primary" type="submit">Create site</button></div>
    </form>
  </div>
  <div class="card">
    <h2>How to use</h2>
    <ol class="muted" style="padding-left:1.2rem;margin:0">
      <li>Create a site and set web root to <code>public_html</code>.</li>
      <li>Scan → review entities → approve.</li>
      <li>Publish live — writes files agents can fetch on your domain.</li>
    </ol>
  </div>
</div>

<h2>Your sites</h2>
<?php if (empty($sites)): ?>
  <div class="card muted">No sites yet.</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto">
  <table>
    <thead><tr><th>Name</th><th>URL</th><th>Entities</th><th>Pending</th><th>Approved</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="<?= h(url('sites/' . $s['id'])) ?>"><strong><?= h($s['name']) ?></strong></a></td>
        <td class="muted"><?= h($s['base_url']) ?></td>
        <td><?= (int) $s['counts']['total'] ?></td>
        <td><?= (int) $s['counts']['pending'] ?></td>
        <td><?= (int) $s['counts']['approved'] ?></td>
        <td><a class="btn btn-sm" href="<?= h(url('sites/' . $s['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
