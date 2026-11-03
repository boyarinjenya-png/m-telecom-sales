#!/usr/bin/env python3
"""Upload a single file over FTPS without deleting anything else."""
from __future__ import annotations

import os
import sys
from ftplib import FTP_TLS, error_perm
from pathlib import Path


def main() -> int:
    host = os.environ["FTP_HOST"]
    port = int(os.environ.get("FTP_PORT") or "21")
    user = os.environ["FTP_USER"]
    password = os.environ["FTP_PASSWORD"]
    remote_root = os.environ.get("FTP_REMOTE_ROOT") or "/"
    local = Path(os.environ["APK_LOCAL"])
    remote = os.environ["APK_REMOTE"].lstrip("/")

    if not local.is_file():
        print(f"Missing local file {local}", file=sys.stderr)
        return 1

    ftp = FTP_TLS()
    ftp.connect(host, port, timeout=120)
    ftp.login(user, password)
    ftp.prot_p()
    ftp.set_pasv(True)
    if remote_root and remote_root != "/":
        ftp.cwd(remote_root)

    parts = remote.split("/")
    cwd = ftp.pwd()
    for d in parts[:-1]:
        try:
            ftp.cwd(d)
        except error_perm:
            ftp.mkd(d)
            ftp.cwd(d)
    ftp.cwd(cwd)

    with local.open("rb") as fh:
        ftp.storbinary(f"STOR {remote}", fh)
    ftp.quit()
    print(f"Uploaded {local} -> {remote} ({local.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
