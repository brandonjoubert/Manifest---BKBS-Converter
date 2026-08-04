#!/usr/bin/env python3
"""Claim Ledger Stage 1 contract gate: all three editions have claims schema + stubs.

Exit 0 on PASS, 1 on FAIL. No WP bootstrap required — WordPress is checked statically.
"""

from __future__ import annotations

import json
import re
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT_PATH = ROOT / "test-fixtures" / "stage1_claims_contract.json"


def fail(msg: str) -> None:
    print(f"FAIL: {msg}", file=sys.stderr)
    raise SystemExit(1)


def ok(msg: str) -> None:
    print(f"OK: {msg}")


def load_contract() -> dict:
    if not CONTRACT_PATH.is_file():
        fail(f"missing contract file {CONTRACT_PATH}")
    return json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))


def check_python(contract: dict) -> None:
    cols_required = set(contract["columns"])
    sys.path.insert(0, str(ROOT))
    from sqlalchemy import create_engine, inspect, text

    from app.models import Base, Claim
    from app.services.resolver import resolve_entity

    with tempfile.TemporaryDirectory() as td:
        db_path = Path(td) / "stage1.sqlite"
        eng = create_engine(f"sqlite:///{db_path}", future=True)
        Base.metadata.create_all(bind=eng)
        with eng.connect() as conn:
            conn.execute(
                text(
                    "CREATE INDEX IF NOT EXISTS idx_claims_entity_attr "
                    "ON claims(entity_id, attribute)"
                )
            )
            conn.execute(
                text("CREATE INDEX IF NOT EXISTS idx_claims_status ON claims(status)")
            )
            conn.execute(
                text(
                    "CREATE INDEX IF NOT EXISTS idx_claims_supersedes "
                    "ON claims(supersedes_id)"
                )
            )
            conn.commit()

        insp = inspect(eng)
        if "claims" not in insp.get_table_names():
            fail("python: claims table missing after create_all")
        cols = {c["name"] for c in insp.get_columns("claims")}
        missing = cols_required - cols
        if missing:
            fail(f"python: claims missing columns {sorted(missing)}")
        if Claim is None:
            fail("python: Claim model missing")
        if resolve_entity("x") is not None:
            fail("python: resolve_entity stub must return None")
        if resolve_entity(None) is not None:
            fail("python: resolve_entity(None) must return None")
    ok("python claims table + Claim model + resolve_entity stub")

def check_php(contract: dict) -> None:
    cols_required = set(contract["columns"])
    db_path_file = ROOT / "php" / "src" / "Database.php"
    resolver_path = ROOT / "php" / "src" / "Resolver.php"
    if not db_path_file.is_file():
        fail("php: Database.php missing")
    if not resolver_path.is_file():
        fail("php: Resolver.php missing")

    db_src = db_path_file.read_text(encoding="utf-8")
    if "CREATE TABLE IF NOT EXISTS claims" not in db_src:
        fail("php: claims CREATE TABLE missing from migrate()")
    for col in cols_required:
        if col == "id":
            continue
        if col not in db_src:
            fail(f"php: claims DDL missing column name {col!r}")

    res_src = resolver_path.read_text(encoding="utf-8")
    if "function resolveEntity" not in res_src:
        fail("php: resolveEntity method missing")
    if "return null" not in res_src:
        fail("php: resolveEntity stub should return null")

    boot = (ROOT / "php" / "src" / "bootstrap.php").read_text(encoding="utf-8")
    if "Bkbs\\\\Resolver" not in boot and "Bkbs\\Resolver" not in boot:
        fail("php: bootstrap must autoload Bkbs\\Resolver")

    # Prefer live migrate when pdo_sqlite is available (CI).
    with tempfile.TemporaryDirectory() as td:
        db_path = Path(td) / "php-stage1.sqlite"
        runner = Path(td) / "run_stage1.php"
        runner.write_text(
            f"""<?php
if (!extension_loaded('pdo_sqlite')) {{
  echo json_encode(["_skip" => "no_pdo_sqlite"]);
  exit(0);
}}
require {json.dumps(str(ROOT / 'php' / 'src' / 'bootstrap.php'))};
use Bkbs\\Database;
use Bkbs\\Resolver;
$path = {json.dumps(str(db_path))};
$db = new Database($path);
$pdo = $db->pdo();
$cols = $pdo->query("PRAGMA table_info(claims)")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($cols, 'name');
echo json_encode($names);
if (Resolver::resolveEntity('x') !== null || Resolver::resolveEntity(null) !== null) {{
  fwrite(STDERR, "resolver not null\\n");
  exit(2);
}}
""",
            encoding="utf-8",
        )
        proc = subprocess.run(
            ["php", str(runner)],
            capture_output=True,
            text=True,
            cwd=str(ROOT),
        )
        if proc.returncode != 0:
            fail(f"php: migrate/resolver failed: {proc.stderr or proc.stdout}")
        try:
            payload = json.loads(proc.stdout.strip() or "null")
        except json.JSONDecodeError:
            fail(f"php: bad column JSON: {proc.stdout!r}")
        if isinstance(payload, dict) and payload.get("_skip") == "no_pdo_sqlite":
            ok("php claims DDL + Resolver (static; pdo_sqlite unavailable locally)")
            return
        names = set(payload)
        missing = cols_required - names
        if missing:
            fail(f"php: claims missing columns {sorted(missing)}")
    ok("php claims table + Resolver::resolveEntity stub")


def check_wordpress(contract: dict) -> None:
    wp = contract["editions"]["wordpress"]
    db_file = ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-database.php"
    res_file = ROOT / "wordpress-plugin/manifest-bkbs-converter/includes/class-mbkbs-resolver.php"
    main_file = ROOT / "wordpress-plugin/manifest-bkbs-converter/manifest-bkbs-converter.php"
    for p in (db_file, res_file, main_file):
        if not p.is_file():
            fail(f"wordpress: missing {p.relative_to(ROOT)}")

    db_src = db_file.read_text(encoding="utf-8")
    if "mbkbs_claims" not in db_src and "claims_table" not in db_src:
        fail("wordpress: claims table helper/SQL not found")
    for col in contract["columns"]:
        # id appears many times; require logical column names in SQL block
        if col == "id":
            continue
        if col not in db_src:
            fail(f"wordpress: claims SQL missing column name {col!r}")
    if "function maybe_upgrade" not in db_src:
        fail("wordpress: maybe_upgrade missing")

    res_src = res_file.read_text(encoding="utf-8")
    if "class MBKBS_Resolver" not in res_src:
        fail("wordpress: MBKBS_Resolver class missing")
    if "function resolve_entity" not in res_src:
        fail("wordpress: resolve_entity method missing")
    if "return null" not in res_src:
        fail("wordpress: stub should return null")

    main_src = main_file.read_text(encoding="utf-8")
    ver = re.search(r"define\(\s*'MBKBS_DB_VERSION'\s*,\s*'(\d+)'\s*\)", main_src)
    if not ver or ver.group(1) != str(wp["db_version"]):
        fail(
            f"wordpress: MBKBS_DB_VERSION must be {wp['db_version']!r}, "
            f"got {ver.group(1) if ver else None!r}"
        )
    if "class-mbkbs-resolver.php" not in main_src:
        fail("wordpress: main plugin must require resolver")
    if "maybe_upgrade" not in main_src:
        fail("wordpress: plugins_loaded must call maybe_upgrade")
    ok("wordpress claims schema (static) + MBKBS_Resolver + DB version")


def main() -> None:
    print("=== Stage 1 contract check ===")
    contract = load_contract()
    check_python(contract)
    check_php(contract)
    check_wordpress(contract)
    print("RESULT: PASS")


if __name__ == "__main__":
    main()
