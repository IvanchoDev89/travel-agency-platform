# Travel Agency Platform

**A multi-agency travel platform for WordPress (B2B & B2C).**

The **Travel Agency Platform** transforms a standard WordPress installation into a full-featured travel marketplace. It provides the data models, business logic, and admin tooling to manage **accommodations, tours, transports, car rentals, boats, and packages**, while agencies and clients operate through a clean, on-brand front-end.

Built as a custom WordPress plugin (`travel-agency-platform`) paired with a dedicated theme (`travel-agency-theme`).

---

## Table of Contents

- [Overview](#overview)
- [Feature Set](#feature-set)
- [Product Scope](#product-scope)
- [Technology Stack](#technology-stack)
- [Repository Layout](#repository-layout)
- [Documentation](#documentation)
- [Versioning](#versioning)
- [Support](#support)

---

## Overview

| Attribute | Value |
| --- | --- |
| **Product** | Travel Agency Platform |
| **Type** | WordPress plugin + companion theme |
| **Model** | B2B & B2C multi-agency marketplace |
| **Current version** | `1.5.7` |
| **Schema version** | `1.5.7` |
| **Text domain** | `travel-agency-platform` |
| **Companion theme** | `travel-agency-theme` |
| **License** | Proprietary (IvanchoDev) |

The platform orchestrates three core actors:

- **Clients** — browse available services, book (as account or guest), pay online, cancel by booking code, manage reservations, submit reviews, and exercise their privacy rights.
- **Agencies** — publish and price their inventory, capture consented leads, and track bookings, commissions, promotions and payouts. (Reviews are posted by clients and replied to officially by the platform admin; agencies are not the ones replying.)
- **Administrators** — govern agencies (approval/KYC, commission %, verification), moderate reviews & leads, manage the commission ledger, disputes, plans/subscriptions/promotions, reports/analytics and platform settings.

---

## Feature Set

### Catalogue & Inventory
- Six agency-facing service types: **accommodation**, **tour**, **transport**, **car rental**, **boat**, **package** — plus **equipment rentals** (`tap_equipment`) as a bookable category (admin-managed).
- Sub-classes for accommodation (**rooms**) with per-room nightly pricing, occupancy limits, and inventory.
- Custom taxonomies: location, service category, property type, amenity, tour type, vehicle type, and boat type.

### Booking Engine
- Server-side reservation creation with unique booking codes.
- Date-range validation (no past check-in, valid check-out ordering).
- **Capacity/inventory control** — tours use per-date slots; rooms enforce inventory and blocked dates; overlapping stays are refused.
- **Guest data capture** (name, email, phone) persisted per booking.
- **Guest checkout** — logged-out visitors book, pay and manage their reservation using `?code=` + their email, with per email+IP rate limiting (max 5 per 15 minutes; a short-lived payment token lets them pay on `/checkout`).
- **Client-side cancellation** with ownership enforcement and lifecycle guards (`pending`/`request`/`confirmed` before check-in → `cancelled`, past stays refused; guest cancels by **booking code + email**, legacy booking **ID + email**). A paid client cancellation triggers a real PayPal refund (failed refunds are retried daily by cron); **agency operators cannot cancel paid bookings on their own** — only an administrator can (with a real PayPal refund).
- Automatic cancellation of **stale `pending` bookings** via a scheduled maintenance task; past **paid** bookings auto-complete; failed refunds are retried daily.

### Pricing & Money
- Multi-currency display with configurable symbols and **decimal rules** (e.g. JPY → 0 decimals).
- Per-room daily pricing, seasonal modifiers, and minimum-stay rules.
- Discount engine and live price breakdowns before confirmation.
- Agency commission calculation and a commission ledger with settlement records.

### Payments
- **PayPal** order creation and capture via AJAX (`create` / `capture`).
- Payment-status lifecycle independent of booking status.
- Sandbox configuration for development.

### Reviews & Trust
- Star ratings with average aggregation; **only confirmed/completed + paid** bookings may leave a review (verified badge).
- Approval workflow with **automated moderation** (abuse/spam/≥3 links → blocked; PII on reviews → blocked; 1–2 links, gibberish and PII on leads → queued for review).
- **Platform responses** to reviews by the admin (author + timestamp, nonce-protected), and clients can delete their own review.
- SEO-friendly rating markup (schema.org `AggregateRating`, `Offer`).

### Search & Discovery
- Faceted search: keyword, dates, guests, property type, amenities, star level, and price range.
- Sortable results: relevance (featured first), price (asc/desc), rating.
- **Interactive map** built on Leaflet with marker clustering.
- Favorites (wishlist) for logged-in clients.

### Agencies & Monetization
- Self-service inventory manager for all **six service types** (plan-limit and ownership enforced).
- **Contact leads** from visitors with mandatory GDPR/Ley 8968 **consent**, automated spam/moderation engine, rate limits (5 email/h, 10 IP/h), masked public display until confirmation, attribution until a booking is confirmed, and CSV export.
- Agency **KYC** (approval/rejection/verification/activation/deactivation, commission % per agency).
- Agency subscriptions (`tap_plans`) with commission overrides, **PayPal** online payment and featured-promotion slots.
- **Featured / ★ Destacado** promotions — months paid online or marked active by admin; automatically expire.
- Commission ledger with settlement (manual bulk or per-booking), **dispute** flow, payout requests, and PayPal webhook sync.
- **Discount engine**: global Early Bird, Last Minute, Long Stay rules and per-accommodation overrides.

### Privacy & Uninstall
- **Ley 8968** (Costa Rica) compliance page: Access, Rectification, Update, Deletion and Opposition rights.
- Native **WordPress Privacy** exporters/erasers covering bookings, leads, consents and reviews.
- Consent recording per form (`consent_scope`: booking/agency_registration/lead).
- **Uninstall** removes all plugin data (17 tables, transients, options, crons and roles); plugin post content is removed after a confirmation step unless `tap_uninstall_keep_content` is enabled.

### Content, SEO & Engagement
- Per-service SEO **meta title & description** fields rendered as `<meta>`, Open Graph, and JSON-LD (`Product`, `AggregateRating`, `Offer`, `BreadcrumbList`).
- Email notifications to clients including a **booking voucher detail link**.
- Spam protection via a **honeypot** field on public forms.
- **Multilingual interface (ES/EN)** — Spanish-first front-end with a one-click English switch (`?lang=en`, persistent via cookie / user meta) backed by dual compiled message catalogs and a `[tap_lang_switcher]` shortcode.

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Platform | WordPress |
| Language | PHP (server-side) |
| Front-end | jQuery, vanilla JS, CSS custom-properties design tokens |
| Mapping | Leaflet + Leaflet.markercluster |
| Data | MySQL via `$wpdb` (custom tables) |
| API | WordPress REST (`tap/v1`) + admin-ajax endpoints |
| Scheduling | WordPress Cron |

---

## Repository Layout

> The repository is a **Local-by-Flywheel** site checkout. WordPress core, uploads, the database, and third-party plugins are **excluded** from version control; only the delivered product is tracked.

```
travel-agency/
├── .gitignore                          # Tracks only the product + docs
├── README.md                           # This document
├── docs/
│   ├── INSTALLATION.md                 # Setup, configuration, deployment
│   ├── USER_GUIDE.md                   # End-user manual (travelers / guests)
│   ├── AGENCY_GUIDE.md                 # Agency manual (agency admin & employees)
│   ├── ADMIN_GUIDE.md                  # Administrator manual (back office)
│   ├── DEVELOPER_GUIDE.md              # Architecture, schema, hooks, APIs
│   └── CHANGELOG.md                    # Version history
└── app/
    └── public/wp-content/
        ├── plugins/travel-agency-platform/   # The plugin
        └── themes/travel-agency-theme/       # The companion theme
```

---

## Documentation

| Guide | Audience | Purpose |
| --- | --- | --- |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Administrators / DevOps | Install, configure, and deploy the platform. |
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | Travelers & visitors | Search, book, pay, cancel, review, privacy rights. |
| [`docs/AGENCY_GUIDE.md`](docs/AGENCY_GUIDE.md) | Agency staff | Register, publish inventory, manage bookings, leads, commissions and plans. |
| [`docs/ADMIN_GUIDE.md`](docs/ADMIN_GUIDE.md) | Administrators | Full back office: bookings, commissions, reviews, moderation, disputes, agencies, reports, analytics, plans/promotions, settings, discounts and privacy. |
| [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md) | Developers | Architecture, database schema, custom hooks, AJAX and REST APIs, and shortcodes. |
| [`docs/FASE1_PLAN.md`](docs/FASE1_PLAN.md) | Product/engineering | Technical plan for the multi-agency marketplace roadmap (P1–P4 status). |
| [`docs/INTEGRATION_PLAN.md`](docs/INTEGRATION_PLAN.md) | Integrators | How to embed the plugin into an existing WordPress site (child-theme strategy). |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | All | Released improvements and fixes by version. |

---

## Versioning

This project follows **semantic versioning** (`MAJOR.MINOR.PATCH`).

- **Plugin constant**: `TAP_VERSION` (defined in `travel-agency-platform.php`).
- **Schema version**: `tap_db_version` option; the installer runs idempotent migrations when the version changes.

See [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for the full history and [`docs/DEVELOPER_GUIDE.md`](docs/DEVELOPER_GUIDE.md#database-migrations) for the migration mechanism.

---

## Support

- **Author**: IvanchoDev
- **Site**: https://ivanchodev.com
- **Bug reports & enhancements**: raise an issue in the project repository.

---

© IvanchoDev. All rights reserved.
