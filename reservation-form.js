/**
 * BHT Reservation Form — front-end controller.
 *
 * Responsibilities:
 *   A. Open / close the popup (trigger button, close button, backdrop, ESC,
 *      focus trap for accessibility).
 *   B. Client-side validation (UX only — server re-validates everything).
 *   C. AJAX submission to admin-ajax.php, redirect to /thank-you/ on success.
 *
 * Globals expected (set via wp_localize_script in includes/form.php):
 *   bhtReservation = { ajaxurl, nonce, thankYou }
 *
 * No dependencies — vanilla JS, IIFE-scoped, runs on DOMContentLoaded.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Cached DOM references — bail out cleanly if the popup isn't on the page.
        var overlay   = document.getElementById('bhtReservationOverlay');
        var modal     = overlay ? overlay.querySelector('.bht-rf-modal') : null;
        var closeBtn  = overlay ? overlay.querySelector('.bht-rf-close') : null;
        var form      = document.getElementById('bhtReservationForm');
        var submitBtn = document.getElementById('bhtSubmitBtn');
        var messages  = document.getElementById('bhtRfMessages');
        var triggerBtn = document.getElementById('ecom-booking-btn');

        if (!overlay || !modal || !form) {
            return;
        }

        /* =================================================================
           A. POPUP OPEN / CLOSE
           ================================================================= */

        var previousActiveElement = null;

        function openPopup() {
            previousActiveElement = document.activeElement;
            overlay.classList.add('bht-rf-visible');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            // Focus the first visible input.
            var firstInput = form.querySelector('input:not([type="hidden"]):not([tabindex="-1"]), select, textarea');
            if (firstInput) {
                setTimeout(function () { firstInput.focus(); }, 100);
            }
        }

        function closePopup() {
            overlay.classList.remove('bht-rf-visible');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            if (previousActiveElement) {
                previousActiveElement.focus();
                previousActiveElement = null;
            }
        }

        // Trigger button.
        if (triggerBtn) {
            triggerBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openPopup();
            });
        }

        // Close button.
        if (closeBtn) {
            closeBtn.addEventListener('click', closePopup);
        }

        // Click outside modal (on overlay backdrop).
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closePopup();
            }
        });

        // Escape key.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('bht-rf-visible')) {
                closePopup();
            }
        });

        // Focus trap.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;

            var focusable = modal.querySelectorAll(
                'a[href], button:not([disabled]), input:not([type="hidden"]):not([tabindex="-1"]), select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusable.length) return;

            var first = focusable[0];
            var last  = focusable[focusable.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        /* =================================================================
           B. (Reserved — section removed)
           ================================================================= */

        /* =================================================================
           C. CLIENT-SIDE VALIDATION
           ================================================================= */

        function clearErrors() {
            var prev = form.querySelectorAll('.bht-rf-field-error');
            prev.forEach(function (el) { el.classList.remove('bht-rf-field-error'); });

            var msgs = form.querySelectorAll('.bht-rf-field-error-msg, .bht-rf-checkbox-error, .bht-rf-departures-error');
            msgs.forEach(function (el) { el.remove(); });

            messages.innerHTML = '';
        }

        function showFieldError(input, msg) {
            var wrapper = input.closest('.bht-rf-field');
            if (wrapper) {
                wrapper.classList.add('bht-rf-field-error');
                var span = document.createElement('span');
                span.className = 'bht-rf-field-error-msg';
                span.textContent = msg;
                wrapper.appendChild(span);
            }
        }

        function showCheckboxError(checkbox, msg) {
            var wrapper = checkbox.closest('.bht-rf-checkbox-field');
            if (wrapper) {
                var p = document.createElement('p');
                p.className = 'bht-rf-checkbox-error';
                p.textContent = msg;
                wrapper.parentNode.insertBefore(p, wrapper.nextSibling);
            }
        }

        function validateForm() {
            clearErrors();
            var valid = true;

            // Required text fields.
            var required = [
                { id: 'bhtFullName',      msg: 'Full name is required.' },
                { id: 'bhtPhone',         msg: 'Phone number is required.' },
                { id: 'bhtDepartureCity', msg: 'Departure city is required.' },
            ];

            required.forEach(function (item) {
                var el = document.getElementById(item.id);
                if (el && !el.value.trim()) {
                    showFieldError(el, item.msg);
                    valid = false;
                }
            });

            // Departure checkboxes — at least one must be selected.
            var departuresContainer = document.getElementById('bhtDepartures');
            if (departuresContainer) {
                var checked = departuresContainer.querySelectorAll('input[type="checkbox"]:checked');
                if (checked.length === 0) {
                    var errP = document.createElement('p');
                    errP.className = 'bht-rf-departures-error';
                    errP.textContent = 'Please select at least one departure date.';
                    departuresContainer.parentNode.insertBefore(errP, departuresContainer.nextSibling);
                    valid = false;
                }
            } else {
                // Fallback: manual text field when no repeater data.
                var manualDates = document.getElementById('bhtDatesManual');
                if (manualDates && !manualDates.value.trim()) {
                    showFieldError(manualDates, 'Dates are required.');
                    valid = false;
                }
            }

            // Email.
            var emailInput = document.getElementById('bhtEmail');
            if (emailInput) {
                var emailVal = emailInput.value.trim();
                if (!emailVal) {
                    showFieldError(emailInput, 'Email is required.');
                    valid = false;
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    showFieldError(emailInput, 'Please enter a valid email.');
                    valid = false;
                }
            }

            // Persons.
            var personsInput = document.getElementById('bhtPersons');
            if (personsInput && parseInt(personsInput.value, 10) < 1) {
                showFieldError(personsInput, 'At least 1 person required.');
                valid = false;
            }

            // Room type.
            var roomSelect = document.getElementById('bhtRoomType');
            if (roomSelect && !roomSelect.value) {
                showFieldError(roomSelect, 'Please select a room type.');
                valid = false;
            }

            // Checkboxes.
            var agreeTerms = document.getElementById('bhtAgreeTerms');
            if (agreeTerms && !agreeTerms.checked) {
                showCheckboxError(agreeTerms, 'You must agree to the Terms and Conditions.');
                valid = false;
            }

            var agreeRefund = document.getElementById('bhtAgreeNoRefund');
            if (agreeRefund && !agreeRefund.checked) {
                showCheckboxError(agreeRefund, 'You must acknowledge the non-refundable policy.');
                valid = false;
            }

            return valid;
        }

        /* =================================================================
           D. FORM SUBMISSION (AJAX)
           ================================================================= */

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!validateForm()) {
                // Scroll to first error inside modal.
                var firstErr = form.querySelector('.bht-rf-field-error, .bht-rf-checkbox-error');
                if (firstErr) {
                    firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            // Enter busy state. Label text swap keeps the user oriented;
            // CSS swaps the icon for the spinner so the layout doesn't shift.
            var labelEl = submitBtn.querySelector('.bht-cta__label');
            var originalLabel = labelEl ? labelEl.textContent : submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
            submitBtn.classList.add('is-busy');
            if (labelEl) {
                labelEl.textContent = 'Sending\u2026';
            } else {
                submitBtn.textContent = 'Sending\u2026';
            }

            function resetBusy() {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('aria-disabled');
                submitBtn.classList.remove('is-busy');
                if (labelEl) {
                    labelEl.textContent = originalLabel;
                } else {
                    submitBtn.textContent = originalLabel;
                }
            }

            var formData = new FormData(form);

            fetch(bhtReservation.ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    // Redirect to thank-you page (busy state stays until navigation).
                    window.location.href = bhtReservation.thankYou;
                } else {
                    messages.innerHTML = '<div class="bht-rf-error-msg">' + escapeHtml(data.data.message || 'Something went wrong.') + '</div>';
                    resetBusy();
                    messages.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            })
            .catch(function () {
                messages.innerHTML = '<div class="bht-rf-error-msg">Network error. Please check your connection and try again.</div>';
                resetBusy();
            });
        });

        /* =================================================================
           E. UTILITY
           ================================================================= */

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    });
})();
