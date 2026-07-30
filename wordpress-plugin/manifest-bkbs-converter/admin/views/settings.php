<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
?>
<div class="wrap mbkbs-wrap">
  <h1><?php esc_html_e('LLM / API settings', 'manifest-bkbs'); ?></h1>
  <p class="mbkbs-muted"><?php esc_html_e('Any OpenAI-compatible chat API (OpenAI, xAI, OpenRouter, Groq, local gateways, etc.).', 'manifest-bkbs'); ?></p>
  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <?php if ($llm_ok) : ?>
    <div class="mbkbs-notice-ok"><?php esc_html_e('LLM is configured.', 'manifest-bkbs'); ?><?php echo $key_set ? ' ' . esc_html__('(API key stored)', 'manifest-bkbs') : ''; ?></div>
  <?php else : ?>
    <div class="mbkbs-notice-warn"><?php esc_html_e('LLM not configured — scans use heuristic extraction only.', 'manifest-bkbs'); ?></div>
  <?php endif; ?>

  <form class="mbkbs-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('mbkbs_save_settings'); ?>
    <input type="hidden" name="action" value="mbkbs_save_settings" />
    <div class="mbkbs-field">
      <label for="provider"><?php esc_html_e('Provider label', 'manifest-bkbs'); ?></label>
      <input id="provider" name="provider" type="text" value="<?php echo esc_attr($provider); ?>" />
    </div>
    <div class="mbkbs-field">
      <label for="base_url"><?php esc_html_e('API base URL', 'manifest-bkbs'); ?></label>
      <input id="base_url" name="base_url" type="url" required value="<?php echo esc_attr($base_url); ?>" placeholder="https://api.openai.com/v1" />
    </div>
    <div class="mbkbs-field">
      <label for="model"><?php esc_html_e('Model', 'manifest-bkbs'); ?></label>
      <input id="model" name="model" type="text" required value="<?php echo esc_attr($model); ?>" />
    </div>
    <div class="mbkbs-field">
      <label for="api_key"><?php esc_html_e('API key', 'manifest-bkbs'); ?><?php echo $key_set ? ' ' . esc_html__('(leave blank to keep current)', 'manifest-bkbs') : ''; ?></label>
      <input id="api_key" name="api_key" type="password" autocomplete="off" value="" />
    </div>
    <p><label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?> /> <?php esc_html_e('Enable LLM extraction', 'manifest-bkbs'); ?></label></p>
    <p><label><input type="checkbox" name="clear_key" value="1" /> <?php esc_html_e('Clear stored API key', 'manifest-bkbs'); ?></label></p>
    <button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'manifest-bkbs'); ?></button>
  </form>
</div>
