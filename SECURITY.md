# Security Policy

## Supported versions

| Version | Status |
|---|---|
| Security Hardened Edition | Supported |

## Reporting a vulnerability

Do not disclose vulnerabilities in public issues, discussions, or pull requests.
Use **Security → Advisories → Report a vulnerability** on GitHub.

Include:

- affected version and file;
- security impact;
- minimal reproduction steps;
- a non-destructive proof of concept, if available;
- logs with all secrets and personal data removed.

Never submit merchant keys, merchant salts, cardholder data, session cookies,
live callback payloads, or customer information. Rotate any exposed credential.

## Published CVEs

- [CVE-2026-16025](docs/security/CVE-2026-16025.md)
- [CVE-2026-16037](docs/security/CVE-2026-16037.md)
- [CVE-2026-16272](docs/security/CVE-2026-16272.md)

The official records list versions from `v9.0.0` up to, but not including,
`v9.0.3` as affected.
