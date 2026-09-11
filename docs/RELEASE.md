# Security Hardened Edition Release Notes

Release date: **2026-09-10**

## CVEs

| CVE | Severity | CWE |
|---|---:|---|
| CVE-2026-16025 | 7.5 High | CWE-1284 |
| CVE-2026-16037 | 7.5 High | CWE-208 |
| CVE-2026-16272 | 9.1 Critical | CWE-348 |

The official records list versions from `v9.0.0` up to, but not including,
`v9.0.3` as affected.

## Changes

- Added strict callback and PayTR status response validation.
- Added verification against the current WHMCS invoice total.
- Bound the order ID to amount, currency, invoice, and test mode.
- Replaced ordinary HMAC comparison with `hash_equals()`.
- Removed trust in client-controlled forwarding headers.
- Added single-use payment session tokens.
- Added callback request and API timeout limits.
- Removed blocking refund retries.
- Added browser security headers.
