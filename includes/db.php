<?php
/**
 * Database — schema management for the reservation table.
 *
 * Creates / upgrades the `{$wpdb->prefix}bht_reservations` table that stores
 * every reservation submitted through the popup form. Schema version is tracked
 * in the `bht_reservation_db_version` option so we can run migrations later
 * without recreating the table on every request.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create / upgrade the reservations DB table.
 *
 * Bumps {@see $db_version} when the schema changes so dbDelta() can apply the
 * delta on the next admin/front-end request. The legacy `1.0` schema is dropped
 * on upgrade because the early columns differed (kept here for safety on dev
 * installs — production will simply create the new table once).
 *
 * Hooked on `init` so the table is guaranteed to exist before any AJAX request.
 *
 * @return void
 */
function bht_reservation_create_table() {
    $db_version      = '1.2';
    $current_version = get_option( 'bht_reservation_db_version', '0' );

    // Fast path: schema already up to date.
    if ( $current_version === $db_version ) {
        return;
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'bht_reservations';
    $charset = $wpdb->get_charset_collate();

    // Drop the original 1.0 table — its column set is incompatible with 1.1.
    if ( $current_version === '1.0' ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        tour_title VARCHAR(255) NOT NULL DEFAULT '',
        tour_code VARCHAR(50) NOT NULL DEFAULT '',
        selected_departures TEXT NOT NULL,
        full_name VARCHAR(255) NOT NULL DEFAULT '',
        email VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        persons TINYINT UNSIGNED NOT NULL DEFAULT 1,
        room_type VARCHAR(20) NOT NULL DEFAULT '',
        departure_city VARCHAR(255) NOT NULL DEFAULT '',
        extension_interest TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NOT NULL,
        hear_about VARCHAR(50) NOT NULL DEFAULT '',
        agree_terms TINYINT(1) NOT NULL DEFAULT 0,
        agree_no_refund TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        admin_notes LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'bht_reservation_db_version', $db_version );
}
add_action( 'init', 'bht_reservation_create_table' );

/**
 * Helper — return the fully-qualified reservations table name.
 *
 * Centralised so the table name only lives in one place; every other module
 * calls this helper instead of hard-coding the prefix.
 *
 * @return string
 

/**
 * Reservation status definitions — single source of truth.
 *
 * Each slug maps to a label (UI) and color (used for the small status pill in
 * the admin list table and detail page). Add a new status here and it
 * automatically appears in the filter dropdown, inline picker, and validators.
 *
 * @return array<string, array{label:string,color:string,bg:string}>
 */
function bht_reservation_statuses() {
    return array(
        'new' => array(
            'label' => 'New lead',
            'color' => '#1d4ed8', // text
            'bg'    => '#dbeafe', // background
        ),
        'contacted' => array(
            'label' => 'Contacted',
            'color' => '#92400e',
            'bg'    => '#fef3c7',
        ),
        'closed' => array(
            'label' => 'Closed (won)',
            'color' => '#166534',
            'bg'    => '#dcfce7',
        ),
        'lost' => array(
            'label' => 'Lost',
            'color' => '#374151',
            'bg'    => '#e5e7eb',
        ),
    );
}

/**
 * @return string[] List of valid status slugs (for whitelisting).
 */
function bht_reservation_status_keys() {
    return array_keys( bht_reservation_statuses() );
}

/**
 * Render a colored status pill (HTML, escaped).
 *
 * @param string $status Slug from {@see bht_reservation_statuses()}.
 * @return string Safe HTML.
 */
function bht_reservation_status_pill( $status ) {
    $statuses = bht_reservation_statuses();
    $def      = $statuses[ $status ] ?? $statuses['new'];

    return sprintf(
        '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;color:%s;background:%s;line-height:1.6;white-space:nowrap;">%s</span>',
        esc_attr( $def['color'] ),
        esc_attr( $def['bg'] ),
        esc_html( $def['label'] )
    );
}

function bht_reservation_table() {
    global $wpdb;
    return $wpdb->prefix . 'bht_reservations';
}

/**
 * Human-readable label for a `hear_about` slug.
 *
 * Single source of truth — used in the admin detail view and the admin
 * notification email so they stay in sync. Slugs are kept in the DB; only the
 * presentation is translated. The form options live in `includes/form.php`
 * (intentionally duplicated for now — change requires a coordinated edit).
 *
 * @param string $slug One of: church, friend, search, social, returning, other.
 * @return string Human label, or '' if the slug is unknown / empty.
 */
function bht_reservation_hear_about_label( $slug ) {
    $labels = array(
        'church'    => 'Church / Parish',
        'friend'    => 'Friend / Family',
        'search'    => 'Online search',
        'social'    => 'Social media',
        'returning' => 'Returning customer',
        'other'     => 'Other',
    );

    return $labels[ $slug ] ?? '';
}
