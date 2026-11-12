# Travel Agency Platform

**A multi-agency travel marketplace for WordPress (B2B & B2C).**

The **Travel Agency Platform** transforms a standard WordPress installation into a full-featured travel marketplace. It manages **accommodations, tours, transports, car rentals, boats, packages, and equipment rentals**, with agencies and clients operating through a clean, on-brand front-end.

Built as a custom WordPress plugin (`travel-agency-platform`) paired with a dedicated theme (`travel-agency-theme`).

---

## Table of Contents

- [Quick Start](#quick-start)
- [Feature Set](#feature-set)
- [Technology Stack](#technology-stack)
- [Repository Layout](#repository-layout)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [Security](#security)
- [Versioning](#versioning)
- [Support](#support)

---

## Quick Start

### For Travelers (Users)
1. Visit the site and use the **search bar** (destination, dates, guests).
2. Browse results and click a service to see details, photos, and reviews.
3. Select dates, guests, and extras → click **Book Now**.
4. Pay online (PayPal, Onvo Pay with cards/SINPE Móvil) or request a booking.
5. Receive a **booking code** and voucher. Manage your reservation at `/my-bookings`.

📖 **Full guide:** [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md)

### For Agencies
1. Register at `/register/` and complete the onboarding checklist.
2. Wait for admin approval and verification.
3. Publish your services (accommodations, tours, transport, etc.) via the **Agency Dashboard**.
4. Set pricing, availability, and cancellation policies.
5. Track bookings, commissions, leads, and payouts in your dashboard.

📖 **Full guide:** [`docs/AGENCY_GUIDE.md`](docs/AGENCY_GUIDE.md)

### For Administrators
1. Install the plugin and theme (see [`docs/INSTALLATION.md`](docs/INSTALLATION.md)).
2. Configure settings: payments (Onvo/PayPal), currencies, email templates.
3. Approve and verify agencies.
4. Manage bookings, commissions, disputes, reviews, and analytics.

📖 **Full guide:** [`docs/ADMIN_GUIDE.md`](docs/ADMIN_GUIDE.md)

### For Developers
1. Clone the repo and run `composer install`.
2. Run the full quality gate: `make check`.
3. Read the architecture in [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md).
4. Follow the contribution guide in [`CONTRIBUTING.md`](CONTRIBUTING.md).

📖 **Full guide:** [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md)

---

## Feature Set

### Catalogue & Inventory
- Six agency-facing service types: **accommodation**, **tour**, **transport**, **car rental**, **boat**, **package** — plus **equipment rentals**.
- Sub-classes for accommodation (**rooms**) with per-room nightly pricing, occupancy limits, and inventory.
- Custom taxonomies: location, service category, property type, amenity, tour type, vehicle type, boat type.

### Booking Engine
- Server-side reservation creation with unique booking codes and **idempotency** (prevents duplicates).
- Date-range validation, capacity/inventory control, guest data capture.
- **Guest checkout** — book, pay, and manage without an account (email + IP rate limiting).
- **Request-to-book** mode: agency must accept before payment.
- Automatic cancellation of stale pending bookings; auto-complete past paid bookings.
- Client ↔ agency messaging per booking with unread counters.

### Pricing & Money
- Multi-currency (CRC/USD) with configurable symbols and decimal rules.
- Per-room daily pricing, seasonal modifiers, minimum-stay rules.
- Discount engine (Early Bird, Last Minute, Long Stay) + per-service overrides.
- Coupon codes with validation.
- Agency commission calculation and settlement ledger.

### Payments
- **Onvo Pay** (Costa Rica): cards (Visa/MC/Amex) + SINPE Móvil, hosted checkout, webhooks, marketplace split payments.
- **PayPal** (legacy): order creation and capture via AJAX.
- **Manual/SINPE** (legacy manual transfer).
- Payment-status lifecycle independent of booking status.
- Partial-payment retry (top-up orders charge only the remaining balance).
- Sandbox mode for development.

### Search & Discovery
- Faceted search: keyword, dates, guests, property type, amenities, star level, price range.
- Sortable results: relevance, price (asc/desc), rating.
- Interactive map (Leaflet + marker clustering).
- Favorites (wishlist) for logged-in clients.

### Reviews & Trust
- Star ratings with average aggregation; only confirmed/completed + paid bookings may review.
- Approval workflow with automated moderation (spam, PII, links).
- Platform responses to reviews by admin.
- Reviews with photos (up to 6).

### Referrals & Growth
- Referral program: unique code, discount for invitee, points for referrer.
- Affiliate tiers (Bronze/Silver/Gold) based on completed referrals.
- Gamification: leaderboard, QR code, shareable link.
- Admin dashboard: metrics, top referrers, CSV export.

### Push Notifications & Email
- Web Push (VAPID) with email fallback.
- Granular notification preferences per client.
- Event-driven: booking created, payment received, chat message, 24h reminder.
- Email template editor (CRUD, variables, multi-locale, log).
- Automated emails: welcome, payment receipt, reminders, pre-arrival, review request.

### Security
- CSP configurable (report-only or enforce).
- Rate limiting on booking creation, search, referral clicks.
- Anti-fraud: self-referral blocking, IP rate limits, idempotency.
- Audit log for sensitive actions.
- Security headers: HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy.
- PCI compliance via hosted checkout / client-side tokenization.

### Admin Dashboard
- Booking calendar view (month grid).
- Bulk actions (confirm, complete, cancel multiple).
- Internal notes per booking.
- Printable invoices/vouchers.
- Automated alerts (booking drop, negative reviews).
- Email templates CRUD.
- Analytics: booking funnel, conversion rates.

### Agencies (B2B)
- Self-service inventory manager for all service types (plan limits enforced).
- 5-step accommodation wizard (info → units → pricing → policies → publish).
- Per-room daily price/availability calendar.
- Contact leads with GDPR/Ley 8968 consent, spam moderation, CSV export.
- Agency KYC (approval, verification, activation, commission %).
- Agency subscriptions with commission overrides.
- Featured listings (paid promotion).
- Financial dashboard with monthly chart and projection.

### Privacy & Compliance
- Ley 8968 (Costa Rica) compliance page: Access, Rectification, Update, Deletion, Opposition.
- WordPress Privacy exporters/erasers (bookings, leads, consents, reviews).
- Consent recording per form.
- Uninstall removes all plugin data (17 tables, transients, options, crons, roles).

### Content & SEO
- Per-service SEO meta title & description, Open Graph, JSON-LD.
- Multilingual interface (ES/EN) with one-click language switch.
- Spam protection (honeypot) on public forms.
- Email notifications with booking voucher links.

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Platform | WordPress 6.4+ |
| Language | PHP 8.2+ (8.2.35, 8.3.36, 8.4.27, 8.5.12) |
| Front-end | jQuery, vanilla JS, CSS custom-properties design tokens |
| Mapping | Leaflet + Leaflet.markercluster |
| Data | MySQL via `$wpdb` (custom tables) |
| API | WordPress REST (`tap/v1`) + admin-ajax |
| Scheduling | WordPress Cron |
| Payments | Onvo Pay (primary), PayPal (legacy) |
| i18n | es_ES / en_US (Spanish-first, compiled .mo) |

---

## Repository Layout

```
travel-agency/
├── README.md                           # This document
├── LICENSE                             # GPL-2.0
├── SECURITY.md                         # Security policy
├── CONTRIBUTING.md                     # Contribution guide
├── CODE_OF_CONDUCT.md                  # Contributor Covenant
├── .env.example                        # Environment variables template
├── docs/                               # User, agency, admin, developer guides
├── tests/                              # E2E suites + runner
├── tools/                              # i18n, lint, static checks
├── .github/workflows/                  # CI/CD (GitHub Actions)
└── app/public/wp-content/
    ├── plugins/travel-agency-platform/ # The plugin
    └── themes/travel-agency-theme/     # The companion theme
```

---

## Documentation

| Guide | Audience | Purpose |
| --- | --- | --- |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Admins / DevOps | Install, configure, deploy. |
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | Travelers & visitors | Search, book, pay, cancel, review, privacy rights. |
| [`docs/AGENCY_GUIDE.md`](docs/AGENCY_GUIDE.md) | Agency staff | Register, publish inventory, manage bookings, leads, commissions. |
| [`docs/ADMIN_GUIDE.md`](docs/ADMIN_GUIDE.md) | Administrators | Full back office: bookings, commissions, moderation, analytics, settings. |
| [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) | Developers | Architecture, schema, hooks, APIs, shortcodes. |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | All | Version history and release notes. |
| [`SECURITY.md`](SECURITY.md) | Security researchers | Vulnerability reporting policy. |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contributors | How to contribute code. |

---

## Contributing

We welcome contributions! Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) before submitting.

**Quick checklist:**
- [ ] PHPStan level 3 passes
- [ ] i18n check passes
- [ ] Ajax nonce check passes
- [ ] Includes check passes
- [ ] PHPCS passes
- [ ] Tests pass
- [ ] New strings translated (es/en)
- [ ] No secrets committed

See [`.github/ISSUE_TEMPLATE/`](.github/ISSUE_TEMPLATE/) for bug reports and feature requests.

---

## Security

For security vulnerabilities, **do NOT open a public issue**. See [`SECURITY.md`](SECURITY.md) for the responsible disclosure process.

---

## Versioning

Semantic versioning (`MAJOR.MINOR.PATCH`).

- **Plugin:** `TAP_VERSION` (defined in `travel-agency-platform.php`).
- **Theme:** `TAT_VERSION` (defined in `functions.php`).
- **Schema:** `tap_version` option; `TAP_Installer::create_tables()` runs idempotent migrations.

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for the full history.

---

## Support

- **Author:** Ivan Bermudez
- **Email:** ivanbermudez@protonmail.ch
- **Site:** https://ivanchodev.com
- **Bug reports & enhancements:** raise an issue in the repository.

---

© Ivan Bermudez. Licensed under [GPL-2.0](LICENSE).