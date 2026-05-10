<?php
/**
 * WP-Admin integration — menu, list page, detail page, status AJAX, notes.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the top-level "Reservations" menu and the (hidden) detail subpage.
 *
 * @return void
 */
function bht_reservation_admin_menu() {
    // Show a count bubble (like Comments / Updates) for "New lead" rows.
    $new_count = bht_reservation_new_lead_count();
    $title     = 'Reservations';
    if ( $new_count > 0 ) {
        $title .= ' <span class="awaiting-mod count-' . absint( $new_count ) . '"><span class="pending-count">' . number_format_i18n( $new_count ) . '</span></span>';
    }

    add_menu_page(
        'Reservations',
        $title,
        'manage_options',
        'bht-reservations',
        'bht_reservation_list_page',
        'dashicons-calendar-alt',
        '2'
    );

    add_submenu_page(
        null, // Hidden from the menu.
        'Reservation Detail',
        'Reservation Detail',
        'manage_options',
        'bht-reservation-detail',
        'bht_reservation_detail_page'
    );
}
add_action( 'admin_menu', 'bht_reservation_admin_menu' );

/**
 * Count reservations currently marked as "new" (unworked leads).
 *
 * Cached for 60 seconds in a transient to avoid querying on every admin page
 * load. The cache is invalidated whenever a status changes or a row is added
 * (see bht_reservation_flush_lead_count()).
 *
 * @return int
 */
function bht_reservation_new_lead_count() {
    $cached = get_transient( 'bht_new_lead_count' );
    if ( false !== $cached ) {
        return (int) $cached;
    }

    global $wpdb;
    $table = bht_reservation_table();
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL

    set_transient( 'bht_new_lead_count', $count, MINUTE_IN_SECONDS );
    return $count;
}

/**
 * Invalidate the cached new-lead count.
 *
 * Hooked anywhere a reservation is created, deleted, or has its status changed.
 *
 * @return void
 */
function bht_reservation_flush_lead_count() {
    delete_transient( 'bht_new_lead_count' );
}

/**
 * Append a single note entry to a reservation's `admin_notes` column.
 *
 * Notes are stored append-only as plain text, separated by `\n---\n`.
 * Each entry is prefixed with `[YYYY-MM-DD HH:MM — Author]` (and `[system]`
 * for auto-generated entries like status-change audit trail).
 *
 * Used by:
 *  - bht_post_add_note()        — manual notes from the detail page
 *  - bht_ajax_update_status()   — auto status-change audit
 *  - bht_post_update_status()   — auto status-change audit
 *
 * @param int    $id        Reservation row ID.
 * @param string $note      Note body (already sanitized by caller).
 * @param bool   $is_system True for auto-generated audit entries.
 * @return void
 */
function bht_reservation_append_note( $id, $note, $is_system = false ) {
    if ( ! $id || '' === trim( (string) $note ) ) {
        return;
    }

    global $wpdb;
    $table = bht_reservation_table();

    $existing = (string) $wpdb->get_var( $wpdb->prepare( "SELECT admin_notes FROM {$table} WHERE id = %d", $id ) );

    if ( $is_system ) {
        // System entries are authored by "System" and tagged so the detail
        // view can render them with muted / distinct styling.
        $author = 'System';
    } else {
        $user   = wp_get_current_user();
        $author = $user && $user->display_name ? $user->display_name : 'Admin';
    }

    $timestamp = date_i18n( 'Y-m-d H:i' );
    $tag       = $is_system ? ' [system]' : '';
    $prefix    = '[' . $timestamp . ' — ' . $author . ']' . $tag;
    $entry     = $prefix . "\n" . $note;

    $combined = '' === $existing ? $entry : $existing . "\n---\n" . $entry;

    $wpdb->update(
        $table,
        array( 'admin_notes' => $combined ),
        array( 'id' => $id ),
        array( '%s' ),
        array( '%d' )
    );
}

/**
 * Enqueue the inline-status JS only on the reservations list screen.
 *
 * Localizes the AJAX URL + nonce so the script can hit the
 * `bht_update_reservation_status` action securely.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function bht_reservation_admin_assets( $hook ) {
    // Top-level pages register as `toplevel_page_{slug}`.
    if ( 'toplevel_page_bht-reservations' !== $hook ) {
        return;
    }

    $base_url = get_stylesheet_directory_uri() . '/reservation-form';
    $base_dir = get_stylesheet_directory()     . '/reservation-form';

    wp_enqueue_script(
        'bht-reservation-admin',
        $base_url . '/includes/admin.js',
        array(),
        filemtime( $base_dir . '/includes/admin.js' ),
        true
    );

    wp_localize_script( 'bht-reservation-admin', 'bhtReservationAdmin', array(
        'ajaxurl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bht_status_nonce' ),
        'statuses' => bht_reservation_statuses(),
    ) );
}
add_action( 'admin_enqueue_scripts', 'bht_reservation_admin_assets' );

/**
 * Render the Reservations list page.
 *
 * @return void
 */
function bht_reservation_list_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized access.' );
    }

    // Success notices after redirect.
    if ( ! empty( $_GET['deleted'] ) ) {
        $count = absint( $_GET['deleted'] );
        $msg   = $count === 1 ? 'Reservation deleted.' : $count . ' reservations deleted.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }
    if ( ! empty( $_GET['note_added'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Note added.</p></div>';
    }
    if ( ! empty( $_GET['status_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Status updated.</p></div>';
    }

    $list_table = new BHT_Reservations_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Reservations</h1>
        <hr class="wp-header-end">

        <form method="get">
            <input type="hidden" name="page" value="bht-reservations">
            <?php
            // Custom-labeled search box that makes the "tour code only" intent
            // explicit. We emulate WP_List_Table::search_box() so it lives
            // inside the same form and submits with the filters.
            $search_value = isset( $_REQUEST['s'] ) ? esc_attr( wp_unslash( $_REQUEST['s'] ) ) : '';
            ?>
            <p class="search-box" style="margin-bottom:12px;">
                <label class="screen-reader-text" for="bht-search-input">Search by tour code:</label>
                <input type="search" id="bht-search-input" name="s" value="<?php echo $search_value; ?>" placeholder="Tour code (e.g. BHT05059 or 05059)" style="min-width:280px;">
                <?php submit_button( 'Search', '', '', false, array( 'id' => 'search-submit' ) ); ?>
            </p>

            <?php $list_table->display(); ?>
        </form>
    </div>
    <style>
        .wp-list-table .column-tour_code { width: 110px; }
        .wp-list-table .column-status { width: 130px; }
        .wp-list-table .column-age { width: 90px; }
        .wp-list-table .column-created_at { width: 160px; }
        .bht-status-select:focus { outline: 2px solid #2271b1; outline-offset: 1px; }
    </style>
    <?php
}

/**
 * Render a single reservation's detail view.
 *
 * Includes the read-only data table, the status changer, and the internal
 * notes section.
 *
 * @return void
 */
function bht_reservation_detail_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized access.' );
    }

    global $wpdb;
    $table = bht_reservation_table();

    $id = absint( $_GET['id'] ?? 0 );
    if ( ! $id ) {
        echo '<div class="wrap"><p>Invalid reservation.</p></div>';
        return;
    }

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
    if ( ! $row ) {
        echo '<div class="wrap"><p>Reservation not found.</p></div>';
        return;
    }

    // Inline notices (status updated / note added on this page).
    if ( ! empty( $_GET['status_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Status updated.</p></div>';
    }
    if ( ! empty( $_GET['note_added'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Note added.</p></div>';
    }

    $delete_url = wp_nonce_url(
        admin_url( 'admin.php?page=bht-reservations&action=delete&id=' . $id ),
        'bht_delete_reservation_' . $id
    );

    $statuses       = bht_reservation_statuses();
    $current_status = isset( $statuses[ $row['status'] ] ) ? $row['status'] : 'new';
    ?>
    <div class="wrap">
        <h1>
            Reservation <?php echo esc_html( $row['tour_code'] ); ?>
            <?php echo bht_reservation_status_pill( $current_status ); // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML. ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bht-reservations' ) ); ?>" class="page-title-action">← Back to list</a>
        </h1>

        <div id="bht-detail" style="max-width:760px;margin-top:20px;">

            <!-- ===== Read-only data ===== -->
            <table class="widefat striped" style="margin-bottom:24px;">
                <tbody>
                    <?php
                    $deps_arr     = json_decode( $row['selected_departures'] ?? '[]', true );
                    $deps_display = ( is_array( $deps_arr ) && ! empty( $deps_arr ) )
                        ? esc_html( implode( ', ', $deps_arr ) )
                        : '<em style="color:#9ca3af;">None</em>';

                    $hear_label   = bht_reservation_hear_about_label( $row['hear_about'] );
                    $hear_display = $hear_label !== ''
                        ? esc_html( $hear_label )
                        : '<em style="color:#9ca3af;">Not specified</em>';

                    $has_message = ! empty( $row['message'] );

                    $fields = array(
                        'Tour'               => esc_html( $row['tour_title'] ),
                        'Tour code'          => '<code>' . esc_html( $row['tour_code'] ) . '</code>',
                        'Departure(s)'       => $deps_display,
                        'Full name'          => esc_html( $row['full_name'] ),
                        'Email'              => '<a href="mailto:' . esc_attr( $row['email'] ) . '">' . esc_html( $row['email'] ) . '</a>',
                        'Phone'              => '<a href="tel:' . esc_attr( $row['phone'] ) . '">' . esc_html( $row['phone'] ) . '</a>',
                        'Persons'            => absint( $row['persons'] ),
                        'Room type'          => esc_html( ucfirst( $row['room_type'] ) ),
                        'Departure city'     => esc_html( $row['departure_city'] ),
                        'Extension interest' => $row['extension_interest'] ? '✅ Yes' : '—',
                        // 'Message' is handled separately below — highlighted full-width
                        // when non-empty, plain row when empty.
                        'How heard'          => $hear_display,
                        'Terms agreed'       => $row['agree_terms'] ? '✅' : '❌',
                        'No-refund agreed'   => $row['agree_no_refund'] ? '✅' : '❌',
                        'Submitted'          => esc_html( date_i18n( 'F j, Y \a\t g:i A', strtotime( $row['created_at'] ) ) ),
                    );

                    foreach ( $fields as $label => $value ) {
                        echo '<tr>';
                        echo '<td style="width:180px;font-weight:600;">' . esc_html( $label ) . '</td>';
                        echo '<td>' . $value . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                        echo '</tr>';

                        // Insert the Message row right after "Extension interest" —
                        // highlighted block when there's content, normal row when not.
                        if ( 'Extension interest' === $label ) {
                            if ( $has_message ) {
                                echo '<tr>';
                                echo '<td colspan="2" style="padding:0;">';
                                echo '<div style="margin:6px 8px;padding:14px 16px;background:#FFF8F2;border-left:4px solid #EA7317;border-radius:4px;color:#242226;font-size:14px;line-height:1.6;">';
                                echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#EA7317;margin-bottom:6px;">Message from customer</div>';
                                echo nl2br( esc_html( $row['message'] ) );
                                echo '</div>';
                                echo '</td>';
                                echo '</tr>';
                            } else {
                                echo '<tr>';
                                echo '<td style="width:180px;font-weight:600;">Message</td>';
                                echo '<td><em style="color:#9ca3af;">None</em></td>';
                                echo '</tr>';
                            }
                        }
                    }
                    ?>
                </tbody>
            </table>

            <!-- ===== Status changer ===== -->
            <h2 style="font-size:16px;margin:28px 0 8px;">Status</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;margin-bottom:24px;">
                <?php wp_nonce_field( 'bht_update_status_' . $id ); ?>
                <input type="hidden" name="action" value="bht_update_reservation_status_post">
                <input type="hidden" name="id" value="<?php echo absint( $id ); ?>">
                <select name="status">
                    <?php foreach ( $statuses as $slug => $def ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_status, $slug ); ?>>
                            <?php echo esc_html( $def['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary">Update status</button>
            </form>

            <!-- ===== Internal notes ===== -->
            <h2 style="font-size:16px;margin:28px 0 8px;">Internal notes</h2>
            <p class="description" style="margin-top:0;">Notes are visible only to admins. They are append-only and timestamped.</p>

            <?php
            $notes_raw = trim( (string) $row['admin_notes'] );
            if ( $notes_raw !== '' ) :
                // Split on the delimiter we use when appending (see handler below).
                $notes = array_filter( array_map( 'trim', explode( "\n---\n", $notes_raw ) ) );
                // Newest first.
                $notes = array_reverse( $notes );
                ?>
                <div style="border:1px solid #e5e7eb;border-radius:6px;background:#fff;margin-bottom:16px;">
                    <?php foreach ( $notes as $i => $note ) :
                        // System notes are tagged with `[system]` in the prefix line.
                        // Detected on the first line so we can style them differently
                        // (muted background + small badge) from human notes.
                        $is_system = false;
                        $first_nl  = strpos( $note, "\n" );
                        $first     = false === $first_nl ? $note : substr( $note, 0, $first_nl );
                        if ( false !== strpos( $first, '[system]' ) ) {
                            $is_system = true;
                            // Strip the [system] tag from the visible prefix — we'll show a pill instead.
                            $note_clean = preg_replace( '/\s*\[system\]/', '', $note, 1 );
                        } else {
                            $note_clean = $note;
                        }

                        $bg     = $is_system ? '#f7f9fc' : '#ffffff';
                        $color  = $is_system ? '#5b6471' : 'inherit';
                        $border = $i > 0 ? 'border-top:1px solid #f3f4f6;' : '';
                        ?>
                        <div style="padding:12px 16px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $color ); ?>;<?php echo $border; ?>">
                            <?php if ( $is_system ) : ?>
                                <span style="display:inline-block;padding:1px 8px;margin-right:6px;border-radius:999px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;background:#e5e7eb;color:#4b5563;vertical-align:1px;">System</span>
                            <?php endif; ?>
                            <?php echo nl2br( esc_html( $note_clean ) ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p style="color:#9ca3af;font-style:italic;">No notes yet.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:32px;">
                <?php wp_nonce_field( 'bht_add_note_' . $id ); ?>
                <input type="hidden" name="action" value="bht_add_reservation_note">
                <input type="hidden" name="id" value="<?php echo absint( $id ); ?>">
                <textarea name="note" rows="3" required style="width:100%;max-width:760px;" placeholder="Add a new note (e.g. Called customer — left voicemail)…"></textarea>
                <p>
                    <button type="submit" class="button button-primary">Add note</button>
                </p>
            </form>

            <!-- ===== Danger zone ===== -->
            <a href="<?php echo esc_url( $delete_url ); ?>"
               class="button button-secondary"
               onclick="return confirm('Are you sure you want to delete this reservation?');"
               style="color:#b32d2e;border-color:#b32d2e;">
                Delete this reservation
            </a>
        </div>
    </div>
    <?php
}

/* =========================================================================
   Status update — AJAX (inline pill in list table)
   ========================================================================= */

/**
 * AJAX handler for the inline status pill.
 *
 * Verifies the dedicated `bht_status_nonce`, the user capability, and
 * whitelists the new status against {@see bht_reservation_status_keys()}.
 *
 * @return void
 */
function bht_ajax_update_status() {
    check_ajax_referer( 'bht_status_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
    }

    $id     = absint( $_POST['id'] ?? 0 );
    $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

    if ( ! $id || ! in_array( $status, bht_reservation_status_keys(), true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid input.' ), 400 );
    }

    global $wpdb;
    $table = bht_reservation_table();

    // Capture the previous status so we can audit-log the transition.
    $old_status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );

    $updated = $wpdb->update(
        $table,
        array( 'status' => $status ),
        array( 'id' => $id ),
        array( '%s' ),
        array( '%d' )
    );

    if ( false === $updated ) {
        wp_send_json_error( array( 'message' => 'Database error.' ), 500 );
    }

    // Audit trail: append a system note when the status actually changed.
    if ( $old_status && $old_status !== $status ) {
        $statuses_def = bht_reservation_statuses();
        $old_label    = $statuses_def[ $old_status ]['label'] ?? $old_status;
        $new_label    = $statuses_def[ $status ]['label']     ?? $status;
        bht_reservation_append_note(
            $id,
            sprintf( 'Status changed: %s → %s', $old_label, $new_label ),
            true
        );
    }

    bht_reservation_flush_lead_count();

    $def = bht_reservation_statuses()[ $status ];

    wp_send_json_success( array(
        'status' => $status,
        'label'  => $def['label'],
        'color'  => $def['color'],
        'bg'     => $def['bg'],
    ) );
}
add_action( 'wp_ajax_bht_update_reservation_status', 'bht_ajax_update_status' );

/* =========================================================================
   Status update — POST (detail page form fallback / non-JS path)
   ========================================================================= */

/**
 * admin-post handler — updates status from the detail-page form.
 *
 * Uses a per-reservation nonce so a leaked nonce can't update a different row.
 *
 * @return void
 */
function bht_post_update_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    $id = absint( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_die( 'Invalid reservation.' );
    }

    check_admin_referer( 'bht_update_status_' . $id );

    $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
    if ( ! in_array( $status, bht_reservation_status_keys(), true ) ) {
        wp_die( 'Invalid status.' );
    }

    global $wpdb;
    $table = bht_reservation_table();

    // Capture the previous status so we can audit-log the transition.
    $old_status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );

    $wpdb->update(
        $table,
        array( 'status' => $status ),
        array( 'id' => $id ),
        array( '%s' ),
        array( '%d' )
    );

    // Audit trail: append a system note when the status actually changed.
    if ( $old_status && $old_status !== $status ) {
        $statuses_def = bht_reservation_statuses();
        $old_label    = $statuses_def[ $old_status ]['label'] ?? $old_status;
        $new_label    = $statuses_def[ $status ]['label']     ?? $status;
        bht_reservation_append_note(
            $id,
            sprintf( 'Status changed: %s → %s', $old_label, $new_label ),
            true
        );
    }

    bht_reservation_flush_lead_count();

    wp_safe_redirect( admin_url( 'admin.php?page=bht-reservation-detail&id=' . $id . '&status_updated=1' ) );
    exit;
}
add_action( 'admin_post_bht_update_reservation_status_post', 'bht_post_update_status' );

/* =========================================================================
   Notes — append a new note
   ========================================================================= */

/**
 * admin-post handler — append a new internal note to a reservation.
 *
 * Notes are stored as plain text in the `admin_notes` LONGTEXT column,
 * separated by a `\n---\n` delimiter. Each note is prefixed with the
 * timestamp and the author's display name. Append-only — there is no
 * edit/delete UI on purpose, so the column doubles as an audit trail.
 *
 * @return void
 */
function bht_post_add_note() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    $id = absint( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_die( 'Invalid reservation.' );
    }

    check_admin_referer( 'bht_add_note_' . $id );

    $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
    if ( $note === '' ) {
        wp_safe_redirect( admin_url( 'admin.php?page=bht-reservation-detail&id=' . $id ) );
        exit;
    }

    bht_reservation_append_note( $id, $note, false );

    wp_safe_redirect( admin_url( 'admin.php?page=bht-reservation-detail&id=' . $id . '&note_added=1' ) );
    exit;
}
add_action( 'admin_post_bht_add_reservation_note', 'bht_post_add_note' );
