<?php
/**
 * AJAX submission handler.
 *
 * Pipeline:
 *   1. Verify the WP nonce.
 *   2. Trip on honeypot — silently reject obvious bots.
 *   3. Sanitize every POST field with the appropriate WP helper.
 *   4. Whitelist enum-style fields (`room_type`, `hear_about`).
 *   5. Run server-side validation (the JS validation is UX, not security).
 *   6. Snapshot tour title + code from the post (cannot trust the client copy).
 *   7. Insert into the reservations table with explicit `$wpdb` formats.
 *   8. Fire admin + client emails (mailer.php).
 *   9. Respond with `wp_send_json_success` so the JS can redirect.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle the `bht_submit_reservation` AJAX action.
 *
 * Both `wp_ajax_*` and `wp_ajax_nopriv_*` hook into this so logged-in and
 * anonymous users can submit. All output is JSON via `wp_send_json_*`, which
 * also terminates execution.
 *
 * @return void
 */
function bht_handle_reservation_submit() {
    // 1. Nonce — protects against CSRF.
    check_ajax_referer( 'bht_reservation_nonce', 'bht_nonce' );

    // 2. Honeypot — humans never fill `bht_website` (it's CSS-hidden).
    if ( ! empty( $_POST['bht_website'] ) ) {
        wp_send_json_error( array( 'message' => 'Something went wrong. Please try again.' ) );
    }

    // 3. Sanitize ----------------------------------------------------------

    $post_id            = absint( $_POST['post_id'] ?? 0 );
    $full_name          = sanitize_text_field( $_POST['full_name'] ?? '' );
    $email              = sanitize_email( $_POST['email'] ?? '' );
    $phone              = sanitize_text_field( $_POST['phone'] ?? '' );
    $persons            = absint( $_POST['persons'] ?? 1 );
    $departure_city     = sanitize_text_field( $_POST['departure_city'] ?? '' );
    $message            = sanitize_textarea_field( $_POST['message'] ?? '' );
    $extension_interest = ! empty( $_POST['extension_interest'] ) ? 1 : 0;
    $agree_terms        = ! empty( $_POST['agree_terms'] ) ? 1 : 0;
    $agree_no_refund    = ! empty( $_POST['agree_no_refund'] ) ? 1 : 0;

    // Departures — checkbox array (preferred) or manual text fallback.
    $selected_departures = array();
    if ( ! empty( $_POST['selected_departures'] ) && is_array( $_POST['selected_departures'] ) ) {
        foreach ( $_POST['selected_departures'] as $dep ) {
            $clean = sanitize_text_field( $dep );
            if ( $clean !== '' ) {
                $selected_departures[] = $clean;
            }
        }
    } elseif ( ! empty( $_POST['selected_departures_manual'] ) ) {
        $manual = sanitize_text_field( $_POST['selected_departures_manual'] );
        if ( $manual !== '' ) {
            $selected_departures[] = $manual;
        }
    }

    // 4. Whitelist enum fields --------------------------------------------

    $room_type = sanitize_text_field( $_POST['room_type'] ?? '' );
    if ( ! in_array( $room_type, array( 'single', 'double', 'triple' ), true ) ) {
        $room_type = '';
    }

    $hear_about         = sanitize_text_field( $_POST['hear_about'] ?? '' );
    $hear_about_allowed = array( '', 'church', 'friend', 'search', 'social', 'returning', 'other' );
    if ( ! in_array( $hear_about, $hear_about_allowed, true ) ) {
        $hear_about = '';
    }

    // 5. Validate ----------------------------------------------------------

    $errors = array();

    if ( ! $post_id ) {
        $errors[] = 'Invalid tour.';
    }
    if ( empty( $selected_departures ) ) {
        $errors[] = 'Please select at least one departure date.';
    }
    if ( empty( $full_name ) ) {
        $errors[] = 'Full name is required.';
    }
    if ( empty( $email ) || ! is_email( $email ) ) {
        $errors[] = 'A valid email address is required.';
    }
    if ( empty( $phone ) ) {
        $errors[] = 'Phone number is required.';
    }
    if ( $persons < 1 ) {
        $errors[] = 'At least one person is required.';
    }
    if ( empty( $room_type ) ) {
        $errors[] = 'Room type is required.';
    }
    if ( empty( $departure_city ) ) {
        $errors[] = 'Departure city is required.';
    }
    if ( ! $agree_terms ) {
        $errors[] = 'You must agree to the Terms and Conditions.';
    }
    if ( ! $agree_no_refund ) {
        $errors[] = 'You must acknowledge the non-refundable policy.';
    }

    if ( ! empty( $errors ) ) {
        wp_send_json_error( array( 'message' => implode( ' ', $errors ) ) );
    }

    // 6. Tour data snapshot — derived server-side, never trusted from POST.
    $tour_title = get_the_title( $post_id );
    $tour_code  = get_field( 'tour_code', $post_id );
    if ( empty( $tour_code ) ) {
        $tour_code = 'BHT' . $post_id;
    }

    // 7. Insert ------------------------------------------------------------

    global $wpdb;
    $table = bht_reservation_table();

    $data = array(
        'post_id'             => $post_id,
        'tour_title'          => $tour_title,
        'tour_code'           => $tour_code,
        'selected_departures' => wp_json_encode( $selected_departures ),
        'full_name'           => $full_name,
        'email'               => $email,
        'phone'               => $phone,
        'persons'             => $persons,
        'room_type'           => $room_type,
        'departure_city'      => $departure_city,
        'extension_interest'  => $extension_interest,
        'message'             => $message,
        'hear_about'          => $hear_about,
        'agree_terms'         => $agree_terms,
        'agree_no_refund'     => $agree_no_refund,
        'status'              => 'new',
        'admin_notes'         => '',
    );

    // Format string aligns 1:1 with $data above — keep them in sync.
    $format = array(
        '%d', // post_id
        '%s', // tour_title
        '%s', // tour_code
        '%s', // selected_departures (JSON)
        '%s', // full_name
        '%s', // email
        '%s', // phone
        '%d', // persons
        '%s', // room_type
        '%s', // departure_city
        '%d', // extension_interest
        '%s', // message
        '%s', // hear_about
        '%d', // agree_terms
        '%d', // agree_no_refund
        '%s', // status
        '%s', // admin_notes
    );

    $inserted = $wpdb->insert( $table, $data, $format );

    if ( false === $inserted ) {
        wp_send_json_error( array( 'message' => 'Could not save your reservation. Please try again.' ) );
    }

    // Bust the "new leads" sidebar bubble cache.
    if ( function_exists( 'bht_reservation_flush_lead_count' ) ) {
        bht_reservation_flush_lead_count();
    }

    // 8. Emails — pass the array form of departures so the mailer can render
    //    them as a comma-separated list (the DB stores JSON).
    $data['selected_departures_list'] = $selected_departures;

    bht_send_admin_notification( $data );
    bht_send_client_confirmation( $data );

    // 9. Done.
    wp_send_json_success( array( 'message' => 'Reservation submitted successfully.' ) );
}
add_action( 'wp_ajax_bht_submit_reservation',        'bht_handle_reservation_submit' );
add_action( 'wp_ajax_nopriv_bht_submit_reservation', 'bht_handle_reservation_submit' );
