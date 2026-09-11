# Contributing

## Issues

- Use [private vulnerability reporting](SECURITY.md) for security issues.
- Do not include merchant credentials, transaction IDs, or customer data.
- Include the WHMCS, PHP, and module versions needed to reproduce the problem.

## Pull requests

1. Keep each pull request focused on one problem.
2. Run `php -l` on every changed PHP file.
3. Validate payment and refund changes in PayTR test mode.
4. Update `CHANGELOG.md` and the relevant documentation.
5. Preserve callback HMAC verification, duplicate protection, and payment amount
   validation.

Describe the change, test results, security impact, and rollback method in the
pull request.
