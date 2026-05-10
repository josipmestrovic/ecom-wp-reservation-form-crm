<?php
/**
 * Plugin Name: BHT Reservation Form
 * Description: Popup booking form for the `catholic-tour` post type, with
 *              AJAX submission, admin + client emails, and a WP-Admin
 *              CMS panel for managing reservations.
 * Author:      Blue Heart Travel
 * Version:     1.2.0
 *
 * This is a Divi child-theme module — not a plugin. To activate it, add the
 * following line to your child theme's `functions.php`:
 *
 *     require_once get_stylesheet_directory() . '/reservation-form/reservation-form.php';
 *
 * File structure:
 *   reservation-form/
 *   ├── reservation-form.php   ← this bootstrap (loads the modules below)
 *   ├── reservation-form.css   ← popup styles (front-end)
 *   ├── reservation-form.js    ← popup behaviour + AJAX (front-end)
 *   ├── README.md              ← full documentation
 *   └── includes/
 *       ├── db.php                 ← schema, table-name helper, status registry
 *       ├── assets.php             ← register CSS / JS
 *       ├── form.php               ← render the popup in wp_footer
 *       ├── handler.php            ← AJAX submit (sanitize / validate / insert)
 *       ├── mailer.php             ← admin notification + client confirmation
 *       ├── admin.php              ← WP-Admin menu, list/detail, status + notes
 *       ├── admin.js               ← inline status-pill AJAX (admin-only)
 *       └── admin-list-table.php   ← BHT_Reservations_List_Table class
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Single source of truth for the module's base path. Useful if any include
// later needs to load a sibling file or template.
if ( ! defined( 'BHT_RF_DIR' ) ) {
    define( 'BHT_RF_DIR', __DIR__ );
}
if ( ! defined( 'BHT_RF_VERSION' ) ) {
    define( 'BHT_RF_VERSION', '1.2.0' );
}

/*
 * Module loader.
 *
 * Order matters:
 *   1. db.php          — defines bht_reservation_table() used everywhere else.
 *   2. assets.php      — registers CSS/JS so other modules can enqueue them.
 *   3. mailer.php      — declares the email senders used by handler.php.
 *   4. handler.php     — AJAX endpoint; depends on db + mailer.
 *   5. form.php        — front-end popup; depends on assets.
 *   6. admin-list-table.php — class used by admin.php.
 *   7. admin.php       — WP-Admin pages.
 */
require_once BHT_RF_DIR . '/includes/db.php';
require_once BHT_RF_DIR . '/includes/assets.php';
require_once BHT_RF_DIR . '/includes/mailer.php';
require_once BHT_RF_DIR . '/includes/handler.php';
require_once BHT_RF_DIR . '/includes/form.php';
require_once BHT_RF_DIR . '/includes/admin-list-table.php';
require_once BHT_RF_DIR . '/includes/admin.php';
