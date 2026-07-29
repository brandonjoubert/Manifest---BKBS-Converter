<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$err = isset($_GET['mbkbs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_err'])) : '';
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
                  <button class="button button-primary" type="submit"><?php esc_html_e('Scan', 'manifest-bkbs'); ?></button>
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
</div>
