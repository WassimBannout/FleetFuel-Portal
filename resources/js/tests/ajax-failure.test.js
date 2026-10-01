// Messages for failed page requests: expired sessions, offline, validation,
// and no framework error text shown to the user. Run with `npm test`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { describeFailure } from '../lib/ajax-failure.js';

test('no answer at all (offline, connection refused) can be retried', () => {
    const failure = describeFailure({ status: 0, statusText: 'error' });

    assert.equal(failure.kind, 'network');
    assert.equal(failure.retry, true);
    assert.match(failure.message, /connection/);
});

test('an expired session (401 on reads, 419 on writes) asks to sign in again', () => {
    for (const status of [401, 419]) {
        const failure = describeFailure({ status, responseJSON: { message: 'Unauthenticated.' } });

        assert.equal(failure.kind, 'session');
        assert.equal(failure.retry, false);
        assert.match(failure.message, /Sign in again/);
    }
});

test('field errors are passed on with a generic summary', () => {
    const errors = { to: ['The to field must be a date after from.'] };
    const failure = describeFailure({ status: 422, responseJSON: { message: 'The to field must be a date after from.', errors } });

    assert.equal(failure.kind, 'validation');
    assert.deepEqual(failure.errors, errors);
    assert.equal(failure.message, 'Check the highlighted fields.');
});

test("the application's own refusal message is shown for a conflict", () => {
    const failure = describeFailure({ status: 409, responseJSON: { message: 'This order is already scheduled.', code: 'stale_state' } });

    assert.equal(failure.kind, 'conflict');
    assert.equal(failure.message, 'This order is already scheduled.');
});

test("a business refusal's own message is shown, a framework 403 message is not", () => {
    const refusal = describeFailure({ status: 403, responseJSON: { message: 'Atlas Logistics is inactive; its orders cannot change.', code: 'company_inactive', details: {} } });
    assert.equal(refusal.message, 'Atlas Logistics is inactive; its orders cannot change.');

    const framework = describeFailure({ status: 403, responseJSON: { message: 'This action is unauthorized.', exception: 'Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException' } });
    assert.equal(framework.kind, 'forbidden');
    assert.doesNotMatch(framework.message, /unauthorized|Symfony/);
});

test('server error bodies are never shown, they may hold debug details', () => {
    const failure = describeFailure({ status: 500, responseJSON: { message: 'SQLSTATE[42S22]: Column not found', trace: [] } });

    assert.equal(failure.kind, 'server');
    assert.equal(failure.retry, true);
    assert.doesNotMatch(failure.message, /SQLSTATE/);
});

test('403, 404 and 429 get their own wording', () => {
    assert.equal(describeFailure({ status: 403 }).kind, 'forbidden');
    assert.equal(describeFailure({ status: 404 }).kind, 'not_found');
    assert.equal(describeFailure({ status: 429 }).retry, true);
});

test('a response without JSON (an HTML error page) is handled', () => {
    const failure = describeFailure({ status: 502, responseText: '<html>Bad Gateway</html>' });

    assert.equal(failure.kind, 'server');
    assert.deepEqual(failure.errors, {});
});
