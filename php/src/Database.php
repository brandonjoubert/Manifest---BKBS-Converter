<?php
declare(strict_types=1);

namespace Bkbs;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sites (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  base_url TEXT NOT NULL,
  max_pages INTEGER DEFAULT 40,
  crawl_delay_ms INTEGER DEFAULT 300,
  publish_root TEXT,
  auto_publish INTEGER DEFAULT 1,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS entities (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL,
  external_key TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  name TEXT NOT NULL,
  description TEXT,
  properties TEXT DEFAULT '{}',
  relationships TEXT DEFAULT '[]',
  evidence TEXT DEFAULT '[]',
  version INTEGER DEFAULT 1,
  trust_level TEXT DEFAULT 'medium',
  source TEXT DEFAULT 'scan',
  status TEXT DEFAULT 'pending',
  notes TEXT,
  last_updated TEXT NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE(site_id, external_key),
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scan_jobs (
  id TEXT PRIMARY KEY,
  site_id TEXT NOT NULL,
  status TEXT DEFAULT 'queued',
  pages_fetched INTEGER DEFAULT 0,
  entities_found INTEGER DEFAULT 0,
  error TEXT,
  stats_json TEXT,
  created_at TEXT NOT NULL,
  finished_at TEXT,
  FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
SQL);
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $st = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string) $row['value'] : $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $st->execute([$key, $value]);
    }
}
