<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($appName ?? 'BKBS Converter') ?></title>
  <style>
    :root { --bg:#0f1419; --card:#1e2a3a; --border:#2d3a4d; --text:#e7ecf3; --muted:#9aa8bc; --accent:#3b9eff; --ok:#3dd68c; --err:#f31260; --warn:#f5a524; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: system-ui,sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
    a { color: var(--accent); text-decoration:none; }
    a:hover { text-decoration:underline; }
    .top { background:#1a2332; border-bottom:1px solid var(--border); padding:.85rem 1.25rem; display:flex; justify-content:space-between; align-items:center; }
    .brand { font-weight:700; color:var(--text); }
    .brand span { color:var(--accent); }
    .wrap { max-width:1000px; margin:0 auto; padding:1.25rem 1.25rem 3rem; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:1.1rem 1.2rem; margin-bottom:1rem; }
    .btn { display:inline-block; border:1px solid var(--border); background:#1a2332; color:var(--text); padding:.4rem .85rem; border-radius:8px; font-size:.9rem; cursor:pointer; text-decoration:none; }
    .btn:hover { border-color:var(--accent); color:var(--accent); text-decoration:none; }
    .btn-primary { background:var(--accent); border-color:var(--accent); color:#041018; font-weight:600; }
    .btn-success { border-color:var(--ok); color:var(--ok); }
    .btn-danger { border-color:var(--err); color:var(--err); }
    .btn-sm { padding:.2rem .5rem; font-size:.8rem; }
    label { display:block; color:var(--muted); font-size:.85rem; margin:.5rem 0 .25rem; }
    input, select, textarea { width:100%; background:var(--bg); border:1px solid var(--border); color:var(--text); border-radius:8px; padding:.5rem .65rem; margin-bottom:.6rem; }
    table { width:100%; border-collapse:collapse; font-size:.9rem; }
    th, td { text-align:left; padding:.55rem .4rem; border-bottom:1px solid var(--border); vertical-align:top; }
    th { color:var(--muted); font-size:.75rem; text-transform:uppercase; }
    .muted { color:var(--muted); }
    .alert { padding:.7rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:.9rem; }
    .alert-ok { background:rgba(61,214,140,.12); border:1px solid rgba(61,214,140,.35); color:var(--ok); }
    .alert-err { background:rgba(243,18,96,.12); border:1px solid rgba(243,18,96,.35); color:var(--err); }
    .alert-warn { background:rgba(245,165,36,.12); border:1px solid rgba(245,165,36,.35); color:var(--warn); }
    .pill { display:inline-block; padding:.1rem .45rem; border-radius:999px; font-size:.72rem; font-weight:600; text-transform:uppercase; }
    .pill-pending { background:rgba(245,165,36,.15); color:var(--warn); }
    .pill-approved { background:rgba(61,214,140,.15); color:var(--ok); }
    .pill-rejected { background:rgba(243,18,96,.15); color:var(--err); }
    .pill-completed { background:rgba(61,214,140,.15); color:var(--ok); }
    .pill-failed { background:rgba(243,18,96,.15); color:var(--err); }
    .pill-running { background:rgba(59,158,255,.15); color:var(--accent); }
    .row { display:flex; flex-wrap:wrap; gap:.5rem; margin:.75rem 0; }
    .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    @media(max-width:720px){ .grid2 { grid-template-columns:1fr; } }
    .badge { font-size:.75rem; padding:.15rem .5rem; border-radius:999px; border:1px solid var(--border); color:var(--muted); }
    .badge.ok { border-color:var(--ok); color:var(--ok); }
    .badge.miss { border-color:var(--warn); color:var(--warn); }
    h1 { font-size:1.45rem; margin:0 0 .4rem; }
    h2 { font-size:1.05rem; margin:0 0 .75rem; }
  </style>
</head>
<body>
  <header class="top">
    <a class="brand" href="<?= h(url('home')) ?>">BKBS <span>PHP</span></a>
    <nav style="display:flex;gap:1rem;align-items:center;font-size:.9rem">
      <a href="<?= h(url('home')) ?>">Sites</a>
      <a href="<?= h(url('settings')) ?>">Settings</a>
      <?php if (!empty($has_llm)): ?>
        <span class="badge ok">LLM ready</span>
      <?php else: ?>
        <a class="badge miss" href="<?= h(url('settings')) ?>">Configure LLM</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="wrap">
    <?php if (!empty($flash)): ?>
      <div class="alert alert-<?= $flash['type'] === 'err' ? 'err' : 'ok' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
