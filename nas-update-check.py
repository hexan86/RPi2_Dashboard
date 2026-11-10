#!/usr/bin/env python3
"""Esegue 'apt update' e 'apt list --upgradable' e scrive /var/www/html/updates.json.
Va eseguito come root (cron). La dashboard legge solo il file prodotto."""
import json
import os
import re
import subprocess
import time

OUT = "/var/www/html/updates.json"

err = ""
try:
    r = subprocess.run(["apt-get", "update", "-qq"], capture_output=True, text=True, timeout=900)
    if r.returncode != 0:
        lines = r.stderr.strip().splitlines()
        err = lines[-1] if lines else "apt update non riuscito"
except Exception as e:
    err = str(e)

env = dict(os.environ, LANG="C", LC_ALL="C")
packages = []
try:
    r = subprocess.run(["apt", "list", "--upgradable"], capture_output=True, text=True, env=env, timeout=120)
    for line in r.stdout.splitlines():
        m = re.match(r"^([^/\s]+)/(\S+)\s+(\S+)\s+(\S+)(?:\s+\[upgradable from: ([^\]]+)\])?", line)
        if m:
            packages.append({
                "name": m.group(1),
                "suite": m.group(2),
                "new": m.group(3),
                "old": m.group(5) or "",
                "security": "security" in m.group(2),
            })
except Exception as e:
    err = err or str(e)

data = {
    "checked": int(time.time()),
    "count": len(packages),
    "security": sum(1 for p in packages if p["security"]),
    "reboot": os.path.exists("/var/run/reboot-required"),
    "error": err,
    "packages": packages,
}

tmp = OUT + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f)
os.chmod(tmp, 0o644)
os.replace(tmp, OUT)
