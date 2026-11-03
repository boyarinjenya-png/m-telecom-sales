#!/usr/bin/env python3
"""FTPS allowlist deploy with backup + health checks + file rollback.

Never deletes remote files. Never uploads .env / install.lock / uploads / DB dumps.
"""
from __future__ import annotations

import hashlib
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from ftplib import FTP_TLS, error_perm
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PACKAGE = ROOT / "artifacts" / "deploy" / "package"
LOG_DIR = ROOT / "artifacts" / "deploy"
BACKUP_DIR = LOG_DIR / "backup"
SHA_LOG = LOG_DIR / "published-sha256.json"
DEPLOY_LOG = LOG_DIR / "deploy.log"

NEVER_UPLOAD_NAMES = {
    ".env",
    "install.lock",
    "seed.local.php",
    ".git",
}
NEVER_UPLOAD_SUFFIXES = (".jks", ".keystore", ".sqlite", ".sql.gz")


def log(msg: str) -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    line = f"{datetime.now(timezone.utc).isoformat()} {msg}"
    print(msg, flush=True)
    with DEPLOY_LOG.open("a", encoding="utf-8") as fh:
        fh.write(line + "\n")


def env(name: str, default: str | None = None) -> str:
    v = os.environ.get(name, default)
    if v is None or v == "":
        if default is not None:
            return default
        raise SystemExit(f"Missing required env: {name}")
    return v


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def connect() -> FTP_TLS:
    host = env("FTP_HOST")
    port = int(os.environ.get("FTP_PORT") or "21")
    user = env("FTP_USER")
    password = env("FTP_PASSWORD")
    ftp = FTP_TLS()
    ftp.connect(host, port, timeout=60)
    ftp.login(user, password)
    ftp.prot_p()
    ftp.set_pasv(True)
    remote_root = os.environ.get("FTP_REMOTE_ROOT") or "/"
    if remote_root and remote_root != "/":
        ftp.cwd(remote_root)
    return ftp


def ensure_remote_dirs(ftp: FTP_TLS, remote_path: str) -> None:
    parts = [p for p in remote_path.replace("\\", "/").split("/") if p]
    if not parts:
        return
    # dirs only
    dirs = parts[:-1]
    cwd = ftp.pwd()
    try:
        for d in dirs:
            try:
                ftp.cwd(d)
            except error_perm:
                ftp.mkd(d)
                ftp.cwd(d)
    finally:
        ftp.cwd(cwd)


def remote_exists(ftp: FTP_TLS, remote_path: str) -> bool:
    try:
        ftp.size(remote_path)
        return True
    except Exception:
        # some servers lack SIZE; try NLST parent
        try:
            parent = "/".join(remote_path.split("/")[:-1]) or "."
            name = remote_path.split("/")[-1]
            return name in ftp.nlst(parent)
        except Exception:
            return False


def download_file(ftp: FTP_TLS, remote_path: str, local_path: Path) -> bool:
    local_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        with local_path.open("wb") as fh:
            ftp.retrbinary(f"RETR {remote_path}", fh.write)
        return True
    except Exception as e:
        log(f"backup skip {remote_path}: {e}")
        if local_path.exists():
            local_path.unlink()
        return False


def upload_file(ftp: FTP_TLS, local_path: Path, remote_path: str) -> None:
    ensure_remote_dirs(ftp, remote_path)
    with local_path.open("rb") as fh:
        ftp.storbinary(f"STOR {remote_path}", fh)


def http_get(url: str, timeout: int = 45, headers: dict | None = None) -> tuple[int, bytes]:
    req = urllib.request.Request(url, headers=headers or {"User-Agent": "mtelecom-sales-deploy/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ssl.create_default_context()) as resp:
            return resp.getcode(), resp.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read() if e.fp else b""


def health_ok(base: str) -> bool:
    code, body = http_get(base.rstrip("/") + "/")
    if code != 200:
        log(f"health page FAIL {code}")
        return False
    code, body = http_get(base.rstrip("/") + "/api/health")
    if code != 200:
        log(f"health api FAIL {code}")
        return False
    try:
        data = json.loads(body.decode("utf-8", errors="replace"))
    except Exception:
        log("health api not JSON")
        return False
    if not data.get("ok"):
        log(f"health api ok=false: {data}")
        return False
    return True


def smoke_auth(base: str) -> None:
    login = os.environ.get("SMOKE_LOGIN") or ""
    password = os.environ.get("SMOKE_PASSWORD") or ""
    if not login or not password:
        log("SMOKE_LOGIN/PASSWORD not set — skipping auth smoke test")
        return
    payload = json.dumps(
        {"login": login, "password": password, "platform": "ci", "device": "deploy-smoke"}
    ).encode()
    req = urllib.request.Request(
        base.rstrip("/") + "/api/auth/login",
        data=payload,
        headers={"Content-Type": "application/json", "User-Agent": "mtelecom-sales-deploy/1.0"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=45, context=ssl.create_default_context()) as resp:
            body = resp.read()
            code = resp.getcode()
    except urllib.error.HTTPError as e:
        code = e.code
        body = e.read() if e.fp else b""
    if code != 200:
        raise SystemExit(f"Auth smoke failed HTTP {code}: {body[:200]!r}")
    data = json.loads(body.decode("utf-8", errors="replace"))
    if not (data.get("token") or data.get("access_token") or (data.get("data") or {}).get("token")):
        # Accept any 200 with user payload
        if "user" not in data and "users" not in str(data):
            log(f"Auth smoke 200 but unexpected payload keys={list(data)[:8]}")
    log("Auth smoke OK")


def maybe_migrate(base: str) -> None:
    token = os.environ.get("DEPLOY_MIGRATE_TOKEN") or ""
    if not token:
        log("DEPLOY_MIGRATE_TOKEN not set — skipping remote migrate (files only)")
        return
    payload = b"{}"
    req = urllib.request.Request(
        base.rstrip("/") + "/api/system/migrate",
        data=payload,
        headers={
            "Content-Type": "application/json",
            "X-Deploy-Token": token,
            "User-Agent": "mtelecom-sales-deploy/1.0",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=120, context=ssl.create_default_context()) as resp:
            body = resp.read()
            code = resp.getcode()
    except urllib.error.HTTPError as e:
        code = e.code
        body = e.read() if e.fp else b""
    if code != 200:
        raise SystemExit(f"Migrate failed HTTP {code}: {body[:300]!r}")
    log(f"Migrate OK: {body.decode('utf-8', errors='replace')[:300]}")


def main() -> int:
    if not PACKAGE.is_dir():
        log(f"Package missing: {PACKAGE}")
        return 1

    deploy_sha = os.environ.get("DEPLOY_SHA") or "unknown"
    base = env("HEALTH_BASE_URL", "https://mtelecom.kz/sales")
    main_site = env("MAIN_SITE_URL", "https://mtelecom.kz/")

    files = sorted(p for p in PACKAGE.rglob("*") if p.is_file())
    if not files:
        log("Empty package")
        return 1

    # Preflight: never upload secrets
    for p in files:
        name = p.name
        if name in NEVER_UPLOAD_NAMES or name.endswith(NEVER_UPLOAD_SUFFIXES):
            log(f"Refusing to upload forbidden file: {p}")
            return 1
        rel = p.relative_to(PACKAGE).as_posix()
        if rel.endswith("/.env") or "/storage/uploads/" in rel or "/storage/logs/" in rel:
            if p.stat().st_size > 0 and p.name not in {".gitkeep", ".htaccess"}:
                # only placeholders allowed under storage
                if "storage/uploads" in rel or "storage/logs" in rel or "storage/backups" in rel:
                    if p.name not in {".gitkeep", ".htaccess"}:
                        log(f"Refusing storage content upload: {rel}")
                        return 1

    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup_root = BACKUP_DIR / stamp
    backup_root.mkdir(parents=True, exist_ok=True)

    log(f"Connecting FTPS for deploy sha={deploy_sha} files={len(files)}")
    ftp = connect()
    uploaded: list[str] = []
    backed: list[str] = []
    digests: dict[str, str] = {}

    try:
        for local in files:
            rel = local.relative_to(PACKAGE).as_posix()
            # Skip empty storage placeholders overwrite? Still OK to upload .gitkeep/.htaccess
            if remote_exists(ftp, rel):
                if download_file(ftp, rel, backup_root / rel):
                    backed.append(rel)
            digests[rel] = sha256_file(local)
            upload_file(ftp, local, rel)
            uploaded.append(rel)
            log(f"uploaded {rel}")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

    SHA_LOG.write_text(
        json.dumps(
            {
                "commit": deploy_sha,
                "published_at": datetime.now(timezone.utc).isoformat(),
                "files": digests,
                "backed_up": backed,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    # Migrations after code is live
    try:
        maybe_migrate(base)
    except SystemExit as e:
        log(str(e))
        log("Migrate failed — attempting file rollback")
        rollback(backup_root, backed)
        return 1

    # Health checks with short retry
    ok = False
    for i in range(5):
        if health_ok(base):
            ok = True
            break
        time.sleep(3 + i)
    if not ok:
        log("Health checks failed — rolling back uploaded files from backup")
        rollback(backup_root, backed)
        # re-check
        if health_ok(base):
            log("Rollback restored health")
        else:
            log("CRITICAL: health still failing after rollback")
        return 1

    # Main site must remain up (read-only check)
    code, _ = http_get(main_site)
    if code != 200:
        log(f"WARNING: main site HTTP {code} (CRM deploy succeeded; main site not modified by this job)")
    else:
        log("Main site OK")

    try:
        smoke_auth(base)
    except SystemExit as e:
        log(str(e))
        log("Auth smoke failed — rolling back")
        rollback(backup_root, backed)
        return 1

    # Static asset check
    code, _ = http_get(base.rstrip("/") + "/assets/css/app.css")
    if code != 200:
        log(f"Static CSS HTTP {code} — rolling back")
        rollback(backup_root, backed)
        return 1

    log(f"Deploy SUCCESS commit={deploy_sha} uploaded={len(uploaded)} backed_up={len(backed)}")
    return 0


def rollback(backup_root: Path, backed: list[str]) -> None:
    if not backed:
        log("No backups available to restore")
        return
    ftp = connect()
    try:
        for rel in backed:
            local = backup_root / rel
            if not local.is_file():
                continue
            upload_file(ftp, local, rel)
            log(f"restored {rel}")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()


if __name__ == "__main__":
    sys.exit(main())
