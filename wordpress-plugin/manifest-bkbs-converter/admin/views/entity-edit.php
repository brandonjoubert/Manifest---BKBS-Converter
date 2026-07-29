<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('Review & edit entity', 'manifest-bkbs'); ?></h1>
  <p class="mbkbs-muted">
    <span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($entity['status']); ?>"><?php echo esc_html($entity['status']); ?></span>
    · <?php echo esc_html($types[$entity['entity_type']] ?? $entity['entity_type']); ?>
    · <?php echo esc_html($entity['source']); ?>
    · v<?php echo esc_html((string) $entity['version']); ?>
  </p>
  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>

  <?php if (in_array($entity['status'], ['pending', 'needs_edit'], true)) : ?>
    <div class="mbkbs-notice-warn">
      <?php esc_html_e('This entry is not published yet. Edit the fields below, then Save or Save & approve when the fact is correct.', 'manifest-bkbs'); ?>
    </div>
  <?php endif; ?>

  <form class="mbkbs-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('mbkbs_save_entity'); ?>
    <input type="hidden" name="action" value="mbkbs_save_entity" />
    <input type="hidden" name="id" value="<?php echo esc_attr($entity['id']); ?>" />

    <div class="mbkbs-field">
      <label for="name"><?php esc_html_e('Name', 'manifest-bkbs'); ?></label>
      <input id="name" name="name" type="text" required value="<?php echo esc_attr($entity['name']); ?>" />
    </div>
    <div class="mbkbs-field">
      <label for="entity_type"><?php esc_html_e('Entity type', 'manifest-bkbs'); ?></label>
      <select id="entity_type" name="entity_type">
        <?php foreach ($types as $k => $lab) : ?>
          <option value="<?php echo esc_attr($k); ?>" <?php selected($entity['entity_type'], $k); ?>><?php echo esc_html($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mbkbs-field">
      <label for="description"><?php esc_html_e('Description', 'manifest-bkbs'); ?></label>
      <textarea id="description" name="description" rows="6"><?php echo esc_textarea((string) $entity['description']); ?></textarea>
    </div>
    <div class="mbkbs-field">
      <label for="status"><?php esc_html_e('Status', 'manifest-bkbs'); ?></label>
      <select id="status" name="status">
        <?php foreach (['pending', 'approved', 'rejected', 'needs_edit'] as $s) : ?>
          <option value="<?php echo esc_attr($s); ?>" <?php selected($entity['status'], $s); ?>><?php echo esc_html($s); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mbkbs-field">
      <label for="trust_level"><?php esc_html_e('Trust level', 'manifest-bkbs'); ?></label>
      <select id="trust_level" name="trust_level">
        <?php foreach (['low', 'medium', 'high'] as $t) : ?>
          <option value="<?php echo esc_attr($t); ?>" <?php selected($entity['trust_level'], $t); ?>><?php echo esc_html($t); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mbkbs-field">
      <label for="notes"><?php esc_html_e('Reviewer notes', 'manifest-bkbs'); ?></label>
      <textarea id="notes" name="notes" rows="3"><?php echo esc_textarea((string) $entity['notes']); ?></textarea>
    </div>

    <div class="mbkbs-actions">
      <button class="button button-primary" type="submit" name="intent" value="save"><?php esc_html_e('Save changes', 'manifest-bkbs'); ?></button>
      <button class="button button-primary" type="submit" name="intent" value="save_approve" style="background:#007017;border-color:#007017"><?php esc_html_e('Save & approve', 'manifest-bkbs'); ?></button>
      <button class="button" type="submit" name="intent" value="save_reject"><?php esc_html_e('Save & reject', 'manifest-bkbs'); ?></button>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Back to list', 'manifest-bkbs'); ?></a>
    </div>
  </form>
</div>
