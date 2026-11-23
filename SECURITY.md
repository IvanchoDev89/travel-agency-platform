# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.6.x   | ✅ |
| < 1.6   | ❌ |

## Reporting a Vulnerability

**Do NOT open a public GitHub issue for security vulnerabilities.**

If you discover a security vulnerability in Travel Agency Platform, please report it responsibly:

1. **Email:** Send details to **security@travelagencyplatform.com** (or the maintainer's email).
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

## Runtime requirements (PHP)

This plugin refuses to run on PHP releases affected by CVE-2026-109618 and
CVE-2026-109619 (unauthenticated RCE under PHP-FPM, published 2026-10-21).
Because each maintained branch has its own patched release, the check is
per-branch, not a single minimum:

| Branch | First patched release |
|---|---|
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

`TAP_Requirements::is_php_patched()` enforces this at runtime and shows an
admin notice on vulnerable versions. A production deployment must use one of
the patched releases above.

## Security Practices

- **Secrets:** Payment/webhook keys (Onvo) live in `wp-config.php` constants. Pexels (`TAP_PEXELS_API_KEY`) and Web Push (`TAP_VAPID_PUBLIC` / `TAP_VAPID_PRIVATE`) also accept constants and fall back to WP options for backwards compatibility; new deployments should define the constants and leave the options empty. Never commit real credentials to the repository.
- **Nonces:** All AJAX handlers verify nonces (`check_ajax_referer`).
- **Input sanitization:** All user input is sanitized (`sanitize_text_field`, `sanitize_email`, `intval`, etc.).
- **Output escaping:** All output is escaped (`esc_html`, `esc_attr`, `esc_url`, `esc_js`).
- **SQL:** All queries use `$wpdb->prepare()` with placeholders.
- **CSP:** Content Security Policy is in report-only mode by default (enforcing would break third-party font/worker sources). Review reports before switching to `enforce`.
- **Site hardening:** XML-RPC disabled, anonymous `/wp/v2/users` enumeration blocked and author archives redirected (`TAP_Site_Hardening`).
- **Anti-spam:** honeypot + rate limits on client chat, Q&A and itinerary comments; moderation for Q&A and reviews.
- **Payments:** PCI scope is minimized via hosted checkout / client-side tokenization. Raw card data never touches the server.
- **Webhooks:** All payment webhooks are verified via HMAC-SHA256 signature.

## Disclosure Policy

- We follow **coordinated disclosure**: vulnerabilities are fixed before public disclosure.
- Credit is given to reporters in the CHANGELOG (unless they prefer anonymity).
- Critical vulnerabilities are patched in a hotfix release within 7 days.

## Contact

- **Security email:** security@travelagencyplatform.com
- **Maintainer:** Ivan Bermudez (ivanbermudez@protonmail.ch)