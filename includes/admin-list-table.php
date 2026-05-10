<?php
/**
 * Admin list-table class for reservations.
 *
 * Extends WP_List_Table with the following filters (all combine with AND):
 *
 *   • Tour-code search (text box, top-right) — accepts `BHT05059`, `bht5059`,
 *     or just `5059`. Matches `tour_code` only.
 *   • Status filter (dropdown) — defaults to "Active" which hides
 *     reservations marked Closed/Lost; pick a specific status to drill down.
 *   • Date filter (dropdown) — defaults to the last 3 months. Older entries
 *     stay queryable via "All time".
 *
 * Sortable columns: Tour code, Tour, Name, Submitted (default DESC).
 * Bulk actions: delete.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Reservations list table.
 */
class BHT_Reservations_List_Table extends WP_List_Table {

    /**
     * Allowed values for the `date_range` query var.
     *
     * Maps slug → [label, INTERVAL spec or null for all-time].
     * The INTERVAL value is hard-coded here, never user input — safe to inline
     * directly into the SQL string.
     */
    const DATE_RANGES = array(
        '1m'  => array( 'Last month',     '1 MONTH' ),
        '3m'  => array( 'Last 3 months',  '3 MONTH' ), // Default.
        '6m'  => array( 'Last 6 months',  '6 MONTH' ),
        '1y'  => array( 'Last year',      '1 YEAR' ),
        'all' => array( 'All time',       null ),
    );

    /**
     * SLA thresholds (hours) used by the Age column to colorize unworked /
     * stale leads. Tweak here — the renderer reads them directly.
     */
    const SLA_NEW_AMBER_HOURS  = 12;   // `new` older than this → amber.
    const SLA_NEW_RED_HOURS    = 24;   // `new` older than this → red.
    const SLA_CONTACTED_HOURS  = 168;  // `contacted` older than this (7d) → amber.

    public function __construct() {
        parent::__construct( array(
            'singular' => 'reservation',
            'plural'   => 'reservations',
            'ajax'     => false,
        ) );
    }

    /* ---------- Columns ---------------------------------------------------- */

    public function get_columns() {
        return array(
            'cb'         => '<input type="checkbox" />',
            'tour_code'  => 'Tour code',
            'tour_title' => 'Tour',
            'full_name'  => 'Name',
            'email'      => 'Email',
            'phone'      => 'Phone',
            'departures' => 'Departures',
            'status'     => 'Status',
            'age'        => 'Age',
            'created_at' => 'Submitted',
        );
    }

    public function get_sortable_columns() {
        return array(
            'tour_code'  => array( 'tour_code', false ),
            'tour_title' => array( 'tour_title', false ),
            'full_name'  => array( 'full_name', false ),
            // Age sorts on the underlying timestamp — newer = bigger date,
            // so DESC = freshest-first which matches the visual semantics.
            'age'        => array( 'created_at', true ),
            'created_at' => array( 'created_at', true ), // Default sort.
        );
    }

    /* ---------- Column renderers ------------------------------------------ */

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="reservation_ids[]" value="%d" />', absint( $item['id'] ) );
    }

    public function column_tour_code( $item ) {
        return '<code style="font-size:12px;">' . esc_html( $item['tour_code'] ) . '</code>';
    }

    /**
     * Name column doubles as the row title with View / Delete row-actions.
     * A small 💬 indicator appears when admin notes exist on the reservation.
     */
    public function column_full_name( $item ) {
        $view_url = admin_url( 'admin.php?page=bht-reservation-detail&id=' . absint( $item['id'] ) );

        $delete_url = wp_nonce_url(
            admin_url( 'admin.php?page=bht-reservations&action=delete&id=' . absint( $item['id'] ) ),
            'bht_delete_reservation_' . $item['id']
        );

        $actions = array(
            'view'   => '<a href="' . esc_url( $view_url ) . '">View</a>',
            'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Delete this reservation?\');" style="color:#b32d2e;">Delete</a>',
        );

        $notes_indicator = '';
        if ( ! empty( $item['admin_notes'] ) ) {
            $notes_indicator = ' <span title="Has internal notes" style="cursor:help;">💬</span>';
        }

        return sprintf(
            '<strong><a href="%s">%s</a></strong>%s%s',
            esc_url( $view_url ),
            esc_html( $item['full_name'] ),
            $notes_indicator,
            $this->row_actions( $actions )
        );
    }

    /**
     * Inline status picker — small <select> styled as a pill.
     * Saved via AJAX (admin.js + bht_ajax_update_status in admin.php).
     */
    public function column_status( $item ) {
        $statuses = bht_reservation_statuses();
        $current  = isset( $statuses[ $item['status'] ] ) ? $item['status'] : 'new';
        $def      = $statuses[ $current ];

        $options = '';
        foreach ( $statuses as $slug => $s ) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $slug ),
                selected( $current, $slug, false ),
                esc_html( $s['label'] )
            );
        }

        return sprintf(
            '<select class="bht-status-select" data-id="%d" data-current="%s" '
            . 'style="border:1px solid transparent;border-radius:999px;font-size:11px;'
            . 'font-weight:600;padding:3px 22px 3px 10px;color:%s;background-color:%s;'
            . 'cursor:pointer;-webkit-appearance:none;appearance:none;line-height:1.5;">%s</select>',
            absint( $item['id'] ),
            esc_attr( $current ),
            esc_attr( $def['color'] ),
            esc_attr( $def['bg'] ),
            $options
        );
    }

    /**
     * Decode the JSON column into a comma-separated list for the table cell.
     */
    public function column_departures( $item ) {
        $deps = json_decode( $item['selected_departures'] ?? '[]', true );
        if ( empty( $deps ) || ! is_array( $deps ) ) {
            return '<em style="color:#9ca3af;">—</em>';
        }
        return esc_html( implode( ', ', $deps ) );
    }

    public function column_created_at( $item ) {
        return esc_html( date_i18n( 'M j, Y \a\t g:i A', strtotime( $item['created_at'] ) ) );
    }

    /**
     * Age column — relative time-ago + SLA color coding.
     *
     * Colors are driven by status + age so unworked / stale leads jump out:
     *  - new       ≤ 24h  → muted blue (fresh, on track)
     *  - new       > 24h  → amber       (needs attention)
     *  - new       > 72h  → red         (overdue)
     *  - contacted > 7d   → amber       (stale follow-up)
     *  - closed / lost     → grey       (terminal, no SLA)
     */
    public function column_age( $item ) {
        $ts = strtotime( $item['created_at'] );
        if ( ! $ts ) {
            return '—';
        }

        $hours    = ( time() - $ts ) / HOUR_IN_SECONDS;
        $ago      = human_time_diff( $ts, time() ) . ' ago';
        $status   = $item['status'] ?? 'new';

        $color  = '#1C4168'; // default fresh / on-track.
        $weight = '500';

        if ( in_array( $status, array( 'closed', 'lost' ), true ) ) {
            $color = '#81868C';
        } elseif ( 'new' === $status && $hours > self::SLA_NEW_RED_HOURS ) {
            $color  = '#b32d2e';
            $weight = '600';
        } elseif ( 'new' === $status && $hours > self::SLA_NEW_AMBER_HOURS ) {
            $color  = '#dba617';
            $weight = '600';
        } elseif ( 'contacted' === $status && $hours > self::SLA_CONTACTED_HOURS ) {
            $color  = '#dba617';
            $weight = '600';
        }

        return sprintf(
            '<span style="color:%s;font-weight:%s;white-space:nowrap;">%s</span>',
            esc_attr( $color ),
            esc_attr( $weight ),
            esc_html( $ago )
        );
    }

    public function column_default( $item, $column_name ) {
        return esc_html( $item[ $column_name ] ?? '' );
    }

    /* ---------- Filters above the table ---------------------------------- */

    /**
     * Render status + date filter dropdowns in the tablenav.
     *
     * The surrounding <form method="get"> in the list page wraps both these
     * dropdowns and the search box, so all filters submit together and combine.
     */
    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        $current_status = isset( $_REQUEST['status_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status_filter'] ) ) : 'active';
        $current_range  = isset( $_REQUEST['date_range'] )    ? sanitize_text_field( wp_unslash( $_REQUEST['date_range'] ) )    : '3m';

        echo '<div class="alignleft actions">';

        // Status filter.
        echo '<select name="status_filter">';
        echo '<option value="active"' . selected( $current_status, 'active', false ) . '>Active (excl. closed/lost)</option>';
        echo '<option value="all"' . selected( $current_status, 'all', false ) . '>All statuses</option>';
        echo '<option disabled>──────────</option>';
        foreach ( bht_reservation_statuses() as $slug => $def ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $slug ),
                selected( $current_status, $slug, false ),
                esc_html( $def['label'] )
            );
        }
        echo '</select> ';

        // Date filter.
        echo '<select name="date_range">';
        foreach ( self::DATE_RANGES as $slug => $def ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $slug ),
                selected( $current_range, $slug, false ),
                esc_html( $def[0] )
            );
        }
        echo '</select> ';

        submit_button( 'Filter', '', 'filter_action', false );
        echo '</div>';
    }

    /* ---------- Bulk actions ---------------------------------------------- */

    public function get_bulk_actions() {
        return array( 'bulk-delete' => 'Delete' );
    }

    /* ---------- Query --------------------------------------------------- */

    public function prepare_items() {
        global $wpdb;
        $table    = bht_reservation_table();
        $per_page = 20;

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        // Run any delete actions first so the next query reflects them.
        $this->process_actions();

        // ----- Build WHERE clauses (all combine with AND) ------------------
        $where  = array( '1=1' );
        $params = array();

        // Search — TOUR CODE ONLY. Accepts BHT-prefixed or numeric input
        // (e.g. "BHT05059", "bht05059", or "05059" all match the same row).
        if ( ! empty( $_REQUEST['s'] ) ) {
            $raw      = trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) );
            $stripped = preg_replace( '/^bht/i', '', $raw );

            $variants = array_unique( array_filter( array(
                $raw,
                'BHT' . $stripped,
                $stripped,
            ) ) );

            $or_parts = array();
            foreach ( $variants as $v ) {
                $or_parts[] = 'tour_code LIKE %s';
                $params[]   = '%' . $wpdb->esc_like( $v ) . '%';
            }
            $where[] = '(' . implode( ' OR ', $or_parts ) . ')';
        }

        // Status filter.
        $status_filter = isset( $_REQUEST['status_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status_filter'] ) ) : 'active';
        $valid_keys    = bht_reservation_status_keys();
        if ( in_array( $status_filter, $valid_keys, true ) ) {
            $where[]  = 'status = %s';
            $params[] = $status_filter;
        } elseif ( 'active' === $status_filter ) {
            // Default — hide closed/lost so worked-through leads fade out.
            $where[] = "status NOT IN ('closed','lost')";
        }
        // 'all' → no status clause.

        // Date filter — default to last 3 months.
        $date_range = isset( $_REQUEST['date_range'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_range'] ) ) : '3m';
        if ( ! isset( self::DATE_RANGES[ $date_range ] ) ) {
            $date_range = '3m';
        }
        $interval = self::DATE_RANGES[ $date_range ][1];
        if ( $interval !== null ) {
            // $interval comes from the hard-coded DATE_RANGES constant — never user input.
            $where[] = "created_at >= (NOW() - INTERVAL {$interval})";
        }

        $where_sql = ' WHERE ' . implode( ' AND ', $where );

        // Sort — whitelisted to prevent SQL injection via orderby/order.
        $allowed_orderby = array( 'tour_code', 'tour_title', 'full_name', 'created_at' );
        $orderby = 'created_at';
        if ( ! empty( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $allowed_orderby, true ) ) {
            $orderby = $_REQUEST['orderby'];
        }
        $order = 'DESC';
        if ( ! empty( $_REQUEST['order'] ) && in_array( strtoupper( $_REQUEST['order'] ), array( 'ASC', 'DESC' ), true ) ) {
            $order = strtoupper( $_REQUEST['order'] );
        }

        // Total for pagination.
        if ( $params ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $sql        = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL
        $sql_params = array_merge( $params, array( $per_page, $offset ) );

        $this->items = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A );

        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        ) );
    }

    /* ---------- Delete handlers ----------------------------------------- */

    /**
     * Handle single-row and bulk delete requests.
     */
    private function process_actions() {
        global $wpdb;
        $table = bht_reservation_table();

        // Single delete (row-action link).
        if ( 'delete' === $this->current_action() && ! empty( $_REQUEST['id'] ) ) {
            $id = absint( $_REQUEST['id'] );
            check_admin_referer( 'bht_delete_reservation_' . $id );
            $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
            bht_reservation_flush_lead_count();

            wp_safe_redirect( admin_url( 'admin.php?page=bht-reservations&deleted=1' ) );
            exit;
        }

        // Bulk delete (checkboxes + Apply).
        if (
            'bulk-delete' === $this->current_action()
            && ! empty( $_POST['reservation_ids'] )
            && is_array( $_POST['reservation_ids'] )
        ) {
            check_admin_referer( 'bulk-reservations' );
            $count = 0;
            foreach ( $_POST['reservation_ids'] as $id ) {
                $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
                $count++;
            }
            bht_reservation_flush_lead_count();

            wp_safe_redirect( admin_url( 'admin.php?page=bht-reservations&deleted=' . $count ) );
            exit;
        }
    }
}
