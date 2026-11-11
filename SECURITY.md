# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.5.x   | ✅ |
| < 1.5   | ❌ |

## Reporting a Vulnerability

**Do NOT open a public GitHub issue for security vulnerabilities.**

If you discover a security vulnerability in Travel Agency Platform, please report it responsibly:

1. **Email:** Send details to **security@travelagencypatform.com** (or the maintainer's email).
2. **Include:**
   - Description of the vulnerability
   - Steps to reproduce
   - Affected version(s)
   - Potential impact
   - Suggested fix (if any)
3. **Response time:** We aim to acknowledge within 48 hours and provide a fix within 7 days for critical issues.

## Scope

The following are in scope:
- SQL injection
- Cross-site scripting (XSS)
- Cross-site request forgery (CSRF)
- Authentication/authorization bypass
- Payment manipulation (amount tampering, double charging)
- Data exposure (PII, payment data)
- Remote code execution

The following are out of scope:
- Denial of service (DoS) via resource exhaustion
- Social engineering
- Issues in third-party plugins/themes
- Issues requiring physical access to the server

## Security Practices

- **Secrets:** All API keys, webhook secrets, and credentials are stored in `wp-config.php` or environment variables. Never in the database or code.
- **Nonces:** All AJAX handlers verify nonces (`check_ajax_referer`).
- **Input sanitization:** All user input is sanitized (`sanitize_text_field`, `sanitize_email`, `intval`, etc.).
- **Output escaping:** All output is escaped (`esc_html`, `esc_attr`, `esc_url`, `esc_js`).
- **SQL:** All queries use `$wpdb->prepare()` with placeholders.
- **CSP:** Content Security Policy is in report-only mode (will be enforced after violation review).
- **Payments:** PCI scope is minimized via hosted checkout / client-side tokenization. Raw card data never touches the server.
- **Webhooks:** All payment webhooks are verified via HMAC-SHA256 signature.

## Disclosure Policy

- We follow **coordinated disclosure**: vulnerabilities are fixed before public disclosure.
- Credit is given to reporters in the CHANGELOG (unless they prefer anonymity).
- Critical vulnerabilities are patched in a hotfix release within 7 days.

## Contact

- **Security email:** security@travelagencypatform.com
- **Maintainer:** Ivan Bermudez (ivanbermudez@protonmail.ch)