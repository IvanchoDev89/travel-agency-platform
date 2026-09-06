# Changelog

All notable changes to the **Travel Agency Platform** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — post-1.3.0 improvements

### Added

- **UI/UX audit batch 2 (P0) — agencies, customer dashboard & data tables**:
  - **Agency directory** (`[tap_agencies]`): cards now render in a responsive auto-fill grid with the designed circular-logo card (hover lift/shadow); `<img>` is rounded via CSS, no markup churn.
  - **Agency profile** (`[tap_agency_details]`): styled header (logo circle, h1, verified badge, location), contact info card, `.tap-content` typography and the "Our Services" mini list (`.tap-agency-services` / `.tap-service-mini`).
  - **Customer dashboard**: `.tap-dashboard`/`.tap-dashboard-links` card layout.
  - **Data tables** (my bookings + agency dashboards): shared `.tap-table`/`.tap-agency-table` styling with uppercase micro-headers, striped-ish hover rows.
  - **Public status pills**: generic `.tap-status` base + pending/confirmed/completed/cancelled/refunded/paid/unpaid variants (previously only existed in admin CSS), so booking status reads correctly on the public "my bookings" list.

- **UI/UX audit batch 1 (P0) — search, language switcher & results page**:
  - `[tap_search]` form now renders inside the designed `.tap-search-box` card (shortcode), so the results page no longer shows a bare, unstyled form.
  - Search params submit via the box card, fields keep responsive stacking at ≤768px.
  - Results page (`templates/search-results.php`): removed inline 3-column grid + inline margins (broke responsiveness); grid now uses themed `.tap-services-grid` (auto-fill, 240px) and container uses `.tap-search-results-container`; service type is shown as a themed pill via `.tap-service-type-label`.
  - Language switcher styled as segmented pills: `.tap-lang-switcher`, `.tap-lang-link` (+ `.is-active`), `.tap-lang-sep`.
  - Hero destination field gains desktop emphasis (`@media (min-width:481px)` → `.tap-hs-destino { flex: 1.5; }`).
  - `index.php` no longer double-wraps the search in `.tap-search-box` (shortcode self-wraps now).

- **C3 — Search filters, sitemap, rich results & detail UX**:
  - **Search filters** on the results page: filter by type, location, min/max price and sort (newest / price asc / price desc / best rated), featured-first, applied as GET params.
  - **Technical SEO**: `TAP_Sitemap` extends WordPress' native sitemaps so all 6 service types and the platform taxonomies are indexed, and robots.txt now references the sitemap. (Rewrites via WP core — no custom rewrite/301.)
  - **FAQ rich results**: service listings emit FAQPage JSON-LD (booking, pricing, operator, cancellation, payment).
  - **Contact rich results**: service listings + agencies emit `ContactPoint`/`telephone` schema.
  - **Visible breadcrumbs** (`TAP_SEO::visible_breadcrumbs()`) rendered on service and agency detail pages (matching the BreadcrumbList schema).
  - **Detail page improvements**: service page now shows type badge, rating (+count), featured badge, agency link with verified badge, and a prominent price; Spanish-friendly labels.
- **C1 — Search results page (conversion)**:
  - New `[tap_search_results]` shortcode renders filtered results (keyword / type / location) from the **search-results** page, featured-first then newest, with result count, service type badge, price, city and empty-state.
  - The **search-results** page now shows both the search form and the live results grid.
  - Autocomplete suggestions are now wired to the `[tap_search]` keyword input (shared `tap_search_suggestions` endpoint), in addition to the hero search.
  - Fixed the `tests/run.sh` path resolution so it works from the repo root.
- **C2 — Search/SEO tags**:
  - Archives (all 6 service types + agencies + location/category tax terms) now output canonical, meta description, and Open Graph tags with human-friendly titles ("Tours y Excursiones", "Alojamientos", etc.).
  - Agency singular pages output canonical, meta description, Open Graph (`og:type=profile`) and JSON-LD `TravelAgency` with contact/address.
  - Service cards: accommodation JSON-LD gained `geo` (from stored coords only — no in-head geocoding) and a `TravelAgency` `provider`; tours now use `TouristTrip` schema with provider.
  - Tightened meta descriptions to ~155 chars and added Twitter Card (`summary_large_image`) tags.
  - Search archive `<title>` tags now humanize the post-type name and append pagination.
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
- **P2 — Auto-service listing manager (generalized to all 6 service types)**:
  - The front-end **manage-listing** panel now Creates/Edits **all** public service types (accommodation, tour, transport, car rental, boat, package) through a generic editor driven by `TAP_Metaboxes::get_fields()`. `tap_room` is intentionally not creatable by providers.
  - The save handler was extracted into a testable core, `TAP_Ajax::save_listing_data()`, that never dies, decoupled from the AJAX/`wp_send_json` wrapper; it validates ownership (`agency_owns_listing`), enforces the plan listing limit, and persists fields with per-type sanitization (plus legacy short-name mapping for the accommodation form).
  - New single source of truth for per-type meta prefixes, `TAP_Post_Types::meta_prefix()` (`acc`, `tour`, `trans`, `car`, `boat`, `pkg`), applied across ajax, shortcodes, API, dashboard and promotions. `TAP_Promotions::prefix_for_type()` now delegates to it. This fixes a latent bug where the owner/`_is_active` lookups wrote/read the wrong keys for accommodation, transport, car-rental and package.
  - New site-agnostic E2E suite `suite_agency_manage`: discovers an agency user dynamically (and creates a temporary second agency when the install has only one), covers create/edit/ownership across all types, and verifies the owner lands on the canonical meta key (no strays on derived keys).
  - `suite_seo` now picks its search keyword from an **active** listing (the results page filters `_is_active=1`), so it no longer depends on the newest published post being active.
- **P3 — Guest checkout (Fase 1, prioridad 3)**:
  - Logged-out visitors can book without an account: the booking form captures `guest_name` / `guest_email` / `guest_phone`, and the booking is stored with `client_id = 0` + the guest contact data.
  - Voucher and checkout pages verify the booking by `?code=` + the **guest email** before rendering the voucher or the PayPal button.
  - Client-side cancellation works for guests via email proof; registered customers can only cancel their own bookings (`forbidden` guard) and vice versa (`no_user`).
  - **Rate limiting**: `TAP_Ajax::guest_book_rate_bump()` / `guest_book_rate_blocked()` (5 bookings / hour / email) on guest checkout; a `guest_email_required` WP_Error rejects guests without a valid email.
- **P4 — Agency contact leads (Fase 1, prioridad 4)**:
  - New `tap_leads` table + `TAP_Leads` class with a CLI-safe `submit()` that validates name/email/message, active-agency and service-ownership checks, and rate limiting (5 emails / 10 IPs per hour).
  - Public `[tap_lead_form]` shortcode on agency profile + service detail pages; email alert to the agency on each lead (`tap_lead_created`).
  - Agency dashboard **Mensajes** section lists lead count and the last 20 leads, with a nonce-protected **Exportar CSV** export (`tap_export_leads` admin-post).
- **Fase B — deuda técnica (verificación + batería de regresión)**:
  - Fixed a fatal on PHP 8: the public services grid stored closures as `stdClass` methods and called them as methods; rewritten with `foreach` + `setup_postdata()`.
  - `TAP_PayPal::currency_code()` centralizes the charge currency (falls back to USD), replacing hard-coded `USD` in PayPal bodies and SDK script tags.
  - Deleting an agency now wipes all 6 per-type meta keys; admin emails point to the real bookings screen (`admin.php?page=tap-bookings`) instead of `/tap-dashboard`; the removed `/edit-profile` link was dropped from the account menu; archives resolve the query post-type via `get_query_var`.
  - New `suite_bugs` regression suite asserting the source of each fix.
- **Fase C — batería de crítica (reserva, pricing, PayPal, REST, reviews)**:
  - Five new suites green on both installs: `suite_booking_flow` (full lifecycle, stale-cancel cron, booking fee), `suite_pricing` (night ranges, blocked dates, min-stay, per-person packages), `suite_paypal` (create/capture/refund/webhook via a `pre_http_request` mock), `suite_rest` (the whole `tap/v1` surface incl. ownership), `suite_reviews` (duplicate/`missing_field`/anonymous guards + rating aggregation behind approval).
  - Fixed a real REST bug surfaced by the suites: `GET /tap/v1/booking/{id}` compared the DB `client_id` (string) to the current user id (int) with a strict `!==`, so owners were always denied; both sides are now cast.
  - `tests/bootstrap.php` now provides shared helpers (service/user discovery, PayPal mock, in-process REST dispatcher, error-code normalization) and every suite is residue-free (verified 0 rows left behind on both sites).
- **Fase 3 — Multilingüe (interfaz pública ES/EN)**:
  - New `TAP_Localization` engine: the **public front-end defaults to Spanish** (`es_ES`) while the WordPress admin keeps the site locale; visitors switch to English via `?lang=en`, a `tap_lang` cookie, or the per-user `tap_lang` meta (`?lang=es` back).
  - New `[tap_lang_switcher]` shortcode renders ES/EN links that preserve the current URL and marks the active language.
  - **Message catalogs** shipped in `languages/` (dual-direction, compiled with `msgfmt`): `es_ES` maps every English source msgid to Spanish (so the whole visitor-facing UI is Spanish by default), and `en_US` maps every Spanish source msgid to English (complete English UI when switched).
  - Wrapped all remaining visible visitor-facing strings into `__()` with the `travel-agency-platform` domain across the theme templates/parts and the plugin's public shortcodes.
  - Hardened `suite_payments` (stale pending subscription cleanup) and `suite_guest_checkout` (voucher guard now asserts the Spanish rendering).
  - New `suite_i18n` regression suite — battery is now **18 suites** green on both installs (travel & ivanchodev).

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
