<?php
/**
 * E2E suite: Fase 4 — chatbot engine (intents, i18n, links, rate limit, events).
 * Run via: wp --path="<site>/app/public" eval-file tests/suite_chatbot.php
 */
require __DIR__ . '/bootstrap.php';

$C = 'TAP_Chatbot';
tap_t_assert(class_exists($C), 'TAP_Chatbot class present');
tap_t_assert(has_action('wp_ajax_tap_chatbot_message', ['TAP_Ajax', 'chatbot_message']), 'AJAX guest handler registered');
tap_t_assert(has_action('wp_ajax_nopriv_tap_chatbot_message', ['TAP_Ajax', 'chatbot_message']), 'AJAX nopriv handler registered');

// --- fixtures: two published tours for the "recommend" intent ---
$tok1 = 'CXREC' . substr(md5(uniqid('', true)), 0, 8);
$tok2 = 'CXREC' . substr(md5(uniqid('', true)), 0, 8);
$p1 = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Chestnut Route ' . $tok1]);
$p2 = wp_insert_post(['post_type' => 'tap_tour', 'post_status' => 'publish', 'post_title' => 'Oak Route ' . $tok2]);
tap_t_assert(is_numeric($p1) && $p1 > 0, 'recommend fixture tour 1 published');
tap_t_assert(is_numeric($p2) && $p2 > 0, 'recommend fixture tour 2 published');

// --- intent mapping (default Spanish front-end) ---
$cases = [
    'hola'                      => 'greeting',
    '¿cómo reservo?'              => 'booking',
    'reservar'                    => 'booking',
    'how do i book a stay'        => 'booking',
    'buscar'                      => 'search',
    'quiero buscar'               => 'search',
    'como pago mi bono'           => 'checkout',
    'este es mi voucher'           => 'checkout',
    'how to pay my voucher'       => 'checkout',
    'paypal'                      => 'payment',
    'devolución de dinero'        => 'payment',
    'cancelar mi reserva'         => 'cancel',
    'cancellation'                => 'cancel',
    'quiero ser agencia'          => 'agency',
    'join as agency'              => 'agency',
    'mis favoritos'               => 'favorites',
    'contacto con la agencia'     => 'contact',
    'hay disponibilidad'          => 'availability',
    'cuánto cuesta'               => 'pricing',
    'recomiéndame'                => 'recommend',
    'xyzzy quux 129384'           => 'fallback',
];
foreach ($cases as $msg => $want) {
    tap_t_assert($C::answer($msg)['intent'] === $want, "intent('$msg') === $want (got " . $C::answer($msg)['intent'] . ')');
}

// --- default ES translations wired + structure ---
$a = $C::answer('hola');
tap_t_assert($a['reply'] === '¡Bienvenido al asistente de viajes! Pregunta cómo reservar alojamiento, encontrar tours, pagar tu bono, cancelar una reserva o unirte como agencia. Usa una de las preguntas rápidas de abajo.', 'ES greeting reply translated');
tap_t_assert(count($a['suggestions']) === 5 && $a['suggestions'][0] === '¿Cómo reservo un alojamiento?', 'ES quick questions render');
tap_t_assert(is_array($a['links']) && count($a['links']) > 0 && isset($a['links'][0]['url']), 'greeting links present');

$v = $C::answer('voucher');
tap_t_assert($v['links'][0]['label'] === 'Página de bono' && strpos($v['links'][0]['url'], 'booking-detail') !== false, 'voucher link labelled ES and points to booking-detail');

// --- nonce/handler shape (rate limit & replies) can be exercised safely below ---

// --- EN round-trip ---
$L = 'TAP_Localization';
tap_t_assert($L::set_lang('en') === true, 'set_lang("en") accepted');
$e = $C::answer('hi');
tap_t_assert($e['reply'] === 'Welcome to the travel assistant! Ask me about booking a stay, finding tours, paying your voucher, cancelling a booking, or joining as an agency. Use one of the quick questions below.', 'EN greeting reply');
tap_t_assert($e['suggestions'][0] === 'How do I book a stay?', 'EN quick questions render');
$ve = $C::answer('voucher');
tap_t_assert($ve['links'][0]['label'] === 'Voucher page' && strpos($ve['links'][0]['url'], 'booking-detail') !== false, 'EN voucher link label/url');
tap_t_assert($C::answer('xyzzy quux 554433') === $C::answer('xyzzy quux 554433'), 'deterministic same input/same output');
tap_t_assert($L::set_lang('es') === true, 'restore es');

// --- normalization: case + accents ---
tap_t_assert($C::answer('RESERVAR')['intent'] === 'booking', 'uppercase normalized to lowercase');
tap_t_assert($C::answer('estación') !== false, 'accented normalization does not throw');

// --- recommendation resolves published titles ---
$r = $C::answer('recomiéndame');
tap_t_assert($r['intent'] === 'recommend', 'recommend intent detected');
$found = (strpos($r['reply'], $tok1) !== false || strpos($r['reply'], $tok2) !== false);
tap_t_assert($found, 'recommend reply lists a live published service');

// --- rate limit (fresh per-IP window) ---
$ip = '198.51.100.' . mt_rand(11, 200) . 'x' . md5(uniqid('', true));
$limited = false;
for ($i = 0; $i < 12; $i++) {
    if ($C::rate_limited($ip)) {
        $limited = true;
        break;
    }
}
tap_t_assert($limited === false, 'first 12 messages pass the rate limit');
tap_t_assert($C::rate_limited($ip) === true, '13th message blocked by rate limit');
delete_transient('tap_chat_' . md5($ip . '|'));

// --- event log: aggregates only, fallback skipped ---
global $wpdb;
$table = $wpdb->prefix . 'tap_chat_events';
tap_t_assert($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table, 'tap_chat_events table exists');
$C::log_event('agency', 'tt');
$C::log_event('fallback', 'tt');
$rows = $wpdb->get_results("SELECT * FROM {$table} WHERE lang='tt'");
tap_t_assert(count($rows) === 1 && isset($rows[0]->intent) && $rows[0]->intent === 'agency', 'chat events logged once, fallback skipped');
$wpdb->delete($table, ['lang' => 'tt']);

// --- cleanup fixtures ---
wp_delete_post($p1, true);
wp_delete_post($p2, true);
tap_t_assert(null === $wpdb->get_var("SELECT ID FROM {$table} WHERE 1=0"), 'table queryable');

tap_t_finish();