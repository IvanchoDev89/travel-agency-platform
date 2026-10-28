<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.ValidatedSanitizedInput
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText
// phpcs:disable WordPress.WP.GlobalVariablesOverride
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification
// phpcs:disable WordPress.Security.EscapeOutput
/**
 * TAP_Ajax_Listing — Listing and room write handlers.
 *
 * The agency-facing AJAX endpoints that create and update listings and
 * rooms, plus the publish toggle. Split out of TAP_Ajax so the write
 * handlers stay in one auditable file. Ownership is delegated to
 * TAP_Listing_Access; image handling to TAP_Agency_Media.
 *
 * @package TravelAgencyPlatform
 * @since 1.5.17
 */
defined('ABSPATH') || exit;

class TAP_Ajax_Listing {

    public static function agency_save_listing() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Authentication required', 'travel-agency-platform')]);
        }

        $result = self::save_listing_data(get_current_user_id(), $_POST);
        if (true === $result) {
            $id = (int) $GLOBALS['_tap_saved_listing'];
            wp_send_json_success([
                'message'    => isset($_POST['listing_id']) ? __('Listing updated', 'travel-agency-platform') : __('Listing created', 'travel-agency-platform'),
                'listing_id' => $id,
                'edit_url'   => home_url('/manage-listing/?id=' . $id),
            ]);
        } else {
            wp_send_json_error(['message' => $result]);
        }
    }

    /**
     * Filter attachment ids sent by the front-end uploader. Only images the
     * current user owns (or admins) can be attached, so an agency cannot point
     * a listing at somebody else's private media.
     *
     * @param mixed $raw   Array or comma separated list of attachment ids.
     * @param int   $limit Max number of ids to keep (0 = unlimited).
     * @return array<int,int> Ids as values, keyed by id.
     */
    /**
     * Map the attachments a post already uses to their URL, so legacy URL
     * submissions are reused instead of re-downloaded on every save.
     */
    /**
     * Core, testable listing save. Returns TRUE on success (listing id in
     * $GLOBALS['_tap_saved_listing']) or an error message string. Never dies,
     * so it can be exercised directly under wp-cli.
     */

    /**
     * Filter attachment ids sent by the front-end uploader. Only images the
     * current user owns (or admins) can be attached, so an agency cannot point
     * a listing at somebody else's private media.
     *
     * @param mixed $raw   Array or comma separated list of attachment ids.
     * @param int   $limit Max number of ids to keep (0 = unlimited).
     * @return array<int,int> Ids as values, keyed by id.
     */
    /**
     * Map the attachments a post already uses to their URL, so legacy URL
     * submissions are reused instead of re-downloaded on every save.
     */
    /**
     * Core, testable listing save. Returns TRUE on success (listing id in
     * $GLOBALS['_tap_saved_listing']) or an error message string. Never dies,
     * so it can be exercised directly under wp-cli.
     */
    public static function save_listing_data($user_id, array $input) {
        wp_set_current_user($user_id);
        $agency = TAP_Ajax_Commerce::current_agency_for($user_id);

        $service_types = array_keys(TAP_Post_Types::get_service_types());
        $service_types = array_values(array_filter($service_types, function ($t) {
            return $t !== 'tap_room';
        }));
        $listing_type  = isset($input['listing_type']) ? sanitize_key($input['listing_type']) : 'tap_accommodation';
        if (!in_array($listing_type, $service_types, true)) {
            $listing_type = 'tap_accommodation';
        }
        $prefix = TAP_Listing_Access::listing_prefix($listing_type);

        $listing_id = isset($input['listing_id']) ? intval($input['listing_id']) : 0;
        if ($listing_id) {
            if (get_post_type($listing_id) !== $listing_type) {
                return __('You can only manage your own listings.', 'travel-agency-platform');
            }
            if (!TAP_Listing_Access::agency_owns_listing($listing_id, $listing_type)) {
                return __('You can only manage your own listings.', 'travel-agency-platform');
            }
            // Editing requires the CPT edit capability (agency admins and
            // employees hold it; plain clients do not), mirroring the
            // create-path guard below.
            $edit_obj = get_post_type_object($listing_type);
            $can_edit = ($edit_obj && current_user_can($edit_obj->cap->edit_posts)) || current_user_can('manage_options');
            if (!$can_edit) {
                return __('No tienes permiso para editar listados.', 'travel-agency-platform');
            }
        }
        if (!$listing_id) {
            // Creating requires the CPT create capability and a linked agency
            // (admins bypass both), so arbitrary logged-in users cannot litter
            // the catalog with orphan listings.
            $type_obj   = get_post_type_object($listing_type);
            $can_create = ($type_obj && current_user_can($type_obj->cap->create_posts)) || current_user_can('manage_options');
            if (!$can_create) {
                return __('No tienes permiso para crear listados.', 'travel-agency-platform');
            }
            if (!$agency && !current_user_can('manage_options')) {
                return __('No tienes una agencia vinculada.', 'travel-agency-platform');
            }
        }

        $title = isset($input['title']) ? sanitize_text_field(wp_unslash($input['title'])) : '';
        if ('' === $title) {
            return __('Name is required', 'travel-agency-platform');
        }

        $fields = TAP_Metaboxes::get_fields($listing_type);

        // Money integrity: any price field that is provided must be > 0.
        foreach ($fields as $meta_key => $cfg) {
            if (!isset($cfg['type']) || 'number' !== $cfg['type'] || false === strpos($meta_key, 'price')) {
                continue;
            }
            $raw = isset($input[$meta_key]) ? $input[$meta_key] : null;
            if ($raw === null && $listing_type === 'tap_accommodation' && $meta_key === '_tap_acc_price_per_night') {
                $raw = isset($input['price_per_night']) ? $input['price_per_night'] : null;
            }
            // Blank means "not provided" (the listing-level price is optional
            // when prices live on the units); only a present value is checked.
            if ($raw === null || (is_scalar($raw) && '' === trim((string) $raw))) {
                continue;
            }
            $is_decimal = isset($cfg['step']) && false !== strpos($cfg['step'], '.');
            $value      = $is_decimal ? floatval($raw) : intval($raw);
            if ($value <= 0) {
                /* translators: %s: etiqueta del tipo de tarifa */
                return sprintf(__('El precio %s debe ser mayor que 0.', 'travel-agency-platform'), isset($cfg['label']) ? $cfg['label'] : $meta_key);
            }
        }

        // Coordinate integrity: lat/lng, when provided, must be valid ranges.
        foreach ($fields as $meta_key => $cfg) {
            if (false === stripos($meta_key, '_lat') && false === stripos($meta_key, '_lng')) {
                continue;
            }
            $raw = isset($input[$meta_key]) ? $input[$meta_key] : null;
            if ($raw === null || (is_scalar($raw) && '' === trim((string) $raw))) {
                continue;
            }
            if (!is_numeric($raw)) {
                return sprintf(__('El campo %s debe ser numérico.', 'travel-agency-platform'), isset($cfg['label']) ? $cfg['label'] : $meta_key); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
            }
            $val = (float) $raw;
            if (false !== stripos($meta_key, '_lat') && ($val < -90 || $val > 90)) {
                return sprintf(__('La latitud debe estar entre -90 y 90 (%s).', 'travel-agency-platform'), isset($cfg['label']) ? $cfg['label'] : $meta_key); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
            }
            if (false !== stripos($meta_key, '_lng') && ($val < -180 || $val > 180)) {
                return sprintf(__('La longitud debe estar entre -180 y 180 (%s).', 'travel-agency-platform'), isset($cfg['label']) ? $cfg['label'] : $meta_key); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
            }
        }

        if (null !== $agency && class_exists('TAP_Subscriptions')) {
            $limit = TAP_Subscriptions::listing_limit($agency);
            if ($limit >= 0 && !$listing_id && TAP_Subscriptions::listing_count($agency) >= $limit) {
                /* translators: %d: número máximo de listados permitidos por el plan */
                return sprintf(__('Your plan allows a maximum of %d listings. Upgrading your plan unlocks more.', 'travel-agency-platform'), $limit);
            }
        }

        $listing_obj = get_post_type_object($listing_type);
        $can_publish = ($listing_obj && current_user_can($listing_obj->cap->publish_posts)) || current_user_can('manage_options');

        $post = [
            'post_type'    => $listing_type,
            'post_title'   => $title,
            'post_content' => isset($input['description']) ? wp_kses_post(wp_unslash($input['description'])) : '',
        ];

        if ($listing_id) {
            $post['ID'] = $listing_id;
            // Editing keeps the current visibility: publishing/unpublishing is an
            // explicit action (see set_listing_published) instead of a side
            // effect of saving the form.
            if (array_key_exists('publish', $input)) {
                $post['post_status'] = empty($input['publish']) ? 'draft' : ($can_publish ? 'publish' : 'pending');
            }
            $new_id     = wp_update_post($post, true);
        } else {
            // Creating: accommodations always start as draft — publishing is
            // an explicit wizard step-5 action. Other types keep publishing
            // immediately because the legacy editor has no publish control.
            // Non-publishers still go to review so an employee cannot self-publish.
            if (!$can_publish) {
                $post['post_status'] = 'pending';
            } else {
                // All service types start as draft: publishing is an explicit
                // action (see set_listing_published / togglePublish), so an
                // agency can review before going live. Accommodation already
                // followed this; the rest now match for consistency.
                $post['post_status'] = 'draft';
            }
            $new_id = wp_insert_post($post, true);
        }

        if (is_wp_error($new_id)) {
            return $new_id->get_error_message();
        }
        if (!$new_id) {
            return __('Could not save the listing', 'travel-agency-platform');
        }

        if (null !== $agency) {
            update_post_meta($new_id, '_tap_' . $prefix . '_agency_id', (int) $agency);
        }

        // Legacy short input-name → meta-key map for the accommodation form.
        $legacy_map = [
            'tap_accommodation' => [
                'type' => '_tap_acc_type', 'stars' => '_tap_acc_stars',
                'price_per_night' => '_tap_acc_price_per_night', 'capacity' => '_tap_acc_capacity',
                'bedrooms' => '_tap_acc_bedrooms', 'bathrooms' => '_tap_acc_bathrooms',
                'checkin_time' => '_tap_acc_checkin_time', 'checkout_time' => '_tap_acc_checkout_time',
                'lat' => '_tap_acc_lat', 'lng' => '_tap_acc_lng', 'currency' => '_tap_acc_currency',
                'is_active' => '_tap_acc_is_active',
            ],
        ];

        foreach ($fields as $meta_key => $cfg) {
            if (strpos($meta_key, '_tap_' . $prefix . '_agency_id') !== false) {
                continue;
            }
            if ($meta_key === '_tap_seo_title' || $meta_key === '_tap_seo_description') {
                continue;
            }

            $input_name = $meta_key;
            $raw = isset($input[$input_name]) ? $input[$input_name] : null;

            if ($raw === null && $listing_type === 'tap_accommodation' && isset($legacy_map['tap_accommodation'][substr($meta_key, strlen('_tap_acc_'))])) {
                $legacy_name = array_search($meta_key, $legacy_map['tap_accommodation'], true);
                $raw = isset($input[$legacy_name]) ? $input[$legacy_name] : null;
            }

            if ($raw === null) {
                continue;
            }

            $ftype = isset($cfg['type']) ? $cfg['type'] : 'text';
            switch ($ftype) {
                case 'number':
                    $value = isset($cfg['step']) && strpos($cfg['step'], '.') !== false ? (string) floatval($raw) : (string) intval($raw);
                    break;
                case 'checkbox':
                    $value = '1' === (string) $raw ? '1' : '0';
                    break;
                case 'select':
                    $value = sanitize_key($raw);
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field(wp_unslash((string) $raw));
                    break;
                case 'email':
                    $value = sanitize_email($raw);
                    break;
                case 'url':
                    $value = esc_url_raw($raw);
                    break;
                default:
                    $value = sanitize_text_field(wp_unslash((string) $raw));
            }
            update_post_meta($new_id, $meta_key, $value);
        }

        $GLOBALS['_tap_saved_listing'] = (int) $new_id;

        if (isset($input['destination'])) {
            $dest  = absint($input['destination']);
            $valid = $dest && term_exists($dest, 'tap_location');
            wp_set_object_terms($new_id, $valid ? [$dest] : [], 'tap_location', false);
        }

        if ('tap_tour' === $listing_type) {
            if (isset($input['_tap_tour_difficulty'])) {
                $difficulty = sanitize_key(wp_unslash($input['_tap_tour_difficulty']));
                if ($difficulty && term_exists($difficulty, 'tap_tour_difficulty')) {
                    $tt = term_exists($difficulty, 'tap_tour_difficulty');
                    $term_id = (int) (is_array($tt) ? $tt['term_id'] : $tt);
                    wp_set_object_terms($new_id, [$term_id], 'tap_tour_difficulty', false);
                } else {
                    wp_set_object_terms($new_id, [], 'tap_tour_difficulty', false);
                }
            }
            if (isset($input['_tap_tour_type'])) {
                $ttype = sanitize_text_field(wp_unslash($input['_tap_tour_type']));
                if ($ttype && term_exists($ttype, 'tap_tour_type')) {
                    $tt = term_exists($ttype, 'tap_tour_type');
                    $term_id = (int) (is_array($tt) ? $tt['term_id'] : $tt);
                    wp_set_object_terms($new_id, [$term_id], 'tap_tour_type', false);
                } elseif ('' === trim($ttype)) {
                    wp_set_object_terms($new_id, [], 'tap_tour_type', false);
                }
            }
        }

        $booking_mode = isset($input['booking_mode']) ? sanitize_key($input['booking_mode']) : '';
        if (in_array($booking_mode, ['instant', 'request'], true)) {
            update_post_meta($new_id, '_tap_booking_mode', $booking_mode);
        }

        // Policies (accommodation): not part of the generic field map, but the
        // agency wizard lets users set them from the front-end back-office.
        if ($listing_type === 'tap_accommodation') {
            if (array_key_exists('_tap_acc_cancellation', $input)) {
                $pol = sanitize_key((string) $input['_tap_acc_cancellation']);
                if (in_array($pol, array_keys(TAP_Refunds::cancellation_policies()), true)) {
                    update_post_meta($new_id, '_tap_acc_cancellation', $pol);
                }
            }
            if (array_key_exists('_tap_acc_house_rules', $input)) {
                update_post_meta($new_id, '_tap_acc_house_rules', sanitize_textarea_field(wp_unslash((string) $input['_tap_acc_house_rules'])));
            }
        }

        // ── Images ────────────────────────────────────────────────────
        // The agency wizard uploads through the Media Library and sends
        // attachment ids, so nothing is re-downloaded. The legacy URL fields
        // keep working for the older editors and reuse existing attachments.
        if (array_key_exists('featured_attachment_id', $input)) {
            $att = TAP_Agency_Media::usable_attachment_ids($input['featured_attachment_id'], 1, $new_id);
            if ($att) {
                set_post_thumbnail($new_id, (int) array_key_first($att));
            } else {
                delete_post_thumbnail($new_id);
            }
        } elseif (array_key_exists('featured_image_url', $input)) {
            $furl = esc_url_raw(trim((string) ($input['featured_image_url'] ?? '')));
            if ('' !== $furl) {
                $current = (int) get_post_thumbnail_id($new_id);
                $known   = TAP_Agency_Media::attachments_by_url([$current]);
                $att     = isset($known[$furl]) ? $known[$furl] : TAP_Agency_Media::sideload_image($furl);
                if ($att) {
                    set_post_thumbnail($new_id, $att);
                }
            } else {
                delete_post_thumbnail($new_id);
            }
        }

        // Gallery: supported for every service type now that the front editor
        // ships the shared image uploader. Accommodation keeps its legacy meta
        // key for backwards compatibility.
        $gallery_meta_key = ('tap_accommodation' === $listing_type)
            ? '_tap_acc_gallery'
            : '_tap_' . $prefix . '_gallery';
        if (array_key_exists('gallery_attachment_ids', $input)) {
            $ids = TAP_Agency_Media::usable_attachment_ids($input['gallery_attachment_ids'], 0, $new_id);
            update_post_meta($new_id, $gallery_meta_key, implode(',', array_keys($ids)));
        } elseif (array_key_exists('gallery_urls', $input)) {
            $urls  = array_values(array_filter(array_map('trim', explode("\n", (string) $input['gallery_urls']))));
            $known = TAP_Agency_Media::attachments_by_url(array_filter(array_map('absint', explode(',', (string) get_post_meta($new_id, $gallery_meta_key, true)))));
            $ids   = [];
            foreach ($urls as $u) {
                if (isset($known[$u])) {
                    $ids[$known[$u]] = $known[$u];
                    continue;
                }
                $att = TAP_Agency_Media::sideload_image($u);
                if ($att) {
                    $ids[$att] = $att;
                }
            }
            update_post_meta($new_id, $gallery_meta_key, implode(',', array_keys($ids)));
        }

        return true;
    }

    /**
     * Reject URLs that point to non-public hosts (SSRF guard for sideload).
     * Blocks literal private/reserved/metadata IPs and hostnames that resolve
     * to any non-public address, so agencies cannot proxy requests to
     * localhost, internal networks or cloud metadata endpoints.
     */
    /**
     * Download a remote image into the Media Library and return its
     * attachment id (0 on any failure). No-op-safe under wp-cli and tests.
     */
    /**
     * Image extensions accepted by the front-end agency uploader.
     */
    /**
     * Max upload size (bytes) accepted by the front-end agency uploader.
     */
    /**
     * Store an image into the Media Library. Testable core: takes a plain
     * file array (as in $_FILES), never dies, and returns the attachment data
     * array or a WP_Error. Handles both real uploads (wp_handle_upload) and
     * local temp files (wp_handle_sideload), which keeps it usable from tests.
     *
     * @param array $file      File array with name/tmp_name/size/type/error.
     * @param int   $parent_id Optional post to attach the media to.
     * @return array|WP_Error  {attachment_id, url, thumb, width, height}
     */
    /**
     * Front-end image upload used by the agency back-office wizard. Auth,
     * ownership and nonce live here; the storage work is in the core above.
     */
    /**
     * Core, testable publish/unpublish toggle. Publishing honours the agency
     * approval rules: users without the publish capability send the listing to
     * moderation instead of making it public.
     *
     * @return array|WP_Error {status, is_active, message}
     */

    /**
     * Reject URLs that point to non-public hosts (SSRF guard for sideload).
     * Blocks literal private/reserved/metadata IPs and hostnames that resolve
     * to any non-public address, so agencies cannot proxy requests to
     * localhost, internal networks or cloud metadata endpoints.
     */
    /**
     * Download a remote image into the Media Library and return its
     * attachment id (0 on any failure). No-op-safe under wp-cli and tests.
     */
    /**
     * Image extensions accepted by the front-end agency uploader.
     */
    /**
     * Max upload size (bytes) accepted by the front-end agency uploader.
     */
    /**
     * Store an image into the Media Library. Testable core: takes a plain
     * file array (as in $_FILES), never dies, and returns the attachment data
     * array or a WP_Error. Handles both real uploads (wp_handle_upload) and
     * local temp files (wp_handle_sideload), which keeps it usable from tests.
     *
     * @param array $file      File array with name/tmp_name/size/type/error.
     * @param int   $parent_id Optional post to attach the media to.
     * @return array|WP_Error  {attachment_id, url, thumb, width, height}
     */
    /**
     * Front-end image upload used by the agency back-office wizard. Auth,
     * ownership and nonce live here; the storage work is in the core above.
     */
    /**
     * Core, testable publish/unpublish toggle. Publishing honours the agency
     * approval rules: users without the publish capability send the listing to
     * moderation instead of making it public.
     *
     * @return array|WP_Error {status, is_active, message}
     */
    public static function set_listing_published($listing_id, $publish) {
        $listing_id = absint($listing_id);
        $type       = $listing_id ? get_post_type($listing_id) : '';
        if (!$listing_id || !$type) {
            return new WP_Error('tap_bad_listing', __('Alojamiento no encontrado.', 'travel-agency-platform'));
        }

        $prefix      = TAP_Listing_Access::listing_prefix($type);
        $active_meta = '_tap_' . $prefix . '_is_active';
        $type_obj = get_post_type_object($type);
        $can_publish = ($type_obj && current_user_can($type_obj->cap->publish_posts)) || current_user_can('manage_options');

        if (!$publish) {
            wp_update_post(['ID' => $listing_id, 'post_status' => 'draft']);
            update_post_meta($listing_id, $active_meta, '0');
            return [
                'status'    => 'draft',
                'is_active' => '0',
                'message'   => __('Alojamiento despublicado. Ya no aparece en el buscador.', 'travel-agency-platform'),
            ];
        }

        // The plan's listing limit is also enforced here, not only when the
        // listing was created: an agency that downgraded (or parked drafts)
        // must not be able to publish past its current plan's limit.
        $already_active = '1' === (string) get_post_meta($listing_id, $active_meta, true);
        $agency_id      = (int) get_post_meta($listing_id, TAP_Post_Types::agency_meta_key($type), true);
        if ($agency_id > 0 && !$already_active && class_exists('TAP_Subscriptions')) {
            $limit = TAP_Subscriptions::listing_limit($agency_id);
            if ($limit >= 0 && TAP_Subscriptions::active_listing_count($agency_id) >= $limit) {
                return new WP_Error('tap_listing_limit', __('Has alcanzado el límite de listados de tu plan. Mejora tu plan para publicar más.', 'travel-agency-platform'));
            }
        }

        update_post_meta($listing_id, $active_meta, '1');

        if ($can_publish) {
            wp_update_post(['ID' => $listing_id, 'post_status' => 'publish']);
            return [
                'status'    => 'publish',
                'is_active' => '1',
                'message'   => __('¡Alojamiento publicado! Ya está visible para los viajeros.', 'travel-agency-platform'),
            ];
        }

        wp_update_post(['ID' => $listing_id, 'post_status' => 'pending']);
        return [
            'status'    => 'pending',
            'is_active' => '1',
            'message'   => __('Enviado a revisión. Un administrador lo aprobará y quedará visible.', 'travel-agency-platform'),
        ];
    }

    /**
     * AJAX: explicit publish / unpublish from the wizard.
     */

    /**
     * AJAX: explicit publish / unpublish from the wizard.
     */
    public static function agency_toggle_publish() {
        check_ajax_referer('tap_agency_listing_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'travel-agency-platform')], 403);
        }

        $listing_type = isset($_POST['listing_type']) ? sanitize_key(wp_unslash($_POST['listing_type'])) : 'tap_accommodation';
        $listing_id   = isset($_POST['listing_id']) ? absint($_POST['listing_id']) : 0;
        if (!$listing_id || get_post_type($listing_id) !== $listing_type) {
            wp_send_json_error(['message' => __('Alojamiento no encontrado.', 'travel-agency-platform')]);
        }
        if (!TAP_Listing_Access::agency_owns_listing($listing_id, $listing_type)) {
            wp_send_json_error(['message' => __('Solo puedes gestionar tus propios alojamientos.', 'travel-agency-platform')], 403);
        }

        $result = self::set_listing_published($listing_id, !empty($_POST['publish']));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $result['permalink'] = 'publish' === $result['status'] ? (string) get_permalink($listing_id) : '';
        wp_send_json_success($result);
    }


    public static function agency_save_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required', 'travel-agency-platform' ) ] );
        }

        $acc_id   = isset( $_POST['accommodation_id'] ) ? intval( $_POST['accommodation_id'] ) : 0;
        if ( ! $acc_id || ! TAP_Listing_Access::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        $result  = self::save_room_data( $acc_id, $room_id, $_POST );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => $room_id ? __( 'Room updated', 'travel-agency-platform' ) : __( 'Room created', 'travel-agency-platform' ),
            'room_id' => (int) $result,
        ] );
    }

    /**
     * Testable room editor core. Returns the room id on success, or a
     * WP_Error with a translated message. Reads from a plain $input array
     * (usually $_POST) and never dies, so it can run under wp-cli.
     */

    /**
     * Testable room editor core. Returns the room id on success, or a
     * WP_Error with a translated message. Reads from a plain $input array
     * (usually $_POST) and never dies, so it can run under wp-cli.
     */
    public static function save_room_data( $acc_id, $room_id, array $input ) {
        $acc_id  = (int) $acc_id;
        $room_id = (int) $room_id;

        $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
        if ( '' === $title ) {
            return new WP_Error( 'no_title', __( 'Room name is required', 'travel-agency-platform' ) );
        }

        if ( $room_id && (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true ) !== $acc_id ) {
            return new WP_Error( 'invalid_room', __( 'Invalid room', 'travel-agency-platform' ) );
        }

        $price = isset( $input['price_per_night'] ) ? floatval( $input['price_per_night'] ) : 0;
        if ( $price <= 0 ) {
            return new WP_Error( 'invalid_price', __( 'Price per night must be greater than 0', 'travel-agency-platform' ) );
        }

        // Rooms follow the accommodation's visibility: a room created while
        // the listing is still a draft must not leak as published content.
        $acc_status  = get_post_status( $acc_id );
        $room_status = ( !$acc_status || 'publish' === $acc_status ) ? 'publish' : $acc_status;
        $post = [
            'post_type'   => 'tap_room',
            'post_status' => $room_status,
            'post_title'  => $title,
            'post_excerpt'=> isset( $input['description'] ) ? wp_kses_post( wp_unslash( $input['description'] ) ) : '',
        ];

        if ( $room_id ) {
            $post['ID'] = $room_id;
            $new_id     = wp_update_post( $post, true );
        } else {
            $new_id     = wp_insert_post( $post, true );
        }

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            return new WP_Error( 'save_failed', __( 'Could not save the room', 'travel-agency-platform' ) );
        }

        $beds_raw = isset( $input['_tap_room_beds'] ) ? wp_unslash( $input['_tap_room_beds'] ) : '';
        $beds     = json_decode( (string) $beds_raw, true );
        $beds_out = [];
        $bed_types = TAP_Post_Types::bed_types();
        if ( is_array( $beds ) ) {
            foreach ( $beds as $bed ) {
                if ( ! is_array( $bed ) || ! isset( $bed['type'], $bed['count'] ) ) {
                    continue;
                }
                $t = sanitize_key( (string) $bed['type'] );
                $c = intval( $bed['count'] );
                if ( $c <= 0 ) {
                    continue;
                }
                if ( ! array_key_exists( $t, $bed_types ) ) {
                    $t = 'double';
                }
                $beds_out[] = [ 'type' => $t, 'count' => $c ];
            }
        }
        if ( $beds_raw !== '' || count( $beds_out ) > 0 ) {
            update_post_meta( $new_id, '_tap_room_beds', wp_json_encode( $beds_out, JSON_UNESCAPED_UNICODE ) );
        }

        $amenities_raw = isset( $input['amenities'] ) ? sanitize_text_field( wp_unslash( $input['amenities'] ) ) : '';
        $amenities = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $amenities_raw ) ) ) ) );
        update_post_meta( $new_id, '_tap_room_amenities', wp_json_encode( $amenities, JSON_UNESCAPED_UNICODE ) );

        // Images: attachment ids from the wizard uploader take precedence over
        // the legacy URL fields, which are reused when already known.
        if ( array_key_exists( 'room_attachment_id', $input ) ) {
            $att = TAP_Agency_Media::usable_attachment_ids( $input['room_attachment_id'], 1, $new_id );
            if ( $att ) {
                set_post_thumbnail( $new_id, (int) array_key_first( $att ) );
            } else {
                delete_post_thumbnail( $new_id );
            }
        } elseif ( array_key_exists( 'room_thumbnail_url', $input ) ) {
            $furl = esc_url_raw( trim( (string) ( $input['room_thumbnail_url'] ?? '' ) ) );
            if ( '' !== $furl ) {
                $known = TAP_Agency_Media::attachments_by_url( [ (int) get_post_thumbnail_id( $new_id ) ] );
                $att   = isset( $known[ $furl ] ) ? $known[ $furl ] : TAP_Agency_Media::sideload_image( $furl );
                if ( $att ) {
                    set_post_thumbnail( $new_id, $att );
                }
            } else {
                delete_post_thumbnail( $new_id );
            }
        }

        if ( array_key_exists( 'gallery_attachment_ids', $input ) ) {
            $ids = TAP_Agency_Media::usable_attachment_ids( $input['gallery_attachment_ids'], 0, $new_id );
            update_post_meta( $new_id, '_tap_room_gallery', implode( ',', array_keys( $ids ) ) );
        } elseif ( array_key_exists( 'gallery_urls', $input ) ) {
            $urls  = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $input['gallery_urls'] ) ) ) );
            $known = TAP_Agency_Media::attachments_by_url( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $new_id, '_tap_room_gallery', true ) ) ) ) );
            $ids   = [];
            foreach ( $urls as $u ) {
                if ( isset( $known[ $u ] ) ) {
                    $ids[ $known[ $u ] ] = $known[ $u ];
                    continue;
                }
                $att = TAP_Agency_Media::sideload_image( $u );
                if ( $att ) {
                    $ids[ $att ] = $att;
                }
            }
            update_post_meta( $new_id, '_tap_room_gallery', implode( ',', array_keys( $ids ) ) );
        }

        $fields = [
            '_tap_room_accommodation_id' => $acc_id,
            '_tap_room_price_per_night'  => $price,
            '_tap_room_min_stay'         => isset( $input['min_stay'] ) ? intval( $input['min_stay'] ) : 1,
            '_tap_room_currency'         => isset( $input['currency'] ) ? sanitize_text_field( $input['currency'] ) : 'USD',
            '_tap_room_max_adults'       => isset( $input['max_adults'] ) ? intval( $input['max_adults'] ) : 2,
            '_tap_room_max_children'     => isset( $input['max_children'] ) ? intval( $input['max_children'] ) : 0,
            '_tap_room_max_occupancy'    => isset( $input['max_occupancy'] ) ? intval( $input['max_occupancy'] ) : 2,
            '_tap_room_inventory'        => isset( $input['inventory'] ) ? intval( $input['inventory'] ) : 1,
            '_tap_room_size'             => isset( $input['size'] ) ? sanitize_text_field( $input['size'] ) : '',
            '_tap_room_view'             => isset( $input['view'] ) ? sanitize_text_field( $input['view'] ) : '',
            '_tap_room_floor'            => isset( $input['floor'] ) && '' !== $input['floor'] ? intval( $input['floor'] ) : 0,
            '_tap_room_is_active'        => isset( $input['is_active'] ) && '1' === $input['is_active'] ? '1' : '0',
        ];

        foreach ( $fields as $key => $value ) {
            update_post_meta( $new_id, $key, $value );
        }

        return (int) $new_id;
    }


    public static function agency_delete_room() {
        check_ajax_referer( 'tap_agency_listing_nonce', 'nonce' );
        $room_id = isset( $_POST['room_id'] ) ? intval( $_POST['room_id'] ) : 0;
        if ( ! $room_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid room', 'travel-agency-platform' ) ] );
        }
        $acc_id = (int) get_post_meta( $room_id, '_tap_room_accommodation_id', true );
        if ( ! $acc_id || ! TAP_Listing_Access::agency_owns_accommodation( $acc_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You can only manage your own listings.', 'travel-agency-platform' ) ] );
        }

        global $wpdb;
        $future = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tap_bookings WHERE room_id = %d AND check_out > %s AND status NOT IN ('cancelled','refunded')",
            $room_id,
            current_time( 'Y-m-d' )
        ) );
        if ( $future > 0 ) {
            wp_send_json_error( [ 'message' => __( 'This room has future bookings and cannot be deleted.', 'travel-agency-platform' ) ] );
        }

        wp_delete_post( $room_id, true );
        wp_send_json_success( [ 'message' => __( 'Room deleted', 'travel-agency-platform' ) ] );
    }
}
