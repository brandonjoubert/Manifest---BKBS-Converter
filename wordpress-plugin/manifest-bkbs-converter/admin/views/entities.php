<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$err = isset($_GET['mbkbs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_err'])) : '';
$diffs = $diffs ?? [];
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Review inbox', 'manifest-bkbs'); ?></h1>
  <p class="mbkbs-muted">
    <?php esc_html_e('Default view is pending and needs edit. Review claim diffs, then approve only what is true. Live facts stay published while an item needs edit.', 'manifest-bkbs'); ?>
  </p>
  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <?php if ($err) : ?><div class="mbkbs-notice-err"><?php echo esc_html($err); ?></div><?php endif; ?>

  <form method="get" class="mbkbs-card mbkbs-actions">
    <input type="hidden" name="page" value="mbkbs-entities" />
    <label>
      <?php esc_html_e('Status', 'manifest-bkbs'); ?>
      <select name="status">
        <option value="inbox" <?php selected($status, 'inbox'); ?>><?php esc_html_e('Inbox (pending + needs edit)', 'manifest-bkbs'); ?></option>
        <option value="all" <?php selected($status, 'all'); ?>><?php esc_html_e('All', 'manifest-bkbs'); ?></option>
        <?php foreach (['pending', 'approved', 'rejected', 'needs_edit'] as $s) : ?>
          <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($s); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button" type="submit"><?php esc_html_e('Filter', 'manifest-bkbs'); ?></button>
    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs')); ?>"><?php esc_html_e('Dashboard', 'manifest-bkbs'); ?></a>
  </form>

  <?php if (empty($entities)) : ?>
    <div class="mbkbs-card mbkbs-muted"><?php esc_html_e('No entities in this view. Run a scan or choose All.', 'manifest-bkbs'); ?></div>
  <?php else : ?>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('mbkbs_bulk_verify'); ?>
    <input type="hidden" name="action" value="mbkbs_bulk_verify" />
    <div class="mbkbs-actions">
      <button class="button button-primary" name="action_name" value="approve" type="submit"><?php esc_html_e('Approve selected (new only)', 'manifest-bkbs'); ?></button>
      <button class="button" name="action_name" value="reject" type="submit"><?php esc_html_e('Reject selected', 'manifest-bkbs'); ?></button>
      <button class="button" name="action_name" value="needs_edit" type="submit"><?php esc_html_e('Mark needs edit', 'manifest-bkbs'); ?></button>
    </div>
    <p class="mbkbs-muted"><?php esc_html_e('Bulk approve is for new entities. Items with live facts and pending diffs open a review list first.', 'manifest-bkbs'); ?></p>
    <div class="mbkbs-card" style="padding:0;overflow:auto">
      <table class="mbkbs-table">
        <thead>
          <tr>
            <th></th>
            <th><?php esc_html_e('Status', 'manifest-bkbs'); ?></th>
            <th><?php esc_html_e('Type', 'manifest-bkbs'); ?></th>
            <th><?php esc_html_e('Name', 'manifest-bkbs'); ?></th>
            <th><?php esc_html_e('Changes', 'manifest-bkbs'); ?></th>
            <th><?php esc_html_e('Actions', 'manifest-bkbs'); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($entities as $e) : ?>
          <?php $d = $diffs[$e['id']] ?? []; ?>
          <tr>
            <td><input type="checkbox" name="entity_ids[]" value="<?php echo esc_attr($e['id']); ?>" /></td>
            <td><span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($e['status']); ?>"><?php echo esc_html($e['status']); ?></span></td>
            <td class="mbkbs-muted"><?php echo esc_html($types[$e['entity_type']] ?? $e['entity_type']); ?></td>
            <td>
              <a href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entity&id=' . rawurlencode($e['id']))); ?>">
                <strong><?php echo esc_html($d['display_name'] ?: $e['name']); ?></strong>
              </a>
            </td>
            <td class="mbkbs-muted"><?php echo esc_html($d['summary'] ?? '—'); ?></td>
            <td>
              <a class="button button-small button-primary" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entity&id=' . rawurlencode($e['id']))); ?>">
                <?php esc_html_e('Review changes', 'manifest-bkbs'); ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
  <?php endif; ?>
</div>
