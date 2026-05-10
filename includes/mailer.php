<?php
/**
 * Email senders — admin notification + client confirmation.
 *
 * Both messages are HTML and use **inline styles** + a table-based layout
 * (the only reliable approach for Outlook / Gmail / Apple Mail). They are
 * sent via `wp_mail()` so any SMTP plugin in place is automatically
 * respected.
 *
 * Brand palette (mirrors /inc/assets/style-guide.md):
 *   Header bg ......... #112941 (deep navy, used behind the white logo)
 *   Primary ........... #1C4168 (navy / accents, links, headings)
 *   Accent ............ #EA7317 (orange, CTA / highlights)
 *   Light grey ........ #CED3DC (table dividers, soft backgrounds)
 *   Body text ......... #384049 (paragraphs)
 *   Title text ........ #242226 (headings)
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Brand "From" address used for both notification + confirmation emails.
 * Must be a real mailbox on the sending domain so SPF / DKIM / DMARC line up.
 */
function bht_reservation_email_from_address() {
    return 'info@bluehearttravel.com';
}

function bht_reservation_email_from_name() {
    return 'Blue Heart Travel';
}

/**
 * Send an email with both an HTML body and a plain-text alternative.
 *
 * Hooks `phpmailer_init` once to set $phpmailer->AltBody, then unhooks. This
 * keeps wp_mail()'s normal pipeline (incl. any SMTP plugin) intact while still
 * giving us a multipart/alternative message — better deliverability and
 * accessibility than HTML-only.
 *
 * @param string|array $to       Recipient(s).
 * @param string       $subject  Subject line.
 * @param string       $html     HTML body.
 * @param string       $text     Plain-text body.
 * @param array        $headers  Extra headers.
 * @return bool wp_mail() return value.
 */
function bht_reservation_send_mail( $to, $subject, $html, $text, $headers = array() ) {
    $set_alt_body = static function ( $phpmailer ) use ( $text ) {
        $phpmailer->AltBody = $text;
    };

    add_action( 'phpmailer_init', $set_alt_body );
    $sent = wp_mail( $to, $subject, $html, $headers );
    remove_action( 'phpmailer_init', $set_alt_body );

    return $sent;
}

/** White-on-dark horizontal logo, used in the admin email header on #112941. */
function bht_reservation_email_logo_header_url() {
    return get_stylesheet_directory_uri() . '/inc/assets/blue-heart-travel-horizontal-logo-dark-background.png';
}

/** Color-on-white horizontal logo, used in the client email header on #ffffff. */
function bht_reservation_email_logo_header_light_url() {
    return get_stylesheet_directory_uri() . '/inc/assets/blue-heart-travel-horizontal-logo-white-background.jpg';
}

/** Contact icons used in the client email footer card. */
function bht_reservation_email_icon_url( $name ) {
    return get_stylesheet_directory_uri() . '/inc/assets/icons/' . $name . '.jpg';
}

/**
 * Wrap a body fragment in the shared branded chrome.
 *
 * @param string $inner_html Body HTML.
 * @param array  $args       'preheader' (string), 'contact_footer' (bool).
 * @return string Full HTML document.
 */
function bht_reservation_email_shell( $inner_html, $args = array() ) {
    $args = wp_parse_args(
        $args,
        array(
            'preheader'      => '',
            'contact_footer' => false,
            'header_style'   => 'dark', // 'dark' = #112941 + white logo (admin); 'light' = #fff + color logo (client); 'none' = no header.
        )
    );

    $header_block = '';
    if ( 'light' === $args['header_style'] ) {
        $logo_header  = esc_url( bht_reservation_email_logo_header_light_url() );
        $header_block = '
                <!-- Header bar (logo — links to homepage) -->
                <tr>
                    <td align="center" class="bht-header-pad" style="background:#ffffff;padding:24px 24px 18px;">
                        <a href="' . esc_url( home_url( '/' ) ) . '" style="display:inline-block;text-decoration:none;border:0;outline:none;">
                            <img src="' . $logo_header . '" alt="Blue Heart Travel" width="240" style="display:block;width:100%;max-width:240px;height:auto;border:0;outline:none;text-decoration:none;">
                        </a>
                    </td>
                </tr>';
    } elseif ( 'dark' === $args['header_style'] ) {
        $logo_header  = esc_url( bht_reservation_email_logo_header_url() );
        $header_block = '
                <!-- Header bar (logo — links to homepage) -->
                <tr>
                    <td align="center" class="bht-header-pad" style="background:#112941;padding:28px 24px;">
                        <a href="' . esc_url( home_url( '/' ) ) . '" style="display:inline-block;text-decoration:none;border:0;outline:none;">
                            <img src="' . $logo_header . '" alt="Blue Heart Travel" width="240" style="display:block;width:100%;max-width:240px;height:auto;border:0;outline:none;text-decoration:none;">
                        </a>
                    </td>
                </tr>';
    }

    $year = gmdate( 'Y' );

    $contact_block = '';
    if ( $args['contact_footer'] ) {
        $icon_mail  = esc_url( bht_reservation_email_icon_url( 'mail' ) );
        $icon_web   = esc_url( bht_reservation_email_icon_url( 'web' ) );
        $icon_phone = esc_url( bht_reservation_email_icon_url( 'phone' ) );

        // Inline-style snippets reused across all four contact rows.
        $cell_style = 'padding:6px 14px;font-size:15px;line-height:1.5;color:#1C4168;vertical-align:middle;';
        $icon_style = 'display:inline-block;width:18px;height:18px;vertical-align:middle;margin-right:10px;border:0;outline:none;text-decoration:none;';
        $link_style = 'color:#1C4168;text-decoration:underline;font-weight:500;';

        $contact_block = '
        <tr>
            <td class="bht-contact-wrap" style="padding:8px 32px 24px;border-top:1px solid #CED3DC;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin-top:18px;">
                    <tr>
                        <td width="50%" class="bht-contact-cell" style="' . $cell_style . '">
                            <img src="' . $icon_mail . '" alt="" width="18" height="18" style="' . $icon_style . '">
                            <a href="mailto:info@bluehearttravel.com" style="' . $link_style . '">info@bluehearttravel.com</a>
                        </td>
                        <td width="50%" class="bht-contact-cell" style="' . $cell_style . '">
                            <img src="' . $icon_phone . '" alt="" width="18" height="18" style="' . $icon_style . '">
                            Toll-free: <a href="tel:+18775455562" style="' . $link_style . '">+1 877 545 5562</a>
                        </td>
                    </tr>
                    <tr>
                        <td width="50%" class="bht-contact-cell" style="' . $cell_style . '">
                            <img src="' . $icon_web . '" alt="" width="18" height="18" style="' . $icon_style . '">
                            <a href="https://bluehearttravel.com" style="' . $link_style . '">bluehearttravel.com</a>
                        </td>
                        <td width="50%" class="bht-contact-cell" style="' . $cell_style . '">
                            <img src="' . $icon_phone . '" alt="" width="18" height="18" style="' . $icon_style . '">
                            Office: <a href="tel:+18574445577" style="' . $link_style . '">+1 857 444 5577</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
    }

    $preheader = '';
    if ( ! empty( $args['preheader'] ) ) {
        $preheader = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f6f9;opacity:0;">' . esc_html( $args['preheader'] ) . '</div>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title>Blue Heart Travel</title>
<style type="text/css">
    /* Prevent iOS auto-linking from restyling phone numbers / addresses. */
    a[x-apple-data-detectors] { color:inherit !important; text-decoration:underline !important; font-weight:inherit !important; }
    /* Long emails / URLs should never trigger horizontal scroll. */
    .bht-sum-val, .bht-sum-label { word-break: break-word; overflow-wrap: anywhere; }

    @media only screen and (max-width: 600px) {
        .bht-shell { width:100% !important; max-width:100% !important; border-radius:0 !important; }
        .bht-outer-pad { padding:0 !important; }
        .bht-body-pad { padding:24px 18px 18px !important; }
        .bht-header-pad { padding:20px 18px !important; }
        .bht-contact-wrap { padding:8px 18px 20px !important; }
        .bht-footer-pad { padding:16px 18px 22px !important; }
        /* Stack the 2x2 contact grid into a single column. */
        .bht-contact-cell {
            display:block !important;
            width:100% !important;
            box-sizing:border-box !important;
            padding:8px 0 !important;
        }
        /* Make the summary table stack label above value for readability. */
        .bht-sum-label, .bht-sum-val {
            display:block !important;
            width:100% !important;
            box-sizing:border-box !important;
        }
        .bht-sum-label { border-bottom:0 !important; padding:10px 12px 4px !important; }
        .bht-sum-val   { padding:0 12px 10px !important; }
        h1 { font-size:20px !important; line-height:1.3 !important; }
        h2 { font-size:12px !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#384049;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
' . $preheader . '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;">
    <tr>
        <td align="center" class="bht-outer-pad" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="bht-shell" style="width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(17,41,65,0.08);">

                ' . $header_block . '

                <!-- Body -->
                <tr>
                    <td class="bht-body-pad" style="padding:32px 32px 24px;color:#384049;font-size:16px;line-height:1.6;">
                        ' . $inner_html . '
                    </td>
                </tr>

                ' . $contact_block . '

                <!-- Footer -->
                <tr>
                    <td align="center" class="bht-footer-pad" style="padding:18px 24px 26px;font-size:12px;color:#81868C;line-height:1.5;">
                        &copy; ' . $year . ' Blue Heart Travel. All rights reserved.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>';
}

/**
 * Render a 2-column key/value summary table in the brand palette.
 *
 * @param array $rows          Label => value pairs (raw, will be escaped).
 * @param array $optional_keys Keys to drop when their value is empty.
 * @return string
 */
function bht_reservation_email_summary_table( $rows, $optional_keys = array() ) {
    foreach ( $optional_keys as $key ) {
        if ( isset( $rows[ $key ] ) && '' === trim( (string) $rows[ $key ] ) ) {
            unset( $rows[ $key ] );
        }
    }

    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:15px;color:#384049;table-layout:fixed;">';
    foreach ( $rows as $label => $value ) {
        $html .= '<tr>'
            . '<td class="bht-sum-label" style="padding:10px 14px;border-bottom:1px solid #CED3DC;background:#F7F9FC;color:#242226;font-weight:600;width:42%;vertical-align:top;word-break:break-word;">' . esc_html( $label ) . '</td>'
            . '<td class="bht-sum-val" style="padding:10px 14px;border-bottom:1px solid #CED3DC;color:#384049;vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">' . nl2br( esc_html( $value ) ) . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    return $html;
}

/**
 * Same as bht_reservation_email_summary_table() but treats values as raw
 * pre-escaped HTML (lets callers embed <a> links). Labels are still escaped.
 *
 * @param array $rows Label => raw HTML value pairs.
 * @return string
 */
function bht_reservation_email_summary_table_raw( $rows ) {
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:15px;color:#384049;table-layout:fixed;">';
    foreach ( $rows as $label => $value ) {
        $html .= '<tr>'
            . '<td class="bht-sum-label" style="padding:10px 14px;border-bottom:1px solid #CED3DC;background:#F7F9FC;color:#242226;font-weight:600;width:42%;vertical-align:top;word-break:break-word;">' . esc_html( $label ) . '</td>'
            . '<td class="bht-sum-val" style="padding:10px 14px;border-bottom:1px solid #CED3DC;color:#384049;vertical-align:top;word-break:break-word;overflow-wrap:anywhere;">' . $value . '</td>'
            . '</tr>';
    }
    $html .= '</table>';

    return $html;
}

/**
 * Send the internal notification to the site admin.
 *
 * @param array $data Reservation payload assembled in handler.php.
 * @return void
 */
function bht_send_admin_notification( $data ) {
    $to      = get_option( 'admin_email' );
    $subject = sprintf(
        '[Blue Heart Travel] New reservation — %s (%s)',
        $data['tour_title'],
        $data['full_name']
    );

    $tour_url  = ! empty( $data['post_id'] ) ? get_permalink( $data['post_id'] ) : '';
    $tour_link = $tour_url
        ? '<a href="' . esc_url( $tour_url ) . '" style="color:#1C4168;font-weight:600;text-decoration:underline;">' . esc_html( $data['tour_title'] ) . '</a>'
        : '<strong style="color:#1C4168;">' . esc_html( $data['tour_title'] ) . '</strong>';

    $tour_cell = ( $tour_url
        ? '<a href="' . esc_url( $tour_url ) . '" style="color:#1C4168;text-decoration:underline;">' . esc_html( $data['tour_title'] ) . '</a>'
        : esc_html( $data['tour_title'] ) ) . ' (' . esc_html( $data['tour_code'] ) . ')';

    // tel: links must be digits only (strip everything but +0-9).
    $phone_tel  = preg_replace( '/[^\d+]/', '', $data['phone'] );
    $phone_link = $phone_tel
        ? '<a href="tel:' . esc_attr( $phone_tel ) . '" style="color:#1C4168;text-decoration:underline;">' . esc_html( $data['phone'] ) . '</a>'
        : esc_html( $data['phone'] );

    // Optional fields: skip when empty so the table stays compact.
    $rows = array(
        'Tour'                => $tour_cell,
        'Selected departures' => esc_html( implode( ', ', $data['selected_departures_list'] ) ),
        'Full name'           => esc_html( $data['full_name'] ),
        'Email'               => '<a href="mailto:' . esc_attr( $data['email'] ) . '" style="color:#1C4168;text-decoration:underline;">' . esc_html( $data['email'] ) . '</a>',
        'Phone'               => $phone_link,
        'Persons'             => esc_html( $data['persons'] ),
        'Room type'           => esc_html( ucfirst( $data['room_type'] ) ),
        'Departure city'      => esc_html( $data['departure_city'] ),
        'Extension interest'  => $data['extension_interest'] ? 'Yes' : 'No',
    );
    if ( ! empty( $data['message'] ) ) {
        $rows['Message'] = nl2br( esc_html( $data['message'] ) );
    }
    if ( ! empty( $data['hear_about'] ) ) {
        $hear_label        = bht_reservation_hear_about_label( $data['hear_about'] );
        $rows['How heard'] = esc_html( '' !== $hear_label ? $hear_label : $data['hear_about'] );
    }

    $summary = bht_reservation_email_summary_table_raw( $rows );

    $inner = '
        <h1 style="margin:0 0 6px;color:#242226;font-size:22px;font-weight:600;line-height:1.3;">New reservation request</h1>
        <p style="margin:0 0 14px;color:#384049;font-size:15px;line-height:1.6;">
            A new booking enquiry was submitted via the website for ' . $tour_link . '.
            Reply directly to <a href="mailto:' . esc_attr( $data['email'] ) . '" style="color:#1C4168;font-weight:600;text-decoration:underline;">' . esc_html( $data['email'] ) . '</a> to contact the customer.
        </p>
        <p style="margin:0 0 22px;color:#384049;font-size:15px;line-height:1.6;">
            You can also call them on ' . $phone_link . '.
        </p>
        ' . $summary . '
        <p style="margin:22px 0 0;color:#81868C;font-size:13px;line-height:1.5;">
            Sent automatically by the reservation form on ' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.
        </p>';

    $body = bht_reservation_email_shell(
        $inner,
        array(
            'preheader'      => sprintf( 'New reservation: %s — %s', $data['tour_title'], $data['full_name'] ),
            'contact_footer' => false,
            'header_style'   => 'none',
        )
    );

    // Plain-text alternative (multipart/alternative — boosts deliverability
    // + lets text-only clients / screen readers render the message cleanly).
    $text_lines = array(
        'New reservation request',
        '',
        sprintf( 'A new booking enquiry was submitted via the website for %s.', $data['tour_title'] ),
    );
    if ( $tour_url ) {
        $text_lines[] = $tour_url;
    }
    $text_lines[] = '';
    $text_lines[] = sprintf( 'Reply directly to %s to contact the customer.', $data['email'] );
    if ( ! empty( $data['phone'] ) ) {
        $text_lines[] = sprintf( 'You can also call them on %s.', $data['phone'] );
    }
    $text_lines[] = '';
    $text_lines[] = '----- Reservation details -----';
    $text_lines[] = sprintf( 'Tour:                %s (%s)', $data['tour_title'], $data['tour_code'] );
    $text_lines[] = sprintf( 'Selected departures: %s', implode( ', ', $data['selected_departures_list'] ) );
    $text_lines[] = sprintf( 'Full name:           %s', $data['full_name'] );
    $text_lines[] = sprintf( 'Email:               %s', $data['email'] );
    $text_lines[] = sprintf( 'Phone:               %s', $data['phone'] );
    $text_lines[] = sprintf( 'Persons:             %s', $data['persons'] );
    $text_lines[] = sprintf( 'Room type:           %s', ucfirst( $data['room_type'] ) );
    $text_lines[] = sprintf( 'Departure city:      %s', $data['departure_city'] );
    $text_lines[] = sprintf( 'Extension interest:  %s', $data['extension_interest'] ? 'Yes' : 'No' );
    if ( ! empty( $data['message'] ) ) {
        $text_lines[] = sprintf( 'Message:             %s', $data['message'] );
    }
    if ( ! empty( $data['hear_about'] ) ) {
        $hear_label   = bht_reservation_hear_about_label( $data['hear_about'] );
        $text_lines[] = sprintf( 'How heard:           %s', '' !== $hear_label ? $hear_label : $data['hear_about'] );
    }
    $text_lines[] = '';
    $text_lines[] = '-- ';
    $text_lines[] = 'Sent automatically by the reservation form on ' . wp_parse_url( home_url(), PHP_URL_HOST );
    $text = implode( "\n", $text_lines );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . bht_reservation_email_from_name() . ' <' . bht_reservation_email_from_address() . '>',
        // Reply-To routes the team's reply straight to the customer's mailbox.
        'Reply-To: ' . $data['full_name'] . ' <' . $data['email'] . '>',
    );

    bht_reservation_send_mail( $to, $subject, $body, $text, $headers );
}

/**
 * Send the styled "we got your request" confirmation to the customer.
 *
 * Uses the admin email as the From address so replies route to the office.
 *
 * @param array $data Reservation payload from handler.php.
 * @return void
 */
function bht_send_client_confirmation( $data ) {
    $to      = $data['email'];
    $subject = sprintf(
        'Your reservation request — %s | Blue Heart Travel',
        $data['tour_title']
    );

    $admin_email = bht_reservation_email_from_address();

    $tour_url  = $data['post_id'] ? get_permalink( $data['post_id'] ) : '';
    $tour_link = $tour_url
        ? '<a href="' . esc_url( $tour_url ) . '" style="color:#1C4168;font-weight:600;text-decoration:underline;">' . esc_html( $data['tour_title'] ) . '</a>'
        : '<strong style="color:#1C4168;">' . esc_html( $data['tour_title'] ) . '</strong>';

    $summary_rows = array(
        'Tour'           => $tour_url
            ? '<a href="' . esc_url( $tour_url ) . '" style="color:#1C4168;text-decoration:underline;">' . esc_html( $data['tour_title'] ) . '</a>'
            : esc_html( $data['tour_title'] ),
        'Tour code'      => esc_html( $data['tour_code'] ),
        'Departure(s)'   => esc_html( implode( ', ', $data['selected_departures_list'] ) ),
        'Persons'        => esc_html( $data['persons'] ),
        'Room type'      => esc_html( ucfirst( $data['room_type'] ) ),
        'Departure city' => esc_html( $data['departure_city'] ),
    );

    $summary = bht_reservation_email_summary_table_raw( $summary_rows );

    $inner = '
        <h1 style="margin:0 0 10px;color:#242226;font-size:24px;font-weight:600;line-height:1.3;">Thank you, ' . esc_html( $data['full_name'] ) . '!</h1>
        <p style="margin:0 0 18px;color:#384049;font-size:16px;line-height:1.6;">
            We\'ve received your reservation request for
            ' . $tour_link . '.
            Our team will review the details and get back to you within
            <strong style="color:#EA7317;">24&nbsp;hours</strong>.
        </p>

        <h2 style="margin:26px 0 12px;color:#1C4168;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;">Your request summary</h2>
        ' . $summary . '

        <p style="margin:24px 0 0;color:#384049;font-size:15px;line-height:1.6;">
            If you have any questions in the meantime, feel free to reply to this email or contact us at
            <a href="mailto:' . esc_attr( $admin_email ) . '" style="color:#1C4168;font-weight:600;text-decoration:underline;">' . esc_html( $admin_email ) . '</a>.
        </p>';

    $body = bht_reservation_email_shell(
        $inner,
        array(
            'preheader'      => sprintf( "We've received your request for %s — we'll be in touch within 24 hours.", $data['tour_title'] ),
            'contact_footer' => true,
            'header_style'   => 'light',
        )
    );

    // Plain-text alternative (multipart/alternative).
    $text_lines = array(
        sprintf( 'Thank you, %s!', $data['full_name'] ),
        '',
        sprintf( "We've received your reservation request for %s.", $data['tour_title'] ),
    );
    if ( $tour_url ) {
        $text_lines[] = $tour_url;
    }
    $text_lines[] = '';
    $text_lines[] = 'Our team will review the details and get back to you within 24 hours.';
    $text_lines[] = '';
    $text_lines[] = '----- Your request summary -----';
    $text_lines[] = sprintf( 'Tour:           %s', $data['tour_title'] );
    $text_lines[] = sprintf( 'Tour code:      %s', $data['tour_code'] );
    $text_lines[] = sprintf( 'Departure(s):   %s', implode( ', ', $data['selected_departures_list'] ) );
    $text_lines[] = sprintf( 'Persons:        %s', $data['persons'] );
    $text_lines[] = sprintf( 'Room type:      %s', ucfirst( $data['room_type'] ) );
    $text_lines[] = sprintf( 'Departure city: %s', $data['departure_city'] );
    $text_lines[] = '';
    $text_lines[] = sprintf( 'If you have any questions in the meantime, reply to this email or contact us at %s.', bht_reservation_email_from_address() );
    $text_lines[] = '';
    $text_lines[] = '-- ';
    $text_lines[] = 'Blue Heart Travel';
    $text_lines[] = 'info@bluehearttravel.com  |  bluehearttravel.com';
    $text_lines[] = 'Toll-free: +1 877 545 5562  |  Office: +1 857 444 5577';
    $text = implode( "\n", $text_lines );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . bht_reservation_email_from_name() . ' <' . bht_reservation_email_from_address() . '>',
        'Reply-To: ' . bht_reservation_email_from_name() . ' <' . bht_reservation_email_from_address() . '>',
    );

    bht_reservation_send_mail( $to, $subject, $body, $text, $headers );
}
