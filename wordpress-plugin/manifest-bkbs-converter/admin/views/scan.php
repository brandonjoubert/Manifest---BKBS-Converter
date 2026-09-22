<?php
if (!defined('ABSPATH')) {
    exit;
}
$msg = isset($_GET['mbkbs_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_msg'])) : '';
$err = isset($_GET['mbkbs_err']) ? sanitize_text_field(wp_unslash((string) $_GET['mbkbs_err'])) : '';
$status = is_array($job) ? (string) ($job['status'] ?? '') : '';
if ($status === 'completed-with-warnings') {
    $heading = __('Scan completed with warnings', 'manifest-bkbs');
} elseif ($status === 'completed') {
    $heading = __('Scan completed', 'manifest-bkbs');
} elseif ($status !== '') {
    $heading = sprintf(
        /* translators: %s: job status */
        __('Scan %s', 'manifest-bkbs'),
        $status
    );
} else {
    $heading = __('Scan', 'manifest-bkbs');
}
?>
<div class="wrap mbkbs-wrap">
  <p class="mbkbs-muted">
    <a href="<?php echo esc_url(admin_url('admin.php?page=mbkbs')); ?>">&larr; <?php esc_html_e('Dashboard', 'manifest-bkbs'); ?></a>
    <?php if (is_array($site)) : ?>
      · <?php echo esc_html((string) $site['name']); ?>
    <?php endif; ?>
  </p>
  <h1><?php echo esc_html($heading); ?></h1>

  <?php if ($msg) : ?><div class="mbkbs-notice-ok"><?php echo esc_html($msg); ?></div><?php endif; ?>
  <?php if ($err) : ?><div class="mbkbs-notice-err"><?php echo esc_html($err); ?></div><?php endif; ?>

  <?php if (!is_array($job)) : ?>
    <div class="mbkbs-notice-err"><?php esc_html_e('Scan job not found.', 'manifest-bkbs'); ?></div>
  <?php else : ?>
    <p class="mbkbs-muted"><?php esc_html_e('Job', 'manifest-bkbs'); ?> <code><?php echo esc_html((string) $job['id']); ?></code></p>
    <div class="mbkbs-stats">
      <div class="mbkbs-stat"><div class="n"><?php echo (int) ($job['pages_fetched'] ?? 0); ?></div><div class="l"><?php esc_html_e('Pages', 'manifest-bkbs'); ?></div></div>
      <div class="mbkbs-stat"><div class="n"><?php echo (int) ($job['entities_found'] ?? 0); ?></div><div class="l"><?php esc_html_e('Entities touched', 'manifest-bkbs'); ?></div></div>
      <div class="mbkbs-stat"><div class="n"><span class="mbkbs-pill mbkbs-pill-<?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span></div><div class="l"><?php esc_html_e('Status', 'manifest-bkbs'); ?></div></div>
    </div>

    <?php if (!empty($job['error'])) : ?>
      <div class="mbkbs-notice-err"><strong><?php esc_html_e('Error:', 'manifest-bkbs'); ?></strong> <?php echo esc_html((string) $job['error']); ?></div>
    <?php endif; ?>

    <?php if (!empty($findings)) : ?>
    <div class="mbkbs-card" id="findings">
      <h2 style="margin-top:0"><?php esc_html_e('Findings', 'manifest-bkbs'); ?></h2>
      <p class="mbkbs-muted"><?php esc_html_e('Origin audit — these checks do not fail the crawl.', 'manifest-bkbs'); ?></p>
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
    </div>
    <?php endif; ?>

    <?php if (!empty($stats)) : ?>
    <div class="mbkbs-card">
      <h2 style="margin-top:0"><?php esc_html_e('Stats', 'manifest-bkbs'); ?></h2>
      <pre class="mbkbs-muted" style="white-space:pre-wrap;margin:0"><?php echo esc_html((string) wp_json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
    </div>
    <?php endif; ?>

    <div class="mbkbs-actions">
      <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs-entities')); ?>"><?php esc_html_e('Review entities', 'manifest-bkbs'); ?></a>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mbkbs')); ?>"><?php esc_html_e('Dashboard', 'manifest-bkbs'); ?></a>
    </div>
  <?php endif; ?>
</div>
