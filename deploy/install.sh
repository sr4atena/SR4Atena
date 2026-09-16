#!/usr/bin/env bash
# Install or update Manor Ledger on the VPS. Run from the repo root on the
# development machine. Idempotent: safe to re-run after every change.
#
#   deploy/install.sh                 # code + config + services
#   deploy/install.sh --with-data     # also push data/history.json + snapshots (first deploy)
#   deploy/install.sh --api-key FILE  # also install the Roblox key (first deploy)
#
# The host runs other services that were there first. This script touches none
# of them: no firewall rule, no unit it did not install itself, nothing on ports
# 80/443. The only shared component it edits is /etc/cloudflared/config.yml
# (adds one hostname), and only when the rule is missing.
set -euo pipefail
cd "$(dirname "$0")/.."

VPS="${MANOR_VPS:-ubuntu@<server>}"
KEY="${MANOR_SSH_KEY:-$HOME/.ssh/id_ed25519}"
HOST="${MANOR_HOST:-manor.handgivers.it}"
APP_DIR=/opt/manor-ledger
DATA_DIR=/var/lib/manor-ledger

WITH_DATA=0; API_KEY_FILE=""
while [ $# -gt 0 ]; do
  case "$1" in
    --with-data) WITH_DATA=1 ;;
    --api-key) API_KEY_FILE="$2"; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
  shift
done

SSH=(ssh -i "$KEY" -o BatchMode=yes "$VPS")
RSYNC_SSH="ssh -i $KEY -o BatchMode=yes"

echo "→ syncing code to $VPS:/tmp/manor-ledger"
rsync -az --delete -e "$RSYNC_SSH" \
  --exclude '.git' --exclude 'data' --exclude 'vendor' --exclude '.phpunit.cache' \
  --exclude 'tests' --exclude 'composer.lock' \
  ./ "$VPS:/tmp/manor-ledger/"

if [ -n "$API_KEY_FILE" ]; then
  echo "→ uploading Roblox API key"
  rsync -az --chmod=600 -e "$RSYNC_SSH" "$API_KEY_FILE" "$VPS:/tmp/manor-ledger.api-key"
fi
if [ "$WITH_DATA" = 1 ]; then
  echo "→ uploading history and snapshots"
  rsync -az -e "$RSYNC_SSH" data/history.json data/snapshots "$VPS:/tmp/manor-ledger-data/"
fi

echo "→ installing on the VPS"
"${SSH[@]}" "sudo HOST='$HOST' APP_DIR='$APP_DIR' DATA_DIR='$DATA_DIR' bash -s" <<'REMOTE'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

# 1. Packages (nginx-light has everything we need and half the footprint).
need=(nginx-light php8.3-fpm php8.3-cli php8.3-curl php8.3-mbstring)
missing=()
for p in "${need[@]}"; do dpkg -s "$p" >/dev/null 2>&1 || missing+=("$p"); done
if [ ${#missing[@]} -gt 0 ]; then
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends "${missing[@]}"
fi
# nginx must never listen on 80/443 here: those ports belong to another service.
rm -f /etc/nginx/sites-enabled/default

# 2. Unprivileged service user + directories.
id manor >/dev/null 2>&1 || useradd --system --home-dir "$DATA_DIR" --shell /usr/sbin/nologin manor
install -d -m 750 -o manor -g manor "$DATA_DIR" "$DATA_DIR"/{cache,snapshots,sessions,throttle}
install -d -m 750 -o root -g manor /etc/manor-ledger
install -d -m 755 -o root -g root /var/log/php
touch /var/log/php/manor-ledger.log && chown manor:manor /var/log/php/manor-ledger.log && chmod 640 /var/log/php/manor-ledger.log

# 3. Code: root-owned, read-only for the service user.
install -d -m 755 "$APP_DIR"
rsync -a --delete --chown=root:root /tmp/manor-ledger/ "$APP_DIR/"
find "$APP_DIR" -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
chmod 755 "$APP_DIR"/bin/*
rm -rf /tmp/manor-ledger

# 4. Secrets and data (only when uploaded).
if [ -f /tmp/manor-ledger.api-key ]; then
  install -m 640 -o root -g manor /tmp/manor-ledger.api-key /etc/manor-ledger/api-key
  shred -u /tmp/manor-ledger.api-key
fi
if [ -d /tmp/manor-ledger-data ]; then
  [ -f /tmp/manor-ledger-data/history.json ] && install -m 600 -o manor -g manor /tmp/manor-ledger-data/history.json "$DATA_DIR/history.json"
  if [ -d /tmp/manor-ledger-data/snapshots ]; then
    rsync -a --chown=manor:manor /tmp/manor-ledger-data/snapshots/ "$DATA_DIR/snapshots/"
    chmod 600 "$DATA_DIR"/snapshots/* 2>/dev/null || true
  fi
  rm -rf /tmp/manor-ledger-data
fi

# 5. php-fpm pool, nginx site, systemd timer.
install -m 644 "$APP_DIR/deploy/php-fpm/manor-ledger.conf" /etc/php/8.3/fpm/pool.d/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/nginx/manor-ledger.conf" /etc/nginx/sites-available/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/nginx/manor-ledger-fastcgi.conf" /etc/nginx/snippets/manor-ledger-fastcgi.conf
ln -sfn /etc/nginx/sites-available/manor-ledger.conf /etc/nginx/sites-enabled/manor-ledger.conf
install -m 644 "$APP_DIR/deploy/systemd/manor-ledger-refresh.service" /etc/systemd/system/
install -m 644 "$APP_DIR/deploy/systemd/manor-ledger-refresh.timer" /etc/systemd/system/
systemctl daemon-reload
php-fpm8.3 -t >/dev/null
nginx -t >/dev/null
systemctl enable --now php8.3-fpm nginx >/dev/null
systemctl reload php8.3-fpm
systemctl reload nginx
systemctl enable --now manor-ledger-refresh.timer >/dev/null

# 6. Cloudflare Tunnel: add the hostname once, never rewrite the rest.
CF=/etc/cloudflared/config.yml
if ! grep -q "hostname: $HOST" "$CF"; then
  cp -p "$CF" "$CF.bak-$(date +%Y%m%d%H%M%S)"
  python3 - "$CF" "$HOST" <<'PY'
import sys
path, host = sys.argv[1], sys.argv[2]
lines = open(path).read().splitlines()
idx = max(i for i, l in enumerate(lines) if l.strip() == '- service: http_status:404')
block = [
    f"  # Manor Ledger dashboard (nginx on loopback, app-level login).",
    f"  - hostname: {host}",
    f"    service: http://127.0.0.1:8090",
    f"    originRequest:",
    f"      httpHostHeader: {host}",
]
lines[idx:idx] = block
open(path, 'w').write("\n".join(lines) + "\n")
PY
  cloudflared --config "$CF" tunnel ingress validate
  systemctl restart cloudflared
  echo "cloudflared: ingress for $HOST added and service restarted"
fi

# 7. sshd hardening drop-in, validated before reload so a typo cannot lock us out.
if ! cmp -s "$APP_DIR/deploy/sshd-hardening.conf" /etc/ssh/sshd_config.d/70-manor-hardening.conf 2>/dev/null; then
  install -m 644 "$APP_DIR/deploy/sshd-hardening.conf" /etc/ssh/sshd_config.d/70-manor-hardening.conf
  if sshd -t; then systemctl reload ssh; echo "sshd: hardening applied"; else rm -f /etc/ssh/sshd_config.d/70-manor-hardening.conf; echo "sshd: config rejected, drop-in removed" >&2; fi
fi

echo "ok: $(php -v | head -1)"
systemctl is-active nginx php8.3-fpm cloudflared manor-ledger-refresh.timer | paste -sd' '
REMOTE

echo "→ publishing DNS route (no-op if it already exists)"
"${SSH[@]}" "cloudflared tunnel route dns \$(sudo awk '/^tunnel:/{print \$2}' /etc/cloudflared/config.yml) $HOST" 2>&1 | tail -1 || true
echo "done: https://$HOST"
