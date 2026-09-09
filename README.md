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
| **Current version** | `1.4.6` |
| **Schema version** | `1.4.6` |
| **Text domain** | `travel-agency-platform` |
| **Companion theme** | `travel-agency-theme` |
| **License** | Proprietary (IvanchoDev) |

The platform orchestrates three core actors:

- **Clients** — browse available services, request bookings, manage reservations, submit reviews, and administer their own bookings.
- **Agencies** — publish and price their inventory, respond to client reviews, and track booking activity and commissions.
- **Administrators** — govern agencies, moderate reviews, manage commissions, and control platform-wide settings.

---

## Feature Set

### Catalogue & Inventory
- Six service types: **accommodation**, **tour**, **transport**, **car rental**, **boat**, **package**.
- Sub-classes for accommodation (**rooms**) with per-room nightly pricing, occupancy limits, and inventory.
- Custom taxonomies: location, service category, property type, amenity, tour type, vehicle type, and boat type.

### Booking Engine
- Server-side reservation creation with unique booking codes.
- Date-range validation (no past check-in, valid check-out ordering).
- **Capacity/inventory control** — tours use per-date slots; rooms enforce inventory and blocked dates; overlapping stays are refused.
- **Guest data capture** (name, email, phone) persisted per booking.
- **Guest checkout** — logged-out visitors book, pay and manage their reservation using `?code=` + their email, with 5/hour rate limiting.
- **Client-side cancellation** with ownership enforcement and lifecycle guards (`pending`/`confirmed` → `cancelled`, past stays refused, paid orders flagged for refund).
- Automatic cancellation of **stale/pending** bookings via a scheduled maintenance task.

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
- Star ratings with average aggregation.
- Approval workflow for moderation.
- **Agency replies** to reviews (author, timestamp, nonce-protected).
- SEO-friendly rating markup (schema.org `AggregateRating`, `Offer`).

### Search & Discovery
- Faceted search: keyword, dates, guests, property type, amenities, star level, and price range.
- Sortable results: relevance, price (asc/desc), rating, name.
- **Interactive map** built on Leaflet with marker clustering.
- Favorites (wishlist) for logged-in clients.

### Agencies & Monetization
- Self-service inventory manager for all **six service types** (plan-limit and ownership enforced).
- **Contact leads** from visitors, with agency email alerts and CSV export.
- Agency subscriptions with commission overrides, featured promotions, booking fees, commission settlement, and financial analytics.

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
│   ├── USER_GUIDE.md                   # End-user manual (clients & agencies)
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
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | Clients & agency staff | How to use the marketplace day-to-day. |
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
