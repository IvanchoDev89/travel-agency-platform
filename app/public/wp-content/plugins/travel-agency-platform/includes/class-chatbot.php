<?php
defined('ABSPATH') || exit;

/**
 * Fase 4 — TAP_Chatbot
 *
 * Deterministic, multilingual support assistant. It matches user messages
 * against lightweight language patterns (ES/EN), returns a translated reply
 * plus actionable links, and logs only intent aggregates (no PII).
 *
 * Extensible: hook the `tap_chatbot_provider` filter to plug an external LLM
 * later; the default provider is the rules engine below.
 */

class TAP_Chatbot {

    /** Quick-question chips offered on every answer. */
    private const SUGGESTIONS = [
        'How do I book a stay?',
        'How do I search trips?',
        'How do I pay my voucher?',
        'How do I cancel a booking?',
        'How do I join as an agency?',
    ];

    /**
     * Answer a visitor message.
     *
     * @param string $message
     * @return array{intent:string,reply:string,links:array,suggestions:array}
     */
    public static function answer($message) {
        $msg = self::normalize((string) $message);
        $best = 'fallback';
        $bestScore = 0;

        foreach (self::intents() as $key => $spec) {
            if ('fallback' === $key) {
                continue;
            }
            $score = self::score($msg, $spec['patterns']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $key;
            }
        }

        return self::build($best);
    }

    /** Build a translated response for an intent. */
    private static function build($key) {
        $spec  = self::intents()[$key] ?? self::intents()['fallback'];
        $links = [];
        foreach (($spec['links'] ?? []) as $item) {
            $links[] = [
                'label' => __($item[0], 'travel-agency-platform'),
                'url'   => $item[1](),
            ];
        }
        $reply = ($key === 'recommend') ? self::recommendation_reply() : __($spec['reply'], 'travel-agency-platform');

        return [
            'intent'      => $key,
            'reply'       => $reply,
            'links'       => $links,
            'suggestions' => array_map(function ($s) {
                return __($s, 'travel-agency-platform');
            }, self::SUGGESTIONS),
        ];
    }

    /** Top published services for the recommendation intent. */
    private static function recommendation_reply() {
        $types = array_keys(TAP_Post_Types::get_service_types());
        $types = array_values(array_diff($types, ['tap_room']));
        $posts = get_posts([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if (!$posts) {
            return __('I could not find any published services right now. Use the search page to explore the catalogue.', 'travel-agency-platform');
        }
        $parts = [__('Here are a few services from the platform right now:', 'travel-agency-platform')];
        foreach ($posts as $p) {
            $parts[] = '  - ' . $p->post_title;
        }
        return implode("\n", $parts);
    }

    /** Pattern lists keyed by intent: [regex, weight]. Scores on normalized text. */
    private static function intents() {
        $search = fn () => home_url('/search-results');
        $voucher = fn () => home_url('/booking-detail/');
        $mybookings = fn () => home_url('/my-bookings');
        $dashboard = fn () => home_url('/mi-cuenta/');
        $login = fn () => wp_login_url(home_url('/my-bookings'));
        $home = fn () => home_url('/');

        return [
            'greeting' => [
                'patterns' => [
                    ['hola', 2], ['hello', 2], ['hi\\b', 2], ['buenas', 2], ['hey', 1], ['ayuda', 2], ['help', 2], ['que puedes hacer', 2], ['what can you', 2], ['empezar', 1], ['como funciona', 3], ['how does this work', 3], ['inicio', 1],
                ],
                'reply' => 'Welcome to the travel assistant! Ask me about booking a stay, finding tours, paying your voucher, cancelling a booking, or joining as an agency. Use one of the quick questions below.',
                'links' => [['Open the search page', $search]],
            ],
            'booking' => [
                'patterns' => [
                    ['como.*reserv', 3], ['reservar', 3], ['reserva', 2], ['hacer una reserva', 4], ['como reservo', 4], ['book a', 2], ['make a booking', 4], ['booking form', 3], ['stay in', 2], ['alojarme', 3], ['alojamiento', 2], ['habitacion', 2], ['room', 2], ['noche', 1], ['cuartos', 2], ['seleccionar fechas', 3],
                ],
                'reply' => 'To book a stay: open the property, choose check-in and check-out dates, pick a room type (if it has rooms) and send the reservation. You can book without an account — you will get a booking code to confirm and pay. Use the search page to find options with your dates.',
                'links' => [['Search services', $search], ['My bookings', $mybookings]],
            ],
            'search' => [
                'patterns' => [
                    ['buscar', 2], ['busca', 2], ['buscando', 2], ['search', 2], ['find', 1], ['encontrar', 2], ['que tours', 2], ['explorar', 2], ['catalogo', 3], ['filtrar', 2], ['filtros', 2], ['resultados', 2], ['results', 2], ['destino', 1], ['como busco', 4],
                ],
                'reply' => 'You can search the whole catalogue from the search page: type a destination, name, or service type (stays, tours, transfers, car rental, boats, packages) and refine by type, location, price, and sort order. The search box suggests destinations as you type.',
                'links' => [['Open the search page', $search]],
            ],
            'checkout' => [
                'patterns' => [
                    ['voucher', 3], ['checkout', 3], ['check out', 3], ['pago.*reserva', 3], ['booking code', 3], ['codigo.*reserva', 3], ['completar.*reserva', 4], ['confirmar.*reserva', 3], ['como pago', 4], ['finalizar', 2], ['resumen', 2], ['pagar mi', 3],
                ],
                'reply' => 'After booking you get a code like TAP-XXXXXXXX-XXXXXX. Open the checkout or voucher page with that code to see the total (including the service fee) and pay by card or PayPal. If you have an account, the booking also appears in My Bookings.',
                'links' => [['Voucher page', $voucher], ['My bookings', $mybookings]],
            ],
            'payment' => [
                'patterns' => [
                    ['paypal', 3], ['tarjeta', 2], ['card payment', 2], ['pago', 2], ['payment', 2], ['moneda', 3], ['currency', 3], ['metodo.*pago', 4], ['refund', 3], ['reembolso', 3], ['devolucion', 3], ['devolucion', 3],
                ],
                'reply' => 'We accept secure card or PayPal payments when you confirm a booking; agency plans also subscribe via PayPal. The total always includes the service fee. For refunds, check the cancellation policy of the agency (see My Bookings).',
                'links' => [['My bookings', $mybookings]],
            ],
            'cancel' => [
                'patterns' => [
                    ['cancelar', 3], ['cancel', 3], ['cancellation', 3], ['cancelacion', 4], ['politica de cancelacion', 5], ['anular', 3], ['cancel my booking', 5], ['cancelar mi reserva', 5],
                ],
                'reply' => 'To cancel: open My Bookings, open the booking and press Cancel; the agency is notified and keeps the record. Refunds depend on the agency cancellation policy. Without an account, enter your booking code on the voucher page.',
                'links' => [['My bookings', $mybookings], ['Voucher page', $voucher]],
            ],
            'agency' => [
                'patterns' => [
                    ['agencia', 2], ['agency', 2], ['unirme como agencia', 5], ['join as agency', 5], ['registrarme', 3], ['registrarme como agencia', 5], ['proveedor', 2], ['publicar', 3], ['comision', 4], ['commission', 4], ['verificada', 3], ['verified', 3], ['subscripcion', 3], ['subscription', 3], ['plan', 1],
                ],
                'reply' => 'Agencies can join from the site: register as an agency, complete your profile (verification), and publish your own catalogue from your dashboard — stays, tours, transfers, cars, boats and packages. The free plan lets you publish; upgrade opens more listings.',
                'links' => [['Open the dashboard', $dashboard]],
            ],
            'favorites' => [
                'patterns' => [
                    ['favorito', 3], ['favorite', 3], ['favorites', 3], ['guardar.*corazon', 4], ['corazon', 2], ['save.*service', 3], ['heart', 2],
                ],
                'reply' => 'Tap the heart on any service to save it to Favorites. You need a free user account — you will be taken to log in if you are not. Your saved list lives on the Favorites page.',
                'links' => [['Log in', $login]],
            ],
            'contact' => [
                'patterns' => [
                    ['contacto', 3], ['contact', 2], ['lead', 2], ['escribirle', 3], ['preguntar', 2], ['question', 1], ['mensaje.*agencia', 4], ['message.*agency', 4], ['hablar con', 2], ['soporte', 2], ['support', 2],
                ],
                'reply' => 'Each agency profile and service page includes a contact form; the message goes straight to the agency by email. For anything else, use the platform support channels or the language switch.',
                'links' => [['Go to the site', $home]],
            ],
            'availability' => [
                'patterns' => [
                    ['disponibilidad', 4], ['available', 2], ['availability', 3], ['calendario', 3], ['calendar', 2], ['minimo.*noches', 5], ['minimum.*nights', 5], ['bloqueado', 2], ['blocked', 2], ['temporada', 3], ['season', 2],
                ],
                'reply' => 'Availability works by dates: minimum-night stays and blocked days show on the property calendar. Pick your check-in and check-out dates first — the booking widget recalculates the total and shows which room types are free.',
                'links' => [['Open the search page', $search]],
            ],
            'pricing' => [
                'patterns' => [
                    ['precio', 2], ['price', 2], ['cuanto', 2], ['how much', 2], ['tarifa', 3], ['rate', 2], ['por noche', 4], ['per night', 4], ['por persona', 4], ['per person', 4], ['por adulto', 4], ['per adult', 4], ['service fee', 3], ['tarifa de servicio', 4], ['impuesto', 2], ['tax', 2],
                ],
                'reply' => 'Prices show per night (stays) or per person or adult (tours, trips) in the site currency. The final total adds the platform service fee. The booking widget updates the total live as you choose dates and guests.',
                'links' => [['Open the search page', $search]],
            ],
            'recommend' => [
                'patterns' => [
                    ['recomend', 4], ['recomienda', 4], ['recomiendame', 5], ['recomiendamelo', 5], ['sugerir', 3], ['suggest', 2], ['opciones', 2], ['options', 2], ['ideas', 2], ['populares', 3], ['popular', 2], ['top', 1], ['tendencias', 3], ['que me ofreces', 5], ['what do you have', 4],
                ],
                'reply' => 'Here are a few services from the platform right now:',
                'links' => [['Open the search page', $search]],
            ],
            'fallback' => [
                'patterns' => [],
                'reply' => 'I am not sure I understood that question. Try one of the quick questions below, use the search page, or contact an agency directly through a profile page.',
                'links' => [['Open the search page', $search], ['My bookings', $mybookings]],
            ],
        ];
    }

    /** Sum the weights of every pattern matched against the normalized message. */
    private static function score($msg, $patterns) {
        $score = 0;
        foreach ($patterns as $p) {
            [$regex, $weight] = $p;
            if (preg_match('/' . $regex . '/', $msg)) {
                $score += (int) $weight;
            }
        }
        return $score;
    }

    /** Normalize: lowercase, strip accents, keep a-z0-9 and spaces, collapse. */
    private static function normalize($s) {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, self::accents());
        $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private static function accents() {
        return [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i',
            'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
        ];
    }

    /**
     * Per-IP rate limit for the chat endpoint (12 messages / 10 min).
     */
    public static function rate_limited($ip) {
        $key  = 'tap_chat_' . md5($ip . '|' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
        $data = get_transient($key);
        if (false === $data) {
            set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
            return false;
        }
        if ((int) $data >= 12) {
            return true;
        }
        set_transient($key, (int) $data + 1, 10 * MINUTE_IN_SECONDS);
        return false;
    }

    /** Log an aggregate chat event (no PII). */
    public static function log_event($intent, $lang) {
        global $wpdb;
        $intent = substr(sanitize_key($intent), 0, 40);
        $lang   = substr(sanitize_key((string) $lang), 0, 10);
        if ('' === $intent || 'fallback' === $intent) {
            return;
        }
        $wpdb->insert($wpdb->prefix . 'tap_chat_events', [
            'intent' => $intent,
            'lang'   => $lang,
        ]);
    }
}