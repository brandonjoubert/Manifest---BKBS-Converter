<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$diffs = $diffs ?? [];
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Review claim diffs before bulk approve', 'manifest-bkbs'); ?></h1>
  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <p class="mbkbs-muted"><?php esc_html_e('These items already have live approved facts. Confirming publishes every pending attribute below.', 'manifest-bkbs'); ?></p>

  <?php foreach ($entities as $e) : ?>
    <?php $d = $diffs[$e['id']] ?? []; ?>
    <div class="mbkbs-card">
      <h2><?php echo esc_html($d['display_name'] ?: $e['name']); ?>
        <span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($e['status']); ?>"><?php echo esc_html($e['status']); ?></span>
      </h2>
      <p class="mbkbs-muted"><?php echo esc_html($d['summary'] ?? ''); ?></p>
      <?php if (!empty($d['changes'])) : ?>
        <ul>
          <?php foreach ($d['changes'] as $c) : ?>
            <li>
              <strong><?php echo esc_html($c['label']); ?></strong>
              <?php if (($c['kind'] ?? '') === 'changed') : ?>
                · live: <code><?php echo esc_html(wp_html_excerpt((string) ($c['old_display'] ?? ''), 80)); ?></code>
                → proposed: <code><?php echo esc_html(wp_html_excerpt((string) ($c['new_display'] ?? ''), 80)); ?></code>
              <?php else : ?>
                · new: <code><?php echo esc_html(wp_html_excerpt((string) ($c['new_display'] ?? ''), 80)); ?></code>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entity&id=' . rawurlencode($e['id']))); ?>">
        <?php esc_html_e('Review this entity', 'manifest-bkbs'); ?>
      </a>
    </div>
  <?php endforeach; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('mbkbs_bulk_verify'); ?>
    <input type="hidden" name="action" value="mbkbs_bulk_verify" />
    <input type="hidden" name="confirm_diffs" value="1" />
    <?php foreach ($entities as $e) : ?>
      <input type="hidden" name="entity_ids[]" value="<?php echo esc_attr($e['id']); ?>" />
    <?php endforeach; ?>
    <p>
      <button class="button button-primary" name="action_name" value="approve" type="submit"><?php esc_html_e('Approve all listed diffs', 'manifest-bkbs'); ?></button>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Cancel', 'manifest-bkbs'); ?></a>
    </p>
  </form>
</div>
