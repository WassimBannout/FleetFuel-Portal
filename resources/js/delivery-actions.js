import $ from 'jquery';
import { describeFailure } from './lib/ajax-failure';

// Delivery status buttons (resources/views/deliveries/_panel.blade.php).
//
// Each form is an ordinary PATCH form that works without JavaScript. With
// JavaScript it is sent with jQuery, asking for JSON; the CSRF header comes
// from $.ajaxSetup in app.js and the form's hidden expected_status tells
// the server which status the user saw. Afterwards the panel is reloaded
// from the server, so the page never shows a stale status:
//
// - success: show the message and reload the panel;
// - 409 (stale_state, invalid_transition): show why and reload the panel,
//   because the order changed in the meantime;
// - 422: show the field errors next to the fields and keep the form;
// - 401/419 (signed out, session expired): say so, with a sign-in link;
// - anything else (offline, server error): a generic message from
//   describeFailure(), and the form is re-enabled to try again.
//
// Messages are inserted with .text(), never as HTML. The reloaded panel is
// HTML rendered (and escaped) by Blade on the server.

function showFeedback($panel, kind, message) {
    const $alert = $('<div class="alert" role="alert"></div>').addClass(`alert-${kind}`).text(message);

    $($panel.data('feedback')).empty().append($alert);
}

function clearFieldErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('[data-ajax-error]').remove();
}

function showFieldErrors($form, errors) {
    let shownNextToField = true;

    Object.entries(errors).forEach(([field, messages]) => {
        const $input = $form.find('[name]').filter((index, element) => element.name === field);
        const text = [].concat(messages).join(' ');

        if ($input.length === 0) {
            shownNextToField = false;

            return;
        }

        $input.addClass('is-invalid');
        $('<div class="invalid-feedback" data-ajax-error></div>').text(text).insertAfter($input);
    });

    return shownNextToField;
}

function reloadPanel($panel) {
    return $.get($panel.data('panel-url')).done((html) => {
        $panel.html(html);
    });
}

$(document).on('submit', 'form[data-delivery-action]', function (event) {
    event.preventDefault();

    const $form = $(this);
    const $panel = $form.closest('[data-delivery-panel]');
    const $buttons = $form.find('button[type="submit"]');
    const confirmMessage = $form.data('delivery-confirm');

    if ($form.data('pending') || (confirmMessage && ! window.confirm(String(confirmMessage)))) {
        return;
    }

    // One request at a time: a double click must not send the change twice.
    $form.data('pending', true);
    $buttons.prop('disabled', true);
    clearFieldErrors($form);

    const restore = () => {
        $form.data('pending', false);
        $buttons.prop('disabled', false);
    };

    $.ajax({
        url: $form.attr('action'),
        method: 'POST', // the form's _method field makes it a PATCH
        data: $form.serialize(),
        dataType: 'json',
        headers: { Accept: 'application/json' },
    })
        .done((response) => {
            showFeedback($panel, 'success', response.message);
            reloadPanel($panel).fail(() => {
                showFeedback($panel, 'warning', `${response.message} Reload the page to see the new status.`);
            });
        })
        .fail((xhr) => {
            const body = xhr.responseJSON || {};

            if (xhr.status === 422 && body.errors) {
                const allShown = showFieldErrors($form, body.errors);
                const other = allShown ? '' : ` ${Object.values(body.errors).flat().join(' ')}`;
                showFeedback($panel, 'danger', `Check the highlighted fields.${other}`);
                restore();

                return;
            }

            if (xhr.status === 409) {
                showFeedback($panel, 'warning', `${body.message || 'This order changed in the meantime.'} The order below has been refreshed.`);
                reloadPanel($panel).fail(restore);

                return;
            }

            const failure = describeFailure(xhr);

            if (failure.kind === 'session') {
                showFeedback($panel, 'danger', failure.message);
                $($panel.data('feedback')).find('.alert')
                    .append(' ', $('<a class="alert-link"></a>').attr('href', $panel.data('login-url')).text('Sign in again'));
            } else {
                showFeedback($panel, 'danger', `The change was not saved. ${failure.message}`);
            }

            restore();
        });
});
