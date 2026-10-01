// What to tell the user when a page's AJAX request fails. Takes a failed
// jQuery request (anything with status, statusText and responseJSON) and
// returns { kind, message, retry, errors }.
//
// Only messages written by this application are shown as they are: field
// errors (422) and business refusals, which carry a `code` (bootstrap/app.php
// renders BusinessRuleViolation as {message, code, details}). Other bodies
// are not, because a framework error in local debug mode can carry
// exception details.
export function describeFailure(xhr) {
    const status = Number(xhr?.status ?? 0);
    const body = xhr?.responseJSON !== null && typeof xhr?.responseJSON === 'object' ? xhr.responseJSON : {};
    const errors = body.errors !== null && typeof body.errors === 'object' ? body.errors : {};
    const ownMessage = typeof body.code === 'string' && typeof body.message === 'string' && body.message !== '' ? body.message : null;

    if (status === 0) {
        return failure('network', 'No answer from the server. Check your connection and try again.', true);
    }

    switch (status) {
        case 401:
        case 419:
            return failure('session', 'Your session has expired. Sign in again to continue.', false);
        case 403:
            return failure('forbidden', ownMessage ?? 'You do not have access to this. Reload the page to see what you can do.', false);
        case 404:
            return failure('not_found', 'Not found. It may no longer exist, or you no longer have access to it.', false);
        case 409:
            return failure('conflict', ownMessage ?? 'This changed in the meantime.', false);
        case 422:
            return failure('validation', 'Check the highlighted fields.', false, errors);
        case 429:
            return failure('throttled', 'Too many requests. Wait a moment and try again.', true);
        default:
            return failure('server', 'Something went wrong on the server. Try again in a moment.', true);
    }
}

function failure(kind, message, retry, errors = {}) {
    return { kind, message, retry, errors };
}
