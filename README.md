# PayTR WHMCS Security Update

Security update for the PayTR Virtual POS iFrame API module for WHMCS.

Current release: **Security Hardened Edition**

<h2 align="center">Maintainer</h2>

<table align="center">
  <tr>
    <td width="340" align="center">
      <a href="https://github.com/hamzadenizyilmaz">
        <img src="https://github.com/hamzadenizyilmaz.png?size=128" width="64" height="64" alt="HAMZA DENİZ YILMAZ">
      </a><br>
      <strong>HAMZA DENİZ YILMAZ</strong><br>
      <sub>Development and maintenance</sub>
    </td>
  </tr>
</table>

## What is included

- Fix coverage for three published CVEs
- Payment amount verification against PayTR and the current WHMCS invoice
- Strict callback validation and HMAC verification
- Duplicate payment protection
- Callback request and API timeout limits
- Single-use payment sessions with CSRF protection
- Browser security headers

## CVEs

### CVE-2026-16272 / Client IP spoofing

**Severity:** Critical · **CVSS 3.1:** 9.1 · **CWE:** CWE-348

Forwarding headers supplied by the client could be treated as the real IP
address. The module now uses the validated connection address only.

[View on NVD](https://nvd.nist.gov/vuln/detail/CVE-2026-16272)

### CVE-2026-16025 / Improper payment validation

**Severity:** High · **CVSS 3.1:** 7.5 · **CWE:** CWE-1284

Payment data was not validated strictly enough. The callback amount, PayTR status
response, currency, test mode, order context, and current WHMCS invoice total are
now compared before a payment is recorded.

[View on NVD](https://nvd.nist.gov/vuln/detail/CVE-2026-16025)

### CVE-2026-16037 / Observable timing discrepancy

**Severity:** High · **CVSS 3.1:** 7.5 · **CWE:** CWE-208

Callback hash comparison could expose a timing difference. HMAC values are now
compared with `hash_equals()`.

[View on NVD](https://nvd.nist.gov/vuln/detail/CVE-2026-16037)

Advisory: [TR-26-1034](https://siberguvenlik.gov.tr/guvenlik-bildirimleri/detay/tr-26-1034)

## Additional protections

- Callback fields are checked for type, format, and length.
- PayTR payment status is verified through a server-to-server request.
- Legacy order IDs without payment context are rejected.
- Repeated callbacks cannot create a second payment record.
- Payment session tokens expire after 30 minutes and are deleted after use.
- Callback body size, request rate, and remote API wait times are limited.
- CSP, HSTS, `nosniff`, `no-store`, and framing rules are sent on HTML responses.
- Unauthenticated callback failures do not write attacker-controlled transaction
  data to WHMCS logs.

## Installation

1. Back up the existing module and WHMCS database.
2. Copy `paytr.php`, `paytr/`, and `callback/` to `modules/gateways/`.
3. Confirm that `callback/.user.ini` was uploaded.
4. Test payments and refunds in PayTR test mode.

Install all module files from the same release.

## Documentation

- [CVE index](docs/security/README.md)
- [Release notes](docs/RELEASE.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## Reporting a vulnerability

Do not open a public issue for a security vulnerability. Use GitHub private
vulnerability reporting and follow [SECURITY.md](SECURITY.md).
