import $ from 'jquery';
import { describeFailure } from './lib/ajax-failure';
import { createLatestOnly } from './lib/latest-only';

// The purchase list (resources/views/transactions/index.blade.php).
//
// The filter form is an ordinary GET form that works without JavaScript.
// With JavaScript, changing a filter reloads only the results (totals, rows,
// pages) from /transactions/results, without a full page load:
//
// - A new filter starts again at page 1. Selects and dates apply at once;
//   the card number waits until typing pauses.
// - Only the newest request may update the page (createLatestOnly), so a
//   slow answer to an older filter can never overwrite a newer one.
// - The address bar keeps the filters (history.pushState). Reload, back and
//   forward, and shared links show the same view.
// - While a request runs, the old results are dimmed and marked busy. After
//   an error they are replaced by the error, so stale totals never look
//   current. Network and server errors offer "Try again"; an expired session
//   offers "Sign in again".
//
// The results are HTML rendered (and escaped) by Blade on the server. Every
// text this script adds is inserted with .text(), never as HTML.

const TYPING_PAUSE_MS = 400;

function pageOf(href) {
    const page = Number(new URL(href, window.location.href).searchParams.get('page'));

    return Number.isInteger(page) && page > 1 ? page : 1;
}

function init($form) {
    const $results = $($form.data('results-target'));
    const $status = $($form.data('status-target'));
    const resultsUrl = String($form.data('results-url'));
    const loginUrl = String($form.data('login-url'));
    const latest = createLatestOnly();
    let currentPage = pageOf(window.location.href);
    let lastQuery = null;
    let typingTimer = null;

    function queryFor(page) {
        const fields = $form.serializeArray().filter((field) => field.value.trim() !== '');

        if (page > 1) {
            fields.push({ name: 'page', value: String(page) });
        }

        return $.param(fields);
    }

    function clearFieldErrors() {
        $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
        $form.find('[data-ajax-error]').remove();
        $form.find('[data-original-describedby]').each(function () {
            const $field = $(this);
            const original = $field.attr('data-original-describedby');

            if (original === '') {
                $field.removeAttr('aria-describedby');
            } else {
                $field.attr('aria-describedby', original);
            }
            $field.removeAttr('data-original-describedby');
        });
    }

    // Puts each message under its field and links it with aria-describedby.
    // Returns the messages that belong to no visible field.
    function showFieldErrors(errors) {
        const unplaced = [];

        Object.entries(errors).forEach(([name, messages]) => {
            const text = [].concat(messages).join(' ');
            const $field = $form.find('[name]').filter((index, element) => element.name === name);

            if ($field.length === 0) {
                unplaced.push(text);

                return;
            }

            const errorId = `filter-${name}-ajax-error`;
            const describedBy = $field.attr('aria-describedby') || '';

            $field.attr('data-original-describedby', describedBy)
                .attr('aria-describedby', `${describedBy} ${errorId}`.trim())
                .attr('aria-invalid', 'true')
                .addClass('is-invalid');
            $('<div class="invalid-feedback" data-ajax-error></div>').attr('id', errorId).text(text).insertAfter($field);
        });

        return unplaced;
    }

    function setBusy(busy) {
        $results.attr('aria-busy', busy ? 'true' : 'false').toggleClass('is-loading', busy);
    }

    function showFailure(failure, extra) {
        const $alert = $('<div class="alert" role="alert"></div>')
            .addClass(failure.kind === 'validation' ? 'alert-warning' : 'alert-danger')
            .append($('<p class="mb-2"></p>').text(extra.length > 0 ? `${failure.message} ${extra.join(' ')}` : failure.message));

        if (failure.kind === 'session') {
            $alert.append($('<a class="btn btn-sm btn-primary"></a>').attr('href', loginUrl).text('Sign in again'));
        } else if (failure.retry) {
            $alert.append($('<button type="button" class="btn btn-sm btn-outline-danger" data-retry></button>').text('Try again'));
        }

        // The old totals and rows go: they no longer describe the filters.
        $results.empty().append($alert);
    }

    function load(page, { push = true, force = false, focusResults = false } = {}) {
        const query = queryFor(page);

        if (! force && query === lastQuery) {
            return;
        }

        lastQuery = query;
        setBusy(true);
        $status.text('Loading purchases…');

        latest.run(
            () => $.ajax({
                url: resultsUrl,
                data: query,
                dataType: 'json',
                headers: { Accept: 'application/json' },
            }),
            {
                success(response) {
                    currentPage = page;
                    clearFieldErrors();
                    $results.html(response.html);
                    setBusy(false);
                    $status.text(response.summary);

                    // The server normalizes the dates (empty dates mean this month).
                    $form.find('[name="from"]').val(response.from);
                    $form.find('[name="to"]').val(response.to);
                    lastQuery = queryFor(page);

                    if (push && response.url !== window.location.pathname + window.location.search) {
                        window.history.pushState({ transactionFilters: true }, '', response.url);
                    }

                    if (focusResults) {
                        $results.trigger('focus');
                    }
                },
                failure(xhr) {
                    const failure = describeFailure(xhr);

                    // Allow the same filters to be sent again.
                    lastQuery = null;
                    clearFieldErrors();
                    setBusy(false);
                    $status.text('');
                    showFailure(failure, failure.kind === 'validation' ? showFieldErrors(failure.errors) : []);
                },
            },
        );
    }

    $form.on('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(typingTimer);
        load(1);
    });

    $form.on('change', 'select, input[type="date"]', () => load(1));

    $form.on('input', 'input[type="search"]', () => {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(() => load(1), TYPING_PAUSE_MS);
    });

    $form.on('click', '[data-reset]', (event) => {
        event.preventDefault();
        window.clearTimeout(typingTimer);
        $form.find('input, select').val('');
        load(1);
    });

    $results.on('click', '.pagination a', function (event) {
        event.preventDefault();
        load(pageOf(this.href), { focusResults: true });
    });

    $results.on('click', '[data-retry]', () => load(currentPage, { force: true }));

    // Back and forward: show the filters that address describes.
    $(window).on('popstate', () => {
        const params = new URLSearchParams(window.location.search);

        $form.find('[name]').each(function () {
            $(this).val(params.get(this.name) ?? '');
        });
        load(pageOf(window.location.href), { push: false, force: true });
    });

    lastQuery = queryFor(currentPage);
}

$(() => {
    $('form[data-transaction-filters]').each(function () {
        init($(this));
    });
});
