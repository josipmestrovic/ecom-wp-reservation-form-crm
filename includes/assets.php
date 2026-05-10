<?php
/**
 * Assets — register the front-end CSS and JS for the popup form.
 *
 * The actual `wp_enqueue_*` calls happen in form.php only on `single
 * catholic-tour` pages, so the assets are not loaded site-wide. Here we
 * register them with a `filemtime()` cache-buster so deploys invalidate
 * browser caches automatically.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register (but do not enqueue) the reservation form's stylesheet and script.
 *
 * Registration is split from enqueueing so the popup renderer can decide,
 * per request, whether the assets are actually needed.
 *
 * @return void
 */
function bht_reservation_enqueue_assets() {
    $base_url = get_stylesheet_directory_uri() . '/reservation-form';
    $base_dir = get_stylesheet_directory()     . '/reservation-form';

    wp_register_style(
        'reservation-form-css',
        $base_url . '/reservation-form.css',
        array(),
        filemtime( $base_dir . '/reservation-form.css' )
    );

    wp_register_script(
        'reservation-form-js',
        $base_url . '/reservation-form.js',
        array(),
        filemtime( $base_dir . '/reservation-form.js' ),
        true // Load in footer.
    );
}
add_action( 'wp_enqueue_scripts', 'bht_reservation_enqueue_assets' );
