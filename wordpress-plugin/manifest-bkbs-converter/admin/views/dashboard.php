<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$err = isset($_GET['mbkbs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_err'])) : '';
$last_scans = is_array($last_scans ?? null) ? $last_scans : [];
$sites = is_array($sites ?? null) ? $sites : [];
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Manifest BKBS Converter', 'manifest-bkbs'); ?> <span class="mbkbs-muted" style="font-size:14px;font-weight:400"><?php esc_html_e('(WordPress edition)', 'manifest-bkbs'); ?></span></h1>
  <p class="mbkbs-muted"><?php esc_html_e('Scan, human-verify, and publish agent-ready knowledge for this WordPress site (and optional extra URLs). Separate from the Python and PHP shared-host editions.', 'manifest-bkbs'); ?></p>

  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <?php if ($err) : ?><div class="mbkbs-notice-err"><?php echo esc_html($err); ?></div><?php endif; ?>

  <?php if (!$llm) : ?>
    <div class="mbkbs-notice-warn">
      <?php esc_html_e('No LLM configured — scans use limited heuristics.', 'manifest-bkbs'); ?>
      <a href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-settings')); ?>"><?php esc_html_e('Add an API key', 'manifest-bkbs'); ?></a>
    </div>
  <?php endif; ?>

  <?php
    $has_last_scan = false;
    foreach ($sites as $s) {
        if (!empty($last_scans[$s['id']])) {
            $has_last_scan = true;
            break;
        }
    }
  ?>
  <?php if ($has_last_scan) : ?>
    <div class="mbkbs-card" id="last-scan-findings">
      <h2><?php esc_html_e('Last scan findings', 'manifest-bkbs'); ?></h2>
      <p class="mbkbs-muted"><?php esc_html_e('Origin audit — these checks do not fail the crawl.', 'manifest-bkbs'); ?></p>
      <?php foreach ($sites as $s) : ?>
        <?php
          $job = $last_scans[$s['id']] ?? null;
          if (!is_array($job)) {
              continue;
          }
          $job_status = (string) ($job['status'] ?? '');
          $job_stats = [];
          if (!empty($job['stats_json'])) {
              $decoded = json_decode((string) $job['stats_json'], true);
              $job_stats = is_array($decoded) ? $decoded : [];
          }
          $findings = is_array($job_stats['findings'] ?? null) ? $job_stats['findings'] : [];
        ?>
        <h3><?php echo esc_html((string) $s['name']); ?></h3>
        <p>
          <span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($job_status); ?>"><?php echo esc_html($job_status); ?></span>
          <a href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-scan&job=' . rawurlencode((string) $job['id']))); ?>"><?php esc_html_e('Scan details', 'manifest-bkbs'); ?></a>
          ·
          <a href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Review entities', 'manifest-bkbs'); ?></a>
        </p>
        <?php if (!empty($job['error'])) : ?>
          <div class="mbkbs-notice-err"><?php echo esc_html((string) $job['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($findings)) : ?>
          <ul class="mbkbs-findings">
            <?php foreach ($findings as $f) : ?>
              <?php
                $f_status = (string) ($f['status'] ?? '');
                $f_sev = (string) ($f['severity'] ?? '');
                $cta = trim((string) ($f['cta'] ?? ''));
                $cta_href = trim((string) ($f['cta_href'] ?? ''));
              ?>
              <li class="mbkbs-finding mbkbs-finding-<?php echo esc_attr($f_status); ?>">
                <span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($f_status); ?>"><?php echo esc_html($f_status); ?></span>
                <span class="mbkbs-pill mbkbs-pill-sev-<?php echo esc_attr($f_sev); ?>"><?php echo esc_html($f_sev); ?></span>
                <strong><?php echo esc_html((string) ($f['title'] ?? '')); ?></strong>
                <p class="mbkbs-muted"><?php echo esc_html((string) ($f['evidence'] ?? '')); ?></p>
                <?php if ($cta !== '' && $cta_href !== '') : ?>
                  <p><a class="button button-small" href="<?php echo esc_url($cta_href); ?>"><?php echo esc_html($cta); ?></a></p>
                <?php elseif ($cta !== '') : ?>
                  <p class="mbkbs-muted"><?php echo esc_html($cta); ?></p>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($job_stats)) : ?>
          <details>
            <summary><?php esc_html_e('Raw stats', 'manifest-bkbs'); ?></summary>
            <pre class="mbkbs-muted" style="white-space:pre-wrap;font-size:12px"><?php echo esc_html((string) wp_json_encode($job_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
          </details>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="mbkbs-stats">
    <div class="mbkbs-stat"><div class="n"><?php echo (int) array_sum($by); ?></div><div class="l"><?php esc_html_e('Entities', 'manifest-bkbs'); ?></div></div>
    <div class="mbkbs-stat"><div class="n"><?php echo (int) ($by['pending'] ?? 0); ?></div><div class="l"><?php esc_html_e('Pending', 'manifest-bkbs'); ?></div></div>
    <div class="mbkbs-stat"><div class="n"><?php echo (int) ($by['approved'] ?? 0); ?></div><div class="l"><?php esc_html_e('Approved', 'manifest-bkbs'); ?></div></div>
  </div>

  <div class="mbkbs-grid">
    <div class="mbkbs-card">
      <h2><?php esc_html_e('Sites to scan', 'manifest-bkbs'); ?></h2>
      <?php if (empty($sites)) : ?>
        <p class="mbkbs-muted"><?php esc_html_e('No sites yet.', 'manifest-bkbs'); ?></p>
      <?php else : ?>
        <table class="mbkbs-table">
          <thead><tr><th><?php esc_html_e('Name', 'manifest-bkbs'); ?></th><th>URL</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($sites as $s) : ?>
            <tr>
              <td><strong><?php echo esc_html($s['name']); ?></strong></td>
              <td class="mbkbs-muted"><?php echo esc_html($s['base_url']); ?></td>
              <td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                  <?php wp_nonce_field('mbkbs_scan'); ?>
                  <input type="hidden" name="action" value="mbkbs_scan" />
                  <input type="hidden" name="site_id" value="<?php echo esc_attr($s['id']); ?>" />
                  <button class="button button-primary" type="submit"><?php esc_html_e('Scan', 'manifest-bkbs'); ?> — <?php echo esc_html($s['name']); ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h3><?php esc_html_e('Add another site / URL', 'manifest-bkbs'); ?></h3>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('mbkbs_add_site'); ?>
        <input type="hidden" name="action" value="mbkbs_add_site" />
        <div class="mbkbs-field">
          <label for="name"><?php esc_html_e('Name', 'manifest-bkbs'); ?></label>
          <input id="name" name="name" type="text" required />
        </div>
        <div class="mbkbs-field">
          <label for="base_url"><?php esc_html_e('Base URL', 'manifest-bkbs'); ?></label>
          <input id="base_url" name="base_url" type="url" required placeholder="https://example.com" />
        </div>
        <div class="mbkbs-field">
          <label for="max_pages"><?php esc_html_e('Max pages', 'manifest-bkbs'); ?></label>
          <input id="max_pages" name="max_pages" type="number" value="40" min="1" max="200" />
        </div>
        <button class="button" type="submit"><?php esc_html_e('Add site', 'manifest-bkbs'); ?></button>
      </form>
    </div>

    <div class="mbkbs-card">
      <h2><?php esc_html_e('Review & publish', 'manifest-bkbs'); ?></h2>
      <ol class="mbkbs-muted">
        <li><?php esc_html_e('Scan a site', 'manifest-bkbs'); ?></li>
        <li><?php esc_html_e('Open Entities → Edit before approve', 'manifest-bkbs'); ?></li>
        <li><?php esc_html_e('Approve only correct facts', 'manifest-bkbs'); ?></li>
        <li><?php esc_html_e('Publish live machine layers', 'manifest-bkbs'); ?></li>
      </ol>
      <div class="mbkbs-actions">
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Review entities', 'manifest-bkbs'); ?></a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-settings')); ?>"><?php esc_html_e('LLM settings', 'manifest-bkbs'); ?></a>
      </div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('mbkbs_publish'); ?>
        <input type="hidden" name="action" value="mbkbs_publish" />
        <p>
          <label>
            <input type="checkbox" name="write_files" value="1" />
            <?php esc_html_e('Also write static files into the WordPress root (if writable)', 'manifest-bkbs'); ?>
          </label>
        </p>
        <button class="button button-primary" type="submit"><?php esc_html_e('Publish live (approved only)', 'manifest-bkbs'); ?></button>
      </form>
      <p class="mbkbs-muted" style="margin-top:12px">
        <?php esc_html_e('Public URLs after publish / rewrites:', 'manifest-bkbs'); ?><br />
        <code><?php echo esc_html(home_url('/llms.txt')); ?></code><br />
        <code><?php echo esc_html(home_url('/graph.json')); ?></code>
      </p>

      <h3><?php esc_html_e('Manual entity', 'manifest-bkbs'); ?></h3>
      <?php if (!empty($sites)) : ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('mbkbs_manual_entity'); ?>
        <input type="hidden" name="action" value="mbkbs_manual_entity" />
        <div class="mbkbs-field">
          <label><?php esc_html_e('Site', 'manifest-bkbs'); ?></label>
          <select name="site_id">
            <?php foreach ($sites as $s) : ?>
              <option value="<?php echo esc_attr($s['id']); ?>"><?php echo esc_html($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mbkbs-field">
          <label><?php esc_html_e('Type', 'manifest-bkbs'); ?></label>
          <select name="entity_type">
            <?php foreach (MBKBS_Plugin::entity_types() as $k => $lab) : ?>
              <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mbkbs-field">
          <label><?php esc_html_e('Name', 'manifest-bkbs'); ?></label>
          <input name="name" type="text" required />
        </div>
        <div class="mbkbs-field">
          <label><?php esc_html_e('Description', 'manifest-bkbs'); ?></label>
          <textarea name="description" rows="3"></textarea>
        </div>
        <p><label><input type="checkbox" name="approve_immediately" value="1" /> <?php esc_html_e('Approve immediately', 'manifest-bkbs'); ?></label></p>
        <button class="button" type="submit"><?php esc_html_e('Add entity', 'manifest-bkbs'); ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="mbkbs-card" id="machine-layers">
    <h2><?php esc_html_e('Machine layers', 'manifest-bkbs'); ?></h2>
    <p class="mbkbs-muted"><?php esc_html_e('Live origin URLs. JSON-LD inject is off by default.', 'manifest-bkbs'); ?></p>
    <ul class="mbkbs-muted">
      <?php foreach (($live_urls ?? []) as $pair) : ?>
        <li><a href="<?php echo esc_url($pair[1]); ?>" target="_blank" rel="noopener"><?php echo esc_html($pair[1]); ?></a> (<?php echo esc_html($pair[0]); ?>)</li>
      <?php endforeach; ?>
    </ul>
    <p>
      <label for="jsonld-snippet"><?php esc_html_e('JSON-LD snippet (script-safe)', 'manifest-bkbs'); ?></label>
      <textarea id="jsonld-snippet" class="large-text code" rows="6" readonly><?php echo esc_textarea($jsonld_snippet ?? ''); ?></textarea>
    </p>
    <p>
      <button class="button" type="button" onclick="navigator.clipboard.writeText(document.getElementById('jsonld-snippet').value)"><?php esc_html_e('Copy snippet', 'manifest-bkbs'); ?></button>
      <?php if (!empty($jsonld_inject)) : ?>
        <span class="mbkbs-muted"><?php esc_html_e('Homepage inject: on', 'manifest-bkbs'); ?></span>
      <?php else : ?>
        <span class="mbkbs-muted"><?php esc_html_e('Homepage inject: off', 'manifest-bkbs'); ?></span>
      <?php endif; ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('mbkbs_save_machine_layers'); ?>
      <input type="hidden" name="action" value="mbkbs_save_machine_layers" />
      <input type="hidden" name="redirect_page" value="mbkbs" />
      <p>
        <label>
          <input type="checkbox" id="jsonld_wp_head" name="jsonld_wp_head" value="1" <?php checked(!empty($jsonld_inject)); ?> />
          <?php esc_html_e('Print JSON-LD in wp_head on homepage', 'manifest-bkbs'); ?>
        </label>
      </p>
      <p class="mbkbs-muted"><?php esc_html_e('AI preferences (AIPREF) — all off by default. Opt in before a Content-Usage line is written into the managed robots block.', 'manifest-bkbs'); ?></p>
      <p><label><input type="checkbox" name="aipref_search" value="1" <?php checked(!empty($aipref_search)); ?> /> <?php esc_html_e('Allow search indexing (search)', 'manifest-bkbs'); ?></label></p>
      <p><label><input type="checkbox" name="aipref_ai_input" value="1" <?php checked(!empty($aipref_ai_input)); ?> /> <?php esc_html_e('Allow use as generative AI input (ai-input)', 'manifest-bkbs'); ?></label></p>
      <p><label><input type="checkbox" name="aipref_train_ai" value="1" <?php checked(!empty($aipref_train_ai)); ?> /> <?php esc_html_e('Allow AI training (train-ai)', 'manifest-bkbs'); ?></label></p>
      <p><button class="button" type="submit"><?php esc_html_e('Save machine-layer settings', 'manifest-bkbs'); ?></button></p>
    </form>
    <p class="mbkbs-muted"><?php esc_html_e('Robots merge preview (always includes # END BKBS):', 'manifest-bkbs'); ?></p>
    <pre class="mbkbs-muted" style="white-space:pre-wrap;font-size:12px"><?php echo esc_html($robots_preview ?? ''); ?></pre>
  </div>
</div>
