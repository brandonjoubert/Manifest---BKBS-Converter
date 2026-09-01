<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$diff = $diff ?? ['changes' => [], 'unchanged' => [], 'summary' => '', 'display_name' => $entity['name']];
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Review changes', 'manifest-bkbs'); ?></h1>
  <p class="mbkbs-muted">
    <span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($entity['status']); ?>"><?php echo esc_html($entity['status']); ?></span>
    · <?php echo esc_html($types[$entity['entity_type']] ?? $entity['entity_type']); ?>
    · <?php echo esc_html($diff['summary'] ?? ''); ?>
  </p>
  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>

  <?php if (($entity['status'] ?? '') === 'needs_edit') : ?>
    <div class="mbkbs-notice-ok"><?php esc_html_e('Live facts are still published. Approving replaces them; rejecting keeps the last approved snapshot live.', 'manifest-bkbs'); ?></div>
  <?php elseif (($entity['status'] ?? '') === 'pending') : ?>
    <div class="mbkbs-notice-warn"><?php esc_html_e('This entity is not published yet. Approve only facts you have checked.', 'manifest-bkbs'); ?></div>
  <?php endif; ?>

  <form class="mbkbs-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('mbkbs_review_entity'); ?>
    <input type="hidden" name="action" value="mbkbs_review_entity" />
    <input type="hidden" name="id" value="<?php echo esc_attr($entity['id']); ?>" />

    <h2><?php esc_html_e('Changes', 'manifest-bkbs'); ?></h2>
    <?php if (!empty($diff['changes'])) : ?>
      <?php foreach ($diff['changes'] as $c) : ?>
        <div class="mbkbs-diff">
          <h3><?php echo esc_html($c['label']); ?> <span class="mbkbs-muted"><?php echo esc_html($c['kind']); ?></span></h3>
          <?php if (($c['kind'] ?? '') === 'changed') : ?>
            <p class="mbkbs-muted"><?php esc_html_e('Last approved (live)', 'manifest-bkbs'); ?></p>
            <pre class="mbkbs-diff-old"><?php echo esc_html((string) ($c['old_display'] ?? '')); ?></pre>
            <p class="mbkbs-muted"><?php esc_html_e('Proposed', 'manifest-bkbs'); ?></p>
          <?php else : ?>
            <p class="mbkbs-muted"><?php esc_html_e('New fact (not published yet)', 'manifest-bkbs'); ?></p>
          <?php endif; ?>
          <textarea name="claim:<?php echo esc_attr($c['attribute']); ?>" rows="4"><?php echo esc_textarea((string) ($c['new_display'] ?? '')); ?></textarea>
          <textarea name="extract:<?php echo esc_attr($c['attribute']); ?>" hidden><?php echo esc_textarea((string) ($c['new'] ?? '')); ?></textarea>
        </div>
      <?php endforeach; ?>
    <?php else : ?>
      <p class="mbkbs-muted"><?php esc_html_e('No pending claim diffs.', 'manifest-bkbs'); ?></p>
    <?php endif; ?>

    <div class="mbkbs-field">
      <label for="notes"><?php esc_html_e('Reviewer notes', 'manifest-bkbs'); ?></label>
      <textarea id="notes" name="notes" rows="3"><?php echo esc_textarea((string) $entity['notes']); ?></textarea>
    </div>

    <div class="mbkbs-actions">
      <button class="button button-primary" type="submit" name="intent" value="save_approve"><?php esc_html_e('Approve', 'manifest-bkbs'); ?></button>
      <button class="button" type="submit" name="intent" value="save"><?php esc_html_e('Save without approve', 'manifest-bkbs'); ?></button>
      <button class="button" type="submit" name="intent" value="save_reject"><?php esc_html_e('Reject', 'manifest-bkbs'); ?></button>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Back to inbox', 'manifest-bkbs'); ?></a>
    </div>

    <?php if (!empty($diff['unchanged'])) : ?>
      <details style="margin-top:1rem">
        <summary><?php echo esc_html(sprintf(__('Unchanged / already live (%d)', 'manifest-bkbs'), count($diff['unchanged']))); ?></summary>
        <ul class="mbkbs-muted">
          <?php foreach ($diff['unchanged'] as $u) : ?>
            <li><strong><?php echo esc_html($u['label']); ?>:</strong> <?php echo esc_html(wp_html_excerpt((string) ($u['display'] ?? ''), 200)); ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </form>
</div>
