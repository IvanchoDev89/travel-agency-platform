# Developer Guide

Technical reference for developers working on the **Travel Agency Platform**: architecture, database schema, custom hooks, AJAX & REST APIs, and shortcodes.

---

## Contents

- [Architecture](#architecture)
- [Directory Layout](#directory-layout)
- [Custom Post Types & Taxonomies](#custom-post-types--taxonomies)
- [Roles & Capabilities](#roles--capabilities)
- [Database Schema](#database-schema)
  - [Database Migrations](#database-migrations)
- [Public API](#public-api)
  - [REST API (`tap/v1`)](#rest-api-tapv1)
  - [AJAX Endpoints](#ajax-endpoints)
- [Shortcodes](#shortcodes)
- [Hooks](#hooks)
  - [Actions](#actions)
  - [Filters](#filters)
- [Currency & Money Handling](#currency--money-handling)
- [Booking Lifecycle](#booking-lifecycle)
- [Multilingual Interface (i18n)](#multilingual-interface-i18n)
- [Common Development Tasks](#common-development-tasks)

---

## Architecture

The platform is organized around a set of **single-responsibility classes**, each exposing static methods, loaded by the plugin's main class `TravelAgencyPlatform` (a singleton):

```
TravelAgencyPlatform (bootstraps everything)
 ├─ TAP_Installer    → tables, migrations, activation
 ├─ TAP_PostTypes    → custom post types (services, rooms)
 ├─ TAP_Taxonomies   → classification taxonomies
 ├─ TAP_Roles        → custom roles & capabilities
 ├─ TAP_Metaboxes    → admin meta fields
 ├─ TAP_Pricing      → dynamic/seasonal pricing
 ├─ TAP_Discounts    → discount engine
 ├─ TAP_Booking      → reservations, capacity, cancellation
 ├─ TAP_API          → REST routes & rating aggregation
 ├─ TAP_Shortcodes   → public-facing shortcodes
 ├─ TAP_Ajax         → admin-ajax handlers & archive filters
 ├─ TAP_Dashboard    → agency dashboard
 ├─ TAP_Paypal       → PayPal integration
 ├─ TAP_Payment      → payment statuses
 ├─ TAP_Emails       → email notifications
 ├─ TAP_SEO          → meta/OG/JSON-LD + visible breadcrumbs
 ├─ TAP_Sitemap      → extends WP native sitemaps (post types + taxonomies)
 ├─ TAP_Subscriptions→ agency plans
 ├─ TAP_Promotions   → featured listings
├─ TAP_Analytics    → listing view analytics
  ├─ TAP_Leads        → agency contact leads (tap_leads)
  └─ TAP_Currency     → money formatting
```

The **companion theme** (`travel-agency-theme`) provides the public templates that render these data structures (cards, single pages, search, voucher, forms) and delegates all business logic to the plugin.

---

## Directory Layout

```
wp-content/
├── plugins/travel-agency-platform/
│   ├── travel-agency-platform.php        # bootstrap, version, enqueue, AJAX wiring
│   ├── includes/                         # all PHP classes
│   ├── assets/
│   │   ├── css/                          # public, admin, tokens, pricing, dashboard
│   │   ├── js/                           # public, calendar, map, admin, dashboard, pricing
│   │   └── leaflet/                      # bundled Leaflet + markercluster
│   └── templates/                        # plugin-side templates
└── themes/travel-agency-theme/
    ├── functions.php                     # theme helpers (favorites, ratings, sort wiring)
    ├── single-tap_accommodation.php      # accommodation booking form
    ├── archive-tap_accommodation.php     # archive + filters + map + sort
    ├── templates/                        # page templates (search, checkout, voucher, …)
    ├── template-parts/                   # reusable card partials
    └── assets/js/                        # theme JS
```

---

## Custom Post Types & Taxonomies

### Post types

| Post type | Description |
| --- | --- |
| `tap_agency` | Agency profile entry. |
| `tap_accommodation` | Accommodation listing (hotel, hostel, villa, …). |
| `tap_room` | Sub-class of an accommodation (nightly price, occupancy, inventory). |
| `tap_tour` | Tour / excursion with per-date capacity. |
| `tap_transport` | Transport service. |
| `tap_car_rental` | Car rental service. |
| `tap_boat` | Boat / nautical service. |
| `tap_package` | Bundled package. |

#### Per-type meta key prefixes

Each service post type stores its listing metadata under a **meta prefix** that is *not*
always the post type's suffix. Use the canonical map — `TAP_Post_Types::meta_prefix($type)` —
as the **single source of truth**; never derive it with `str_replace('tap_', '', $type)`.

| Post type | Prefix | Example meta keys |
| --- | --- | --- |
| `tap_accommodation` | `acc` | `_tap_acc_agency_id`, `_tap_acc_price_per_night`, `_tap_acc_is_active` |
| `tap_tour` | `tour` | `_tap_tour_agency_id`, `_tap_tour_price_adult` |
| `tap_transport` | `trans` | `_tap_trans_agency_id`, `_tap_trans_price` |
| `tap_car_rental` | `car` | `_tap_car_agency_id`, `_tap_car_price_per_day` |
| `tap_boat` | `boat` | `_tap_boat_agency_id`, `_tap_boat_price` |
| `tap_package` | `pkg` | `_tap_pkg_agency_id`, `_tap_pkg_price_adult` |

Common keys built from the prefix: `_tap_{prefix}_agency_id` (owning agency),
`_tap_{prefix}_is_active` (visibility), `_tap_{prefix}_is_featured` /
`_tap_{prefix}_featured_until` (promotions). Use `TAP_Promotions::keys_for_type($type)`
when you need all of them at once.

`TAP_Promotions::prefix_for_type()` delegates to `meta_prefix()`, and
`TAP_Ajax::listing_prefix()` is the same helper — so owner/active/featured lookups in the
admin, public API, shortcodes, and the agency manager all agree.

### Taxonomies

| Taxonomy | Applied to |
| --- | --- |
| `tap_location` | All services |
| `tap_service_cat` | All services |
| `tap_property_type` | `tap_accommodation` |
| `tap_amenity` | `tap_accommodation`, `tap_room` |
| `tap_tour_type` | `tap_tour` |
| `tap_vehicle_type` | `tap_transport`, `tap_car_rental` |
| `tap_boat_type` | `tap_boat` |

---

## Roles & Capabilities

Roles registered on activation:

| Role | Purpose |
| --- | --- |
| `tap_agency_admin` | Agency manager. |
| `tap_agency_employee` | Agency staff. |
| `tap_client` | Customer. |

Platform capability flags (granted to administrators):

- `tap_manage_bookings`
- `tap_manage_agencies`
- `tap_manage_commissions`
- `tap_manage_reviews`
- `tap_view_reports`
- `tap_manage_settings`

---

## Database Schema

All tables use the WordPress prefix (`$wpdb->prefix`) and are created/updated idempotently.

### `{prefix}tap_bookings`

Stores the primary reservation records.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK AI | |
| `booking_code` | varchar(20) | Unique, human-readable code. |
| `agency_id` | bigint | Owning agency. |
| `client_id` | bigint | Booking owner (WP user). |
| `service_type` | varchar(50) | Bookable service type (`tap_accommodation`, `tap_tour`, `tap_transport`, `tap_car_rental`, `tap_boat`, `tap_package`, `tap_equipment`). |
| `service_id` | bigint | Service post ID. |
| `room_id` | bigint NULL | Room post ID (accommodation). |
| `package_id` | bigint NULL | |
| `check_in` | date NULL | |
| `check_out` | date NULL | |
| `adults` | int | |
| `children` | int | |
| `nights` | int | Computed stay length. |
| `bed_config` | text NULL | |
| `total_amount` | decimal(15,2) | Client total = service subtotal + booking fee. |
| `booking_fee` | decimal(15,2) | Platform fee (M2); excluded from agency commission. |
| `commission_amount` | decimal(15,2) | Calculated on the service subtotal (total − booking_fee). |
| `commission_percent` | decimal(5,2) | |
| `commission_status` | varchar(20) | `owed` / settled. |
| `status` | varchar(30) | `pending`, `confirmed`, `completed`, `cancelled`, … |
| `payment_status` | varchar(30) | `pending`, `paid`, `refunded`, … |
| `payment_method` | varchar(50) | |
| `notes` | text | |
| `guest_name` | varchar(100) | Contact guest (R3). |
| `guest_email` | varchar(150) | Contact email (R3). |
| `guest_phone` | varchar(50) | Contact phone (R3). |
| `cancel_requested_at` | datetime NULL | When client requested cancellation (R2). |
| `cancelled_by` | varchar(20) | `client` / `admin` / `system` (R2). |
| `created_at` / `updated_at` | datetime | |

### `{prefix}tap_booking_items`

Line items per booking.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `booking_id` | FK to bookings. |
| `service_type` / `service_id` | |
| `quantity` / `unit_price` / `subtotal` | Money columns. |
| `room_id` | |
| `date_from` / `date_to` / `time_from` / `time_to` | |

### `{prefix}tap_agencies`

Agency records.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `user_id` | Linked WP user. |
| `name` / `slug` / `description` / `logo_id` | Profile. |
| `email` / `phone` / `whatsapp` / `website` | Contact. |
| `address` / `city` / `country` | Location. |
| `commission_percent` | Default 10%. |
| `is_verified` / `is_active` | Moderation flags. |

### `{prefix}tap_reviews`

Client reviews + platform (admin) replies.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `service_type` / `service_id` / `user_id` | |
| `booking_id` | Optional link to a booking. |
| `rating` | decimal(2,1) star value. |
| `title` / `content` | |
| `is_approved` | Moderation flag. |
| `reply` / `reply_author` / `reply_at` | Platform reply (admin, V1+). |

### `{prefix}tap_availability`

Per-date availability and price modifiers for a service.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `service_type` / `service_id` | |
| `date` | |
| `available` | int |
| `price_modifier` | decimal NULL |
| `is_blocked` | tinyint |

### `{prefix}tap_daily_pricing`

Per-room daily pricing, minimum stays, and blocked days.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `room_id` / `date` | |
| `price` / `min_stay` | |
| `is_blocked` / `label` | |
| Unique key: `room_id + date` | |

### `{prefix}tap_commission_payments`

Commission settlement ledger.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `agency_id` / `amount` | |
| `booking_ids` | text |
| `method` / `note` | |
| `created_by` / `created_at` | |

### `{prefix}tap_plans`

Subscription plans (seeded: Gratis / Básico / Pro).

| Column | Notes |
| --- | --- |
| `id` / `name` / `slug` | Slug is unique. |
| `price_monthly` | Monthly price. |
| `commission_rate` | `NULL` = keep agency/global commission; otherwise overrides while active. |
| `listing_limit` | `-1` = unlimited. |
| `featured_slots` | Featured slots included. |
| `features` | JSON array of feature strings. |
| `is_active` / `created_at` | |

### `{prefix}tap_agency_subscriptions`

Agency subscription lifecycle (`pending` → `active` → `expired`).

| Column | Notes |
| --- | --- |
| `id` / `agency_id` / `plan_id` | |
| `status` | `pending`, `active`, `expired`. |
| `paid_until` | Active-until date (extended on renewal). |
| `payment_status` / `payment_method` | `manual` marking of payments. |
| `notes` / `created_at` / `updated_at` | |

### `{prefix}tap_promos`

Featured-listing promotions (`pending` → `active` → `expired`).

| Column | Notes |
| --- | --- |
| `id` / `agency_id` / `listing_id` | Listing is any service post type. |
| `months` / `amount` | Amount = months × `tap_featured_price`. |
| `status` | `pending`, `active`, `expired`. |
| `paid_until` | Featured-until date (extended on renewal). |
| `payment_status` / `payment_method` | `manual` marking of payments. |
| `notes` / `created_at` / `updated_at` | |

Promotion activation sets the listing's `_tap_{type}_is_featured` to `1` and `_tap_{type}_featured_until` to the expiry date; expiry (auto or manual) clears both. Global price lives in the `tap_featured_price` option.

### `{prefix}tap_payment_orders`

Maps PayPal Checkout orders to subscription/promotion records for webhook routing.

| Column | Notes |
| --- | --- |
| `id` | Primary key. |
| `paypal_order_id` | Unique PayPal order id. |
| `object_type` | `subscription` or `promotion`. |
| `object_id` | Row id in `tap_agency_subscriptions` / `tap_promos`. |
| `amount` / `status` / `capture_id` | Charge amount, lifecycle (`created`/`completed`), PayPal capture id. |
| `created_at` / `updated_at` | |

`TAP_Payment::record_order()` upserts by `paypal_order_id`; `resolve_order()` looks up the object to finalize on capture.

### `{prefix}tap_listing_views`

Per-day listing view counts (analytics).

| Column | Notes |
| --- | --- |
| `id` | Primary key. |
| `listing_id` | Any service post ID. |
| `service_type` | Canonical `tap_*` type (denormalized for reporting). |
| `view_date` | `DATE`, part of the unique key. |
| `views` | Daily count. |

Unique key `listing_id + view_date`; writes come from `TAP_Analytics::record_view()` (5-min throttle), reads from `total_views()` / `listing_views()`.

### `{prefix}tap_leads`

Contact leads from visitors to agencies (monetization input).

| Column | Notes |
| --- | --- |
| `id` | PK |
| `agency_id` | Owning agency (validated active on submit). |
| `service_id` | NULL unless the form was submitted from a service detail page. |
| `name` / `email` / `phone` | Contact data (name/email required). |
| `message` | Free text (≤ 2000 chars). |
| `ip` / `source` | Submission IP and `agency`/`service` source. |
| `created_at` | |

Validation and rate limiting live in `TAP_Leads::submit()` (5 emails / 10 IPs per hour, keyed with `DATE_SUB(NOW(), INTERVAL 1 HOUR)` so *server* time is used). Submission is CLI-safe (never calls `wp_send_json_*`/`wp_die`).

### Database Migrations

Migrations live in `TAP_Installer::migrate()` and run from `create_tables()`.

- Run on **activation** and whenever `tap_version` differs from `TAP_VERSION` (see `TravelAgencyPlatform::maybe_update_tables()`).
- Each migration is **idempotent** — it checks for the presence of a column/table before altering, so it is safe to run repeatedly.

To add a migration:

1. Append the guard + `ALTER TABLE`/`CREATE TABLE` in `TAP_Installer::migrate()`.
2. Bump `TAP_VERSION`.
3. Document the change in [`docs/CHANGELOG.md`](CHANGELOG.md).

---

## Public API

### REST API (`tap/v1`)

Registered in `TAP_API::register_routes()`. Most read endpoints are public (`__return_true`).

| Route | Methods | Description |
| --- | --- | --- |
| `/services` | GET | List services (all types). |
| `/services/{type}` | GET | List by type. |
| `/services/{type}/{id}` | GET | Single service detail. |
| `/agencies` | GET | List agencies. |
| `/agencies/{id}` | GET | Agency detail. |
| `/search` | GET | Full-text / filtered search. |
| `/booking` | POST | Create a booking. |
| `/booking/{id}` | GET | Booking detail. |
| `/my-bookings` | GET | Current user's bookings. |
| `/locations` | GET | Available locations. |
| `/reviews/{type}/{id}` | GET | Reviews for a service. |
| `/review` | POST | Submit a review. |
| `/availability/{type}/{id}` | GET | Per-date availability. |

**Auth guards** (verified by `suite_rest`): `/booking` POST and `/review` POST require a logged-in user (`rest_forbidden` 401 otherwise); `GET /booking/{id}` enforces ownership — both `client_id` and `get_current_user_id()` are compared as integers, and a non-owner gets `forbidden` 403. Only approved reviews (`is_approved = 1`) are served by `/reviews/{type}/{id}`; `/search` returns only listings whose `_tap_{prefix}_is_active = '1'`.

### AJAX endpoints

Registered in `TravelAgencyPlatform::init_hooks()` via `admin-ajax.php`. Public (guest) endpoints are additionally registered with the `nopriv` suffix.

| Action | Handler | Public | Purpose |
| --- | --- | --- | --- |
| `tap_booking_create` | `TAP_Ajax::create_booking` | ✅ | Create a booking. |
| `tap_cancel_booking` | `TAP_Ajax::cancel_booking` | ✅ | Client cancels a booking (R2). |
| `tap_search_services` | `TAP_Ajax::search_services` | ✅ | Filtered search. |
| `tap_create_paypal_order` | `TAP_Ajax::create_paypal_order` | ✅ | Start PayPal checkout. |
| `tap_capture_paypal_order` | `TAP_Ajax::capture_paypal_order` | ✅ | Capture PayPal payment. |
| `tap_calculate_booking_total` | `TAP_Ajax::calculate_booking_total` | ✅ | Live price breakdown. Returns `subtotal`, `fee`, and `total` (subtotal + booking fee, M2). |
| `tap_get_rooms` | `TAP_Ajax::get_rooms` | ✅ | Rooms for an accommodation. |
| `tap_get_public_pricing` | `TAP_Ajax::get_public_pricing` | ✅ | Public pricing for a date range. |
| `tap_search_suggestions` | `TAP_Ajax::search_suggestions` | ✅ | Autocomplete suggestions. |
| `tap_update_booking_status` | `TAP_Ajax::update_booking_status` | — | Admin status updates. |
| `tap_agency_register` | `TAP_Ajax::agency_register` | ✅ | Agency sign-up. |
| `tap_agency_save_listing` | `TAP_Ajax::agency_save_listing` | — | Agency saves inventory. |
| `tap_agency_save_room` | `TAP_Ajax::agency_save_room` | — | Agency saves a room. |
| `tap_agency_delete_room` | `TAP_Ajax::agency_delete_room` | — | Agency removes a room. |
| `tap_toggle_favorite` | `TAP_Ajax::toggle_favorite` | — | Add/remove favorite. |
| `tap_agency_subscribe` | `TAP_Ajax::agency_subscribe` | — | Agency subscribes to a plan (creates a pending subscription). |
| `tap_promo_request` | `TAP_Ajax::promo_request` | — | Agency requests a featured promotion (creates a pending promo). |
| `tap_lead_submit` | `TAP_Ajax::lead_submit` | ✅ | Visitor sends a contact lead to an agency. |

**Guest mode**

Public booking/cancel/paypal endpoints work for **logged-out visitors** too:

- A guest booking is stored with `client_id = 0` plus `guest_name`/`guest_email`/`guest_phone`.
- The voucher (`[tap_booking_detail]`) and checkout (`[tap_checkout]`) only disclose the booking when `?code=` matches **and** the posted email equals `guest_email` ("no coincide" otherwise).
- Payment is gated behind a short-lived proof token: `TAP_Ajax::set_guest_pay_token($booking_code)` (30-min transient `tap_guest_pay_{code}`) is set after email verification and checked by the PayPal capture flow.
- Guest cancellation is allowed with `client_cancel_request($id, 0, $guest_email)`; cross-ownership is refused both ways (`forbidden` / `no_user`).
- Guest checkout is rate-limited by email+IP via `guest_book_rate_bump()` / `guest_book_rate_blocked()` (max 5 per 15 minutes, transient `tap_guest_book_{hash}`).

**Nonce handling**

- Booking/cancellation endpoints verify the nonce produced by `wp_create_nonce('tap_booking_nonce')`.
- Admin back-office booking actions (status change / cancel / mark paid) verify `tap_admin_booking` (`assets/js/admin.js`), while the **front-dash** agency/clients operations (`tap_dash_*` AJAX: cancel, toggle favorite, delete review, agency booking ops, agency payout) verify `tap_front_dash_nonce`.
- Public endpoints further enforce that guests are logged in where required.

**Anti-spam (H1)**

Public write forms include a hidden `tap_hp` honeypot field. If it contains a value, the request (booking or registration) is silently discarded.

---

## Shortcodes

Registered in `TAP_Shortcodes::init()`.

| Shortcode | Description |
| --- | --- |
| `[tap_search]` | Search form. |
| `[tap_services]` | Service listings. |
| `[tap_service_detail]` | Single service renderer. |
| `[tap_agencies]` | Agency listing. |
| `[tap_agency_detail]` | Agency page. |
| `[tap_booking_form]` | Reservation form. |
| `[tap_my_bookings]` | Client's bookings (+ cancel controls). |
| `[tap_dashboard]` | Client/legacy dashboard shortcode (page `dashboard` 301-redirects to `/mi-cuenta/`). |
| `[tap_front_dash]` | Unified user/agency hub at `/mi-cuenta/` (`TAP_Front_Dash`, role-aware: client, agency, admin hint). |
| `[tap_featured]` | Featured services. |
| `[tap_agency_services]` | Services of an agency. |
| `[tap_agency_register]` | Agency registration. |
| `[tap_agency_manage]` | Agency inventory manager. |
| `[tap_booking_detail]` | Voucher page (accepts `?code=`). |
| `[tap_favorites]` | Client wishlist. |
| `[tap_checkout]` | Checkout flow. |
| `[tap_plans]` | Subscription plans (renders plan cards + subscribe buttons). |
| `[tap_search_results]` | Renders filtered results (reads `keyword`/`type`/`location` GET params) featured-first, cut into cards; used on the `search-results` page with `[tap_search]`. |
| `[tap_lead_form]` | Contact form for an agency (profile page or service detail); posts to the `tap_lead_submit` AJAX endpoint. |
| `[tap_chatbot]` | Fase 4 support-chat widget: toggleable dialog with bilingual assistant, quick-question chips, and an input that posts to `tap_chatbot_message` (rate-limited). |
| `[tap_privacy]` | Privacy rights page (Ley 8968): Access, Rectification, Update, Erasure, Opposition; saves requests into `tap_privacy_requests`. |
| `[tap_privacy_consent]` | Standalone consent checkbox for forms (records rows in `tap_consents`). |
| `[tap_itinerary_builder]` | Trip builder UI (page `/armar-mi-viaje`) combining catalog services into a plan. |

> The **search-results** page should contain `[tap_search]` followed by `[tap_search_results]`. Search is a GET to the page; the form's destination field has an autocomplete wired to `tap_search_suggestions` (shared with the hero `#hs-destino`).

### Fase 4 — Chatbot (`TAP_Chatbot`)

Deterministic, multilingual (ES/EN) rules engine in `includes/class-chatbot.php`, **no external LLM**. Extensible via the `tap_chatbot_provider` filter (not yet hooked by default).

- `TAP_Chatbot::answer($message)` → `{intent, reply, links[], suggestions[]}`. Matching is accent-insensitive (manual `strtr` strip, no intl dependency), lowercase, punctuation-stripped; each intent defines weighted regex patterns and the highest-scoring intent wins (`fallback` otherwise). Replies + link labels are English msgids of the `travel-agency-platform` domain, so the front-end locale renders them in Spanish or `?lang=en` English.
- Intents: `greeting`, `booking`, `search`, `checkout`, `payment`, `cancel`, `agency`, `favorites`, `contact`, `availability`, `pricing`, `recommend` (lists up to 3 recent published services, excluding `tap_room`), `fallback`.
- Endpoints: `wp_ajax_(nopriv_)tap_chatbot_message` (`TAP_Ajax::chatbot_message`) — nonce `tap_nonce`, per-IP rate limit (12 msgs / 10 min via transient), empty-message guard. Sends the response in the front-end language because replies are translated by `__()` at render time.
- Logging: `{prefix}tap_chat_events` stores only `intent`, `lang`, `created_at` aggregates (no PII; `fallback` is never logged).
- Widget: `[tap_chatbot]` renders `.tap-chat` markup (bufferized return), styled in `assets/css/public.css`, wired in `assets/js/public.js` (`Chat` module: toggle, focus management, chips, AJAX send with DOM-safe rendering — no user input flows through `innerHTML`). UI labels live in the `tapI18n` bundle (`chatLabel`, `chatOpen`, `chatPlaceholder`, `chatSend`, `chatIntro`, `chatThinking`, `chatError`).

### Fase 4 — Moderation (`TAP_Moderation`)

In `includes/class-moderation.php`. `TAP_Moderation::assess($text, $kind)` classifies every visitor-generated item as `ok` / `review` / `block` with a short reason code (`abuse|pii|spam|links|gibberish`):

- **Reviews** (`tap/v1/review`, `TAP_API::submit_review`): abuse / personal data / spam / 3+ URLs → HTTP 400 `review_blocked` (nothing stored); 1–2 URLs → stored `mod_status=review` (still hidden: `is_approved=0`); clean → `ok`. The public message switches to *pending moderation* when flagged.
- **Leads** (`TAP_Leads::submit`): spam → `WP_Error('lead_blocked')`; URL/PII-bearing → stored `mod_status=review`; clean → `ok`.
- **Storage**: `mod_status varchar(20) default 'ok'` + `mod_reason varchar(50)` on `{prefix}tap_reviews` and `{prefix}tap_leads` (added in `TAP_Installer::migrate()` and in the fresh-install `CREATE TABLE`); `tap_version` tracks schema migrations.
- **Admin queue**: `Travel Platform → Moderación` (`manage_options`, `TAP_Dashboard::moderation_page`) lists flagged rows from both tables with translated reason labels; nonce-verified actions approve (publish / clear), manual-block, or delete via `TAP_Moderation::handle_admin_actions()` on `admin_init`.

---

## Search Engine Optimization (`TAP_SEO`)

Registered in `travel-agency-platform.php` via `add_action('init', ['TAP_SEO','init'], 13)` which hooks `wp_head` and `document_title_parts`.

- **Singular services** (`tap_accommodation`, `tap_tour`, `tap_transport`, `tap_car_rental`, `tap_boat`, `tap_package`): canonical, meta description (~155 chars, from `_tap_seo_description` with excerpt/content fallback), Open Graph, Twitter Card, JSON-LD, and BreadcrumbList.
- **JSON-LD types**: accommodation `Hotel` (with `PostalAddress`, optional `geo` from stored `_tap_acc_lat`/`_tap_acc_lng` — no in-head geocoding, and `TravelAgency` provider when `_tap_acc_agency_id` set); tours `TouristTrip` with provider; everything else `Product`. `offers` emitted when a price exists, using the global `tap_currency`.
- **Agencies** (`tap_agency`): canonical, meta description, Open Graph `og:type=profile`, JSON-LD `TravelAgency` (email/phone/website/address) and BreadcrumbList.
- **Archives** (all service types, agencies, and tax terms): canonical, meta description, and Open Graph with humanized titles (see `TAP_SEO::archive_map()`).
- `document_title_parts` humanizes archive `<title>` and appends `- Página N` on pagination.
- Optional per-listing override fields `_tap_seo_title` / `_tap_seo_description` (agencies too).
- **Visible breadcrumbs**: `TAP_SEO::visible_breadcrumbs($post)` returns an HTML `<nav class="tap-breadcrumbs">`; it is echoed by the `[tap_service_detail]` and `[tap_agency_detail]` shortcodes (mirrors the BreadcrumbList JSON-LD).
- **FAQ rich results**: listings emit an `FAQPage` JSON-LD block (booking, cancellation, pricing, operator, payment).
- **Contact rich results**: service listings and agencies emit `ContactPoint`/`telephone` when a phone is configured (agency phone, else `tap_support_phone` option).

## Sitemaps (`TAP_Sitemap`)

Rather than a custom rewrite (which collides with WP Core's `/sitemap.xml` + 301s), the plugin **extends WordPress' native XML sitemaps**:

- `wp_sitemaps_post_types` → ensures all bookable service types (incl. `tap_equipment`) are covered.
- `wp_sitemaps_taxonomies` → ensures `tap_location`, `tap_service_cat`, `tap_property_type`, `tap_amenity`, `tap_tour_type`, `tap_vehicle_type`, `tap_boat_type` are covered.
- Empty post types/taxonomies correctly produce no sitemap pages.
- `robots.txt` references the sitemap via `do_robots` (`/wp-sitemap.xml`).

The sitemap is served at the WordPress-standard `/sitemap.xml` (redirects to `/wp-sitemap.xml`) with no additional setup.

---

## Hooks

The platform exposes a small, stable set of hooks for extension.

### Actions

| Hook | When |
| --- | --- |
| `tap_booking_status_updated` | After a booking status change. Receives `(booking_id, status)`. |
| `tap_maintenance_hook` | Hourly scheduled maintenance (stale-booking cleanup). |

### Filters

| Filter | When |
| --- | --- |
| `posts_clauses` | Used internally for rating-based ordering of archives. |

> Event-driven integrations (email, notifications) can hook `tap_booking_status_updated` rather than modifying the core classes.

---

## Currency & Money Handling

`TAP_Currency` centralizes all money formatting.

- **Decimals** are derived per currency code — **JPY → 0**, all other supported codes → **2**.
- `fmt($amount, $decimals = null)` formats a value with symbol and localized decimals.
- `fmt0($amount)` renders integer-style amounts.
- `symbol()` / `code()` / `decimals()` expose the active currency to the front-end.

> **Always use `TAP_Currency` for display** to keep formatting consistent across search, cards, vouchers, and emails.

---

## Monetization & the Booking Fee

The platform monetizes through (1) **agency commissions**, (2) an optional **client booking fee**, and (3) **agency subscription plans**.

- **Booking fee settings** — `tap_booking_fee_type` (`none` | `fixed` | `percent`) and `tap_booking_fee_value` (amount or percentage), registered in `TAP_Admin_Dashboard::register_settings()` under the `tap_settings` group.
- **Booking fee computation** — `TAP_Booking::get_booking_fee($subtotal)` returns the fee for a service subtotal.
- **Total semantics** — `total_amount` = service subtotal + booking fee. `booking_fee` is stored separately so the agency commission is always calculated on the **subtotal** (fee belongs to the platform, never to the agency). Agency net = `total_amount − booking_fee − commission_amount`.
- **Subscription plans** — `TAP_Subscriptions::active_plan($agency_id)` returns the active plan (or the free default). While active, its nonzero `commission_rate` overrides the agency commission in `TAP_Booking::get_agency_commission()`, and its `listing_limit` is enforced when the agency creates listings. Administration is manual: agencies request via `tap_agency_subscribe` (pending), admins confirm via the **Subscriptions** admin page (`TAP_Subscriptions::mark_paid()`), and expiry is automatic (`expire_active()`, run from an `init` transient guard).
- **Featured promotions** — `TAP_Promotions::request($agency_id, $listing_id, $months)` validates ownership, plan featured slots (`TAP_Subscriptions::active_plan()->featured_slots`, so the free plan blocks promotions), and prevents duplicate pending requests. Admins confirm via `TAP_Promotions::activate()` (sets `_tap_{type}_is_featured` + `_tap_{type}_featured_until`), and `expire_active()` on an `init` transient unfeatures expired listings. Price comes from the `tap_featured_price` option. Featured is promo-driven only — there is no free-form editor checkbox anymore.
- **Online payments (PayPal)** — `TAP_PayPal` implements Checkout v2: `get_access_token()`, `create_order()` (bookings), `create_order_generic()` (subscriptions & promotions), `capture_order()`, `verify_webhook()`, `refund_capture()`. Credentials come from options (`tap_paypal_client_id`, `tap_paypal_secret`, `tap_paypal_sandbox`, `tap_paypal_webhook_id`); `is_ready()` gates the buttons. Payment <-> object association for non-booking charges uses the `tap_payment_orders` ledger (`TAP_Payment::record_order()` / `resolve_order()`).
- **Payment webhooks** — `TAP_Payment::handle_paypal_webhook()` (REST `tap/v1/paypal-webhook`) verifies signatures and routes events: `PAYMENT.CAPTURE.COMPLETED` marks bookings paid/confirmed and, for subscription/promotion orders, routes to `TAP_Payment::confirm_subscription_payment()` / `confirm_promotion_payment()` which call `TAP_Subscriptions::mark_paid()` / `TAP_Promotions::activate()`.
- **AJAX payment endpoints** — booking: `tap_create_paypal_order` / `tap_capture_paypal_order`. Subscription: `tap_subscribe_paypal` / `tap_capture_subscription_paypal`. Promotion: `tap_promo_paypal` / `tap_capture_promo_paypal`. All are nonce-protected and agency-gated. The **manual** admin-confirmation flow (`tap_agency_subscribe`, `tap_promo_request`) remains as a fallback whenever PayPal is not ready.
- **Reports** — `TAP_Admin_Dashboard::reports_page()` shows GMV, retained commissions, booking fees, and confirmed promotion revenue; `get_booking_stats()` exposes `revenue`, `commission`, `net`, and `fees`.
- **Analytics** — `TAP_Dashboard::analytics_page()` (admin page `tap-analytics`, capability `tap_view_reports`) aggregates platform revenue across `tap_bookings` (commissions + booking fees, excluding `cancelled`/`refunded`), `tap_agency_subscriptions` (active plan MRR; paid subscriptions counted in the month of `created_at`), and `tap_promos` (paid promotions counted in the month of `updated_at`). It renders KPI cards, a 12-month stacked chart, top agencies (agency name resolved from the `tap_agency` post title joined on `agency_id`), top listings, and supports a `YYYY-MM` period filter (`tap_from`/`tap_to`). Two nonce-protected CSV exports are available: `export=bookings` (booking-level detail with fee/commission/net) and `export=summary` (monthly financial summary, now including views), handled by the private `analytics_csv_export()` before any HTML output.
- **Featured-first ordering** — `TAP_Promotions::prefix_for_type()` maps each `tap_*` type to its canonical meta prefix (`_tap_acc_`, `_tap_tour_`, `_tap_trans_`, `_tap_car_`, `_tap_boat_`, `_tap_pkg_`); `keys_for_type()` derives all promotion meta keys from it. `TAP_Ajax::featured_sort_clauses()` hooks `posts_clauses` and prepends a `CASE WHEN featured THEN 0 ELSE 1 END` ordering term so featured listings sort first in archives and search without disturbing the user-chosen order.
- **Listing views** — `TAP_Analytics` hooks `template_redirect`, and on singular service pages upserts a per-day row in `tap_listing_views` (keyed `listing_id + view_date`). A 5-minute transient per user+listing throttles writes (`tap_view_{user}_{listing}`). Totals come from `TAP_Analytics::total_views($from, $to)` and `listing_views($from, $to)`; the Analytics page derives the **Conversión vistas → reservas** KPI by dividing period bookings by period views.
- **Contact leads** — `TAP_Leads::submit()` stores visitor messages in `tap_leads` and fires `tap_lead_created` (wired to `TAP_Emails` for the agency notification). The `[tap_lead_form]` shortcode posts to `tap_lead_submit` (nonce `tap_lead_nonce`); the agency dashboard lists leads and exports them as CSV (`tap_export_leads` admin-post).
- **Commission book** — commissions screen (`tap-commissions`) settles either via the bulk checkbox flow (one payment row per agency) or per-booking (`tap_settle_booking` POST, nonce `tap_settle_booking`) which liquidates a single booking's commission directly. Both write `tap_commission_payments` and flip `commission_status → paid`; `tap_commission_paid` fires with `(agency_id, payment_id)`. Settlement history resolves `booking_ids` back to booking codes. The agency panel lists the last 30 commission-generating bookings with status pills.
- **Tests** — `tests/run.sh` executes the WP-CLI suites in `tests/` against a real install. Target the travel site with `SITE=travel ./tests/run.sh` or the second site with `SITE=ivanchodev ./tests/run.sh`; override `WP_PATH`/`WP_BIN`/`SUITES` as needed. The default battery runs **20 suites** (`suite_core`, `suite_bookings`, `suite_commissions`, `suite_promotions`, `suite_views`, `suite_analytics`, `suite_payments`, `suite_guest_checkout`, `suite_leads`, `suite_booking_flow`, `suite_pricing`, `suite_paypal`, `suite_rest`, `suite_reviews`, `suite_i18n`, `suite_bugs`, `suite_seo`, `suite_agency_manage`, `suite_chatbot`, `suite_moderation`). `tests/bootstrap.php` ships shared helpers (site-agnostic service/user discovery, a PayPal `pre_http_request` mock, an in-process REST dispatcher with `tap_t_rest_error_code()` normalization, and cleanup helpers). Every suite **seeds and then deletes its own rows** — the battery is verified residue-free on both installs.
- **Adding a fee type** — extend `get_booking_fee()` and mirror the value in `calculate_booking_total` so the front-end breakdown stays consistent with the persisted booking.
- **Adding a plan** — insert into `tap_plans` (or seed via `TAP_Installer::migrate()`); optional `commission_rate`, `listing_limit` (`-1` = unlimited), and `featured_slots` then take effect automatically.

---

## Booking Lifecycle

```
pending  →  confirmed  →  completed
              │
              └─> cancelled    (client R2 / admin / system, or stale cleanup)
```

- **Status** (`status`) describes the reservation state.
- **Payment status** (`payment_status`) is tracked independently (`pending` → `paid` → `refunded`).

**Client cancellation guard** (`TAP_Booking::client_cancel_request()`):

1. Caller must own the booking (`client_id`), unless it is a **guest** booking (`client_id = 0`) — then the third argument `$guest_email` must match `guest_email`.
2. Status must be `pending` or `confirmed`.
3. `check_in` must not be in the past.
4. On success: status → `cancelled`, `cancel_requested_at` set, `cancelled_by = client`; paid bookings are flagged `refunded`.

**Capacity / inventory** (`TAP_Booking::create()`):

- **Tours**: `tour_slots()` enforces per-date `_tap_tour_capacity`.
- **Rooms**: `get_room_availability()` enforces `_tap_room_inventory` and blocked dates; overlapping stays are refused when inventory is exhausted.

---

## Multilingual Interface (i18n)

The visitor-facing UI is **bilingual (ES/EN)** with **Spanish as the default**. The engine is `TAP_Localization` (`includes/class-localization.php`).

- **Default locale** — a `locale` + `determine_locale` filter force the **public front-end** to `es_ES` regardless of the WordPress site locale. The **admin** and WP-CLI context are unaffected (they keep `en_US`), so backend screens and the E2E batteries stay as before.
- **Switching** — visitors choose English via `?lang=en` (URL param), the `tap_lang` cookie, or their `tap_lang` user meta. `TAP_Localization::set_lang($lang)` persists the choice and calls `switch_to_locale()` + a textdomain reload; `current_lang()` returns `es` | `en`; unsupported codes are rejected.
- **Switcher** — `[tap_lang_switcher]` renders ES/EN links (labels `ES`/`EN`) preserving the current URL and marks the active language with `is-active`; optional `class` attribute.
- **Message catalogs** (`languages/`, compiled with `msgfmt`, not WP's built-in i18n generator):
  - `travel-agency-platform-es_ES.mo` — English **source msgids → Spanish**, making the default Spanish UI fully translated.
  - `travel-agency-platform-en_US.mo` — Spanish **source msgids → English**, completing the English UI when switched.
  - Both catalogs are **generated** from `/tmp/opencode/dictionaries.py` + `gen_mo.py`; the `.po` files live next to the `.mo` files for reference.
- **Authoring strings** — all visitor-facing strings use `__()/esc_html__()/esc_html_e()` (and `esc_attr__()` for visible attributes) with the `travel-agency-platform` domain. **The msgid is the Spanish literal** (byte-exact), so the default rendering never changes. When a new string is added: wrap it, add the msgid + English translation to `EN_US` in `dictionaries.py`, recompile both `.mo` files, and re-run `suite_i18n`.
- **Scope** — the default/es catalogs cover the plugin's public shortcodes, emails, booking/AJAX/REST messages, and the theme templates/parts. **Content** (service titles, descriptions) is intentionally **not** translated (bilingual content is a later iteration); `TAP_Localization::set_lang()` unloads/reloads only the TEXT domain, it does not switch custom-fields.
- **Regression** — `tests/suite_i18n.php` asserts default `es_ES`, `EN→ES` fallback, `set_lang('en')` → `en_US`, `ES→EN` translation, switcher markup, invalid-lang rejection, and the back-to-Spanish round-trip.

---

## Common Development Tasks

**Add a new service type**

1. Register a `tap_*` post type in `TAP_PostTypes`.
2. Add taxonomy mappings in `TAP_Taxonomies`.
3. Add meta fields in `TAP_Metaboxes`.
4. Extend price calculation in `TAP_Booking::calculate_price()`.
5. Add a theme template / template-part for rendering.

**Add a DB column**

1. Append an idempotent migration in `TAP_Installer::migrate()`.
2. Bump `TAP_VERSION`.
3. Update the schema tables above and the changelog.

**Add an AJAX endpoint**

1. Add a static handler in `TAP_Ajax`.
2. Register `wp_ajax_*` (and `wp_ajax_nopriv_*` if public) in `TravelAgencyPlatform::init_hooks()`.
3. Localize the nonce if required by the front-end.

**Enforce a new booking rule**

1. Add the validation in `TAP_Booking::create()` before the insert; return a `WP_Error` with a clear message.
2. Surface the message in the front-end AJAX form.

---

## Conventions

- All classes are prefixed `TAP_`; public methods are `public static`.
- All public-facing text is translatable via the `travel-agency-platform` text domain.
- All database access goes through `$wpdb` (never raw `mysqli`).
- Money is stored as `decimal(15,2)` and formatted with `TAP_Currency`.
- Migrations must remain **idempotent**.
