/**
 * BHT Reservations — admin list-table inline status picker.
 *
 * Loaded only on the Reservations admin screen (see
 * bht_reservation_admin_assets() in includes/admin.php). Listens for changes
 * on every `.bht-status-select`, POSTs to admin-ajax.php, and recolors the
 * pill on success.
 *
 * Globals expected (set via wp_localize_script):
 *   bhtReservationAdmin = { ajaxurl, nonce, statuses: { slug: {label,color,bg} } }
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bht-status-select').forEach(function (select) {
            select.addEventListener('change', function () {
                var id        = select.dataset.id;
                var newStatus = select.value;
                var previous  = select.dataset.current;

                // Lock during request to prevent rapid-fire double submits.
                select.disabled = true;

                var body = new URLSearchParams();
                body.append('action', 'bht_update_reservation_status');
                body.append('nonce', bhtReservationAdmin.nonce);
                body.append('id', id);
                body.append('status', newStatus);

                fetch(bhtReservationAdmin.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            // Recolor the pill to match the new status.
                            select.style.color = res.data.color;
                            select.style.backgroundColor = res.data.bg;
                            select.dataset.current = res.data.status;
                            flashBorder(select, '#22c55e');
                        } else {
                            // Revert on failure.
                            select.value = previous;
                            flashBorder(select, '#dc2626');
                            window.alert((res && res.data && res.data.message) || 'Could not update status.');
                        }
                    })
                    .catch(function () {
                        select.value = previous;
                        flashBorder(select, '#dc2626');
                        window.alert('Network error while updating status.');
                    })
                    .finally(function () {
                        select.disabled = false;
                    });
            });
        });

        function flashBorder(el, color) {
            el.style.borderColor = color;
            setTimeout(function () { el.style.borderColor = 'transparent'; }, 800);
        }
    });
})();
