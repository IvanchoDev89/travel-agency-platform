# User Guide

A practical manual for the people using the **Travel Agency Platform** every day: **clients**, **agency staff**, and **administrators**.

---

## Contents

- [For Clients](#for-clients)
  - [Create an account & sign in](#create-an-account--sign-in)
  - [Search & discover services](#search--discover-services)
  - [Favorites (wishlist)](#favorites-wishlist)
  - [Book a service](#book-a-service)
  - [Manage your bookings](#manage-your-bookings)
  - [Cancelling a booking](#cancelling-a-booking)
  - [Vouchers](#vouchers)
  - [Leave a review](#leave-a-review)
- [For Agencies](#for-agencies)
  - [Register & complete your profile](#register--complete-your-profile)
  - [Publish & price inventory](#publish--price-inventory)
  - [Respond to reviews](#respond-to-reviews)
  - [Track bookings & commissions](#track-bookings--commissions)
- [For Administrators](#for-administrators)
  - [Moderate agencies & reviews](#moderate-agencies--reviews)
  - [Manage commissions](#manage-commissions)

---

# For Clients

## Create an account & sign in

1. Open the platform and select **Register** (or the sign-up link).
2. Complete the registration form and submit.
3. Sign in with your credentials to access personalized features.

> Public forms include an invisible **anti-spam** field. It is hidden from humans; if it is filled by automation, the request is silently discarded.

## Search & discover services

- Use the **search box** by destination/keyword.
- Refine results with **filters**:
  - Travel dates (check-in / check-out)
  - Number of guests
  - Service type
  - Star level
  - Price range (min / max per night)
  - Amenities
- **Sort** results by:
  - Relevance (default)
  - Price — lowest to highest
  - Price — highest to lowest
  - Best rating
  - Name (A–Z)
- Toggle between **grid**, **list**, and **map** views. The map shows property locations with clustering for easier browsing.

## Favorites (wishlist)

While signed in:

1. Open any service card.
2. Click the **heart** (♥) icon.
3. Access saved services from the **Favorites** page.

## Book a service

1. Open the service detail page.
2. Choose your dates and number of guests.
   - For **accommodations**, select a room.
   - For **tours**, select a date (capacity is enforced per date).
3. Add the main guest's **name**, **contact email**, and (optionally) **phone**.
4. Review the live **price breakdown** (nights, per-night price, subtotal, discounts, service fee when applicable, and total).
5. Confirm — you will not be charged until you complete pay.
6. Follow the **checkout** flow to finalize payment (when applicable).

> The platform refuses bookings for past dates, invalid date ranges, and dates when capacity/inventory is full.

## Manage your bookings

- Go to **My Bookings** to view your current and past reservations.
- Each booking shows its status, dates, service, and total.
- Open a booking to view its **voucher**.

## Cancelling a booking

A **Cancel** action is available on bookings that can still be cancelled:

- Status must be **Pending** or **Confirmed**.
- The check-in date must not have passed.

To cancel:

1. Open **My Bookings** (or the booking's voucher).
2. Select **Cancel** and confirm.

After cancellation the booking is marked **Cancelled** (and any paid payment is flagged for refund). You cannot cancel a booking that is not yours; you cannot cancel a stay that has already started.

## Vouchers

Each booking has a **booking code** and a dedicated voucher page that contains:

- Booking code and status
- Service details and price breakdown (with per-line items)
- **Titular** (account holder) and **contact guest** (name, email, phone)
- Agency contact information

Present this voucher at check-in. Any changes must be coordinated with the agency.

## Leave a review

After completing a service:

1. Open the service and select **Leave a review ★**.
2. Choose a **star rating**, add a **title** and **comment**.
3. Submit. Reviews are shown publicly once approved by an administrator.

---

# For Agencies

## Register & complete your profile

1. Submit the **agency registration** form.
2. Once an administrator activates your agency, complete your **profile**:
   - Name and slug
   - Description and logo
   - Contact details (email, phone, WhatsApp, website)
   - Location (city, country)
   - Commission rate (set by the administrator)

## Publish & price inventory

From your agency dashboard **manage** your listings across every service type:

- **Accommodations** — create rooms, set nightly prices, occupancy, and inventory per room.
- **Tours** — set per-date capacity and availability.
- **Transports / car rentals / boats / packages** — configure pricing and availability.

Use availability controls to block dates, apply seasonal pricing, or enforce minimum stays where supported.

## Respond to reviews

Agencies can **reply** to client reviews on their services:

1. Open the **Reviews** panel for a service.
2. Select **Reply**.
3. Write your response and save.

The reply (with author and timestamp) is shown alongside the original review. Replies are nonce-protected to prevent unauthorized edits.

## Track bookings & commissions

- Monitor incoming bookings and their statuses.
- Review generated commissions per booking (calculated on the service subtotal, before the client booking fee).
- Commission totals are tracked in the platform's commission ledger for settlement.
- The agency panel shows your **current plan** (plan name, valid until, listings used, and commission rate).
- The **Destacados (promociones)** section lets you promote a listing: choose the number of months and pay. If online payment is configured, an inline **PayPal** button charges and activates the listing instantly; otherwise a manual request is sent for an administrator to confirm. Promotions count against your plan's featured slots; the free plan has none. A **★** badge appears on the listing while it is featured, and it is removed automatically when the promotion expires.

### Paying for a plan / promotion with PayPal

- On the **planes** page, paid plans show a **Pagar con PayPal** button. Completing the PayPal flow activates your plan for a month immediately — no manual confirmation needed. If PayPal is not configured, the **Seleccionar plan** button remains and an administrator confirms the manual payment.
- Promotions are charged the same way: pick months and pay inline with the PayPal button; the listing becomes featured right away.
- PayPal must be configured by an administrator in **Ajustes → PayPal Settings** (Client ID + Secret). Until then, the manual flow is used.
- **Featured** listings appear **first** in archives and search results, ahead of non-featured ones in the same sort order.
- The **Libro de comisiones** section lists your last 30 commission-generating bookings with a status pill per booking: **Cobrada** (settled) or **Por cobrar** (outstanding). Settlement history shows booking codes instead of raw IDs.

---

# For Administrators

## Moderate agencies & reviews

- **Agencies**: activate/deactivate accounts and set commission rates.
- **Reviews**: approve or reject client reviews before they become visible, and monitor agency replies.

## Manage commissions

- Review commission amounts owed per agency and per booking.
- Record commission settlements in the ledger — either **select bookings and register payment**, or click **Liquidar** on an individual booking to settle it directly (opens a dialog for method + note).
- Track `owed` vs. settled commission across the platform.
- Settlement history resolves each payment to the concrete booking codes it covers.

## Manage subscription plans

- **Plans** (admin → Plans): edit each plan's price, commission rate, listing limit, and featured slots.
- **Subscriptions** (admin → Subscriptions): see every agency's current/past subscriptions. When an agency requests a plan, its subscription is **Pending** until a payment is received. With PayPal enabled, agencies pay online and the subscription activates automatically; otherwise confirm the manual payment with **Mark paid** (select renewal months) to activate it, or **Expire** to cancel it. Confirmed payments notify the agency by email, and the plan takes effect immediately.

## Manage featured promotions

- **Promotions** (admin → Promotions): review agency requests (price = months × `tap_featured_price`). With PayPal enabled, agencies pay online and the listing activates automatically; otherwise confirm the manual payment with **Marcar activo**, or **Expirar** promotions early.
- Active promotions mark the listing as **featured** (★) for the paid period; expired promotions remove the badge automatically. Confirmed promotion amounts are shown in **Reports**.

## Configure online payments (PayPal)

- **Ajustes → PayPal Settings** (`travel-agency-platform`): enable **PayPal**, set it to **Sandbox** or **Live** mode, and paste the **Client ID** and **Secret** from your PayPal app (developer dashboard, or live REST API app). Save and set **Webhook ID** to the verification value.
- Webhook URL to register in PayPal: `https://<your-site>/wp-json/tap/v1/paypal-webhook`.
- When ready, agencies see PayPal buttons for plan subscriptions and promotions and pay instantly; the platform records the charge in `tap_commission_payments`/ledger and activates the subscription/promotion automatically.

## View platform analytics

- **Analytics** (admin → Analytics) gives a financial overview of the platform:
  - KPI cards: platform revenue (last 12 months), subscription MRR, filtered GMV, active promotion value, live bookings, average ticket, active agencies, and published listings.
  - A 12-month stacked chart of platform revenue split by **Comisiones**, **Booking fees**, **Suscripciones**, and **Destacados**, plus a revenue-source breakdown table.
  - **Top agencias** (bookings, GMV, platform commission, fees) and **Top listados** (bookings, GMV, **Vistas**, **Conversión**).
  - Use **Desde**/**Hasta** to filter by month (format `YYYY-MM`); KPIs, top tables, and exports respect the filter.
  - **Vistas** counts singular-page views per listing (tracked with a 5-minute throttle per visitor) and **Conversión vistas → reservas** is the ratio of bookings to views.
  - **Exportar reservas (CSV)** downloads booking-level detail (with fee, commission, and net). **Exportar resumen (CSV)** downloads the monthly financial summary including subscriptions, promotions, and views.

---

> For developer-focused details (architecture, hooks, APIs, shortcodes), see [`docs/DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md).
