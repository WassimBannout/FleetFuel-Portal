// Keeps only the newest of overlapping requests (docs/06-UI-SPEC.md, "AJAX
// behavior"), so a slow answer to an older filter can never overwrite the
// results of a newer one.
//
// Each run() takes the next sequence number and aborts the request still in
// flight, when it can be aborted. When an answer arrives, its callback runs
// only if no newer run() has started since. Aborting alone is not enough: an
// answer can already be on its way when abort() is called.
//
// `send` returns a promise or a thenable such as a jQuery request; a jQuery
// request also has abort(). No DOM is needed, so this module is unit-tested
// with Node's built-in test runner (resources/js/tests).
export function createLatestOnly() {
    let sequence = 0;
    let inFlight = null;

    return {
        run(send, { success, failure }) {
            sequence += 1;
            const ticket = sequence;

            if (inFlight !== null && typeof inFlight.abort === 'function') {
                inFlight.abort();
            }

            const request = send();
            inFlight = request;

            Promise.resolve(request).then(
                (value) => {
                    if (ticket === sequence) {
                        inFlight = null;
                        success(value);
                    }
                },
                (error) => {
                    if (ticket === sequence) {
                        inFlight = null;
                        failure(error);
                    }
                },
            );

            return ticket;
        },

        isCurrent(ticket) {
            return ticket === sequence;
        },
    };
}
