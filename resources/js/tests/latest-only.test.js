// T34 "AJAX latest response wins", for the logic itself. Run with `npm test`
// (Node's built-in runner; no extra packages). The real page was also checked
// in a browser with a delayed first response (docs/PROGRESS.md, M09).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createLatestOnly } from '../lib/latest-only.js';

// A request whose answer the test releases by hand, like a slow network.
function controllable() {
    let resolve;
    let reject;
    const request = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    request.aborted = false;
    request.abort = () => {
        request.aborted = true;
    };

    return { request, resolve, reject };
}

const settle = () => new Promise((done) => setTimeout(done, 0));

function recorder() {
    const calls = [];

    return {
        calls,
        callbacks: {
            success: (value) => calls.push(['success', value]),
            failure: (error) => calls.push(['failure', error]),
        },
    };
}

test('an older answer that arrives last is ignored', async () => {
    const latest = createLatestOnly();
    const { calls, callbacks } = recorder();
    const first = controllable();
    const second = controllable();

    latest.run(() => first.request, callbacks);
    latest.run(() => second.request, callbacks);

    second.resolve('results for the newer filter');
    first.resolve('results for the older filter');
    await settle();

    assert.deepEqual(calls, [['success', 'results for the newer filter']]);
});

test('starting a new request aborts the one still in flight', () => {
    const latest = createLatestOnly();
    const { callbacks } = recorder();
    const first = controllable();
    const second = controllable();

    latest.run(() => first.request, callbacks);
    assert.equal(first.request.aborted, false);

    latest.run(() => second.request, callbacks);
    assert.equal(first.request.aborted, true);
    assert.equal(second.request.aborted, false);
});

test('a failure of an outdated request is ignored, a failure of the newest is reported', async () => {
    const latest = createLatestOnly();
    const { calls, callbacks } = recorder();
    const first = controllable();
    const second = controllable();

    latest.run(() => first.request, callbacks);
    latest.run(() => second.request, callbacks);

    first.reject({ status: 0, statusText: 'abort' });
    await settle();
    assert.deepEqual(calls, []);

    second.reject({ status: 500 });
    await settle();
    assert.deepEqual(calls, [['failure', { status: 500 }]]);
});

test('requests answered in order each update the page', async () => {
    const latest = createLatestOnly();
    const { calls, callbacks } = recorder();

    const first = controllable();
    latest.run(() => first.request, callbacks);
    first.resolve('page 1');
    await settle();

    const second = controllable();
    latest.run(() => second.request, callbacks);
    assert.equal(first.request.aborted, false, 'a finished request is not aborted');
    second.resolve('page 2');
    await settle();

    assert.deepEqual(calls, [['success', 'page 1'], ['success', 'page 2']]);
});

test('isCurrent tells whether a ticket belongs to the newest request', () => {
    const latest = createLatestOnly();
    const { callbacks } = recorder();

    const firstTicket = latest.run(() => controllable().request, callbacks);
    assert.equal(latest.isCurrent(firstTicket), true);

    const secondTicket = latest.run(() => controllable().request, callbacks);
    assert.equal(latest.isCurrent(firstTicket), false);
    assert.equal(latest.isCurrent(secondTicket), true);
});

test('a plain promise without abort() works too', async () => {
    const latest = createLatestOnly();
    const { calls, callbacks } = recorder();

    latest.run(() => Promise.resolve('older'), callbacks);
    latest.run(() => Promise.resolve('newer'), callbacks);
    await settle();

    assert.deepEqual(calls, [['success', 'newer']]);
});
