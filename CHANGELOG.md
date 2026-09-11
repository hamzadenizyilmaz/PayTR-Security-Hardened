# Changelog

## Security Hardened Edition — 2026-09-10

### Security

- Added a single-use CSRF token bound to the user and invoice.
- Rejected legacy order IDs without amount, currency, and test-mode context.
- Added verification against the current WHMCS invoice total.
- Added strict callback and PayTR status response validation.
- Added optional IPv4 and IPv6 callback allowlists.
- Added CSP, HSTS, `nosniff`, `no-referrer`, and `no-store` headers.
- Stopped unauthenticated callbacks from adding attacker-controlled transaction
  log entries.

### Availability

- Added callback preflight checks and APCu-based per-IP rate limiting.
- Reduced PayTR API timeouts.
- Removed blocking refund sleeps and repeated status requests.
- Limited PayTR session entries to eight per session.
- Moved the duplicate transaction check before the database lock.
