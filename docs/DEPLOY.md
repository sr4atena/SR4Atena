# Deploy

Target: Ubuntu 24.04 on an Oracle Cloud *Always Free* `VM.Standard.E2.1.Micro`
(2 vCPU, 1 GB RAM) that already hosts other services. The design goal is to
add the dashboard **without changing the host's exposure**: no new open port,
no service on 80/443, no reboot.

## Topology

```
browser ──TLS──► Cloudflare edge ──tunnel──► cloudflared (host) ──► nginx 127.0.0.1:8090
                                                                        │ unix socket
                                                                   php-fpm pool "manor-ledger" (user manor)
                                                                        │ reads
                                                                   /var/lib/manor-ledger/dashboard.json

systemd timer 07:00 Europe/Rome ─► bin/refresh (user manor-fetch, key in /etc/manor-ledger) ─► bin/build
```

| Path on the host | Owner / mode | Content |
|---|---|---|
| `/opt/manor-ledger` | root, 755 / 644 | the repository (no `data/`, no tests) |
| `/var/lib/manor-ledger` | manor-fetch:manor, 2750 | cache, snapshots, history, dashboard, users, sessions, throttle, audit log |
| `/etc/manor-ledger/api-key` | root:manor-fetch, 640 | Roblox Open Cloud key (read scope) |
| `/etc/nginx/sites-available/manor-ledger.conf` | root | loopback-only site |
| `/etc/php/8.3/fpm/pool.d/manor-ledger.conf` | root | dedicated pool |
| `/etc/systemd/system/manor-ledger-refresh.{service,timer}` | root | daily job |
| `/etc/cloudflared/config.yml` | root | one added `hostname:` rule |

## First deployment

```bash
# from the repo root on the dev machine (needs ssh access as ubuntu)
deploy/install.sh --api-key ~/secrets/roblox-analytics.key --with-data
ssh ubuntu@<vps> sudo -u manor MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/user add <owner> --role=owner
ssh ubuntu@<vps> sudo systemctl start manor-ledger-refresh.service   # first build now, don't wait for 07:00
```

`--with-data` uploads `data/history.json` and `data/snapshots/` so the
dashboard starts with the history already collected elsewhere instead of an
empty month. `install.sh` publishes the DNS record through `cloudflared
tunnel route dns`; DNS propagation at Cloudflare is immediate.

## Updates

```bash
make check && deploy/install.sh
```

The script re-syncs the code, re-installs configs only if they changed, and
reloads nginx / php-fpm (no downtime). It never touches the data directory.

## Operations

```bash
systemctl list-timers manor-ledger-refresh.timer         # next run
journalctl -u manor-ledger-refresh.service -n 50         # last job log
sudo -u manor MANOR_DATA_DIR=/var/lib/manor-ledger php /opt/manor-ledger/bin/user list
tail -f /var/lib/manor-ledger/auth.log                   # logins, lockouts
tail -f /var/log/nginx/manor-ledger.access.log
```

The refresh unit is idempotent: `--if-older-than=8` makes the noon retry a
no-op when the morning run succeeded, and a manual `systemctl start` is
always safe.

## Shared-host guard rails

This machine is not the dashboard's alone. Other services were there first,
they own ports 80 and 443, and which one holds a port at any given moment is
not something a deploy may assume. The installer therefore:

- never installs anything on 80/443 and removes nginx's `default` site;
- edits `/etc/cloudflared/config.yml` only to *add* one hostname (with a
  timestamped backup and `cloudflared tunnel ingress validate` before restart);
- touches no firewall rule and no unit it did not install itself;
- applies the sshd hardening drop-in only after `sshd -t` accepts it, and
  reloads (not restarts) sshd.

## Rollback

`install.sh` keeps the previous cloudflared config as `config.yml.bak-<ts>`.
To remove the dashboard entirely:

```bash
sudo systemctl disable --now manor-ledger-refresh.timer
sudo rm /etc/nginx/sites-enabled/manor-ledger.conf /etc/php/8.3/fpm/pool.d/manor-ledger.conf
sudo systemctl reload nginx php8.3-fpm
# delete the manor.handgivers.it rule from /etc/cloudflared/config.yml, then: sudo systemctl restart cloudflared
```
