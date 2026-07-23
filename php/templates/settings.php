<p class="muted"><a href="<?= h(url('home')) ?>">← Sites</a></p>
<h1>LLM / API settings</h1>
<p class="muted">Any OpenAI-compatible chat API (OpenAI, xAI, OpenRouter, Groq, Mistral, etc.).</p>

<?php if (!empty($has_llm)): ?>
  <div class="alert alert-ok">LLM configured<?= $api_key_set ? ' (API key set)' : '' ?>.</div>
<?php else: ?>
  <div class="alert alert-warn">LLM not configured — extraction will be heuristic only.</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="<?= h(url('settings/llm')) ?>">
    <label>Provider label</label>
    <input name="provider" value="<?= h($provider) ?>" placeholder="openai / xai / custom" />
    <label>API base URL</label>
    <input name="base_url" value="<?= h($base_url) ?>" required placeholder="https://api.openai.com/v1" />
    <label>Model</label>
    <input name="model" value="<?= h($model) ?>" required placeholder="gpt-4o-mini" />
    <label>API key <?= $api_key_set ? '(leave blank to keep current)' : '' ?></label>
    <input name="api_key" type="password" autocomplete="off" />
    <label><input type="checkbox" name="enabled" value="1" <?= !empty($enabled) ? 'checked' : '' ?> /> Enable LLM</label>
    <label><input type="checkbox" name="clear_key" value="1" /> Clear stored key</label>
    <div class="row">
      <button class="btn btn-primary" type="submit">Save</button>
    </div>
  </form>
  <form method="post" action="<?= h(url('settings/llm/test')) ?>" style="margin-top:.5rem">
    <button class="btn" type="submit">Test connection</button>
  </form>
</div>
