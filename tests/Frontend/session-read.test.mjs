import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

function hook() {
    const ref = { current: {} };
    const requests = [];
    const retries = new Map();
    let effect;
    let cleanup;
    let timer = 0;
    const module = { exports: {} };
    const source = ts.transpileModule(
        readFileSync('resources/js/components/hub/use-session-read.ts', 'utf8'),
        {
            compilerOptions: { module: ts.ModuleKind.CommonJS },
        },
    ).outputText;
    vm.runInNewContext(source, {
        module,
        exports: module.exports,
        require: (name) =>
            name === 'react'
                ? {
                      useRef: () => ref,
                      useEffect: (callback) => {
                          effect = callback;
                      },
                  }
                : { xsrfHeader: () => ({}) },
        AbortController,
        document: { visibilityState: 'visible' },
        setTimeout: (callback) => {
            retries.set(++timer, callback);
            return timer;
        },
        clearTimeout: (id) => retries.delete(id),
        fetch: (url, options) =>
            new Promise((resolve, reject) => {
                requests.push({
                    url,
                    body: JSON.parse(options.body),
                    resolve,
                    reject,
                });
            }),
    });
    return {
        requests,
        retries,
        render(session, events) {
            cleanup?.();
            module.exports.useSessionRead(session, [session], events, true);
            cleanup = effect();
        },
    };
}

const session = (id, run) => ({
    id,
    runs: [{ id: run }],
    last_seen_event_id: 0,
});
const event = (id, run) => ({ id, research_run_id: run });
const flush = async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
};

test('session switch excludes retained events and ignores old request completion', async () => {
    const state = hook();
    state.render(session('a', 'run-a'), [event(90, 'run-a')]);
    state.render(session('b', 'run-b'), [event(90, 'run-a')]);
    assert.equal(state.requests.length, 1);
    state.render(session('b', 'run-b'), [event(10, 'run-b')]);
    assert.equal(state.requests[1].url, '/research/sessions/b/read');
    assert.equal(state.requests[1].body.through_event_id, 10);
    state.requests[0].resolve({ ok: true });
    state.requests[1].resolve({ ok: true });
    await flush();
    state.render(session('b', 'run-b'), [event(10, 'run-b')]);
    assert.equal(state.requests.length, 2);
});

test('out-of-order responses cannot lower the acknowledged cursor', async () => {
    const state = hook();
    const active = session('b', 'run-b');
    state.render(active, [event(10, 'run-b')]);
    state.render(active, [event(20, 'run-b')]);
    state.requests[1].resolve({ ok: true });
    await flush();
    state.requests[0].resolve({ ok: true });
    await flush();
    state.render(active, [event(20, 'run-b')]);
    assert.equal(state.requests.length, 2);
});

test('HTTP and network failures retry without acknowledging an unsaved cursor', async () => {
    const state = hook();
    state.render(session('b', 'run-b'), [event(10, 'run-b')]);
    state.requests[0].resolve({ ok: false });
    await flush();
    assert.equal(state.retries.size, 1);
    let retry = state.retries.values().next().value;
    state.retries.clear();
    retry();
    state.requests[1].reject(new Error('offline'));
    await flush();
    assert.equal(state.retries.size, 1);
    retry = state.retries.values().next().value;
    state.retries.clear();
    retry();
    state.requests[2].resolve({ ok: true });
    await flush();
    state.render(session('b', 'run-b'), [event(10, 'run-b')]);
    assert.equal(state.requests.length, 3);
});
