<?php
/**
 * suite_privacy.php — Ley 8968 (data protection): consent logs, ARCO requests,
 * privacy policy page + shortcodes, email footer, and booking/agency-consent gates.
 * Run: wp --path="<site>/app/public" eval-file tests/suite_privacy.php
 */
require __DIR__ . '/bootstrap.php';

global $wpdb;
$p = $wpdb->prefix;

/* 1) tables + page installed ------------------------------------------------- */
$consents = $p . 'tap_consents';
$requests = $p . 'tap_privacy_requests';
tap_t_assert($wpdb->get_var("SHOW TABLES LIKE '{$consents}'") === $consents, 'tap_consents table exists');
tap_t_assert($wpdb->get_var("SHOW TABLES LIKE '{$requests}'") === $requests, 'tap_privacy_requests table exists');

$page_id = (int) get_option('tap_privacy_page');
$page = $page_id ? get_post($page_id) : null;
tap_t_assert($page && $page->post_status === 'publish', 'privacy page option resolves to a published page');
tap_t_assert($page && strpos($page->post_content, '[tap_privacy]') !== false, 'privacy page embeds [tap_privacy]');
tap_t_assert(TAP_Privacy::policy_url() !== '', 'policy_url returns a URL');
tap_t_assert(TAP_Privacy::contact_email() === get_option('admin_email'), 'privacy contact defaults to admin email');
tap_t_assert(TAP_Privacy::POLICY_VERSION === '1.0', 'policy version constant present');

/* 2) consent logging ---------------------------------------------------------- */
$email = tap_t_unique_email();
tap_t_assert(TAP_Privacy::record_consent($email, 'booking', 0) > 0, 'record_consent returns an id');
tap_t_assert(TAP_Privacy::has_consent($email, 'booking'), 'has_consent true after recording');
tap_t_assert(!TAP_Privacy::has_consent($email, 'agency_registration'), 'has_consent false for other scope');
tap_t_assert(TAP_Privacy::record_consent('not-an-email', 'booking', 0) === 0, 'record_consent rejects invalid email');
tap_t_assert(TAP_Privacy::record_consent($email, 'bogus-scope', 0) === 0, 'record_consent rejects unknown scope');

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$consents} WHERE email = %s ORDER BY id DESC LIMIT 1", $email));
tap_t_assert($row && $row->scope === 'booking' && $row->policy_version === TAP_Privacy::POLICY_VERSION, 'consent row stores scope + policy version');
tap_t_assert($row && $row->created_at !== '', 'consent row has created_at');

$recent = TAP_Privacy::recent_consents(50);
tap_t_assert(is_array($recent) && count($recent) >= 1, 'recent_consents returns logged consents');

/* 3) ARCO request lifecycle ---------------------------------------------------- */
tap_t_assert(is_wp_error(TAP_Privacy::save_request('nope', ['access'], 'mensaje largo de prueba para superar el límite')), 'save_request rejects invalid email');
$err_none = TAP_Privacy::save_request($email, [], 'mensaje largo de prueba para superar el límite que exijo aquí');
tap_t_assert(is_wp_error($err_none), 'save_request requires at least one right');
$err_short = TAP_Privacy::save_request($email, ['access'], 'corto');
tap_t_assert(is_wp_error($err_short), 'save_request requires details length');

$req_id = TAP_Privacy::save_request($email, ['access', 'deletion'], 'Deseo acceder a mis datos personales y solicitar su supresión.');
tap_t_assert(!is_wp_error($req_id) && (int) $req_id > 0, 'save_request returns request id');
$req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$requests} WHERE id = %d", (int) $req_id));
tap_t_assert($req && $req->status === 'pending', 'request stored as pending');
tap_t_assert(in_array('Acceso', TAP_Privacy::rights_labels($req->rights), true), 'stored rights decode to label list');

$pending = TAP_Privacy::all_requests('pending');
tap_t_assert(is_array($pending) && (int) current($pending)->id === (int) $req_id, 'pending request listed first');
tap_t_assert(TAP_Privacy::mark_handled((int) $req_id), 'mark_handled returns true');
tap_t_assert(TAP_Privacy::all_requests('done')[0]->id == $req_id, 'handled request appears under done');
tap_t_assert(!TAP_Privacy::mark_handled(0), 'mark_handled rejects zero id');

/* 4) shortcodes + policy content ----------------------------------------------- */
$page_html = do_shortcode('[tap_privacy]');
tap_t_assert(strpos($page_html, 'Aviso de Privacidad') !== false, 'privacy shortcode renders policy heading');
tap_t_assert(strpos($page_html, 'Ley 8968') !== false, 'privacy shortcode mentions Ley 8968');
tap_t_assert(strpos($page_html, 'Ejercer sus derechos') !== false, 'privacy shortcode renders ARCO form');
tap_t_assert(strpos($page_html, 'tap_privacy_request') !== false, 'ARCO form posts to tap_privacy_request');
tap_t_assert(strpos($page_html, '_tap_privacy_nonce') !== false, 'ARCO form includes nonce field');

$consent_box = do_shortcode('[tap_privacy_consent scope="booking"]');
tap_t_assert(strpos($consent_box, 'name="tap_privacy_consent"') !== false, 'consent shortcode renders checkbox');
tap_t_assert(strpos($consent_box, 'Aviso de Privacidad') !== false, 'consent shortcode links to the aviso');

$footer = TAP_Privacy::email_footer();
tap_t_assert(strpos($footer, 'Aviso de Privacidad') !== false, 'email footer mentions the aviso');
tap_t_assert(strpos($footer, TAP_Privacy::contact_email()) !== false, 'email footer includes the privacy contact');
tap_t_assert(strpos($footer, TAP_Privacy::policy_url()) !== false, 'email footer links the policy page');

/* 5) booking consent gate (server-side) ---------------------------------------- */
[$fid, $ftype] = tap_t_find_service();
$GLOBALS['tap_pr_die_hit'] = false;
$GLOBALS['tap_pr_die_handler'] = function () {
    $GLOBALS['tap_pr_die_hit'] = true;
    throw new RuntimeException('tap_pr_die_captured');
};
$GLOBALS['tap_pr_die_filters'] = ['wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_rest_handler', 'wp_die_handler'];
$run_die = function ($fn) {
    foreach ($GLOBALS['tap_pr_die_filters'] as $filter) {
        add_filter($filter, $GLOBALS['tap_pr_die_handler']);
    }
    add_filter('wp_doing_ajax', '__return_true');
    ob_start();
    try {
        $fn();
        $died = false;
    } catch (RuntimeException $e) {
        $died = $e->getMessage() === 'tap_pr_die_captured';
    }
    $json = trim(ob_get_clean());
    foreach ($GLOBALS['tap_pr_die_filters'] as $filter) {
        remove_filter($filter, $GLOBALS['tap_pr_die_handler']);
    }
    remove_filter('wp_doing_ajax', '__return_true');
    return [$died, $json ? json_decode($json, true) : null];
};

$post = [
    'nonce'        => wp_create_nonce('tap_nonce'),
    'service_type' => $ftype,
    'service_id'   => $fid,
    'check_in'     => gmdate('Y-m-d', strtotime('+90 days')),
    'check_out'    => gmdate('Y-m-d', strtotime('+91 days')),
    'adults'       => 2,
    'children'     => 0,
    'guest_name'   => 'Prueba Privacidad',
    'guest_email'  => $bk_email = tap_t_unique_email(),
];
$_POST = $post; // NO consent
$_REQUEST = $post;
[$died, $json] = $run_die(['TAP_Ajax', 'create_booking']);
tap_t_assert($died, 'booking without consent short-circuits via wp_die');
tap_t_assert(is_array($json) && strpos($json['data']['message'] ?? '', 'Aviso de Privacidad') !== false, 'consent rejection message mentions Aviso de Privacidad');
tap_t_assert(!TAP_Privacy::has_consent($bk_email, 'booking'), 'no consent logged when consent missing');

// With consent -> booking created + consent recorded.
$_POST = ['tap_privacy_consent' => '1'] + $post;
$_REQUEST = $_POST;
[$died, $json] = $run_die(['TAP_Ajax', 'create_booking']);
tap_t_assert($died && is_array($json), 'booking with consent reached wp_send_json');
$bid = isset($json['data']['booking_id']) ? (int) $json['data']['booking_id'] : 0;
tap_t_assert($bid > 0, 'booking created with consent');
tap_t_assert(TAP_Privacy::has_consent($bk_email, 'booking'), 'consent recorded for booking after success');

/* 6) agency registration consent gate ----------------------------------------- */
$uname = 'tap_pr_' . tap_t_suffix();
$reg = [
    'nonce'           => wp_create_nonce('tap_register_nonce'),
    'agency_name'     => 'Agencia Privacidad',
    'username'        => $uname,
    'email'           => $ag_email = tap_t_unique_email(),
    'password'        => tap_t_unique_pass(),
    'kyc_legal_name'  => 'Representante Legal',
    'kyc_doc_type'    => 'fisica',
    'kyc_doc_number'  => '123456789',
    'kyc_accept'      => '1',
];
$_POST = $reg; // NO consent
$_REQUEST = $reg;
[$died, $json] = $run_die(['TAP_Ajax', 'agency_register']);
tap_t_assert($died && is_array($json) && strpos($json['data']['message'] ?? '', 'Aviso de Privacidad') !== false, 'agency register without consent is rejected with privacy message');

$_POST = $reg + ['tap_privacy_consent' => '1'];
$_REQUEST = $_POST;
[$died, $json] = $run_die(['TAP_Ajax', 'agency_register']);
$success = is_array($json) && !empty($json['success']);
tap_t_assert($died && $success, 'agency register with consent succeeds');
tap_t_assert(TAP_Privacy::has_consent($ag_email, 'agency_registration'), 'agency consent recorded after success');

unset($GLOBALS['tap_pr_die_handler'], $GLOBALS['tap_pr_die_filters'], $GLOBALS['tap_pr_die_hit']);

/* cleanup (keep it surgical) ---------------------------------------------------- */
$user = get_user_by('email', $ag_email);
if ($user) {
    $agency_posts = get_posts(['post_type' => 'tap_agency', 'meta_key' => '_tap_agency_user_id', 'meta_value' => $user->ID, 'fields' => 'ids', 'numberposts' => 10]);
    foreach ($agency_posts as $aid) {
        $wpdb->delete($p . 'tap_agencies', ['user_id' => $user->ID]);
        wp_delete_post($aid, true);
    }
    wp_delete_user($user->ID);
}
if ($bid) {
    tap_t_cleanup_bookings([$bid]);
}
$wpdb->delete($consents, ['email' => $email]);
$wpdb->delete($requests, ['email' => $email]);
$wpdb->delete($consents, ['email' => $bk_email]);
$wpdb->delete($consents, ['email' => $ag_email]);

// Regression: privacy settings must live in their own options group. Sharing
// 'tap_settings' made core options.php wipe all other settings on save.
// FUNCTIONAL regression: simulate core options.php saving each group with a
// partial $_POST. Core nulls every option in the group that is absent from
// $_POST — if the two pages shared a group, saving one wiped the other.
TAP_Dashboard_Settings::register_settings();
TAP_Privacy::register_settings();
$wl = $GLOBALS['new_whitelist_options'];
// Snapshot so the destructive simulation can be fully undone.
$settings_snapshot = [];
foreach ($wl['tap_settings'] as $opt) {
    $settings_snapshot[$opt] = get_option($opt, null);
}
$privacy_snapshot = get_option('tap_privacy_contact', '');
tap_t_assert(
    !in_array('tap_privacy_contact', $wl['tap_settings'] ?? [], true)
    && !in_array('tap_commission_default', $wl['tap_privacy_group'] ?? [], true),
    'settings and privacy option groups are disjoint'
);

update_option('tap_commission_default', '15.55');
update_option('tap_privacy_contact', 'legal@example.test');

// Simulate saving the Privacy page: only its option is in $_POST.
$saved = ['tap_privacy_contact' => 'legal2@example.test'];
foreach ($wl['tap_privacy_group'] as $opt) {
    update_option($opt, $saved[$opt] ?? null); // core behavior for absent options
}
tap_t_assert('15.55' === (string) get_option('tap_commission_default'), 'saving privacy group preserves the commission setting');
tap_t_assert('legal2@example.test' === get_option('tap_privacy_contact'), 'saving privacy group updates its own option');

// Simulate saving the Settings page: privacy contact is NOT in this group.
$saved = ['tap_commission_default' => '16.66'];
foreach ($wl['tap_settings'] as $opt) {
    update_option($opt, $saved[$opt] ?? null);
}
tap_t_assert('legal2@example.test' === get_option('tap_privacy_contact'), 'saving settings group preserves the privacy contact');

// Restore EVERY option touched by the simulations (the partial-POST emulation
// nulls all options in the group). Snapshot taken before any simulation.
foreach ($wl['tap_settings'] as $opt) {
    if (array_key_exists($opt, $settings_snapshot)) {
        update_option($opt, $settings_snapshot[$opt]);
    } else {
        delete_option($opt);
    }
}
update_option('tap_privacy_contact', $privacy_snapshot);
/* 7) GDPR/ARCO exporter + eraser (FUNCTIONAL) -------------------------------- */
$exp_email = 'tap_exp_' . tap_t_suffix() . '@example.test';
$exp_user  = wp_insert_user([
    'user_login' => 'tap_exp_' . tap_t_suffix(),
    'user_pass'  => tap_t_unique_pass(),
    'user_email' => $exp_email,
    'role'       => 'subscriber',
]);
tap_t_assert(!is_wp_error($exp_user) && (int) $exp_user > 0, 'export fixture user created');
if (!is_wp_error($exp_user) && (int) $exp_user > 0) {
    $exp_booking = tap_t_seed_booking([
        'booking_code' => 'EXP-' . tap_t_suffix(),
        'client_id'    => (int) $exp_user,
        'guest_email'  => $exp_email,
        'guest_name'   => 'Persona Export',
    ]);
    $wpdb->insert($p . 'tap_leads', [
        'agency_id' => tap_t_test_agency(), 'name' => 'Persona Export',
        'email' => $exp_email, 'message' => 'mensaje privado de prueba',
    ]);
    $lead_id = (int) $wpdb->insert_id;
    TAP_Privacy::record_consent($exp_email, 'booking', 0);
    $wpdb->insert($p . 'tap_reviews', [
        'service_type' => 'tap_tour', 'service_id' => 75, 'user_id' => (int) $exp_user,
        'rating' => 5, 'title' => 'Reseña privada', 'content' => 'Contenido de prueba', 'is_approved' => 0,
    ]);
    $review_id = (int) $wpdb->insert_id;

    // Registration callbacks.
    $exporters = TAP_Privacy::register_exporter([]);
    $erasers   = TAP_Privacy::register_eraser([]);
    tap_t_assert(
        isset($exporters[0]['callback']) && ['TAP_Privacy', 'export_personal_data'] === $exporters[0]['callback'],
        'privacy exporter callback is registered'
    );
    tap_t_assert(
        isset($erasers[0]['callback']) && ['TAP_Privacy', 'erase_personal_data'] === $erasers[0]['callback'],
        'privacy eraser callback is registered'
    );

    // Export must include all four data groups.
    $export = TAP_Privacy::export_personal_data($exp_email);
    $groups = [];
    foreach ((array) ($export['data'] ?? []) as $group) {
        foreach ((array) ($group['data'] ?? []) as $item) {
            $groups[] = $item['group_id'] ?? '';
        }
    }
    tap_t_assert(true === ($export['done'] ?? false), 'export reports done');
    foreach (['tap_bookings', 'tap_leads', 'tap_consents', 'tap_reviews'] as $gid) {
        tap_t_assert(in_array($gid, $groups, true), "export includes group {$gid}");
    }
    $export_flat = wp_json_encode($export);
    tap_t_assert(false !== strpos($export_flat, 'Persona Export'), 'export contains the personal data');
    tap_t_assert(false !== strpos($export_flat, 'mensaje privado de prueba'), 'export contains the lead message');

    // Erase: transient data gone, operational booking retained but anonymised.
    $erase = TAP_Privacy::erase_personal_data($exp_email);
    tap_t_assert(true === ($erase['items_removed'] ?? false) && true === ($erase['done'] ?? false), 'erase reports removed + done');
    tap_t_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}tap_leads WHERE id = %d", $lead_id)), 'lead row erased');
    tap_t_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}tap_reviews WHERE id = %d", $review_id)), 'review row erased');
    tap_t_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}tap_consents WHERE email = %s", $exp_email)), 'consents erased');
    $kept = TAP_Booking::get_booking($exp_booking);
    tap_t_assert($kept && '' === (string) $kept->guest_email, 'booking row retained but guest email anonymised');

    tap_t_cleanup_bookings([$exp_booking]);
    $wpdb->delete($p . 'tap_leads', ['email' => $exp_email]);
    $wpdb->delete($p . 'tap_consents', ['email' => $exp_email]);
    wp_delete_user((int) $exp_user);
}

tap_t_assert(!isset($wl['tap_payment_methods']), 'orphan tap_payment_methods option is not registered');
$privacy_page = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-privacy.php');
tap_t_assert(false !== strpos($privacy_page, "settings_fields('tap_privacy_group')"), 'privacy form uses the dedicated group');
$settings_src = (string) file_get_contents(dirname(__DIR__) . '/app/public/wp-content/plugins/travel-agency-platform/includes/class-dashboard-settings.php');
tap_t_assert(substr_count($settings_src, 'type="hidden" name="tap_') >= 10, 'settings checkboxes have hidden 0 fallbacks');

tap_t_finish();