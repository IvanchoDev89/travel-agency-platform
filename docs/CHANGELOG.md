# Changelog

All notable changes to the **Travel Agency Platform** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to [Semantic Versioning](https://semver.org/).

---

## [1.5.45] — 2026-10-21

Interactive educational destinations map.

### Added

- **Editable destination spots (`tap_spot` CPT).** A new "Destinos" admin post type hosts each protected place — national park, biological reserve, wildlife refuge, volcano, protected beach, archaeological site… — with a rich description, featured image and its own canonical page at `/destino/{slug}/`.
- **Destination types taxonomy (`tap_spot_type`).** Seeded with eight categories (parque nacional, reserva biológica, refugio de vida silvestre, volcán, playa protegida, sitio arqueológico, zona protectora, monumento natural), fully manageable from wp-admin.
- **Educational fields.** Latitude, longitude, province, "datos de interés" (one bullet per line), schedule, fee, area, year established, accessibility and official website — each shown on the place's page and used by the map.
- **`[tap_destinations_map]` shortcode.** A Leaflet + OpenStreetMap map (no API key) with marker clustering, type filter chips with live counts, a linked list of places and an educational detail panel that opens from both the markers and the list.
- **`/destinos/` landing page.** Created once by the plugin and linked into the primary menu; its body holds the shortcode so the intro copy stays editable from the normal editor.
- **Seed content.** Fifteen real Costa Rican places (Volcán Arenal, Tortuguero, Manuel Antonio, Corcovado, Rincón de la Vieja, Poás, Irazú, Chirripó, Cahuita, Marino Ballena, Guayabo, Tenorio, Palo Verde, Isla del Coco, Gandoca-Manzanillo) with approximate coordinates and factual notes. Idempotent, and never overwrites an admin's edits on re-runs.

### Changed

- Plugin version bumped to `1.5.45` (header + `TAP_VERSION`); the map assets load only on the destinations page and each spot's page.

### Tests

- `suite_spots`: CPT/taxonomy registration, idempotent seed, map-data payload (coordinate filtering + type filter), shortcode markup, the editable meta pipeline (multiline interest + coordinate validation) and the page/menu wiring.

---

## [1.5.44] — 2026-10-21

Agency directory polish.

### Changed

- **Cards get a real cover.** The newest published service photo is used as the card cover (lazy-loaded, subtle zoom on hover) with the agency logo overlapping it; agencies without photos get one of five deterministic gradients, so no card looks empty.
- **Location chips and sorting.** Cities are now one-tap chips instead of a dropdown, and the toolbar adds a sort selector: name (A-Z) or most published services first.
- **Richer cards.** The approved-review average is shown with a star and review count, and the service families offered appear as icon pills with their counts.
- **Typography and spacing pass** on the hero, toolbar and cards (tighter heading scale, aligned meta lines, calmer borders).
- **Compact pagination and smarter empty state.** Previous/next arrows with fewer page numbers, and when a filter returns nothing the empty panel suggests the available locations.

### Tests

- `suite_bugs`: location chips, covers, service-type pills, review average and "most services first" ordering (5 assertions fail without the changes).

---

## [1.5.43] — 2026-10-21

Agency directory redesign (`/agency/`).

### Changed

- **The public agency directory is now a real landing page.** The `[tap_agencies]` shortcode renders inside `.tap-container` (the grid used to run edge-to-edge) with an intro, a "list your agency" call to action, a server-side search box and location filter, a matching-count line, pagination and a closing sign-up block.
- **Cards carry the data travelers need**: logo (or the agency initial), verified badge, city/country, a three-line excerpt and the number of published services, with the whole card linking to the profile.
- **Accessibility and responsive behaviour**: labelled search controls (visually hidden labels), 44px targets, visible focus rings, `aria` labels on pagination, single-column layout on small screens, lazy thumbnails.
- **Empty state**: a clear "no agencies found" panel with a way back to the full directory.

### Fixed

- **Legacy agencies no longer disappear from the directory.** The listing required `_tap_agency_is_active != 0`; agencies approved before that meta existed (treated as active everywhere else) were silently excluded. The check now accepts a missing meta as active.

### Tests

- `suite_bugs`: a legacy agency without the active meta is listed, the toolbar/cards/join CTA render, name and location filters return exactly one match, and the empty state appears.

---

## [1.5.42] — 2026-10-21

Follow-up review of the agency audit: money edge cases and checkout hygiene.

### Fixed

- **Abandoned PayPal checkouts no longer lock featured slots or listings.** A `pending` promo created before PayPal answered had no cleanup path, so it counted against the plan forever and blocked new requests for the same listing. `occupied_promos_count()` now counts only `active` promos still in date plus unpaid `pending` ones from a 2-hour checkout window, and `request()` drops stale unpaid pending rows for that listing instead of duplicating or blocking them. Paid rows are never touched.
- **The bulk commission settle re-checks pending payouts under the per-agency lock.** The `NOT EXISTS` guard ran before `GET_LOCK`, so an agency payout request could insert its pending row in between and both sides would pay the same booking. Candidate ids are now re-selected inside the lock, and zero-commission bookings are skipped.
- **A failed ledger insert never marks commissions paid.** Both the bulk and the single settle release the lock and leave the commission `owed` when `$wpdb->insert()` fails, with a clear notice instead of a silent double state.
- **Partial payments are refunded, capped at what was captured.** Cancelling a `partial` booking used to skip the refund; it now refunds up to `amount_paid` (and a `paid` booking is capped the same way), so the gateway is never asked to reverse money it never took. The policy percentage is still stored for auditing.
- **A stray `room_id` on a tour/package is ignored.** It no longer takes a room lock or runs the accommodation availability gate for a non-accommodation booking.
- **The paid-cancellation path records the real actor.** Legacy AJAX cancellations now store `admin`/`agency`/`system` in `cancelled_by` instead of always `system`.
- **Service-page leads are matched in the right namespace.** The form's row id is no longer re-resolved through the agency-post namespace, which could match an unrelated agency that happened to share the numeric id.
- **Settings warns when PayPal still has sample credentials.** The PayPal section shows a notice when the keys are empty or the installer's `test-client`/`test-secret`, instead of letting checkout fail with an opaque gateway error.

### Tests

- `suite_promotions`: fresh pending blocks a slot, a 3-hour-old pending row frees it, and a stale pending request for the same listing is replaced without duplicating.
- `suite_refunds`: a partial capture is refunded down to zero and capped at the amount paid.
- `suite_bugs`: real `WP_Query` assertion for the inactive-accommodation search rule, a guard that the bulk settle re-checks pending payouts after locking, and the PayPal placeholder detector.

---

## [1.5.41] — 2026-10-21

Agency-logic audit: bookings, promotions, moderation, leads and payouts.

### Security

- **Agency staff can no longer cancel a paid booking.** Cancelling triggers a real gateway refund, which only platform managers may authorise; `TAP_Booking::agency_booking_action()` now refuses it with `paid_cancel`, matching the legacy AJAX endpoint, and `cancelled_by` records the real actor (`admin`/`agency`/`system`).
- **The client chat endpoint refuses agency members.** An agency member could pass the ownership check on their own bookings and post as the traveler — the guest UI rendered it as the guest's own bubble and `mark_read()` cleared the agency's unread badge. Agency staff must use `tap_dash_chat_send`.
- **Authenticated booking creation is rate-limited per account** (20/hour). Only guests were throttled before, so a registered account could flood an agency with unlimited bookings.

### Fixed

- **A room from another property can no longer be booked.** `TAP_Booking::create()` validates that `room_id` is a room of the requested accommodation (`room_mismatch`), instead of taking that room's inventory lock cross-tenant.
- **Accepting request-to-book requests can no longer oversell a tour.** `tour_slots()` excludes `request`, so capacity is re-counted under a per-tour `GET_LOCK` when a request becomes `pending`/`confirmed` (`tour_full`, `booking_busy`).
- **Promotion slots are enforced at request and activation time.** `request()` now counts active promos plus pending ones from the current checkout window against the plan's featured slots, and replaces a stale unpaid request for the same listing instead of blocking it forever, so a closed PayPal prompt never locks a slot; `activate()` re-checks them (the agency may have queued several or been downgraded meanwhile); if no slot is free the captured payment is recorded and the promo stays pending for an administrator (`slots_full`).
- **Blocking a review now unpublishes it.** The moderation `block` action cleared `mod_status` but left `is_approved = 1`, so every public query and the star average kept showing the "blocked" review.
- **Service-page leads reach their own agency.** The listing stores the agency POST id while the form posts the `tap_agencies` row id; `service_belongs_to()` compared them raw and rejected every service-page lead. Both sides are now compared in the row namespace.
- **Keyword search hides inactive accommodations.** `/?s=` now applies the same `_tap_acc_is_active` rule as the archive, via a `NOT EXISTS` arm so tours and other post types are unaffected.
- **Admin commission settlements are recorded as completed.** A pending admin payout whose Cancel/Fail was clicked released commissions back to `owed` while the money was already gone, allowing a second payout. The row is now written `completed` with `paid_at`, the bulk settle skips zero-commission bookings, both the bulk and the single settle take the per-agency payout lock (so they cannot interleave with an agency request), and a failed ledger insert never marks commissions paid.
- **Payment reminders skip unpaid request-mode bookings**, which are not payable until the agency accepts them.

### Tests

- New regression coverage in `suite_bugs` and `suite_request_to_book`; 17 assertions fail without these fixes.

---

## [1.5.40] — 2026-10-18

The free tier is no longer offered for sale.

### Fixed

- **The Gratis card on `/planes/` no longer renders a "Seleccionar plan" button.** Both subscription endpoints reject a plan with `price_monthly <= 0`, so an agency already on a paid plan was shown a button that could only ever return "The selected plan is not available." The card now renders no purchase action; paid plans the agency is not on keep theirs.

---

## [1.5.39] — 2026-10-18

Agency plan checkout no longer deadlocks or runs ahead of approval.

### Fixed

- **A pending plan request no longer blocks every later attempt.** `TAP_Subscriptions::subscribe()` used to reject any agency that already had a `pending` row, while `TAP_PayPal::create_order_generic()` inserted that row *before* calling PayPal — so a failed token request, a network error or a buyer who abandoned checkout locked the agency out of **both** the manual and the PayPal paths until an administrator deleted the row by hand. The draft is now reused when the plan is unchanged and superseded (`status = 'cancelled'`) when the buyer picks a different plan.
- **`/planes/` only offers payment to approved agencies.** The backend already rejected unapproved agencies, but the cards still rendered a paying button and routed the buyer into a dead end; unapproved agencies now see "Tu agencia está en revisión" instead of `data-plan-id` buttons.
- **`/mi-cuenta/` shows the approval banner.** The standalone `[tap_agency_panel]` shortcode had it, the agency dashboard did not — a brand new agency logged in with no indication of why nothing worked. Pending and rejected agencies now get the same notice on every dashboard section (administrators are exempt, matching the panel).
- **Agency employees can no longer contract plans.** `tap_agency_employee` resolved to the parent agency through `current_agency_for()`, so anyone on the team could spend the agency's money; `agency_subscribe` and `subscribe_paypal` now require `TAP_Roles::can_manage_agency_panel()` before doing anything else.
- **`current_subscription()` resolves ties deterministically.** `ORDER BY created_at DESC` left the superseded row and the replacement with identical timestamps and no defined winner; it now falls back to `id DESC`.
- **PayPal connection failures show a buyer-facing message.** Token and order-creation errors surfaced raw cURL/HTTP internals to the agency admin; the details are logged server-side and the response now says what to do. Explicit PayPal responses (declines, already-captured orders) keep their own wording.

### Tests

- **E2E coverage for the whole plan flow:** approval banner on the dashboard, employee gate on both subscription endpoints, `/planes/` gating by approval state, PayPal retry after a failed create-order, and the pending-draft reuse/supersede rules (one pending row per agency, never two).
- **The suite can no longer strand placeholder PayPal credentials.** `test-client` / `test-secret` were written inline and restored inline, so a suite that died mid-run left a working site permanently unable to reach PayPal — the current site was in exactly that state. Each swap is now backed up and restored from a shutdown function.

---

Idempotency key wired to every public booking entry point.

### Fixed

- **`[tap_booking_form]` shortcode now sends an idempotency key.** The form posts its whole serialized payload, so a double click or network retry created two bookings; it now renders a per-render `idempotency_key` field like the theme templates.
- **REST `POST /tap/v1/booking` documents and sanitises `idempotency_key`.** Replays with the same key (scoped to the caller) return the original booking instead of a duplicate.

---

## [1.5.37] — 2026-10-17

Contact unlock requires actual payment.

### Security

- **Client contact is withheld until a booking is paid.** Both `TAP_Attribution::contact_unlocked()` and `booking_unlocked()` treated a `confirmed` status as proof of payment, but a booking can be confirmed without money (auto-confirm option, request-to-book approvals) — leaking the traveler's full name, email and phone to the agency for free. Both now require `payment_status = 'paid'` in addition to `status = 'confirmed'`.

---

## [1.5.36] — 2026-10-17

Listing limit re-evaluated at publish time.

### Fixed

- **Publishing enforces the plan's listing limit.** The limit was only checked when a listing was *created* (`save_listing_data`), so an agency that downgraded its plan — or parked drafts while within the limit — could publish past its current plan's cap. `TAP_Ajax_Listing::set_listing_published()` now counts the agency's live listings (`TAP_Subscriptions::active_listing_count()`) and returns `tap_listing_limit` when activating one would exceed the limit (already-active listings and unlimited plans are exempt).

---

## [1.5.35] — 2026-10-17

Subscription plan fallback and renewal time accounting.

### Fixed

- **`default_plan()` never returns a paid plan.** When no free plan existed, it fell back to the cheapest plan in the table — so an agency without an active subscription inherited a paid plan's `commission_rate` and `listing_limit` for free. It now returns the built-in free tier instead.
- **Renewals keep the remaining paid days.** A renewal is a fresh `pending` subscription row (see `subscribe()`), so its own `paid_until` is null. `mark_paid()` started the new period from today, discarding whatever remained on the agency's still-active subscription. It now extends from the latest remaining `paid_until` (this row or another active one).

---

## [1.5.34] — 2026-10-17

Promotion capture ownership.

### Fixed

- **`capture_promo_paypal` verifies the caller's agency.** The handler activated a promotion from any PayPal order id it was handed, without checking that the order belonged to the caller — unlike `capture_subscription_paypal`, which already enforced this. A foreign logged-in user holding another agency's promotion order id could activate that agency's feature. It now resolves the promo's owning agency (`TAP_Promotions::agency_for_promo()`) and rejects any caller that is not that agency.

---

## [1.5.33] — 2026-10-17

Booking creation idempotency and advisory-lock safety.

### Fixed

- **`GET_LOCK` failure no longer proceeds unsynchronized.** `TAP_Booking::create()` ignored the result of `GET_LOCK`; on timeout/error it ran the availability check + insert without the lock — the exact race the lock guards. It now releases any held locks and returns a retryable `booking_busy` error instead.
- **Double-submitted bookings are idempotent.** `create()` accepts an optional `idempotency_key` from the caller (both front-end booking forms now send a per-render UUID). The key is scoped to the caller (user id or guest email) and memoised for 10 minutes, so a replayed submit returns the booking created the first time instead of creating a duplicate.

### Changed

- The accommodation and single-service booking forms send `idempotency_key`.

---

## [1.5.32] — 2026-10-17

Room occupancy limits and minimum stay are enforced when booking.

### Fixed

- **Over-occupancy room bookings are rejected.** `TAP_Booking::create()` never validated the room's occupancy limits, so a client could post more guests than `_tap_room_max_occupancy` / `_tap_room_max_adults` / `_tap_room_max_children` allowed (the limits were only used to filter the display list). `create()` now returns `max_occupancy` when adults, children or the combined party exceed the room's limits (0/empty meta stays unlimited, matching the display filter).
- **Bookings shorter than the room's minimum stay are rejected.** The per-date minimum stay (`tap_daily_pricing.min_stay`, surfaced via `TAP_Pricing::get_max_min_stay_for_range()`) was advisory only; `create()` now returns `min_stay` when the requested nights are below the range minimum.

---

## [1.5.31] — 2026-10-17

Blocked calendar dates are enforced at booking time.

### Fixed

- **A range containing a blocked night can no longer be booked.** Blocked nights live in `tap_daily_pricing.is_blocked` (the per-date room calendar), but `TAP_Booking::create()` only checked `tap_room` overlap against `tap_availability` (never written), so blocked dates were booked anyway. `create()` now consults `TAP_Pricing::get_prices_for_range()` and rejects the booking with `dates_blocked` when any night in the range is blocked (or unpriced).
- **Blocked ranges are no longer quoted at the flat base rate.** `TAP_Booking_Pricing::calculate_price()` used to fall through to the plain per-night rate whenever `TAP_Pricing::calculate_total()` returned `false` (a blocked date), effectively pricing unavailable nights. It now returns `0` for an unpriceable accommodation range instead of masking the block.

---

## [1.5.30] — 2026-10-17

Booking input validation (reservation integrity).

### Fixed

- **Guest counts are validated in `TAP_Booking::create()`.** `adults` and `children` are client-supplied; a zero/negative party size produced a negative price and commission (a package tour with `adults=-2` earned negative money) and, for tours, offset the booked `SUM(adults + children)` to oversell. `create()` now rejects any booking with fewer than one adult or a negative child count (`invalid_guests`) before pricing. This is the single authoritative path for both the AJAX and REST booking endpoints.

---

## [1.5.29] — 2026-10-17

Money integrity fixes in the payouts / refunds flow (all three were confirmed by tests).

### Fixed

- **Admin payouts no longer settle to $0.** `TAP_Payouts::complete()` re-derived the amount from the `owed` bookings, but admin-source payouts already flip their bookings to `paid` at creation, so the eligible set was empty and the amount was overwritten to `0.00`. `complete()` now branches on `source`: only agency-source requests (bookings kept `owed` until transfer) reconcile their amount; admin-source payouts keep their authoritative amount and booking set.
- **Partial PayPal refunds no longer void the whole commission.** `TAP_Payment::handle_capture_refunded()` treated any refund as a full refund. It now compares the refunded amount against the booking's paid total: a shortfall records the refund id (`_tap_refund_amount`) and fires `tap_payment_partially_refunded`, leaving `payment_status` and the earned commission untouched; only a full refund flips the booking to `refunded` and voids the commission.
- **Auto-refunds are no longer refunded twice.** `TAP_Booking::auto_refund_capture()` wrote a non-empty `_tap_refund_error` on success, so the nightly `retry_pending_refunds()` cron picked the row up again and reversed the same capture a second time. Success now mirrors `TAP_Refunds::execute_refund()`: it clears `_tap_refund_error` and drops `_tap_refund_pending`.

---

## [1.5.28] — 2026-10-17

Seed the service categories so the itinerary wizard's first step is not empty.

### Added

- **11 service categories seeded into `tap_service_cat`** (`seed_service_categories()`, idempotent and guarded by the `tap_service_cat_seeded` option, run from `TAP_Taxonomies::seed()`): Tours guiados, Alojamiento, Transporte y traslados, Alquiler de vehículos, Náutico, Paquetes, Aventura, Naturaleza, Bienestar & Spa, Gastronomía, Traslados aeropuerto. This taxonomy is the "categories" step of `[tap_itinerary_builder]`, which previously rendered with no options.

---

## [1.5.27] — 2026-10-17

Admin screen to manage the destination hierarchy.

### Added

- **Destinations management page** (Travel Platform → Destinations, `manage_options`): shows the full país → provincia → cantón → distrito → lugar tree in one place, with a parent select and an order field per term, plus an "add destination" form. Replaces hunting through each post type's separate term UI to correct the hierarchy. Saving re-derives `tap_dest_level` from the term's new depth so the tree stays coherent with the picker, and blocks a term from becoming its own ancestor (cycle). Both actions are protected by a nonce and a capability check (`admin-post.php`).

---

## [1.5.26] — 2026-10-17

The destination cascading picker actually cascades now.

### Fixed

- **The picker rendered only one dropdown ("País") on a fresh form.** `render_picker()` stopped at the first empty level and the script only toggled visibility on already-rendered options, so on a new search/listing you could never reach provincia → cantón → distrito. The picker now renders all five levels and the script rebuilds the next level's options from the parent→children map whenever a level changes (resetting deeper levels to their placeholder), so the hidden field always carries the deepest selected term.

### Changed

- Selects carry a `data-placeholder` attribute so the cascade can restore the "— Nivel —" option when a parent changes.

---

## [1.5.25] — 2026-10-17

Destination taxonomy (`tap_location`) hygiene: coherent país → provincia → cantón → distrito hierarchy.

### Fixed

- **Two orphan tourist places hung directly under the country** (`Lago Arenal`, `Nuevo Arenal`), so the cascade listed them as if they were provinces. `TAP_Destinations::normalize()` now reparents them under their canton (`San Carlos`, `Alajuela` and `Tilarán`, `Guanacaste` respectively).
- **`tap_dest_level` did not match a term's real depth**: the seed labelled the tourist places `4` (lugar) while they sit at depth 3 (distrito), and manually created terms carried no level at all. Levels are now normalized to mirror depth (`país = 0 … distrito = 3`) and a missing `tap_dest_order` is backfilled, so the picker labels the cascade correctly.

### Changed

- **Seed places are now created at level 3 (distrito)** instead of 4, matching the hierarchy the picker renders (país → provincia → cantón → distrito). Real distritos remain fully editable and addable from the admin.
- A term created through WordPress's own term UI now gets `tap_dest_level` / `tap_dest_order` backfilled automatically (`created_term` hook), so new destinations stay consistent no matter how they are added.

### Tooling

- `normalize()` is idempotent and guarded by the `tap_dest_norm` option, so it runs once per plugin version instead of on every page load; it is invoked from both `TAP_Installer::migrate()` and `TAP_Taxonomies::seed()`.

---

## [1.5.24] — 2026-10-16

Commission integrity reconciliation.

### Fixed

- **A `commission_status = 'paid'` row could survive on a booking that was never paid.** `TAP_Payouts` only settles bookings that are both `owed` and `payment_status = 'paid'`, so a `paid` commission on an unpaid (nor refunded) booking is impossible — it can only come from legacy/seed data. The existing migration normalized `owed`+unpaid and cancelled/refunded commissions but deliberately skipped `paid` rows, leaving them stuck in the settled totals. `TAP_Installer::migrate()` now returns such commissions to `waiting` (the state an unpaid booking belongs in). Refunded payments are left untouched (money collected and returned; no-clawback is deliberate).

### Tooling

- `suite_commissions` gained a reconciliation guard: an impossible `paid`+unpaid row is normalized to `waiting` by `migrate()`, while a legitimately settled `paid`+`paid` row is left untouched.

---

## [1.5.23] — 2026-10-16

Production-readiness pass: uninstaller now removes plugin user meta, and the plugin declares its WordPress/PHP baseline.

### Fixed

- **Uninstaller left plugin user meta behind**: `tap_favorites`, `_tap_agency_id` (agency↔staff link) and `tap_lang` were written to users but never cleaned on delete. `uninstall.php` now deletes them for every user, so deleting the plugin leaves no trace.

### Changed

- **Plugin headers**: added `Requires at least: 6.4` and `Requires PHP: 8.1` to match the project's declared baseline (`.phpcs.xml.dist`).
- **README** version/schema cells synced to the current release.

---

## [1.5.22] — 2026-10-16

Date boundary consistency. Every check that compares a stored business date (booking `check_in`/`check_out`) or "today" against the clock now uses the site's local date instead of UTC, so the day boundary is correct in any site timezone.

### Fixed

- **Business-date comparisons used `gmdate()` (UTC)** in the booking lifecycle, front panels and refund policy while the rest of the plugin runs on WordPress time; they now use `current_time()`. Affected: past-date rejection on create, client/agency cancellation, the auto-complete cron (`class-booking.php`), `can_cancel_booking()`, the agency/client dashboards (`class-front-dash.php`, `class-shortcode-booking.php`), the room-delete future-bookings guard (`class-ajax-listing.php`) and the refund `days_before` policy calculation (`class-refunds.php`). The automations sent-log prune cutoff (`class-automations.php`) now matches the `current_time()` stamps it compares against.

---

## [1.5.21] — 2026-10-16

Reporting single-source and booking-clock consistency. The commission definition now lives in exactly one place, and booking timestamps no longer depend on the database server's timezone.

### Fixed

- **Commission drift is now structurally impossible**: the committed-commission predicate (`commission_status <> 'void'` over active bookings) lives in one place — `TAP_Booking_Stats::commission_case()` and `net_expr()` — used by the Dashboard, Reports, Analytics and the CSV export, instead of diverging inline copies.
- **`create()` relied on the MySQL server default for `created_at`**, so bookings were stamped in the database server's timezone rather than WordPress'. Bookings are now stamped with `current_time('mysql')`, matching every other timestamp in the plugin (seeding, cancellations, reminders).
- **`cancel_stale_bookings()` compared `created_at` against `gmdate()` (UTC)**, disagreeing with WordPress local time; it now uses the same `current_time('timestamp')` basis as the rest of the plugin, so the stale window is exact in any site timezone.

### Tooling

- `suite_reports` gained anti-regression guards: the helper SQL must key on `commission_status <> 'void'` and never on `payment_status`, plus a dashboard-vs-stats congruence check. `suite_booking_flow` asserts `create()` stamps `created_at` on the WordPress clock.

## [1.5.20] — 2026-10-16

Unified committed-commission base across all reporting views.

### Fixed

- Reports/Analytics/export now count committed commission over active bookings (`status NOT IN ('cancelled','refunded')` **and** `commission_status <> 'void'`), matching the Dashboard, so `revenue − fees − commission = net` holds in every view. The previous `payment_status = 'paid'` gate silently zeroed whole reports on a site where no booking had settled.
- Idempotent migration: cancelled/refunded bookings still marked `waiting`/`owed`/`disputed` are flipped to `commission_status = 'void'` (settled `paid` rows untouched).
- `net` is now NULL-safe (`COALESCE(booking_fee, 0)`).

### Security

- `reports_page` and `analytics_page` now require the `tap_view_reports` capability.

## [1.5.19] — 2026-10-16

### Fixed

- Cancelled/refunded bookings no longer contribute commission in the stats, dashboard totals and Reports/Analytics (`commission_status = 'void'` is excluded); added the `(commission_status, payment_status)` index.

---

## [1.5.18] — 2026-10-13

Agency-panel recovery and monetization pass. The front-end booking flow broken by the nonce hardening was repaired, two tandas of panel fixes landed, and two revenue paths that had been left dead (agency highlight purchase, traveler self-registration) were brought back to life on the new panel. Internal quality: duplicated permission predicates unified, N+1 title queries primed, chat gained keyboard support.

### Fixed

- **Frontend bookings were broken by the nonce hardening** (`00271ab`): the booking form posted a nonce the handler no longer accepted. Restored the correct nonce end to end.
- **Panel Tanda 1** (`cf4a464`): agency wizard, chat, registration and calendar fixes on the legacy panel seams that still served production pages.
- **Header "Regístrate" was a dead button** (`30e6984`): it pointed at `wp-login.php?action=register` while `users_can_register` was off. The header now links to `/register/`, which became a hub offering both audiences: a native traveler registration form (new users land as `tap_client` via the existing hook) and the existing agency form.
- **Double-cancel race on cancellations** (`f8e208b`): `apply_cancellation` now claims the transition with an atomic conditional `UPDATE`, writes `_tap_refund_pending` before touching the gateway and clears it on success; a second cancel is rejected instead of double-refunding.

### Added

- **Highlight (destacado) purchase on the new panel** (`3d918df`): new "Destacados" section (`bo-promos`, agency-admin only) listing the agency's published listings with plan slots, monthly price and 1/3/6/12/24-month selection. Both inherited paths work: PayPal payment when configured, or an admin-confirm request otherwise. The three promo AJAX endpoints now validate the panel's `tap_front_dash_nonce`.
- **Full employee ↔ agency mapping with an "Equipo" section** (`04c7773`): `TAP_Booking::get_agency_for_user` falls back to the `_tap_agency_id` user meta; `team_add`/`team_remove` cores plus nonce-guarded AJAX endpoints; employees operate bookings of their own agency (hard-block removed, `mark_paid` stays admin-only); multi-role users get the canonical agency role in nav and routing.
- **Plan-limit pre-check in the listing wizard** (`f8e208b`): non-admin agencies at their listing limit see the upgrade note instead of the wizard; admins bypass.
- **Registration as traveler** (see Fixed above): `/register/` renders both forms; `users_can_register` enabled on the live site.

### Changed

- **Draft-on-create for accommodations only** (`f8e208b`): new `tap_accommodation` listings start as `draft` (the legacy editor has no publish control); other types keep publish-on-create; non-publishers get `pending`.
- **Agency nav slimmed for employees**: Resumen / Operaciones / Listados only; Finanzas, Destacados and Equipo are agency-admin sections. Employees see action buttons on their own agency's bookings.
- **Dirty-form guard on the agency wizard** (`f8e208b`): `beforeunload` fires only when the form was actually modified; all navigations go through a `navigate()` helper that clears the flag.
- **Permission predicates unified** (this release): `TAP_Roles::is_agency_admin`, `is_agency_employee` and `can_manage_agency_panel` replace the inlined role checks repeated across `TAP_Front_Dash`, `TAP_Front_Dash_Client` and `TAP_Ajax_Dash`.
- **Dashboard N+1 title lookups primed** (this release): booking, leads and favorites loops prime post caches (`TAP_Front_Dash::prime_titles`) so `get_the_title()`/`get_post()` no longer queries per row.
- **Chat keyboard support** (this release): opening a thread focuses the message input; Escape closes the visible thread and returns focus to its toggle button.

### Tooling

- **PHPStan raised to level 3** (`982b184`); dead code removed from `class-listing-editor.php` (`615ce95`).
- New test suites: `suite_agency_team` (employee mapping, 17 assertions) and promo-panel render coverage inside `suite_promotions`.

---

## [1.5.17] — 2026-10-12

Structural pass. Seven god classes were split along seams chosen from their actual `self::` dependency graphs, one latent installer bug was fixed, and three order-dependent test failures were repaired. No public API changed and no user-visible behaviour moved.

### Fixed

- **The unique index on `booking_code` was recreated on every migration.** The guard in `TAP_Installer::migrate` queried `SHOW INDEX … WHERE Not_unique = 0`, but the column MySQL and MariaDB actually return is `Non_unique`. The query failed every time, `$bc_unique` came back `null`, and the `if` ran `DROP INDEX` + `ADD UNIQUE KEY` on each run while logging a database error. With the correct column name the existing index is detected and the `ALTER` only fires when it is genuinely needed.

### Changed

- **`TAP_Front_Dash_Client`** — the client dashboard (overview, bookings, favorites, reviews) split out of `TAP_Front_Dash`. `render_sidebar` and `render_nav_icon` stay behind because both dashboards share them; they widen from `private` to `public` with an `@internal` note.
- **`TAP_Booking_Pricing`** — price calculation and room availability split out of `TAP_Booking`.
- **`TAP_Acc_Wizard_Room_Form`** — the per-room editor form, the bed-type label map and the image uploader split out of `TAP_Acc_Wizard`.
- **`TAP_Acc_Wizard_Steps`** — the five step renderers, the progress bar and the label/lookup helpers they share.
- **`TAP_Emails_Send`** — message composition and delivery split out of `TAP_Emails`. The dependency graph there is a strict DAG (`on_*` → `send_*` → helpers) with no back edges, so the split follows that layer line exactly. Helpers used only inside the new class stay `private`.
- **`TAP_Ajax_Listing`** and **`TAP_Listing_Access`** — the listing and room write handlers split out of `TAP_Ajax`. The three ownership guards move to their own class because `TAP_Agency_Media` needs them and the listing handlers need `TAP_Agency_Media`; putting the guards below both removes what would otherwise have been a cycle.
- **`TAP_Shortcode_Agency_Panel`** — the 701-line agency panel split out of `TAP_Shortcode_Account`.

**3770 lines** moved out of oversized files, each extracted verbatim and checked for byte-identity against the previous commit before anything else was touched.

### Not split, deliberately

`TAP_Booking` stays whole at 1160 lines. Its internal graph is dense — `update_status` is called from five methods and `apply_cancellation` from four — and it models a single cohesive domain. The one clean cut available (pricing) was taken in 1.5.15; the rest would have been fragmentation rather than structure.

### Tests

Three failures that depended on suite execution order rather than on regressions:

- **`suite_promotions`** asserted that a featured listing appeared at index 0 of a *secondary* `WP_Query`. `TAP_Archive_Query::featured_sort_clauses` deliberately returns early unless the query is the main one, so the assertion only passed when creation order happened to line up. It now exercises the clause builder directly.
- **`suite_leads`** required the whole `tap_leads` table to be empty after deleting only its own namespace. The check is now scoped to that namespace.
- **`suite_bugs`**, **`suite_security`**, **`suite_back_office`**, **`suite_dashboard`** and **`suite_refunds`** read plugin source to assert escaping and SQL invariants. Those reads were extended to cover the files the code was split into, so future refactors move the assertions with the code instead of failing them.

### Verification

- PHPStan level 1 clean; `includes-check`, `ajax-nonce-check` 35/35 and `i18n-check` (1825/1825, 0 untranslated in both locales) green.
- Full E2E battery **46/46 suites, 0 failures**, run twice consecutively to confirm the order-dependence is gone; 0 fatals and 0 database errors.
- PHPCS: **703 → 523**.

## [1.5.16] — 2026-10-12

Closing pass over the static-analysis report. The translator-comment backlog is gone, four strings that never reached the translation layer are fixed, and the naming/style debt is cleared. PHPCS drops from 703 to 521 and what remains is false positives already audited and deliberately left. No public API changed.

### Fixed

- **Traducciones que no llegaban a ejecutarse.** Cuatro patrones rompían la i18n sin dar ningún error visible:
  - `class-dashboard-settings` construía el msgid concatenando la fecha (`'Subscription marked as paid until ' . $result`), así que el msgid nunca coincidía con ninguna entrada del catálogo y el aviso se mostraba siempre en inglés. Ahora usa `sprintf` con `%s`, y las dos entradas nuevas se añaden traducidas a `es_ES` y `en_US`.
  - `class-dashboard-money` envolvía `get_error_message()` en `__()`. Los mensajes de `WP_Error` ya vienen traducidos; el `__()` exterior los buscaba en el catálogo como si fueran literales.
  - `class-front-dash` doblaba la traducción de *"Todas"* (`esc_html__(__())`) y además pasaba por `__()` un slug dinámico (`ucfirst($fk)`), que no puede estar en ningún catálogo.
  - `class-shortcode-search` traducía los nombres de términos de taxonomía (`__($t->name)`). Son datos que introduce el administrador, no cadenas del catálogo.
- **Sanitización de `label`** en las celdas de precio en bloque de `TAP_Pricing`: iba por `(string)` a secas. Los demás campos ya iban casteados a `int`/`float`.
- **`IN ()` endurecido** en `class-dashboard-money.php`: la lista de IDs de agencia pasa por `array_map('intval', …)`.
- **Ocho `esc_attr()`** en contexto de atributo.
- **`wp_redirect` → `wp_safe_redirect`** en el redirect legacy del dashboard.
- **41 `in_array` con comparación estricta**, previa revisión de cada lista para confirmar que ninguna mezcla enteros con cadenas.
- **13 `json_encode` → `wp_json_encode`**.
- **Dos parámetros muertos eliminados**: `TAP_Dashboard::paginate::$page_slug` y `TAP_Emails::money::$currency`.

### Added

- **`/* translators: */` en los 132 sitios que faltaban.** Los textos traducibles llevaban huecos `%s`/`%d` sin decir al traductor qué significa cada uno, lo que obliga a adivinar el orden. 137 comentarios quedaban en contexto de plantilla HTML y no podían salirse de `<?php ?>` porque se habrían impreso en la página; esos van en la misma línea justo antes de la función i18n, que es el segundo formato que acepta WPCS. La colocación se verificó con `token_get_all` de PHP: 137 dentro, 0 fuera.
- **`tests/suite_security.php`** (46ª suite): ningún suite anterior cubría escaping ni nonces de formulario.

### Changed

- **Naming**: 25 variables locales en camelCase pasan a snake_case (`$staleRaw`, `$bestScore`, `$tourTypes`, `$parentName`, `$priceKey`, `$mainEntity`). Se dejaron `$bc_col->Type` y `$idx->Key_name`, que son propiedades que devuelve MySQL. `TAP_Payouts::_transition()` pasa a `transition()`.
- **Estilo**: se separan 9 asignaciones encadenadas y 3 asignaciones dentro de condición, que ocultan el efecto secundario detrás de un `if` o de un `return`.
- **Docblocks**: 6 comentarios de documento sin resumen o con resumen en minúscula.
- **`.phpcs.xml.dist`** excluye tres convenciones deliberadas —placeholders sin renumerar, nombres de fichero `class-*.php`, `meta_query`— con su justificación escrita en el propio fichero.

### Audit

Se revisaron y **rechazaron** como falsos positivos los hallazgos que más peso tenían, incluidos los grupos que faltaban por auditar. **No hay inyección SQL activa**: los 198 hallazgos de `PreparedSQL` se agruparon por variable (25 en total) y las cinco sospechosas se verificaron una a una. Los 12 `UnfinishedPrepare` y los 2 `ReplacementsWrongNumber` son el sniff sin ver los placeholders dentro de la variable interpolada; el `array_merge` alinea argumentos y el código es correcto. Los 96 `OutputNotEscaped` son renderers internos que devuelven HTML ya escapado o funciones de WordPress. Los 92 `NonceVerification` se verifican vía `TAP_Dashboard::post_verified`/`post_nonce_ok`, que el sniff no resuelve. Los 26 `UnusedFunctionParameter` son firmas de callbacks de WordPress (shortcodes, `save_post`, `do_action`): quitarlos rompería los ganchos. Los 8 `GlobalVariablesOverride` son el patrón guardar/restaurar de `$GLOBALS['post']`. El `error_log` es el hook `wp_mail_failed` de diagnóstico, los tres `NonEnqueuedScript` son el SDK de PayPal con parámetros de query, y `TAP()` es el accessor público del plugin. Se dejaron sin tocar los 16 `date()` y los 7 `current_time('timestamp')`, porque cambiarlos es semántica de zona horaria y puede desplazar un día en las automatizaciones.

Las 5 `NonSingularStringLiteralText` restantes son las respuestas del chatbot y los chips de preguntas frecuentes, que sí están en el catálogo como entradas curadas —`tools/i18n.sh` lo documenta y las rescata del `msgmerge`.

### Verification

- PHPStan nivel 1 sin errores; `includes-check`, `ajax-nonce-check` 35/35 e `i18n-check` (1825/1825, 0 sin traducir en `es_ES` y `en_US`) en verde.
- Batería E2E **46/46 suites, 0 fallos**, ejecutada tras cada tanda.
- PHPCS: **703 → 521** hallazgos en 41 → 31 ficheros.

## [1.5.15] — 2026-10-12

Static-analysis pass over the whole plugin: the report goes from 1044 to 703 findings, the SQL and escaping audits close, and the invariants they established are locked in a new test suite. No public API changed and no user-visible feature was added.

### Added

- **`tests/suite_security.php`** (46ª suite, registrada en `run.sh`): ningún suite anterior cubría escaping ni nonces de formulario. Fija los invariantes que la auditoría estableció —clave canónica de agencia (D4), pistas de cancelación resolviendo todas las políticas (D13), helpers de nonce compartidos, IDs enteros en los `IN ()`, los 8 sitios de `esc_attr` corregidos y msgids sin renumerar placeholders— para que cualquier refactor que los suelte falle la batería en lugar de llegar a producción.

### Fixed

- **Sanitización de `label`**: las celdas de precio en bloque de `TAP_Pricing` pasaban `label` por `(string)` a secas; ahora `sanitize_text_field`. El resto de campos ya iban casteados a `int`/`float`, y las listas de escalones se parsean a `(int)`/`(float)`.
- **`IN ()` endurecido**: la lista de IDs de agencia de `class-dashboard-money.php` se construía con `implode` sobre valores de la BD; ahora `array_map('intval', …)`.
- **Ocho `esc_attr()`** en contexto de atributo donde una variable se emitía a secas dentro de `class=` o `value=` (`shortcode-account` ×2, `dashboard-content`, `dashboard-money` ×3, `acc-wizard` ×2).
- **`wp_redirect` → `wp_safe_redirect`** en el redirect legacy del dashboard. El destino es `home_url('/mi-cuenta/')`, ya presente en la lista de hosts permitidos; el cambio endurece el código si la URL dejara de ser fija.
- **41 `in_array` con comparación estricta**. Se revisaron las listas antes de convertirlas: todas son literales de cadena (estados, tipos de servicio, hooks de admin, nombres de columna de `SHOW COLUMNS`) o arrays de roles, y las agujas son cadenas o `null`. Ningún caso mezcla enteros con cadenas, que es donde la estricta rompería algo.
- **13 `json_encode` → `wp_json_encode`**: misma salida para entrada válida, pero aplica los filtros `json_encode_pre` y degrada mejor si la codificación falla.
- **Dos parámetros muertos eliminados**: `TAP_Dashboard::paginate` recibía `$page_slug` en 4 call sites sin usarlo, y `TAP_Emails::money` tenía `$currency` con valor por defecto que ningún caller pasaba.

### Changed

- **`.phpcs.xml.dist` excluye tres convenciones deliberadas** —placeholders sin renumerar, nombres de fichero `class-*.php`, `meta_query`— con su justificación escrita en el propio fichero. El informe deja de mezclar deuda con decisiones ya tomadas: 1044 → 760.
- **`phpcbf` aplicado** a 376 errores de formato, excluyendo la categoría `WordPress.WP.I18n` porque su autofix reescribe `%d` a un placeholder numerado, cambia el msgid y rompe todas las traducciones.

### Audit

Se revisaron y **rechazaron** como falsos positivos los hallazgos que más peso tenían. **No hay inyección SQL activa**: los 198 hallazgos de `PreparedSQL` se agruparon por variable (25 en total) y las cinco sospechosas se verificaron una a una —`$lock` ya iba casteado a `int` en su propia función, `$payment_orders` y `$key`/`$col` del instalador son nombre de tabla y array literal. Los dos `ReplacementsWrongNumber` son el sniff sin ver los placeholders dentro de la variable interpolada; el `array_merge` alinea argumentos y el código es correcto. Los 96 `OutputNotEscaped` son renderers internos que devuelven HTML ya escapado o funciones de WordPress, salvo los 8 corregidos arriba. Los 92 `NonceVerification` se verifican vía `TAP_Dashboard::post_verified`/`post_nonce_ok`, que el sniff no resuelve. Los 26 `UnusedFunctionParameter` restantes son firmas de callbacks de WordPress (shortcodes, `save_post`, `do_action`): quitarlos rompería los ganchos. Los 8 `GlobalVariablesOverride` son el patrón guardar/restaurar de `$GLOBALS['post']` al renderizar otros posts dentro de shortcodes. Se dejaron sin tocar los 16 `date()` y los 7 `current_time('timestamp')`: cambiarlos es semántica de zona horaria y puede desplazar un día en las automatizaciones.

### Verification

- PHPStan nivel 1 sin errores; `includes-check`, `ajax-nonce-check` 35/35 e `i18n-check` (1823/1823, 0 sin traducir en `es_ES` y `en_US`) en verde.
- Batería E2E **46/46 suites, 0 fallos**, ejecutada tras cada lote.
- PHPCS: **1044 → 703** hallazgos en 56 → 41 ficheros.

## [1.5.14] — 2026-10-11

Structural quality pass: eight focused classes extracted from the god classes, two real bugs fixed, and the static guards extended. No public API changed and no user-visible feature was added.

### Fixed

- **Propiedad de agencia (D4)**: `TAP_Post_Types` expone `prefixes()`, `agency_meta_key()` y `agency_meta_keys()` como fuente canónica. Los 6 sitios que derivaban `_tap_agency_id` de forma independiente —y divergían entre sí— ahora las usan. *Cambio de comportamiento deliberado*: los listados de equipo (`tap_equipment`) pasan a contar para los límites del plan, a aparecer en los paneles de agencia y en las estadísticas de admin, y a limpiarse al borrar la agencia. SQL del listado con `IN` ahora vía `$wpdb->prepare`.
- **Pistas de cancelación (D13)**: `TAP_Acc_Wizard::acc_cancellation_hint` devolvía la clave inexistente `nonrefund` y una pista en blanco para `strict`. Ahora usa `non_refundable` y *"50% de reembolso hasta 7 días antes de la llegada."*.
- **`ajax-nonce-check` ignoraba comentarios**: `tap_method_body()` trataba toda comilla como apertura de cadena sin saltarse `//` ni `/* */`. Un apóstrofo dentro de un comentario (`PayPal's`) abría una cadena fantasma, desbalanceaba el conteo de llaves y reportaba como *sin cuerpo* un handler existente, **dejándolo sin auditar**. El bucle ahora salta comentarios antes de mirar comillas; cobertura 27/27 → **35/35**.

### Added

- **Ocho clases extraídas** de los god classes, con sus cuerpos movidos *verbatim* y cableadas en el cargador: `TAP_Archive_Query` (144), `TAP_Booking_Stats` (91), `TAP_Acc_Wizard` (1498, desde `class-listing-editor.php`), `TAP_Agency_Media` (256), `TAP_Guest_Checkout` (33), `TAP_Ajax_Dash` (217), `TAP_Refunds` (159), `TAP_Ajax_Commerce` (342).
- **`TAP_Dashboard::post_nonce_ok()` / `post_verified()`**: los 9 manejadores POST del back-office redeclaraban el mismo prologo de verificación de nonce.
- **`i18n-check` valida traducciones**: la guarda solo comprobaba que los msgids existieran; una cadena presente pero sin traducir pasaba inadvertida. Ahora une las líneas de continuación (`msgstr ""` seguido de `"..."`) antes de decidirlo, y omite las entradas plurales.

### Changed

- **Tamaños**: `class-listing-editor.php` 2018 → 538 (−73 %), `class-ajax.php` 2075 → 1052 (−52 %), `class-booking.php` 1649 → 1412, `class-front-dash.php` 1316 → 1115.
- **`commissions_page`** separa el POST (95 líneas, siempre termina en `redirect`+`exit`) del render (292). La firma pública no cambia. `plans`/`subscriptions`/`promotions_page` no se parten: no redirigen tras el POST, emiten el aviso en línea y siguen al marcado.
- **Cotización de habitación**: `TAP_Pricing::get_quote` se pedía dos veces con argumentos idénticos en `TAP_Ajax_Public::quote_price`; ahora se pide una sola vez y el resultado alimenta el total y el desglose.
- **CABecera del plugin** `Version:` corrige el 1.5.7 heredado; todo el código usa `TAP_VERSION`, que es quien gobierna el esquema.

Se revisaron y **rechazaron** tres duplicaciones propuestas tras auditarlas, porque unificarlas habría cambiado comportamiento: los cálculos de noches difieren en su valor por defecto (0, 1 y sin guarda), las dos `csv_cell` tienen contratos distintos (`implode` necesita comillas, `fputcsv` ya cotiza) y los agregados de comisiones son de agencia frente a plataforma.

### Verification

- PHPStan nivel 1 sin errores; `includes-check`, `ajax-nonce-check` 35/35 e `i18n-check` (1823/1823, 0 sin traducir en `es_ES` y `en_US`) en verde.
- Batería E2E **45/45 suites, 0 fallos**, ejecutada tras cada lote.
- Los métodos extraídos se contrastaron contra su revisión previa en git: 8 de 9 idénticos byte a byte, y el noveno solo cambió `private`→`public`. Sin código muerto entre las clases nuevas.

## [1.5.13] — 2026-10-10

### Added

- **`Makefile`**: targets reproducibles `install`, `analyse`, `lint`, `lint-fix`, `audit-security`, `test`, `i18n`, `i18n-check`, `ajax-nonce-check`, `includes-check` y `check` (puerta local completa).
- **Guardias estáticas** (PHP puro, sin dependencias; se ejecutan en CI y fallan el build):
  - `tools/i18n-check.php` — un string traducido usado en PHP debe existir en los catálogos (hoy 1823/1823).
  - `tools/ajax-nonce-check.php` — todo handler `wp_ajax_*` debe verificar un nonce (hoy 27/27).
  - `tools/includes-check.php` — todo fichero de `includes/` debe estar cableado en el cargador (hoy 46/46), para que un fichero de clase nuevo no acabe en fatal en runtime.
- **`tools/phpcs-security.xml`** versionado y target `make audit-security`: informe PHPCS solo de seguridad/DB, sin las reglas de formato.

### Changed

- **`TAP_Ajax_Public`**: se extraen los 5 handlers AJAX de solo lectura (`search_services`, `calculate_booking_total`, `get_public_pricing`, `search_suggestions`, `get_rooms`) a `includes/class-ajax-public.php`. `TAP_Ajax` baja de 2075 a 1773 líneas y queda con los handlers que escriben, más fáciles de auditar. Cuerpos movidos verbatim y los 10 `add_action` redirigidos.
- **Escapado**: 24 `_e()` → `esc_html_e()` y 3 `echo __(literal)` → `esc_html__()` en renderers (`class-discounts.php`, `class-metaboxes.php`, `class-shortcode-account.php`); el resto de salidas marcadas por WPCS ya estaban escapadas.
- **README**: sección *Development & QA* ampliada con los targets de make y las guardias.

### Verification

- PHPStan nivel 1 sin errores; PHPCS seguridad 1393 → 1171 errores.
- Batería E2E **45/45 suites, 0 fallos** tras el escapado y el refactor.
- Cada guardia nueva pasó su prueba negativa (simular el fallo y comprobar que la detecta).

## [1.5.12] — 2026-10-09

### Added

- **Toolchain de calidad**: `composer.json` con PHPStan 2 (nivel 1, stubs WordPress) y PHPCS-WPCS; `phpstan.neon.dist`, `.phpcs.xml.dist` (estándar WordPress acotado a corrección/seguridad/DB/i18n), `tools/lint.sh` y workflow de CI (`.github/workflows/ci.yml`, PHPStan bloqueante + PHPCS informativo).
- **`tools/i18n.sh`**: regenera el POT con `wp i18n make-pot` y refresca/compila los catálogos `es_ES`/`en_US`, rescatando las entradas curadas que `msgmerge` marca como obsoletas (strings del chatbot).

### Changed

- **Seguridad — CSRF**: `tap_search_services`, `tap_calculate_booking_total`, `tap_get_public_pricing`, `tap_search_suggestions` y `tap_get_rooms` verifican ahora el nonce; `tap_create_booking` pasa a `check_ajax_referer` y los callers JS envían el nonce que faltaba.
- **Seguridad — entrada**: `wp_unslash()` en las lecturas de `$_POST`/`$_GET` que se pasaban a `sanitize_*` (~105 llamadas), `(int) ($_POST[...])` → `intval()`, y saneo en la frontera de leads, planes, camas, itinerario, `tap_action` y `status` de redirección. Las verificaciones de nonce manuales envuelven el valor en `sanitize_text_field` + `wp_unslash`.
- **Chat**: el polling se pausa con la pestaña oculta (`visibilitychange`) y aplica backoff exponencial (15 s → 120 s) ante fallos de red, en el dashboard y en el voucher de la reserva.

### Fixed

- `TAP_Booking` inicializa `$booking_id` antes del bloque `try` (PHPStan nivel 1).
- Se verifica de nuevo la inyección SQL: las consultas dinámicas ya usaban placeholders `prepare()`/`intval`, y las salidas marcadas por WPCS son literales traducidos, no datos variables.

## [1.5.11] — 2026-10-08

### Added

- **Chat en vivo (auto-refresh)**: el hilo del huésped se refresca solo (nuevo endpoint `tap_chat_client_thread`, priv + nopriv) y la agencia obtiene un contador global de no leídos (`tap_dash_chat_unread` + badge rojo en la nav *Operaciones*, oculto a empleados). El polling (cada 15 s) solo reemplaza el hilo cuando cambia el número de mensajes.
- `TAP_Chat::unread_total_for_agency()` (total de mensajes del huésped sin leer en todas las reservas de la agencia) y `TAP_Chat::last_id()`.
- `suite_chat` cubre los nuevos endpoints y el total global por agencia.
- i18n: nueva cadena de conversación vacía en ambos catálogos.

### Changed

- `ajax_chat_thread` devuelve ahora `count` y `last_id` para el refresco selectivo.

## [1.5.10] — 2026-10-08

### Added

- **Emails de flujo**: `TAP_Emails::on_payout_failed` avisa a la agencia cuando una liquidación no llega a completarse (dispara en `tap_payout_failed` y anexa el motivo a la nota); `on_chat_message_sent` avisa a la contraparte del chat por reserva (huésped → agencia y agencia → huésped).
- **Analytics — embudo de reservas**: `TAP_Analytics::booking_funnel()` deriva 4 etapas de tablas existentes (vistas de listados → reservas creadas → confirmadas → pagadas) con conversión por paso y % del total, en la página de Analytics respetando el filtro de meses.
- `suite_analytics` cubre el render y la forma de los datos del embudo; `suite_emails` cubre ambos hooks de email.

### Changed

- `TAP_Payouts::fail()` lee la nota existente para anexarla en lugar de sobrescribirla.

### Fixed

- **i18n**: POT regenerado (1818 msgids) y catálogos en_US (2031) / es_ES (2073) puestos al día (el en_US estaba congelado en v1.5.5). Se conservan por unión los msgids que `make-pot` marca obsoletos (strings del chatbot vía `__($spec['reply'])`) para no romper traducciones en runtime.

## [1.5.9] — 2026-10-08

### Added

- **Mensajería huésped ↔ agencia por reserva** (`TAP_Chat` + tabla `tap_chat_messages`): sección "Mensajes con tu agencia" en el voucher (cliente o huésped verificado por email), y en el back-office de agencia un botón *Chat* con badge de no leídos + fila expandible en la tabla de reservas. AJAX `tap_chat_client_send` (nopriv con guard de propiedad) y `tap_dash_chat_thread`/`tap_dash_chat_send`.
- Lectura **por lado**: `mark_read` solo limpia lo escrito por el otro bando y `unread_for` cuenta los mensajes del bando contrario.
- `TAP_Booking::user_can_manage_booking()` como guard de propiedad reutilizable.
- `suite_chat` (validación, persistencia, orden, no-leídos cruzados, guardas de propiedad y render del voucher).

## [1.5.8] — 2026-10-08

### Added

- **Wizard B2B de alojamiento (5 pasos)** en el back-office de agencia: Información → Unidades → Precios y disponibilidad → Políticas → Publicar, con calendario de precios/disponibilidad (`agency-calendar.js`/`.css`) y carga de imágenes. `TAP_Pricing` refactorizado en núcleo compartido (`month_data`, `save_cell`, `can_manage_room`) con nuevos endpoints de agencia `tap_agency_get_pricing`/`tap_agency_save_pricing` (single o bulk).
- **Retry de pago por saldo restante**: nueva columna `amount_paid`; `record_paid_capture()` acumula lo abonado y completa al alcanzar el total; `create_order()` cobra solo el saldo pendiente y rechaza `already_paid` (protege contra recobrar el total).
- **Auto-cancel de `request` sin respuesta** y **motivo de rechazo**: nueva columna `cancel_reason`, persistida al cancelar/rechazar y mostrada al huésped y en el back-office; los emails de cancelación incluyen el motivo.
- **Landing / SEO (tema)**: hub de conocimiento, hero con vídeo (Pexels) y schema (`Organization`/`WebSite`/`FAQPage`/`BlogPosting`).

### Changed

- **Refactor — división de god-classes**: `TAP_Shortcodes` (4598 líneas) → `TAP_Listing_Editor`, `TAP_Shortcode_Search`, `TAP_Shortcode_Booking`, `TAP_Shortcode_Account` + core de helpers; `TAP_Dashboard` (2266 líneas) → `TAP_Dashboard_Bookings`, `TAP_Dashboard_Money`, `TAP_Dashboard_Content`, `TAP_Dashboard_Insights`, `TAP_Dashboard_Settings` + core.
- **Máquina de estados del dinero**: `completed` + pagada ya no puede pasar a `cancelled` (solo a `refunded`); `mark_paid` de admin cubre reservas `completed` atascadas en pago `pending`/`partial`/`failed` (idempotente).
- **i18n**: idioma fuente ES unificado en labels, cabeceras CSV y privacidad (`__()`).
- Rendimiento de roles: `TAP_Roles::setup` corre una sola vez por versión del catálogo.

### Fixed

- `ajax_get_pricing` exige `can_manage_room` (antes exponía precios/calendario de alojamientos ajenos solo con el nonce).
- Seeds `seed_tour_types`/`migrate_tour_difficulty` corren una vez (antes ~24 queries por request vía `init`).
- Índices + backfill idempotente (`tap_bookings(agency_id, created_at)`, `tap_reviews(...)`, `commission_payments(status)`); autocompletado con `LIKE 'prefijo%'`; `wp_count_posts` del dashboard cacheado 10 min; validación y `LIMIT` en REST.
- Emails que faltaban: cancelación (huésped + agencia), pago aprobado (agencia), payout solicitado (admin) y refund retry (admin).
- `add_meta_box` en front-end y rewrite rules del tema (`/accommodation/`, `/tour/`, `/destination/` devolvían 404).

## [1.5.7] — 2026-09-18

### Added

- **`uninstall.php`**: full cleanup on plugin deletion (drops all `tap_*` tables after schema checks, clears cron hooks, deletes `tap_%` options/transients, removes the plugin roles and capabilities, and deletes CPT posts/meta/term relationships unless `tap_uninstall_keep_content` is set).
- **GDPR (privacy) compliance for leads**: the lead form now requires explicit consent (`tap_privacy_consent`); `TAP_Leads::submit()` rejects missing consent with `lead_consent_required` and stores it via `TAP_Privacy::record_consent($email, 'lead')`. WordPress Privacy exporters/erasers now cover bookings, leads, consents and reviews (erasure anonymises guest data on user-linked bookings, deletes guest bookings, leads and consents).
- **Agency booking-code cancellation**: guests can cancel with their `booking_code` + email (high-entropy flow), with the legacy `booking_id` + email fallback for older bookings.
- **Idempotent payment ledger**: PayPal refunds/orders are recorded in `tap_payment_orders` (UPSERT by `paypal_order_id`), live refunded-status sync, and pending refunds are retried every day by cron.

### Changed

- **Performance (Lote D)**: paid analytics views are recorded deferred on `shutdown` (non-blocking `INSERT ... ON DUPLICATE KEY UPDATE`), `listing_views` is capped, calendar pricing is fetched in a single batch AJAX call (`tap_get_public_pricing` with `room_ids`, one meta pre-fetch) instead of one request per room, admin assets load on CPT edit screens too, and the `[tap_search_results]`, `[tap_featured_services]` and `[tap_agency_services]` shortcodes use a single indexed main query (active/featured/agency flags resolved with one `wp_postmeta` scan) instead of an N-way meta JOIN. Listing images render with `loading="lazy"`/`decoding="async"`.
- **Security (Lote B)**: stored-XSS sinks in the public search, autocomplete and map popups escape output client-side; agencies can only publish listings they can publish (otherwise `pending`); agency staff can no longer cancel/refund paid bookings (admin-only); verified reviews and the auto-complete of past bookings require `payment_status='paid'`; auto-confirm applies only outside `request` booking mode; role capabilities use the correct plural (`tap_agencies`); moderation admin actions require `manage_options`; CSV exports neutralise spreadsheet formula injection; the `_tap_tap_accommodation_agency_id` meta key typo is fixed to `_tap_acc_agency_id`.
- **Automations/emails**: `TAP_Emails::send()` returns the real result of `wp_mail`, reminder/pre-arrival/review/expiry emails propagate it, and the automation cron runs in bounded batches (100 per task) with cursors and 60-day log pruning.

### Fixed

- Version bumped to 1.5.7 (triggers idempotent schema migrations).

## [1.5.6] — 2026-09-16

### Fixed

- **Fresh-install DB broken**: `tap_bookings` was created without the `guest_name` column, yet the migration queued `ADD COLUMN guest_email ... AFTER guest_name` — every new install crashed with MySQL error 1054. `guest_name` is now in the base `CREATE TABLE` and a guarded `ALTER ... AFTER client_id` migrates existing installs first.
- **`mark_paid` restricted (office money integrity)**: agencies can no longer mark their own bookings as paid (previously any agency could flip `payment_status` and earn owed commission without real collection). The operation now requires `manage_options`, a `confirmed` booking and a non-refunded payment; it also runs as a single atomic conditional update and ignores already-paid bookings, so repeat submissions cannot re-fire `tap_payment_completed`.
- **PayPal capture double-charge (C1)**: `capture_paypal_order` now refuses to capture when the PayPal order maps to no booking (instead of charging and still answering "Payment completed successfully!"), and is idempotent: an already `paid` booking returns success without a second capture, and non-payable statuses (`refunded`, etc.) are rejected.
- **Webhook replay / commission re-earning (C2)**: booking captures now go through `TAP_Booking::record_paid_capture()`, a single atomic + idempotent path (conditional `UPDATE ... WHERE payment_status = prior`), used by both the AJAX capture and `handle_capture_completed`. A replayed capture event (same `capture_id`) or double click can no longer re-mark a booking paid or re-owe the agency commission.
- **Version bumped to 1.5.6.**

### Removed from 1.5.5 (claims that do not match the code)

- `_tap_processed` webhook marker, transactional cancellation/refund webhooks, `GET_LOCK` refund-vs-capture serialization, and the "3011 visible in back-office" alert are **not implemented**; those bullets were overclaims and have been corrected below.

## [1.5.5] — 2026-09-12

### Added

- **Fase 1 — Integridad del dinero (refunds reales + reconciliables):**
- **Gateway failure safeguard**: when the PayPal capture fails (via AJAX or REST), the booking is rolled back instead of blindly setting `payment_status = paid`; the payment row is removed or re-acquitted, and a new payment can be retried.
- **Offline (manual) refund**: refunds set by the admin now create a real ledger `refund` line whose amount is subtracted from net; `net_totals` moved to `payment_status = paid` only, so settlements can be recomputed precisely and are auditable.
- **Admin CSRF protection**: `suite_refunds` asserts `check_admin_referer` usage on the admin refund action (see 1.5.6 for the refund-vs-capture serialization that remains open).
  - **Books closed**: `_tap_payment_details` and `_tap_room_beds`/`_tap_room_amenities` now persist JSON with `JSON_UNESCAPED_UNICODE` — the previous `wp_json_encode` output was being stripped of its `\u` escapes by the DB layer, silently corrupting accents in Spanish amenities/beds/payment names (a real production data-integrity bug).
- **Fase 3 — Producto para agencias (front-editor completo):**
  - Front-end listing editor (shortcode managed by the agency panel) now lets agencies set the **featured image** and a **gallery** via URL (server-side `sideload()` into the media library with extension/type validation), and a **full room editor**: description, floor, amenities (comma separated, deduplicated/trimmed), bed builder (add/remove rows from `bed_types()`), thumbnail and gallery per room.
  - **Server-side price guard**: any number field whose key contains `price` (including legacy `price_per_night`) must be greater than 0 in `save_listing_data`, protecting catalog pricing.
  - **Employee hardening**: `tap_agency_employee` users are denied booking operations server-side (`agency_booking_action` returns `no_agency` unless `manage_options`), and the back-office operations table renders **read-only for employees** instead of dead buttons.
  - `agency_save_room` refactored into a testable core (`TAP_Ajax::save_room_data`) that returns an id or `WP_Error` (`no_title`, `invalid_room`, `invalid_price`, `save_failed`).
- **Tests (run.sh battery now 38 suites, all PASS):** new `suite_refunds` (34 asserts: gateway-failure rollback, offline refund, webhook idempotency, CSRF, `GET_LOCK`) and `suite_front_editor` (asserts the whole F3 stack: featured/gallery sideload, room save/scan/bed builder, amenities normalization, price guard, employee restrictions, read-only ops table).
- **i18n:** 12 new strings (room editor, price guard, employee read-only marker) added to both catalogs (es_ES identity / en_US translation); `.mo` recompiled (`msgfmt --check` OK).

## [Unreleased] — post-1.5.3 improvements

### Added

- **Fase 16b — Unificação del back-office de agencia en un solo panel (T9, v1.5.4):**
  - New professional **Resumen** section in `/mi-cuenta/` for agencies: agency header (name, verified badge, commission rate), plan summary (subscription status, listing capacity), 12-card stats grid (bookings, revenue, commission, net, owed/disputed/settled), listing counts by type, recent bookings, and a leads inbox with CSV export. Employees see a reduced overview (no finances/leads).
  - **Consolidation**: `/dashboard/` (the legacy `[tap_dashboard]` page "Panel de Agencia") now issues a **301 redirect to `/mi-cuenta/`**, and all plugin links (welcome/approval emails, agency-register AJAX redirect, chatbot, manage-listings login prompt, register shortcode) point to the unified back-office. `TAP_Shortcodes::agency_panel()` remains for backward compatibility only.
  - Tests: `suite_back_office` extended with overview assertions (stats grid, quick actions, employee gating, 301 hook, no legacy `/dashboard/` links). Full battery **36/36 suites PASS**.
  - Plugin version bumped to **1.5.4**.

- **Fase 16 — Agency back-office: operaciones + finanzas + liquidaciones (T9, v1.5.3):**
  - New `[tap_front_dash]` sections **Operaciones** (`bo-operations`) and **Finanzas** (`bo-finances`), rendered by `TAP_Front_Dash` for agency administrators; agency employees see only Resumen + Operaciones.
  - `TAP_Booking::agency_booking_action($booking_id, $action)` centralises agency-side operations: `confirm`, `complete`, `mark_paid` and `cancel`; the shared `apply_cancellation()` ensures policy-based penalties and commission voiding are identical for client and agency cancellations.
  - `TAP_Payouts::request($agency_id, $method, $note)` allows agency administrators to self-request a payout for all `owed` commissions; bookings stay `owed` until an admin calls `complete()`, which now also flips `commission_status` from `owed` → `paid` for every booking in the payout.
  - New `source` column on `tap_commission_payments` distinguishes settlements created by the admin (`source='admin'`) from those requested by the agency (`source='agency'`).
  - Front-office JS wiring in `dashboard.js` for `.tap-bo-api` action buttons (confirm/complete/mark_paid/cancel) and the `#tap-bo-payout` payout-request form; i18n labels included.
  - Tests: new `suite_back_office` (41 asserts) covers ownership isolation, confirm→complete flow, mark_paid commission hook, agency cancellation policy, payout request/complete/cancel lifecycle and the new front-office sections + wiring. Full battery **36/36 suites PASS**.
  - Plugin version bumped to **1.5.3**.

- **Fase 15 — Server-side price authority & input hardening (T8, v1.5.2):**
  - `TAP_Booking::create()` is now **authoritative for money**: `total_amount`, `booking_fee` and `client_id` are never trusted from client input. Totals are always recomputed from `calculate_price()` (catalogue price, dates, guests, room) plus the server-derived `get_booking_fee()`; the booking is always attributed via `get_current_user_id()` (or `client_id = 0` for guest checkout).
  - REST `POST /tap/v1/booking` no longer requires (or honours) a client-supplied `total_amount`, closing the vector that let any authenticated user store arbitrary/zero-priced bookings; `privacy_consent` remains mandatory.
  - The web AJAX flow was already safe (it re-derives prices server-side) and is unchanged.
  - Audit confirmed `TAP_Promotions` is a featured/boost engine (`get_price()` returns the featured price option) with no discount layer inside `calculate_price()` — no pricing rules to reconcile.
  - Tests: new `suite_price_authority` (17 asserts) proves forged `total_amount=0.01`, `booking_fee=999` and spoofed `client_id` are rejected/ignored both in `create()` and via REST; `suite_attribution` now simulates a logged-in client (faithful to the real flow), `suite_dashboard` seeds its own booking (removing reliance on ambient data), and `suite_rest`/`suite_guest_checkout` expectations were derived from server computation. Full battery **35/35 suites PASS**.
  - Plugin version bumped to **1.5.2**.

- **Fase 14 — Booking lifecycle & cancellations (T7, v1.5.1):**
  - **Cancellation policies with penalties**, resolved per service with a clear priority: listing metadata (`_tap_cancellation_policy`) → legacy accommodation metadata (`_tap_acc_cancellation`) → per-service-type setting → `flexible` default.
    - `flexible`: 100% refund if cancelling at least 24h before check-in.
    - `moderate`: 100% up to 5 days before, 50% between 2–5 days, 0% below 2 days.
    - `strict`: 50% up to 7 days before, 0% after.
    - `non_refundable`: 0% always.
  - New columns on `tap_bookings`: `refund_amount`, `refund_percent`, `cancellation_policy`, `refunded_at` (idempotent migration). Client cancellations now store the computed refund and only flip `payment_status` to `refunded` when money is actually returned.
  - **Booking status state machine** in `TAP_Booking::update_status()`: `request → pending/confirmed/cancelled`, `pending → confirmed/completed/cancelled/refunded`, `confirmed → completed/cancelled/refunded`, `completed → refunded/cancelled`; `cancelled`/`refunded` are terminal. Transitions are filterable via `tap_booking_status_transitions` and reject illegal moves with a `WP_Error`.
  - **Auto-complete after check-out**: `complete_past_bookings()` converts confirmed bookings whose `check_out` has passed to `completed`, riding the existing hourly `tap_maintenance_hook` and toggleable via the new `tap_booking_auto_complete` option.
  - **Commission lifecycle corrected**: bookings now earn commission only after payment (`tap_payment_completed` → `mark_commission_owed()`) and cancel/refund voids any still-owed commission (`void_commission()`), while never touching disputed or already-settled amounts. Agency settlement and self-service totals only count `payment_status='paid'` bookings that aren't cancelled/refunded.
  - Settings UI section "Cancellation policies" with a policy selector per service type + auto-complete toggle; cancellation emails include the applied policy/refund amount; `my_bookings` and the voucher page show the applied refund; the agency ledger shows `—` for voided commission.
  - New `suite_booking_lifecycle` (60+ asserts) covers policy resolution, refund tables, client-cancel penalties, state-machine guards, auto-complete, commission void/payable rules and settlement filtering. Full battery **34/34 suites PASS**.
  - i18n: ~45 new translatable strings in both catalogues (compiled to `.mo`); plugin version bumped to **1.5.1**.

- **Fase 13 — Role dashboards (T6, v1.5.0):**
  - New unified `/mi-cuenta/` hub (`[tap_front_dash]` shortcode) with role-based routing:
    - **Anonymous** users get a styled login card with signup CTA.
    - **Clients** (`tap_client`) get a full personal dashboard: overview (stat cards for total/upcoming/recent bookings and total spent), bookings list with per-status actions (details, pay, review, cancel), favorites grid, and reviews manager.
    - **Agencies** (`tap_agency_admin`/`tap_agency_employee`) get the existing agency panel wrapped in a color-coded sidebar (employees see a reduced nav).
    - **Administrators** are pointed to the WP admin panel.
  - New class `TAP_Front_Dash` (`includes/class-front-dash.php`) with pure-method rendering, `esc_html()`/`esc_url()` everywhere and a nonce on every AJAX mutation.
  - Dashboard assets: `assets/css/dashboard.css` (sidebar, stat cards, booking/favorite/review cards, empty states, status badges, 100% responsive) and `assets/js/dashboard.js` (AJAX cancel-booking, toggle-favorite, delete-review with confirmations).
  - AJAX endpoints with ownership checks: `tap_dash_cancel_booking`, `tap_dash_toggle_favorite`, `tap_dash_delete_review` — all via `check_ajax_referer` and scoped to the logged-in user's own rows.
  - `agency_panel()` made public so `TAP_Front_Dash` can reuse the existing management UI.
  - New `suite_dashboard` (35 asserts) validates role routing, per-role nav, client sections, asset registration, AJAX hooks and nonce wiring.
  - i18n: +45 translatable strings in both `es_ES` and `en_US` catalogues (dashboard sections, actions, JS confirmations), compiled to `.mo`.
  - Plugin version bumped to **1.5.0**.

- **Fase 12 — Base content & SEO (T5, v1.4.9):**
  - Seeded the production site with real content for Costa Rica's Lago Arenal region:
    - **Services:** 6 tours, 2 transports, 1 boat, 2 packages, 1 equipment — each with GD-generated cover images, approved-agency assignment, location terms, pricing and SEO descriptions.
    - **Accommodations:** 6 existing listings enriched with agency, price-per-night, SEO descriptions and thumbnails.
    - **Destinations:** Nuevo Arenal, Lago Arenal, La Fortuna and Río Celeste as child terms under their parent regions, each with GD-generated cover images.
    - **Blog:** 8 seed articles with categories (Destinos, Aventura, Consejos de viaje, Cultura y gastronomía) and GD cover images.
    - **Pages:** Cómo Funciona, Términos y Condiciones, Blog — all with SEO descriptions and GD covers.
    - **Menus:** Primary Menu (12 items including destination sub-menu) and Footer Menu rebuilt with real page links.
  - New theme templates:
    - `taxonomy-tap_location.php` — SEO-facing destination landing page with hero, breadcrumbs, `[tap_services location=…]` grid, child-destination cards and itinerary CTA.
    - `home.php` — blog index with post-card grid and pagination.
    - CSS blocks for `.tap-dest-*` and `.tap-blog-*` in theme `style.css`.
  - New `suite_content` (28 asserts) validates all seeded content, menus, reading options, i18n and WP 7.1 compatibility.
  - Full battery **32/32 suites PASS**; live smoke: home, tours, destinations, blog, individual tour/accommodation/agency detail pages all 200 / 0 PHP warnings.

### Fixed

- **Payout/settlement integrity (T7):** the admin settlement select and the agency commission totals over-counted — they only filtered by `commission_status='owed'`, so unpaid and cancelled/refunded bookings still showed as collectable commission. Both paths now require `payment_status='paid' AND status NOT IN ('cancelled','refunded')`.
- **WP 7.1 compat (`setup_postdata` global `$post` regression):** WordPress 7.1 changed `setup_postdata()` to no longer set the global `$post` variable. This broke all plugin shortcodes (`tap_services`, `tap_service_detail`, `tap_featured`) which relied on `the_title()`, `the_permalink()`, `the_post_thumbnail()` etc. reading the global. Fixed in `class-shortcodes.php` by explicitly setting `$GLOBALS['post'] = $post` after each `setup_postdata()` call and restoring the previous global after the loop — all three locations patched with backup/restore guards.
  - Symptom: destination pages showed the same service card 9 times; tour detail pages showed empty titles and broken links; blog index showed no posts.
  - Verified on WP 7.1 / PHP 8.3.6.
- **Dashboard review dates (T6):** the client reviews section previously called `get_comment_date()` with an object that wasn't a `WP_Comment`, producing a PHP warning. Reviews now render their date from the review table's own `created_at` column. Also made `TAP_Shortcodes::agency_panel()` public so the front dashboard can reuse the management panel.

- **Fase 11 — Internal automations (T4, v1.4.8):**
  - New `TAP_Automations` + `tap_auto_hook` daily WP-Cron task with four independently toggleable workflows, each with an option-backed sent-log so nothing is actioned twice:
    1. **Payment reminders** — guests with `pending`/`request` bookings that never got paid (window between `tap_payment_reminder_hours` and the stale-booking cutoff, which now safely tolerates an empty stored value).
    2. **Pre-arrival messages** — guests with confirmed bookings whose check-in starts within 48h (code + voucher link).
    3. **Post-stay review requests** — guests whose completed stay ended 2–14 days ago (review link via `?tap_review=`).
    4. **Expiry warnings** — agencies whose active plan subscription or featured listing ends within N days.
  - Wired the previously orphaned `TAP_Emails` notification hooks: `tap_payment_failed`, `tap_payment_refunded`, `tap_dispute_opened`, `tap_dispute_resolved`, `tap_payout_completed`, `tap_payout_cancelled` (agency notified of the payout outcome), `tap_subscription_requested`, `tap_promo_requested` (both admin), plus a contact-form auto-reply to the lead (`tap_auto_lead_ack`, on by default).
  - New platform settings section **Automations** (`register_setting` + settings page deltas) with the toggles and thresholds; cron is cleared on plugin deactivation.
  - i18n: 58 new strings compiled into both `.mo` catalogs (handlers + automation emails + settings labels).
  - QA: new `suite_automations` (17 asserts) added to the battery — full battery **31/31 suites / 773 asserts PASS** in `travel`; live home verified 200 / 0 PHP warnings.

- **Fase 10 — Mobile-first hardening (T2):**
  - Full responsive audit of every front-end surface (theme templates + plugin `public.css`/JS).
  - Data tables (My Bookings, agency Settlements, booking voucher) wrapped in `.tap-table-scroll` so they scroll horizontally instead of overflowing on phones.
  - Hero search bar no longer clips its controls between 481-700px (fields wrap and the button goes full-width); the mobile hero-height override was being silently defeated by a later `80vh` base rule — fixed.
  - Itinerary cards stack their media block on top and budget inputs fill the width on phones; accommodation gallery no longer forces a huge `380px` image min-height on mobile.
  - Reviews summary and review items wrap/stack on narrow screens; categories grid collapses to 2 columns between 481-600px.
  - Unified the `.tap-status-*` palette (pending/confirmed/paid/completed/cancelled/refunded/unpaid) to the design tokens used on booking cards, so the same status looks identical across the whole UI.
  - QA: full battery **30/30 suites / 756 asserts PASS**; live renders (home, wizard, voucher, my-bookings) verified with 0 PHP warnings.

- **Fase 9 — Landing (T1) + Itinerary Builder (T3):**
  - **T1 Landing:** new `front-page.php` — bilingual modern landing (hero+search with parallax `.tap-hero-bg` and `tap-hs-*` autosuggest), stats strip, "explore by interest" chips (real `tap_tour_type` terms), selected experiences (real content, E2E fixtures filtered, per-type price + rating), 3-step itinerary CTA, verified agencies, approved reviews, articles, agency CTA; `.tap-landing-*` scoped CSS in theme `style.css` (mobile-first, tokens, no JS needed for visibility); every section hides when its data is empty. CTA now links to the itinerary builder page (`/armar-mi-viaje/`).
  - **T3 Itinerary builder (v1.4.7):**
    - `TAP_Itinerary` + `[tap_itinerary_builder]` shortcode: interactive wizard 1) interests (`tap_tour_type`/`tap_service_cat`) → 2) destinations (`tap_location`, 2-level) → 3) budget range + service types → matching results.
    - Matching reuses canonical keys (`TAP_Promotions::keys_for_type` active flag, `TAP_API::get_price_key`), tax queries on established taxonomies, numeric price band, approved-agency exclusion, featured/rating/date ordering.
    - Progressive enhancement: JS (AJAX `tap_itinerary_search`, nonce-checked) uses localStorage for "Mi itinerario"; without JS a plain GET renders the same results server-side. Relaxed fallback (drops interests, keeps destination+price) when strict yields zero.
    - Shareable `?itin=<id,id>` view: saved itinerary summary with per-service CTAs, estimated total and print.
    - `assets/js/itinerary.js` + `.tap-itin-*` styles in `public.css`.
  - i18n: 36 new builder strings authored in both catalogs (+44 T1 landing strings earlier); `.po` de-duplicated, `.mo` recompiled.
  - QA: new `suite_itinerary` (31 asserts); full battery **30/30 suites / 756 asserts PASS** on travel; live ES/EN renders (wizard, no-JS GET empty-state and share view) verified with 0 PHP warnings.

- **Fase 8 — full i18n coverage + QA (v1.4.6)**:
  - Closed every translation gap across both catalogs: re-extracted all 1,349 runtime strings from the plugin (domain-matched), kept the ES→EN (`en_US.po`) and EN→ES (`es_ES.po`) catalogs fully translated — 492 brand-new bilingual strings authored (placeholders `%s`/`%1$s` and inline HTML preserved), plus 329 English-identity entries in `en_US` and 527 Spanish-identity entries in `es_ES`; every string in the plugin now exists in both catalogs (0 missing).
  - `.po` books rewritten with correct quote/backslash escaping and de-duplicated by unescaped `msgid` (first definition wins) so `msgfmt --check` compiles clean; both `.mo` recompiled and verified at runtime in ES and EN modes (`TAP_Localization::set_lang`).
  - Plugin header `Version: 1.4.6` synced with `TAP_VERSION`.
  - Full battery **29/29 suites / 725 asserts PASS** on travel.
  - **Hotfix:** `TAP_Privacy::ensure_privacy_page()` now guarantees a `WP_Rewrite` instance before `wp_insert_post()` — a fresh-install migration (which runs on `plugins_loaded`, before rewrites exist) used to die with `Call to a member function get_page_permastruct() on null`. Reproduced on ivanchodev (rollback to 1.4.5 + page deletion) and verified the migration recreates the privacy page; both installs green **29/29** (commit `9ab9008`).

- **Fase 7 — Ley 8968 personal-data protection (v1.4.6)**:
  - New `TAP_Privacy` (`includes/class-privacy.php`): public **Privacy Notice** page (`[tap_privacy]`, shortcode-rendered policy covering the 8 Ley 8968 sections: data controller, data collected, purpose/legal basis, retention, transfers, rights, cookies, notice updates) plus an **ARCO request form** (access, rectification, update, erasure, opposition, consent withdrawal) handled via `admin_post` with nonce and consent logging.
  - **Consent capture** enforced everywhere personal data is submitted: booking creation (AJAX + REST `tap/v1/booking`), agency registration and the theme templates all require the consent checkbox; each consent is recorded in `wp_tap_consents` (`tap_privacy_consent` param, `TAP_Leads`/booking payloads) and refusal blocks the operation with a translated message.
  - New `wp_tap_consents` + `wp_tap_privacy_requests` tables (installer/migrate, `tap_version` 1.4.6) and an admin **Privacidad** panel (`Travel Platform`, `tap_manage_settings`) with Pending/Handled request tabs, consent log and privacy contact email setting.
  - Emails (booking flow, agency lifecycle) now carry a privacy note + data-rights contact footer.
  - `suite_privacy` (44 assertions) covering consent gating, ARCO submission/marking, admin render, email footer and page auto-provision; battery **29/29 / 725 asserts**.

- **Fase 6 — payouts & disputes (v1.4.5)**:
  - Settlement lifecycle `pending → completed|cancelled` in `wp_tap_commissions` (`paid`/`paid_at` columns): cancelling a settlement returns the commissions to "por cobrar"; admin **Comisiones** panel gains settlement selection, confirm/cancel and CSV export.
  - **Traveler disputes** freezing the commission (`disputed`) from the booking voucher and resolved for/against the agency or withdrawn; new `wp_tap_disputes` table, admin **Disputas** panel and agency-facing "Disputas de tus reservas" section.
  - `suite_disputes` green; TAP_VERSION 1.4.5.

- **Fase 5 — verified reviews (v1.4.4)**:
  - Only guests with a confirmed/completed booking can review (`TAP_Reviews` eligible/latest/match server-side); reviews are stored `is_verified=1` and carry a **"Reseña verificada"** badge on the front-end and admin; `get_reviews` no longer leaks email or moderation fields.
  - Migration adds `is_verified` column; `suite_reviews_verified` green.

- **Fase 4 — Lote C: public support-chat widget, i18n and phase close**:
  - New `[tap_chatbot]` shortcode (`TAP_Shortcodes::chatbot`, bufferized return): self-contained collapsible `.tap-chat` block (role=dialog, `aria-hidden` panel, focus handling) with 5 translated quick-question chips, bilingual placeholder/send labels and a `tapI18n` bundle (`chatLabel`, `chatOpen`, `chatClose`, `chatPlaceholder`, `chatSend`, `chatIntro`, `chatThinking`, `chatError`).
  - `public.js` `Chat` module: toggle + caret state, keyboard-safe send, AJAX POST to `tap_chatbot_message` (nonce), DOM-safe message rendering (no user input through `innerHTML`), thinking/error states and graceful rate-limit handling; widget CSS in `public.css` matching the audit visual language.
  - 8 new catalog entries (EN→ES + ES→EN) validated through the `dictionaries.py` / `gen_mo.py` / `msgfmt --check` pipeline (0 missing / 0 duplicates) and shipped as `.po` + `.mo`.
  - `suite_chatbot` widget checks: shortcode registered, skeleton (role/labels), 5 chips, ES placeholder, ES/EN chip translation, unique DOM ids across two instances. Full battery **20/20** on travel and ivanchodev, migrations on `1.4.2` in both, 0 residue.
  - Docs closed: `USER_GUIDE` (Support chat), `DEVELOPER_GUIDE` (chatbot + moderation sections, 20-suite battery, `tap_chatbot_provider` hook), `FASE4_PLAN` marked complete.

- **Fase 4 — Lote B: automatic content-moderation engine**:
  - New `TAP_Moderation` engine (`includes/class-moderation.php`): deterministic `assess($text, $kind)` scoring every piece of visitor content as `ok` / `review` / `block` — abuse vocabulary (ES/EN, accent-insensitive), privacy leaks (email/phone in reviews), spam keywords and URL bursts, and gibberish detection; no external services, reason codes stored for the admin queue.
  - **Review pipeline** (`tap/v1/review`): abusive/PII/spam/3+-link reviews are rejected with a translated `400` (`review_blocked`); single-link reviews are stored `mod_status=review` and still hidden from public reads; clean reviews land `ok`. Response message switches to "pending moderation" when flagged.
  - **Lead pipeline** (`TAP_Leads::submit`): spam leads are rejected (`lead_blocked`, nothing stored); link-bearing/PII-carrying leads are stored `mod_status=review`; clean leads `ok`.
  - New `mod_status` / `mod_reason` columns on `wp_tap_reviews` and `wp_tap_leads` (added via `TAP_Installer::migrate()`; `tap_version` 1.4.2, fresh-install `CREATE TABLE` updated).
  - New admin **Moderation** page (`Travel Platform → Moderación`, `manage_options`): queue of flagged reviews + leads with kind, reason label (translated ES/EN), approve / manual-block / delete actions (nonce-verified `handle_admin_actions` on `admin_init`).
  - 9 new catalog entries (blocked/PII/spam/links/gibberish/manual reason labels, public moderation messages, menu name) via `gen_mo.py` + `msgfmt --check`, shipped as `.po` + `.mo`.
  - New `suite_moderation` (33 assertions): assess rule matrix ES/EN, REST blocked/accepted/held states + queue surfacing + admin page render + lead submission filtering + migration columns + inert-action guard. Both installs green on **20/20** suites, migrations applied (`1.4.2`) and 0 residue.

- **Fase 4 — Lote A: support chatbot engine (IA/chatbot, back-end core)**:
  - New `TAP_Chatbot` rules engine (`includes/class-chatbot.php`): deterministic, multilingual intent detection (ES/EN) via accent-insensitive normalization + weighted pattern scoring. Intents: greeting, booking, search, checkout/voucher, payment, cancel, agency, favorites, contact, availability, pricing, recommend (top published services) and fallback.
  - Replies are English msgids translated through the plugin domain (Spanish descriptions); every answer returns the reply plus actionable links (search, my bookings, voucher, dashboard, login) and 5 quick-question chips in the active language.
  - New AJAX endpoint `tap_chatbot_message` (guest + logged-in) with public nonce `tap_nonce`, per-IP rate limit (12 msgs / 10 min via transient), empty-message guard and Spanish/English error messages.
  - New `wp_tap_chat_events` table (aggregate intent/lang/date only, no PII); installed on activation/version bump (`tap_version` 1.4.1) in `TAP_Installer` (dbDelta + `migrate()` fallback).
  - 28 new catalog entries (bot replies, link labels, quick questions, limit/placeholder messages) committed to `es_ES`/`en_US` `.po` + `.mo` (built with `msgfmt --check`).
  - New `suite_chatbot` (28 assertions): intent mapping ES/EN, ES/EN translation round-trip, deterministic output, accent normalization, recommend resolves live published titles, rate-limit window, chat-event logging (fallback skipped), AJAX handler + migration table presence. Both installs green on **19/19** suites, `tap_chat_events` migrated on both, 0 residue.

- **UI/UX audit batch 6 — responsive polish & full-audit sign-off**:
  - `[tap_search]` box: search fields stretch full width on ≤768px (were previously right-aligned in column mode); submit button full width on mobile.
  - Tables: `.tap-table-scroll` gets iOS momentum scrolling; cells compact + `white-space: nowrap` on ≤480px so wide booking/dashboard tables scroll cleanly.
  - Agency public profile: header stacks (logo above info) at ≤640px; archive sidebar filters collapse to a single column on tablets.
  - Hero autocomplete suggestions clamp height on short/small screens; availability calendar gains horizontal overflow protection.
  - Verified end-to-end: ES homepage and search-results render only translated strings; `?lang=en` renders English and sets the `tap_lang` cookie. Audit lots 1–6 complete with both installs green (battery 18/18) and 0 residue.

- **UI/UX audit batch 5 — visible i18n & technical a11y**:
  - **Theme strings now translate** (139 msgids across 20 theme templates): theme textdomain migrated to `travel-agency-platform`; 133 new catalog entries added (ES→EN and EN→ES) so ES mode shows Spanish and EN mode (`?lang=en`) shows English for hero, search, results, 404, booking widget, filters and type labels.
  - **Front-end JS strings externalized** via a new `tapI18n` global (localized on the `tap-public` handle): favorites toasts, review list/form messages, placeholder counts (`reseña`/`reseñas`), booking-capacity messages, and lightbox ARIA labels. Theme inline scripts (`service-reviews.php`, `single-service.php`) consume `window.tapI18n` instead of hardcoded ES.
  - **A11y técnicas**:
    - Reduced-motion support: `prefers-reduced-motion` CSS override (`.tap-section`, `.tap-category-card`, gallery images) + JS guard in `main.js` disables reveal animation, staggered category entrance and hero parallax.
    - Mobile drawer: toggle now declares `aria-controls="main-nav"`; opening moves focus to the first nav link, closing restores focus to the toggle.
    - Lightbox: `role="dialog"`, `aria-modal`, ARIA labels, focus moves to the close button on open, restores to the trigger on close, and Tab cycles within the overlay.
    - `[tap_search]` shortcode: unique `for`/`id` label association (`tap-sf-*`) for destination, check-in, check-out and guests fields.
    - New entries produced with `msgfmt --check`; catalogs re-shipped as both `.po` and `.mo` for `es_ES` and `en_US`.

- **UI/UX audit batch 4 (P1) — generic single-service page**:
  - Header block (`.tap-svc-header`): badge + h1 rows baseline aligned, agency link emphasized.
  - Specs grid (`.tap-acc-facts`/`.tap-acc-fact`): responsive auto-fit cards with micro uppercase labels.
  - Legacy detail shortcode meta row (`.tap-meta-grid`/`.tap-meta-item`, `.tap-detail-price`).
  - Review star selector spacing (`.tap-review-stars`).
  - Content-partial cards: `.tap-price` (primary, bold) and `.tap-duration` (micro uppercase) paragraphs for tour/transport/boat/car/package listings.
  - Fixed single-image gallery layout on generic service pages: `.tap-acc-gallery` with a lone `<img>` (tours, transport, boats…) no longer leaves an empty thumb column; image stretches full width via `:has(> img:only-of-type)`.

- **UI/UX audit batch 3 (P1) — forms, leads, voucher & booking summary**:
  - **Booking widget**: `.tap-form-group` labels/margins, `.tap-total-display` moved out of inline styles into a themed rule; total row reads as a strong summary line.
  - **Lead forms** (`[tap_lead_form]`): card wrapper (`.tap-lead-form-wrap`) with styled labels and full-width inputs/textarea with focus ring, matching the rest of the theme.
  - **Voucher guest-payment panel** (`.tap-voucher-lookup`) styled as a card; voucher totals table right-aligns `.tap-voucher-total-label` rows.
  - **Favorites login callout**: generic `.tap-box` + `.tap-box-warn` styles.
  - **Promo/PayPal actions**: `.tap-promo-paypal-form` flex layout + `.tap-promo-btn` nowrap; plan paypal/subscribe buttons already rode on `.tap-btn` but now have spacing context.
  - **Booking summary rows**: `.tap-bw-total-row` modifiers for price (strong) and fee (subtle) rows, covering both server-rendered and JS-rendered lines.

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
