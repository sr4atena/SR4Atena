# Security

Manor Ledger is a private, read-only dashboard exposed on the public internet.
This page records what it protects, how, and what it deliberately does not do.

## Threat model

Assets: the game's business figures (revenue, audience), the Roblox Open
Cloud key (read scope only, but it identifies the owner and shares a rate
limit with every other key of the same account), and the host itself, which
also runs unrelated services.

Adversaries considered: opportunistic internet scanners and credential
stuffing; a malicious page in the same browser as a logged-in user (CSRF,
clickjacking); an attacker who obtains the repository (it is public) or a
copy of the server code; a compromised web process.

Out of scope: a compromised Cloudflare account or edge, a compromised
developer machine, and denial of service beyond basic request limits.

## Controls

**Network.** nginx and php-fpm listen on `127.0.0.1` only. The public entry
point is a Cloudflare Tunnel: no inbound port is opened on the cloud
firewall, TLS terminates at the edge, and the origin only receives requests
for the configured hostname (any other `Host` gets a bare TCP close, 444).
The client IP is taken from `CF-Connecting-IP` only when the TCP peer is
loopback, so it cannot be spoofed by a direct connection.

**Authentication.** Passwords are hashed with argon2id (64 MiB, t=4). The
verifier always performs a real hash comparison, against a fixed dummy hash
when the username is unknown, so response time does not reveal whether a
user exists, and the error message is the same for both cases. Failures are
throttled per IP and per username (5 attempts → 15 min, doubling on each
consecutive lockout, capped at 24 h) by the application, and by nginx
(`limit_req` on `/login`) as a second layer. Optional TOTP (RFC 6238) can be
enabled per user from the CLI. Users are only ever created or changed from
the command line; passwords are never accepted as CLI arguments.

**Sessions.** PHP native sessions with `use_strict_mode`, a `__Host-`
prefixed cookie (`HttpOnly; Secure; SameSite=Strict`), a new id on login,
30-minute idle and 12-hour absolute timeouts enforced server-side, and a
binding to the user-agent hash. Logout destroys the server-side session and
expires the cookie.

**Requests.** Every `POST` requires a per-session CSRF token compared with
`hash_equals`, plus an `Origin`/`Referer` host check. The router is an exact
match table; unknown paths are 404 and wrong methods 405. Bodies are capped
at 16 KB, uploads are disabled.

**Responses.** Every response carries: `Content-Security-Policy: default-src
'none'; script-src 'self'; style-src 'self'; img-src 'self' data:;
connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action
'self'`, `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: no-referrer`, `Permissions-Policy`, and
`Cross-Origin-Opener-Policy: same-origin`. HTML and API responses are
`Cache-Control: no-store`. There is no inline JavaScript or CSS anywhere.

**Least privilege on the host.** The web pool runs as a dedicated system user
with `open_basedir`, `disable_functions` for process execution, `expose_php`
off and `allow_url_fopen` off. The code tree is root-owned and read-only for
that user. The Roblox key is readable only by root and the refresh job's
user, and the refresh job is a systemd unit sandboxed with `ProtectSystem=
strict`, `NoNewPrivileges`, `PrivateTmp`, restricted address families and an
empty capability set. SSH on the host is key-only with root login disabled.

**Logging.** Authentication events (success, failure, lockout, logout) are
appended to an audit log with timestamp, username and IP. Passwords, tokens
and session ids are never logged.

**Supply chain.** The runtime has no third-party PHP code. The only vendored
asset is Apache ECharts, pinned to an exact version with its SHA-256 recorded
next to the file. PHPUnit is a dev-only dependency.

## Known limitations

- Single-factor by default; TOTP is opt-in per user.
- No account self-service (by design: the user base is a handful of people).
- The file-based throttle is per host; behind several origins it would need a
  shared store.
- Cloudflare sees the plaintext of every request (it terminates TLS). That is
  an accepted trade-off for not exposing a port on a shared host.

## Reporting

Open a private security advisory on the GitHub repository or contact the
maintainer through the profile page. Please do not open public issues for
vulnerabilities.
