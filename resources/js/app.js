import $ from 'jquery';
// Enables Bootstrap's data-bs-* components (dropdowns, collapse, modals).
import 'bootstrap';

// Page scripts use jQuery for AJAX. Laravel rejects session-authenticated
// writes without the CSRF token, so send it with every jQuery request.
window.$ = window.jQuery = $;

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
    },
});

// Forms with data-confirm="..." (deactivate, archive) ask before submitting.
// The message is read as plain text, never inserted as HTML.
$(document).on('submit', 'form[data-confirm]', function (event) {
    if (! window.confirm(String($(this).data('confirm')))) {
        event.preventDefault();
    }
});
