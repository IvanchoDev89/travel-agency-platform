# Changelog

All notable changes to the **Travel Agency Platform** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/) and the project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — post-1.5.2 improvements

### Added

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
