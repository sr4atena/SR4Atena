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
`Referrer-Policy: same-origin`, `Permissions-Policy`, and
`Cross-Origin-Opener-Policy: same-origin`. The referrer policy is
`same-origin` rather than `no-referrer` on purpose: some browsers omit the
`Origin` header on same-origin form posts, so suppressing the `Referer` as
well would leave the CSRF origin check with nothing to read and make the
login form unusable. HTML and API responses are
`Cache-Control: no-store`. There is no inline JavaScript or CSS anywhere.

**Least privilege on the host.** Two unprivileged accounts split the work.
`manor-fetch` runs the daily job, owns the data directory and is the only
account that can read the API key. `manor` runs the web pool: it may read the
built dashboard but not write it, and it owns a separate `web/` directory for
the state it does write, namely sessions, login throttling, user records and
the audit log. A compromise of the web process therefore cannot reach the
credential or corrupt the history.

The web pool runs as a dedicated system user
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

**Third-party model and what leaves the machine.** The *Voci* view sends
text to Google's Gemini API: public video titles and descriptions, public
comments that passed a deterministic relevance filter, and public
auto-generated captions. Nothing of ours goes with it: no Roblox figures, no
user records, no server data. Calls are made from the owner's workstation
under a paid (not free-tier) key, so the applicable data-handling terms are
those of the paid API; they should be re-read whenever the key or plan
changes, and the answer recorded here. The key never reaches the server. A
local model (Ollama, `qwen2.5:14b-instruct`) is wired as a fallback and every
summary records which model produced it, so a mixed or degraded run is
visible on the page rather than silent.

Model output is untrusted input to the rest of the system: it is validated
against a fixed JSON shape, length-capped, inserted with `textContent`, and
never executed or fed back into another prompt unvalidated. Comments are a
prompt-injection surface; the prompts delimit them and declare them data, and
nothing downstream trusts a summary beyond displaying it.

**Why the YouTube job runs on a workstation.** YouTube refuses caption
requests from datacenter address ranges. Rather than route around that with
residential proxies, which would mean evading a platform control, the
extraction runs where it is permitted and publishes a file. The server only
serves it, and holds no YouTube or model credential.

**Origin address.** The server sits behind a Cloudflare Tunnel so that its
address is never published; the deployment scripts therefore read it from a
git-ignored `deploy/deploy.local.env` rather than carrying a default.

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
