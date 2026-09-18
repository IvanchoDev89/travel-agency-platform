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
| `mi-cuenta` | Page provided by the plugin's unified agency back-office (`TAP_Front_Dash`); legacy `[tap_dashboard]` (page `dashboard`) redirects here |
| `armar-mi-viaje` | Trip builder (itinerary UI rendered by `TAP_Itinerary`) |
| `dashboard` | `[tap_dashboard]` |
| `agency-register` | `[tap_agency_register]` |
| `agency-manage` | `[tap_agency_manage]` |
| `favorites` | `[tap_favorites]` |

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

---

## 5. Roles & Permissions

The plugin registers three roles on activation:

| Role | Capabilities |
| --- | --- |
| `tap_agency_admin` | Manage agency inventory, bookings, and listings. |
| `tap_agency_employee` | Staff-level access to agency resources. |
| `tap_client` | Standard customer: favorites, bookings, reviews. |

Administrators receive all platform management capabilities (`tap_manage_bookings`, `tap_manage_agencies`, `tap_manage_commissions`, `tap_manage_reviews`, `tap_view_reports`, `tap_manage_settings`, and custom post-type CRUD).

---

## 6. Maintenance

A scheduled task runs every hour and automatically **cancels stale pending bookings** (defined by the platform policy). No user action is required.

---

## 7. Upgrades

1. Back up the site (files + database).
2. Replace the plugin/theme files with the new version (or deploy via the repository).
3. The plugin detects a version change and runs schema migrations automatically on load.
4. Confirm **Settings → Permalinks** → Save, then verify public pages.

See [`docs/CHANGELOG.md`](CHANGELOG.md) for per-version notes and migration implications.

---

## 8. Common Issues

| Symptom | Likely cause | Resolution |
| --- | --- | --- |
| 404 on archive pages | Pretty permalinks not flushed | Re-save **Settings → Permalinks** |
| Currency shows wrong decimals | Unsupported currency code | Verify configured code maps to decimals |
| Payments not completing | Sandbox credentials missing/invalid | Set `tap_paypal_client_id` / `tap_paypal_secret` |
| Roles missing | Installed before activation settled | Deactivate/reactivate the plugin |
