<?php
/**
 * Front-end form renderer.
 *
 * Outputs the hidden popup markup in `wp_footer` on single `catholic-tour`
 * pages. The popup is opened by the existing `#ecom-booking-btn` button via
 * reservation-form.js — no shortcode or template tag is required.
 *
 * Tour metadata (title, code, ACF departure repeater, extension flag) is
 * pulled directly from the current post and printed as a snapshot so the
 * AJAX handler can re-derive everything from `post_id` server-side.
 *
 * @package BHT\ReservationForm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the reservation popup in the footer.
 *
 * Skips silently on any context other than a single catholic-tour. Handles the
 * Divi Theme Builder edge case where `get_the_ID()` returns the template's ID
 * instead of the queried tour by falling back to `get_queried_object_id()`.
 *
 * @return void
 */
function bht_reservation_render_popup() {
    if ( ! is_singular( 'catholic-tour' ) ) {
        return;
    }

    // Divi Theme Builder fix — get_the_ID() may return the template ID.
    $post_id = get_the_ID();
    if ( ! $post_id || get_post_type( $post_id ) === 'et_template' ) {
        $post_id = get_queried_object_id();
    }
    if ( ! $post_id ) {
        return;
    }

    $tour_title = get_the_title( $post_id );
    $tour_code  = get_field( 'tour_code', $post_id );
    if ( empty( $tour_code ) ) {
        // Deterministic fallback so every tour always has a code.
        $tour_code = 'BHT' . $post_id;
    }
    $has_extension = get_field( 'tour_extensions_boolean', $post_id );

    // Collect departure options from the ACF repeater (label + optional note).
    $departures = array();
    if ( have_rows( 'tour_departures', $post_id ) ) {
        while ( have_rows( 'tour_departures', $post_id ) ) {
            the_row();
            $departures[] = array(
                'label' => get_sub_field( 'departure_label' ),
                'note'  => get_sub_field( 'departure_note' ),
            );
        }
    }

    // Enqueue the previously-registered assets.
    wp_enqueue_style( 'reservation-form-css' );
    wp_enqueue_script( 'reservation-form-js' );

    // Pass server data to JS (AJAX URL, nonce, redirect target).
    wp_localize_script( 'reservation-form-js', 'bhtReservation', array(
        'ajaxurl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bht_reservation_nonce' ),
        'thankYou' => home_url( '/thank-you/' ),
    ) );

    ?>
    <!-- BHT Reservation Popup -->
    <div class="bht-rf-overlay" id="bhtReservationOverlay" aria-hidden="true">
      <div class="bht-rf-modal" role="dialog" aria-modal="true" aria-label="Reservation Form">
        <button class="bht-rf-close" type="button" aria-label="Close">&times;</button>

        <!-- ===== HEADER BAR =====
             Three inline crumbs separated by 1px vertical dividers; on narrow
             screens the bar wraps to a column and the dividers self-hide. -->
        <div class="bht-rf-header">
          <span class="bht-rf-header-item">Reservation form</span>
          <span class="bht-rf-header-divider" aria-hidden="true"></span>
          <span class="bht-rf-header-item"><?php echo esc_html( $tour_title ); ?></span>
          <span class="bht-rf-header-divider" aria-hidden="true"></span>
          <span class="bht-rf-header-item">Tour code: <strong><?php echo esc_html( $tour_code ); ?></strong></span>
        </div>

        <hr class="bht-rf-hr">

        <p class="bht-rf-intro">Fill out the form below and we'll get back to you within 24&nbsp;hours.</p>

        <div class="bht-rf-messages" id="bhtRfMessages"></div>

        <form id="bhtReservationForm" novalidate>
          <?php wp_nonce_field( 'bht_reservation_nonce', 'bht_nonce' ); ?>
          <input type="hidden" name="action" value="bht_submit_reservation">
          <input type="hidden" name="post_id" value="<?php echo absint( $post_id ); ?>">
          <input type="hidden" name="tour_code" value="<?php echo esc_attr( $tour_code ); ?>">

          <!-- Honeypot — invisible to humans, bots tend to fill every field. -->
          <div class="bht-rf-hp" aria-hidden="true">
            <label for="bhtWebsite">Website</label>
            <input type="text" id="bhtWebsite" name="bht_website" tabindex="-1" autocomplete="off">
          </div>

          <!-- ===== TWO-COLUMN GRID (collapses to single column on narrow screens) ===== -->
          <div class="bht-rf-grid">

            <!-- ============ LEFT COLUMN ============ -->
            <div class="bht-rf-col">

              <!-- ----- Preferred departures ----- -->
              <?php if ( ! empty( $departures ) ) : ?>
              <h3 class="bht-rf-section-title">Select your preferred departure(s) <span class="bht-rf-req">*</span></h3>
              <p class="bht-rf-section-hint">Feel free to choose more than one option.</p>

              <div class="bht-rf-departures" id="bhtDepartures">
                <?php foreach ( $departures as $i => $dep ) : ?>
                <label class="bht-rf-departure-option">
                  <input type="checkbox" name="selected_departures[]" value="<?php echo esc_attr( $dep['label'] ); ?>">
                  <span class="bht-rf-departure-label"><?php echo esc_html( $dep['label'] ); ?></span>
                </label>
                <?php endforeach; ?>
              </div>
              <?php else : ?>
              <!-- Fallback when no ACF departures are defined for this tour. -->
              <h3 class="bht-rf-section-title">Preferred dates <span class="bht-rf-req">*</span></h3>
              <div class="bht-rf-field">
                <label for="bhtDatesManual">Dates</label>
                <input type="text" id="bhtDatesManual" name="selected_departures_manual" placeholder="e.g. May 10 – May 22, 2026" required>
              </div>
              <?php endif; ?>

              <!-- ----- Trip details ----- -->
              <h3 class="bht-rf-section-title">Trip details</h3>

              <div class="bht-rf-row">
                <div class="bht-rf-field">
                  <label for="bhtPersons">Number of persons <span class="bht-rf-req">*</span></label>
                  <input type="number" id="bhtPersons" name="persons" min="1" value="1" required>
                </div>
                <div class="bht-rf-field">
                  <label for="bhtRoomType">Room type <span class="bht-rf-req">*</span></label>
                  <select id="bhtRoomType" name="room_type" required>
                    <option value="" disabled selected>&mdash; Select &mdash;</option>
                    <option value="single">Single</option>
                    <option value="double">Double</option>
                    <option value="triple">Triple</option>
                  </select>
                </div>
              </div>

              <div class="bht-rf-field">
                <label for="bhtDepartureCity">Preferred departure city / airport <span class="bht-rf-req">*</span></label>
                <input type="text" id="bhtDepartureCity" name="departure_city" placeholder="e.g. New York (JFK), Chicago (ORD)" required>
              </div>

              <?php if ( $has_extension ) : ?>
              <div class="bht-rf-checkbox-field">
                <input type="checkbox" id="bhtExtensionInterest" name="extension_interest" value="1">
                <label for="bhtExtensionInterest">I'm interested in the tour extension (if available)</label>
              </div>
              <?php endif; ?>

              <div class="bht-rf-field">
                <label for="bhtMessage">Your message (optional)</label>
                <textarea id="bhtMessage" name="message" placeholder="Special requests, questions, or anything else you'd like us to know..."></textarea>
              </div>
            </div>

            <!-- ============ RIGHT COLUMN ============ -->
            <div class="bht-rf-col">

              <h3 class="bht-rf-section-title">Personal information</h3>

              <div class="bht-rf-field">
                <label for="bhtFullName">First and last name <span class="bht-rf-req">*</span></label>
                <input type="text" id="bhtFullName" name="full_name" placeholder="Your full name" required>
              </div>

              <div class="bht-rf-field">
                <label for="bhtEmail">Email <span class="bht-rf-req">*</span></label>
                <input type="email" id="bhtEmail" name="email" placeholder="you@example.com" required>
              </div>

              <div class="bht-rf-field">
                <label for="bhtPhone">Phone number <span class="bht-rf-req">*</span></label>
                <input type="tel" id="bhtPhone" name="phone" placeholder="+1 555 123 4567" required>
              </div>

              <div class="bht-rf-field">
                <label for="bhtHearAbout">How did you hear about us?</label>
                <select id="bhtHearAbout" name="hear_about">
                  <option value="" selected>&mdash; Select (optional) &mdash;</option>
                  <option value="church">Church / Parish</option>
                  <option value="friend">Friend / Family</option>
                  <option value="search">Online search</option>
                  <option value="social">Social media</option>
                  <option value="returning">Returning customer</option>
                  <option value="other">Other</option>
                </select>
              </div>

              <div class="bht-rf-checkbox-field bht-rf-checkbox-field--terms">
                <input type="checkbox" id="bhtAgreeTerms" name="agree_terms" value="1" required>
                <label for="bhtAgreeTerms">I have read and agree to the <a href="#" target="_blank">Terms&nbsp;and&nbsp;Conditions</a> of Blue Heart Travel <span class="bht-rf-req">*</span></label>
              </div>

              <div class="bht-rf-checkbox-field">
                <input type="checkbox" id="bhtAgreeNoRefund" name="agree_no_refund" value="1" required>
                <label for="bhtAgreeNoRefund">I understand that this reservation is non-refundable once airline tickets have been purchased on my behalf <span class="bht-rf-req">*</span></label>
              </div>

              <button type="submit" class="bht-cta bht-cta--full bht-rf-submit" id="bhtSubmitBtn">
                <span class="bht-cta__label">Send Reservation Request</span>
                <span class="bht-cta__icon" aria-hidden="true">
                  <svg width="17" height="13" viewBox="0 0 17 13" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.3596 0.00803625L3.61884 1.48028C3.50295 1.46517 3.44746 1.53069 3.44744 1.67179C3.44743 1.81289 3.4878 1.89349 3.56841 1.91365L12.5376 2.53777L-0.000173671 15.0756C-0.000173671 15.0756 0.0250379 15.1612 0.120759 15.2973C0.21648 15.4232 0.337375 15.5643 0.483492 15.7104C0.594384 15.8213 0.720334 15.9271 0.846284 16.0228C0.972289 16.1186 1.04785 16.1639 1.06801 16.1437L13.6411 3.57069L14.23 12.5752C14.23 12.6356 14.2903 12.6759 14.4113 12.686C14.5322 12.696 14.6179 12.6407 14.6684 12.5298L16.1406 0.789029C16.191 0.48665 16.1607 0.274994 16.0449 0.159154C15.929 0.0432607 15.6771 -0.0373556 15.3697 0.0180932L15.3596 0.00803625Z" fill="currentColor"/></svg>
                </span>
                <span class="bht-cta__spinner" aria-hidden="true"></span>
              </button>
            </div>
          </div>

          <hr class="bht-rf-hr bht-rf-hr--bottom">

          <p class="bht-rf-privacy">
            The contact information collected through this form will not be publicly
            disclosed nor used for anything other than answering your inquiry.
            <br>Check out our <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy&nbsp;Policy</a>.
          </p>
        </form>
      </div>
    </div>
    <?php
}
add_action( 'wp_footer', 'bht_reservation_render_popup' );
