# Changelog

All notable changes to the **Travel Agency Platform** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to [Semantic Versioning](https://semver.org/).

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

## [Unreleased / Next] — Planned

> The following improvements were implemented and verified in the working round following `1.2.0` and are queued for release as **1.3.0**:

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

---

## How this version was built

- **`1.2.0`** = the approved improvement batch (R1, C1, R4, F1, V1, S1, E1, H1).
- **`pending 1.3.0`** = follow-up improvements (R2, R3, T1, P1, V2) plus the database schema migration to version `1.3.0`.

> Pending items are listed under **Unreleased** until the plugin constant and `tap_db_version` are officially bumped to `1.3.0` in the release process.

---

## Version numbering

| Version | Plugin constant | Schema |
| --- | --- | --- |
| 1.2.0 | `TAP_VERSION` = `1.2.0` | `1.2.0` |
| (next) | `TAP_VERSION` = `1.3.0` | `1.3.0` |
