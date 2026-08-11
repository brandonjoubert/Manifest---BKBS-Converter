<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$err = isset($_GET['mbkbs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_err'])) : '';
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Manifest BKBS — Tools', 'manifest-bkbs'); ?></h1>
  <p class="mbkbs-muted">
    <?php esc_html_e('Claim Ledger Stage 2: one-shot backfill of approved claims from entity rows. Production publish still reads entities.', 'manifest-bkbs'); ?>
  </p>

  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <?php if ($err) : ?><div class="mbkbs-notice-err"><?php echo esc_html($err); ?></div><?php endif; ?>

  <div class="mbkbs-card" style="max-width:640px">
    <h2><?php esc_html_e('Backfill claims', 'manifest-bkbs'); ?></h2>
    <p>
      <?php
      printf(
          /* translators: 1: approved entity count, 2: claim row count */
          esc_html__('Approved entities: %1$d · Current claim rows: %2$d', 'manifest-bkbs'),
          (int) $approvedEntities,
          (int) $claimCount
      );
      ?>
    </p>
    <p class="mbkbs-muted">
      <?php esc_html_e('Idempotent: re-run skips attributes that already have an identical approved claim. Optional update supersedes changed values.', 'manifest-bkbs'); ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('mbkbs_backfill_claims'); ?>
      <input type="hidden" name="action" value="mbkbs_backfill_claims" />
      <p>
        <label>
          <input type="checkbox" name="include_pending" value="1" />
          <?php esc_html_e('Include pending / needs_edit entities', 'manifest-bkbs'); ?>
        </label>
      </p>
      <p>
        <label>
          <input type="checkbox" name="update" value="1" />
          <?php esc_html_e('Update (supersede) when claim value differs', 'manifest-bkbs'); ?>
        </label>
      </p>
      <button class="button button-primary" type="submit"><?php esc_html_e('Run backfill', 'manifest-bkbs'); ?></button>
    </form>
  </div>
</div>
