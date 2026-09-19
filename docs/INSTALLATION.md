# Installation & Configuration

This guide covers installing, configuring, and deploying the **Travel Agency Platform** on a WordPress site.

---

## 1. Requirements

| Component | Minimum |
| --- | --- |
| WordPress | 5.8+ |
| PHP | 7.4+ (8.0 recommended) |
| MySQL | 5.7+ / MariaDB 10.2+ |
| Browsers | modern evergreen (Chrome, Firefox, Safari, Edge) |
| PayPal | Sandbox credentials for testing, Live for production |

> **Note:** WordPress core, the uploads directory, and the database are **not** distributed with this repository. Install them first, then copy the plugin and theme into place (see below).

---

## 2. Installation

### 2.1 Copy the product files

From a release/copy of this repository, place the two directories into a running WordPress site:

```
wp-content/plugins/travel-agency-platform/
wp-content/themes/travel-agency-theme/
```

The intended layout is:

```
<wordpress-root>/
└── wp-content/
    ├── plugins/travel-agency-platform/   # plugin
    └── themes/travel-agency-theme/       # theme
```

### 2.2 Enable plugin & theme

1. Log in to **wp-admin**.
2. Go to **Plugins → Installed Plugins** and activate **Travel Agency Platform**.
3. Go to **Appearance → Themes** and activate **Travel Agency Theme**.

### 2.3 Automatic activation tasks

On activation the plugin automatically:

- Registers custom post types, taxonomies, and user roles.
- **Creates/updates** the custom database tables.
- Runs schema migrations to the current `1.5.7` layout (idempotent).
- Flushes rewrite rules and schedules the hourly maintenance task.

No manual SQL is required.

---

## 3. Permalink & Rewrite Configuration

Because the platform exposes search and archive endpoints, use a **pretty permalink** structure:

1. Go to **Settings → Permalinks**.
2. Select **Post name** (recommended) or a custom structure.
3. Save. This flushes rewrite rules for the custom post types.

---

## 4. Configuration

### 4.1 Required pages

Create the following pages and insert the provided shortcodes. Refer to [`docs/DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md#shortcodes) for the full list.

| Page slug | Shortcode / content |
| --- | --- |
| `search-results` | `[tap_search]` + results template (theme handles rendering) |
| `my-bookings` | `[tap_my_bookings]` |
| `booking-detail` | `[tap_booking_detail]` (voucher, accepts `?code=`) |
| `checkout` | `[tap_checkout]` |
| `mi-cuenta` | `[tap_front_dash]` (unified user/agency hub rendered by `TAP_Front_Dash`); legacy `[tap_dashboard]` page `dashboard` 301-redirects here |
| `armar-mi-viaje` | Trip builder (itinerary UI rendered by `TAP_Itinerary`) |
| `dashboard` | `[tap_dashboard]` |
| `agency-register` | `[tap_agency_register]` |
| `agency-manage` | `[tap_agency_manage]` |
| `favorites` | `[tap_favorites]` |
| `planes` | `[tap_plans]` |
| `privacidad` | `[tap_privacy]` (rights: Acceso, Rectificación, Actualización, Supresión, Oposición) + `[tap_privacy_consent]` on contact forms |
| `chat` | `[tap_chatbot]` (travel assistant) |

### 4.2 Currency

Currency settings affect the public display of prices.

- Configure the **symbol** and **code** (e.g. `$` / `USD`).
- Decimal places are derived automatically from the currency code (JPY → 0; all others → 2).

### 4.3 PayPal

The PayPal gateway requires credentials.

| Option | Purpose |
| --- | --- |
| `tap_paypal_client_id` | PayPal client ID |
| `tap_paypal_secret` | PayPal secret |

**Environment**

- Use **Sandbox** credentials while developing.
- Switch to **Live** credentials only for production.

> To process real payments in a production environment the PayPal account must be approved for live transactions.

### 4.4 Agency commissions

Commissions are calculated as a percentage of the booking total.

- Default commission: **10%** per agency (configurable per agency).
- Each booking stores the applied `commission_percent`, `commission_amount`, and a `commission_status`.
- `commission_status` values: `owed` (**only after the client pays**), `disputed` (frozen by a dispute), `void` (cancelled/refunded booking), `paid` (settled via the commission ledger). Settlement and agency totals only count paid, non-cancelled bookings.

### 4.5 Cancellation policies

Every service type has a default cancellation policy (configurable in **Settings → Cancellation policies**); a listing can override it via its own policy field (legacy accommodation `_tap_acc_cancellation` is honored).

| Policy | Refund |
| --- | --- |
| Flexible | 100% if cancelling ≥ 24h before check-in; 0% after |
| Moderate | 100% (≥ 5 days), 50% (2–5 days), 0% (< 2 days) |
| Strict | 50% (≥ 7 days), 0% after |
| Non-refundable | 0% always |

Client cancellations store `refund_amount`, `refund_percent`, `cancellation_policy` and `refunded_at` on the booking; payment only flips to `refunded` when money is actually returned. Confirmed bookings whose check-out has passed are **auto-completed** by the hourly maintenance task (toggleable via `tap_booking_auto_complete`).

### 4.6 Booking modes & automation

- `booking_mode`: **normal** (auto-confirm on payment when enabled) or **request** (agency must confirm or cancel; the booking stays `request` until the agency acts).
- `tap_stale_booking_hours`: window (default **24 h**) after which `pending` bookings are auto-cancelled by the maintenance task.
- Automation toggles (settings) control email reminders, pre-arrival messages, review requests, subscription/promotion expiry warnings, etc.
- Failed PayPal refunds are retried daily by cron (`tap_refund_retry_hook`).

---

## 5. Roles & Permissions

The plugin registers three roles on activation:

| Role | Capabilities |
| --- | --- |
| `tap_agency_admin` | Manage agency inventory (publish/edit/delete) and panels; must be **approved** (KYC) by an admin before publishing/reserving. |
| `tap_agency_employee` | Staff-level access: can **edit** agency listings; cannot publish, delete, or manage money. |
| `tap_client` | Standard customer: favorites, bookings, reviews. |

Administrators receive all platform management capabilities (`tap_manage_bookings`, `tap_manage_agencies`, `tap_manage_commissions`, `tap_manage_reviews`, `tap_manage_disputes`, `tap_view_reports`, `tap_manage_settings`, and custom post-type CRUD).

> **Consent & moderation**: contact leads require explicit consent (GDPR / Ley 8968); the platform blocks/queues abusive or spam leads automatically. Configure the **Privacy** page and let WordPress manage data requests (Tools → Export/Erase personal data) — the plugin registers its own exporters/erasers for bookings, leads, consents and reviews.

---

## 6. Maintenance

A scheduled task runs every hour and automatically:

- **Cancels stale `pending` bookings** (after `tap_stale_booking_hours`).
- **Auto-completes past `paid` bookings** (when auto-complete is enabled).
- Runs other automation toggles (payment reminders, pre-arrival messages, review requests, expiry warnings).

A separate daily task retries failed PayPal refunds. No user action is required.

---

## 7. Uninstall

Deleting the plugin (via WordPress admin) runs an uninstaller that removes **all** platform data:

- **Custom tables** (17, each verified against the schema before `DROP`): `tap_agencies`, `tap_agency_subscriptions`, `tap_availability`, `tap_booking_items`, `tap_bookings`, `tap_chat_events`, `tap_commission_payments`, `tap_consents`, `tap_daily_pricing`, `tap_disputes`, `tap_leads`, `tap_listing_views`, `tap_payment_orders`, `tap_plans`, `tap_privacy_requests`, `tap_promos`, `tap_reviews`.
- **Cron events**: `tap_maintenance_hook`, `tap_auto_hook`, `tap_refund_retry_hook`.
- **Options & transients** (`tap_%`, `_transient_tap_%`, `_transient_timeout_tap_%`).
- **Roles & caps**: removes `tap_agency_admin`, `tap_agency_employee`, `tap_client` and the `tap_manage_*` caps from `administrator`.
- **Content**: deletes posts of `tap_agency`, `tap_accommodation`, `tap_room`, `tap_tour`, `tap_transport`, `tap_car_rental`, `tap_boat`, `tap_package`, `tap_equipment` plus their postmeta and taxonomy links, and any leftover `_tap_%` postmeta.

> Set the option `tap_uninstall_keep_content = 1` **before** deleting the plugin to keep the `tap_*` posts (tables are still removed).
> ⚠️ Backup before uninstalling: this removes customer bookings, leads and financial records permanently.

---

## 8. Upgrades

1. Back up the site (files + database).
2. Replace the plugin/theme files with the new version (or deploy via the repository).
3. The plugin detects a version change and runs schema migrations automatically on load.
4. Confirm **Settings → Permalinks** → Save, then verify public pages.

See [`docs/CHANGELOG.md`](CHANGELOG.md) for per-version notes and migration implications.

---

## 9. Common Issues

| Symptom | Likely cause | Resolution |
| --- | --- | --- |
| 404 on archive pages | Pretty permalinks not flushed | Re-save **Settings → Permalinks** |
| Currency shows wrong decimals | Unsupported currency code | Verify configured code maps to decimals |
| Payments not completing | Sandbox credentials missing/invalid | Set `tap_paypal_client_id` / `tap_paypal_secret` |
| Roles missing | Installed before activation settled | Deactivate/reactivate the plugin |
