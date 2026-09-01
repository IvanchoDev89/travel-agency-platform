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
- [Common Development Tasks](#common-development-tasks)

---

## Architecture

The platform is organized around a set of **single-responsibility classes**, each exposing static methods, loaded by the plugin's main class `TravelAgencyPlatform` (a singleton):

```
TravelAgencyPlatform (bootstraps everything)
 ├─ TAP_Installer   → tables, migrations, activation
 ├─ TAP_PostTypes   → custom post types (services, rooms)
 ├─ TAP_Taxonomies  → classification taxonomies
 ├─ TAP_Roles       → custom roles & capabilities
 ├─ TAP_Metaboxes   → admin meta fields
 ├─ TAP_Pricing     → dynamic/seasonal pricing
 ├─ TAP_Discounts   → discount engine
 ├─ TAP_Booking     → reservations, capacity, cancellation
 ├─ TAP_API         → REST routes & rating aggregation
 ├─ TAP_Shortcodes  → public-facing shortcodes
 ├─ TAP_Ajax        → admin-ajax handlers & archive filters
 ├─ TAP_Dashboard   → agency dashboard
 ├─ TAP_Paypal      → PayPal integration
 ├─ TAP_Payment     → payment statuses
 ├─ TAP_Emails      → email notifications
 ├─ TAP_SEO         → meta/OG/JSON-LD output
 ├─ TAP_Currency    → money formatting
 └─ TAP_Dashboard   → admin reports
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
| `service_type` | varchar(50) | One of the six service types. |
| `service_id` | bigint | Service post ID. |
| `room_id` | bigint NULL | Room post ID (accommodation). |
| `package_id` | bigint NULL | |
| `check_in` | date NULL | |
| `check_out` | date NULL | |
| `adults` | int | |
| `children` | int | |
| `nights` | int | Computed stay length. |
| `bed_config` | text NULL | |
| `total_amount` | decimal(15,2) | |
| `commission_amount` | decimal(15,2) | |
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

Client reviews + agency replies.

| Column | Notes |
| --- | --- |
| `id` | PK |
| `service_type` / `service_id` / `user_id` | |
| `booking_id` | Optional link to a booking. |
| `rating` | decimal(2,1) star value. |
| `title` / `content` | |
| `is_approved` | Moderation flag. |
| `reply` / `reply_author` / `reply_at` | Agency response (V1). |

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

### AJAX endpoints

Registered in `TravelAgencyPlatform::init_hooks()` via `admin-ajax.php`. Public (guest) endpoints are additionally registered with the `nopriv` suffix.

| Action | Handler | Public | Purpose |
| --- | --- | --- | --- |
| `tap_booking_create` | `TAP_Ajax::create_booking` | ✅ | Create a booking. |
| `tap_cancel_booking` | `TAP_Ajax::cancel_booking` | ✅ | Client cancels a booking (R2). |
| `tap_search_services` | `TAP_Ajax::search_services` | ✅ | Filtered search. |
| `tap_create_paypal_order` | `TAP_Ajax::create_paypal_order` | ✅ | Start PayPal checkout. |
| `tap_capture_paypal_order` | `TAP_Ajax::capture_paypal_order` | ✅ | Capture PayPal payment. |
| `tap_calculate_booking_total` | `TAP_Ajax::calculate_booking_total` | ✅ | Live price breakdown. |
| `tap_get_rooms` | `TAP_Ajax::get_rooms` | ✅ | Rooms for an accommodation. |
| `tap_get_public_pricing` | `TAP_Ajax::get_public_pricing` | ✅ | Public pricing for a date range. |
| `tap_search_suggestions` | `TAP_Ajax::search_suggestions` | ✅ | Autocomplete suggestions. |
| `tap_update_booking_status` | `TAP_Ajax::update_booking_status` | — | Admin status updates. |
| `tap_agency_register` | `TAP_Ajax::agency_register` | ✅ | Agency sign-up. |
| `tap_agency_save_listing` | `TAP_Ajax::agency_save_listing` | — | Agency saves inventory. |
| `tap_agency_save_room` | `TAP_Ajax::agency_save_room` | — | Agency saves a room. |
| `tap_agency_delete_room` | `TAP_Ajax::agency_delete_room` | — | Agency removes a room. |
| `tap_toggle_favorite` | `TAP_Ajax::toggle_favorite` | — | Add/remove favorite. |

**Nonce handling**

- Booking/cancellation endpoints verify the nonce produced by `wp_create_nonce('tap_booking_nonce')`.
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
| `[tap_dashboard]` | Agency/admin dashboard. |
| `[tap_featured]` | Featured services. |
| `[tap_agency_services]` | Services of an agency. |
| `[tap_agency_register]` | Agency registration. |
| `[tap_agency_manage]` | Agency inventory manager. |
| `[tap_booking_detail]` | Voucher page (accepts `?code=`). |
| `[tap_favorites]` | Client wishlist. |
| `[tap_checkout]` | Checkout flow. |

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

## Booking Lifecycle

```
pending  →  confirmed  →  completed
              │
              └─> cancelled    (client R2 / admin / system, or stale cleanup)
```

- **Status** (`status`) describes the reservation state.
- **Payment status** (`payment_status`) is tracked independently (`pending` → `paid` → `refunded`).

**Client cancellation guard** (`TAP_Booking::client_cancel_request()`):

1. Caller must own the booking (`client_id`).
2. Status must be `pending` or `confirmed`.
3. `check_in` must not be in the past.
4. On success: status → `cancelled`, `cancel_requested_at` set, `cancelled_by = client`; paid bookings are flagged `refunded`.

**Capacity / inventory** (`TAP_Booking::create()`):

- **Tours**: `tour_slots()` enforces per-date `_tap_tour_capacity`.
- **Rooms**: `get_room_availability()` enforces `_tap_room_inventory` and blocked dates; overlapping stays are refused when inventory is exhausted.

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
