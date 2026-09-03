# Changelog

All notable changes to the **Travel Agency Platform** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — post-1.3.0 improvements

### Added

- **P1 — Online payments via PayPal for subscriptions & promotions**:
  - **PayPal Checkout v2** now charges **agency subscriptions** and **featured promotions** directly online, in addition to the existing booking payments.
  - New generic order flow in `TAP_PayPal::create_order_generic()` + a `tap_payment_orders` ledger table mapping PayPal orders to subscription/promotion records.
  - Plans page renders a **Pagar con PayPal** button per paid plan; promotions render an inline PayPal button per listing.
  - New AJAX endpoints `tap_subscribe_paypal`, `tap_capture_subscription_paypal`, `tap_promo_paypal`, `tap_capture_promo_paypal`; webhooks route `PAYMENT.CAPTURE.COMPLETED` to activate the subscription/promotion.
  - The **manual** confirmation flow remains as a fallback for both whenever PayPal is not configured.
  - Add `tap_payment_orders` migration (idempotent).
- **F-FF — Featured-first ordering**:
  - Archive and search results now sort *featured* listings first (stable within the existing sort order) across all service types.
  - Canonical meta-key map in `TAP_Promotions` (`_tap_acc_*`, `_tap_tour_*`, `_tap_trans_*`, `_tap_car_*`, `_tap_boat_*`, `_tap_pkg_*`) fixes the derived prefixes that broke featured/active lookups for accommodation, transport, and package types.
  - `TAP_Ajax::featured_sort_clauses()` applied on `posts_clauses` so archives (accommodation) and search agree on ordering.
- **F-AN — Listing views & conversion**:
  - New `tap_listing_views` table (daily per-listing view counts) with `TAP_Analytics` tracking.
  - Views recorded on singular service pages with a 5-minute per-user throttle.
  - Analytics page adds **Vistas** and **Conversión vistas → reservas** KPIs plus per-listing view/conversion columns in Top Listings; summary CSV now includes views.
- **F-LV — Commission book**:
  - Per-booking commission settlement: admin can liquidate a single booking directly from the commissions screen (modal, method + note) in addition to the bulk checkbox flow.
  - Settlement history shows booking **codes** instead of raw IDs (admin + agency panel).
  - Agency panel adds a **Libro de comisiones** section listing the last 30 commission-generating bookings with status pills (Cobrada / Por cobrar).
- **F-TS — Test suite**:
  - `tests/run.sh` runner (WP-CLI based, target site via `SITE`/`WP_PATH`) plus consolidated E2E suites: `suite_core`, `suite_bookings`, `suite_commissions`, `suite_promotions`, `suite_views`, `suite_analytics`.
  - Suites are self-cleaning (delete seeded rows) and idempotent on re-run.

### Fixed

- Featured/active meta lookups used the wrong prefix for 3 of 6 service types; the canonical prefix map now guarantees `keys_for_type()` returns the correct keys everywhere.

---

## [1.2.0] — 2026-08-31

### Added

- **R1 — Date validation** server-side in `TAP_Booking::create()`:
  - Rejects bookings with a past check-in date.
  - Rejects invalid date ranges (check-out not after check-in).
  - Native `min` attributes on all date inputs (accommodation & single-service forms).
- **C1 — Currency decimals**:
  - `TAP_Currency::decimals()` derives decimal places per currency (JPY → 0, others → 2).
  - `fmt()` accepts an optional decimals argument.
  - Front-end uses localized decimals (`currency_decimals`) for live price totals.
- **R4 — Bookings admin**:
  - GET filters and CSV export moved to `admin_init` (priority 5) for clean headers.
  - Pagination preserves active filters.
- **S1 — SEO fields**:
  - `_tap_seo_title` and `_tap_seo_description` meta fields on service metaboxes.
  - `<meta name="description">`, Open Graph and JSON-LD prefer SEO fields.
  - `BreadcrumbList` schema on single pages.
- **V1 — Review replies**:
  - Admin reply form in the reviews panel (author + timestamp + nonce).
  - Renders agency replies publicly; exposed via REST.
- **F1 — Cards**:
  - `tap_card_rating()` helper plus favorite/rating markup on 7 template-parts, search results, and related cards.
  - Result cards show favorite hearts and rating summaries.
- **E1 — Voucher in emails**:
  - `voucher_url` (booking-detail by booking code) added to the client email CTA context.
- **H1 — Anti-spam honeypot**:
  - Hidden `tap_hp` field on agency registration and booking forms.
  - `agency_register()` and `create_booking()` silently discard bot submissions.

### Hardened

- Archive **sorting** now reliably includes all listings — accommodations without a direct price meta were silently dropped by `price_asc`/`price_desc`; prices are backfilled from the base room so all results sort correctly.

---

## [1.3.0] — 2026-09-02

### Added

- **R2 — Client cancellation**:
  - `TAP_Booking::client_cancel_request()` with ownership, status, and started-date guards.
  - Sets `cancel_requested_at` and `cancelled_by = client`; paid bookings flagged for refund.
  - New `tap_cancel_booking` AJAX endpoint and **Cancel** controls in My Bookings and the voucher page (only shown when cancellable).
- **R3 — Guest data**:
  - `guest_name`, `guest_email`, `guest_phone` fields in the booking form (prefilled from the logged-in user) and the accommodation booking form.
  - Persisted on the booking and displayed on the voucher ("Huésped de contacto").
  - Schema migration adds the new columns.
- **T1 — Capacity / full-date blocking**:
  - Room reservations now enforce `_tap_room_inventory` and blocked dates via `get_room_availability()`.
  - Tours already enforce per-date capacity; overlapping stays refused when inventory is exhausted.
- **P1 — Archive sort correctness**:
  - Price sorting includes all listings (accommodations without a direct price meta are backfilled from the base room).
- **V2 — Unified rating widget**:
  - Shared `tap_rating_stars($avg, $count)` helper used by the accommodation archive and cards, replacing duplicated inline markup.
- **M1 — Agency net breakdown (monetization, phase 1)**:
  - `get_booking_stats()` now returns `net` (subtotal minus commission) and `fees`.
  - Agency panel adds a **Net to you** stat and a **Net** column per booking.
- **M2 — Booking fee (monetization, phase 4)**:
  - New settings **Booking Fee (client)**: `tap_booking_fee_type` (`none`/`fixed`/`percent`) and `tap_booking_fee_value`.
  - Fee computed in `TAP_Booking::get_booking_fee()` and added to the client total across booking forms (single-service and accommodation), the price calculator AJAX, the voucher, and reports.
  - Persisted in the new `booking_fee` column (migration) and excluded from agency commission (commission is calculated on the service subtotal, not on the fee).
  - Admin reports now show GMV, retained commissions, and booking fees.
- **M3 — Agency subscriptions (monetization, phase 2)**:
  - New tables `tap_plans` and `tap_agency_subscriptions` with seeded plans: **Gratis** (3 listings), **Básico** (10 listings, 8% commission), **Pro** (unlimited listings, 5% commission).
  - `TAP_Subscriptions` class: subscribe → pending, manual payment confirmation via admin, paid-until extension/upgrade, automatic expiry.
  - Commission override: while a plan is active, `get_agency_commission()` uses the plan rate.
  - Listing-limit enforcement when an agency creates a new listing.
  - Admin menus **Plans** (edit pricing/limits) and **Subscriptions** (mark paid / expire).
  - New `[tap_plans]` shortcode (plans page at `/planes/`), plan summary card in the agency panel, and `tap_agency_subscribe` AJAX endpoint.
  - Email notification to the agency when a subscription payment is confirmed.
- **M4 — Featured promotions (monetization, phase 3)**:
  - New `tap_promos` table and `tap_featured_price` option (default **$5/mes**).
  - `TAP_Promotions` class: request → pending → admin confirmation → featured until date, automatic expiry.
  - Featured slots are enforced against the agency's plan (`featured_slots`); freelancers on the free plan are blocked until they upgrade.
  - Featured is now promo-driven: the free "Featured" checkbox was removed from the tour/package editors.
  - Agencies request promotions from the dashboard (Destacar + months), general admin confirms/expires via the new **Promotions** admin page.
  - Reports now include confirmed promotion revenue; agencies get an email when a promotion is activated.
- **M5 — Financial analytics (monetization, phase 5)**:
  - New **Analytics** admin page (`tap-analytics`, capability `tap_view_reports`).
  - KPI cards: 12-month platform revenue, subscription MRR, filtered GMV, active promotion value, live bookings, average ticket, active agencies, published listings.
  - 12-month stacked chart of platform revenue (commissions / booking fees / subscriptions / featured promotions) with a source-breakdown table.
  - **Top agencies** (bookings, GMV, platform commission, fees) and **Top listings** (bookings, GMV) tables.
  - Period filter (`Desde`/`Hasta`, YYYY-MM) applied to KPIs, top tables, and exports.
  - CSV exports: booking-level detail (with fee, commission, net) and monthly financial summary (with subscriptions and promotions), both nonce-protected.

---

## How this version was built

- **`1.2.0`** = the approved improvement batch (R1, C1, R4, F1, V1, S1, E1, H1).
- **`1.3.0`** = follow-up improvements (R2, R3, T1, P1, V2) plus the monetization roadmap phases 1–5 (M1 net breakdown, M2 booking fee, M3 agency subscriptions, M4 featured promotions, M5 financial analytics).

---

## Version numbering

| Version | Plugin constant |
| --- | --- |
| 1.2.0 | `TAP_VERSION` = `1.2.0` |
| 1.3.0 | `TAP_VERSION` = `1.3.0` |

Schema upgrades are handled incrementally and idempotently by `TAP_Installer::migrate()` on startup whenever `tap_version` differs from `TAP_VERSION`.
