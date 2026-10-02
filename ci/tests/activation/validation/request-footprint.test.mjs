import assert from 'node:assert/strict';
import test from 'node:test';

import {
    REQUEST_FOOTPRINT_ANNOTATION,
    REQUEST_FOOTPRINT_PROBES,
    REQUEST_FOOTPRINT_REPEATS,
    buildRequestFootprintAnnotation,
    buildRequestFootprintEvidence,
    buildUnsupportedRequestFootprintEvidence,
    describeRequestFootprintForLog,
    requestFootprintIsSelected,
    runRequestFootprintPhase,
    summarizeRequestFootprintProbe,
} from '../test-package/tests/request-footprint.js';

const SUT = {slug: 'ungated', entrypoint: 'ungated/ungated.php'};

function site(overrides = {}) {
    return {
        hook: 'admin_init',
        file: 'ungated/ungated.php',
        line: 12,
        queries: 2,
        writes: 1,
        http: 0,
        sample: 'UPDATE `wp_options` SET `option_value` = ?',
        ...overrides,
    };
}

// What the recorder reports per hook for these call sites, files included.
function hookTotalsOf(callSites) {
    const hooks = new Map();
    for (const callSite of callSites) {
        const hook = hooks.get(callSite.hook) || {hook: callSite.hook, queries: 0, writes: 0, http: 0, files: [], omitted_files: 0};
        let file = hook.files.find((candidate) => candidate.file === callSite.file);
        if (!file) {
            file = {file: callSite.file, queries: 0, writes: 0, http: 0};
            hook.files.push(file);
        }
        for (const metric of ['queries', 'writes', 'http']) {
            hook[metric] += callSite[metric];
            file[metric] += callSite[metric];
        }
        hooks.set(callSite.hook, hook);
    }

    return [...hooks.values()];
}

function footprint(totalQueries, callSites = [], droppedCallSites = 0, hookTotals = hookTotalsOf(callSites)) {
    return {
        plugin: 'ungated',
        plugin_active: true,
        queries: totalQueries,
        sut: {
            queries: callSites.reduce((sum, callSite) => sum + callSite.queries, 0),
            writes: callSites.reduce((sum, callSite) => sum + callSite.writes, 0),
            http: callSites.reduce((sum, callSite) => sum + callSite.http, 0),
            call_sites: callSites,
            hook_totals: hookTotals,
        },
        dropped_call_sites: droppedCallSites,
    };
}

function withHookTotals(value, hookTotals) {
    return {...value, sut: {...value.sut, hook_totals: hookTotals}};
}

function probe(id, phase, footprints) {
    const definition = REQUEST_FOOTPRINT_PROBES.find((candidate) => candidate.id === id);
    return {
        ...definition,
        phase,
        samples: footprints.map((value) => (
            value instanceof Error
                ? {status: null, footprint: null, error: value.message}
                : {status: 200, footprint: value, error: null}
        )),
    };
}

function everyProbe(phase, footprints) {
    return REQUEST_FOOTPRINT_PROBES.map((definition) => probe(definition.id, phase, footprints));
}

const QUIET = [footprint(10), footprint(10), footprint(10)];

test('reports the SUT counts and subtracts the baseline for the total delta', () => {
    const post = footprint(20, [site()]);
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', [footprint(15), footprint(14), footprint(16)]),
        probe('admin-ajax', 'post_activation', [post, post, post])
    );

    assert.equal(summary.complete, true);
    assert.deepEqual(summary.sut.queries, {min: 2, max: 2});
    assert.deepEqual(summary.sut.writes, {min: 1, max: 1});
    assert.equal(summary.sut.varies, false);
    assert.equal(summary.total_query_delta, 6);
    assert.deepEqual(summary.sut.call_sites.map((callSite) => callSite.line), [12]);
});

test('keeps only call sites seen on every repeat, at their smallest count, and flags variance', () => {
    const steady = site({line: 12, queries: 2, writes: 0});
    const oneOff = site({hook: 'init', line: 40, queries: 5, writes: 1});
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [
            footprint(17, [steady, oneOff]),
            footprint(12, [{...steady, queries: 3}]),
            footprint(12, [steady]),
        ])
    );

    assert.deepEqual(summary.sut.queries, {min: 2, max: 7});
    assert.deepEqual(summary.sut.writes, {min: 0, max: 1});
    assert.equal(summary.sut.varies, true);
    assert.deepEqual(
        summary.sut.call_sites.map((callSite) => [callSite.hook, callSite.line, callSite.queries]),
        [['admin_init', 12, 2]]
    );
});

test('lists no call sites as steady when a repeat was not measured', () => {
    const sample = footprint(12, [site()]);
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [sample, new Error('Event endpoint returned HTTP 500'), sample])
    );

    assert.equal(summary.complete, false);
    assert.deepEqual(summary.errors, ['Event endpoint returned HTTP 500']);
    assert.deepEqual(summary.sut.queries, {min: 2, max: 2});
    assert.deepEqual(summary.sut.call_sites, []);
});

test('ranks writes and HTTP calls ahead of reads, keeps ten, and counts the rest', () => {
    const reads = Array.from({length: 11}, (_, index) => site({line: 100 + index, queries: 9, writes: 0}));
    const write = site({line: 2, queries: 1, writes: 1});
    const http = site({line: 3, queries: 0, writes: 0, http: 1, sample: 'api.example.com'});
    const sample = footprint(30, [...reads, http, write], 4);
    const summary = summarizeRequestFootprintProbe(
        probe('front-page', 'baseline', QUIET),
        probe('front-page', 'post_activation', [sample, sample, sample])
    );

    assert.deepEqual(summary.sut.call_sites.slice(0, 3).map((callSite) => callSite.line), [2, 3, 100]);
    assert.equal(summary.sut.call_sites.length, 10);
    assert.equal(summary.sut.omitted_call_sites, 3 + 4);
});

test('totals a read-only hook whose only call site is past the ten reported', () => {
    const reads = Array.from({length: 12}, (_, index) => site({hook: 'init', file: 'big/multi-currency.php', line: 100 + index, queries: 3, writes: 0}));
    const hidden = site({hook: 'admin_init', file: 'big/class-notes.php', line: 7, queries: 1, writes: 0});
    const sample = footprint(60, [...reads, hidden]);
    const description = JSON.parse(buildRequestFootprintAnnotation(buildRequestFootprintEvidence(
        SUT,
        everyProbe('baseline', QUIET),
        everyProbe('post_activation', [sample, sample, sample])
    )).description).probes['admin-ajax'];

    assert.ok(description.call_sites.every((callSite) => callSite.hook === 'init'));
    assert.deepEqual(description.hooks, ['admin_init', 'init']);
    assert.deepEqual(description.hook_totals, [
        {hook: 'init', queries: 36, writes: 0, http: 0, files: [{file: 'big/multi-currency.php', queries: 36, writes: 0, http: 0}], omitted_files: 0},
        {hook: 'admin_init', queries: 1, writes: 0, http: 0, files: [{file: 'big/class-notes.php', queries: 1, writes: 0, http: 0}], omitted_files: 0},
    ]);
});

test('hook totals are steady: a hook missing from a repeat is left out, and one whose files move keeps its total', () => {
    const steadyHook = (file) => site({hook: 'admin_init', file, queries: 2, writes: 0});
    const oneOff = site({hook: 'shutdown', file: 'ungated/cron.php', queries: 4, writes: 1});
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [
            footprint(14, [steadyHook('ungated/a.php'), oneOff]),
            footprint(12, [steadyHook('ungated/b.php')]),
            footprint(12, [steadyHook('ungated/a.php')]),
        ])
    );

    assert.deepEqual(summary.sut.hook_totals, [
        {hook: 'admin_init', queries: 2, writes: 0, http: 0, files: [], omitted_files: 0},
    ]);
});

test('ranks hook totals by writes, then HTTP calls, then reads, and keeps the omitted file count', () => {
    const hooks = [
        {hook: 'init', queries: 9, writes: 0, http: 0, files: [], omitted_files: 0},
        {hook: 'admin_init', queries: 2, writes: 0, http: 1, files: [], omitted_files: 0},
        {hook: 'wp_loaded', queries: 1, writes: 1, http: 0, files: [{file: 'ungated/a.php', queries: 1, writes: 1, http: 0}], omitted_files: 3},
    ];
    const sample = footprint(20, [], 0, hooks);
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [sample, sample, sample])
    );

    assert.deepEqual(summary.sut.hook_totals.map((hook) => hook.hook), ['wp_loaded', 'admin_init', 'init']);
    assert.equal(summary.sut.hook_totals[0].omitted_files, 3);
});

test('hook and file totals are the smallest count over the repeats, wherever it falls', () => {
    const totals = (queries, aQueries) => [{
        hook: 'admin_init', queries, writes: 0, http: 0, omitted_files: 1,
        files: [{file: 'ungated/a.php', queries: aQueries, writes: 0, http: 0}, {file: 'ungated/b.php', queries: queries - aQueries, writes: 0, http: 0}],
    }];
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [
            footprint(20, [], 0, totals(5, 1)),
            footprint(20, [], 0, totals(3, 1).map((hook) => ({...hook, omitted_files: 4}))),
            footprint(20, [], 0, totals(4, 2)),
        ])
    );

    assert.deepEqual(summary.sut.hook_totals, [{
        hook: 'admin_init', queries: 3, writes: 0, http: 0, omitted_files: 4,
        files: [{file: 'ungated/b.php', queries: 2, writes: 0, http: 0}, {file: 'ungated/a.php', queries: 1, writes: 0, http: 0}],
    }]);
});

test('hooks with equal counts are ordered by name', () => {
    const hook = (name) => ({hook: name, queries: 1, writes: 0, http: 0, files: [], omitted_files: 0});
    const sample = footprint(12, [], 0, [hook('wp_loaded'), hook('admin_init'), hook('init')]);
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [sample, sample, sample])
    );

    assert.deepEqual(summary.sut.hook_totals.map((total) => total.hook), ['admin_init', 'init', 'wp_loaded']);
});

test('hook totals are unavailable, not empty, when a repeat was not measured', () => {
    const sample = footprint(12, [site()]);
    const summary = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', QUIET),
        probe('admin-ajax', 'post_activation', [sample, new Error('Event endpoint returned HTTP 500'), sample])
    );

    assert.equal(summary.sut.hook_totals, null);
});

test('leaves the total delta unknown without a baseline, and a probe unmeasured without samples', () => {
    const failed = new Error('Instrumentation session endpoint returned HTTP 403');
    const sample = footprint(12, [site()]);
    const noBaseline = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', [failed, failed, failed]),
        probe('admin-ajax', 'post_activation', [sample, sample, sample])
    );
    const unmeasured = summarizeRequestFootprintProbe(
        probe('admin-ajax', 'baseline', [failed, failed, failed]),
        probe('admin-ajax', 'post_activation', [failed, failed, failed])
    );

    assert.equal(noBaseline.complete, false);
    assert.equal(noBaseline.total_query_delta, null);
    assert.equal(unmeasured.sut, undefined);
});

test('an entrypoint-less SUT is unsupported rather than a clean zero', () => {
    const evidence = buildRequestFootprintEvidence({slug: 'x', entrypoint: ''}, everyProbe('baseline', QUIET), everyProbe('post_activation', QUIET));

    assert.equal(evidence.supported, false);
    assert.equal(JSON.parse(buildRequestFootprintAnnotation(evidence).description).state, 'unsupported');
});

test('the annotation carries steady-state minimums, call sites, per-probe completeness and the errors', () => {
    const baseline = everyProbe('baseline', QUIET);
    const post = everyProbe('post_activation', [
        footprint(14, [site(), site({hook: '(no hook)', line: 3, writes: 0}), site({line: 4, writes: 0})]),
        footprint(20, [site({queries: 5}), site({hook: '(no hook)', line: 3, writes: 0}), site({line: 4, writes: 0})]),
        footprint(14, [site(), site({hook: '(no hook)', line: 3, writes: 0}), site({line: 4, writes: 0})]),
    ]);
    post[2] = probe('front-page', 'post_activation', [new Error('The probe request returned HTTP 500'), footprint(10), footprint(10)]);
    const annotation = buildRequestFootprintAnnotation(buildRequestFootprintEvidence(SUT, baseline, post));
    const description = JSON.parse(annotation.description);

    assert.equal(annotation.type, REQUEST_FOOTPRINT_ANNOTATION);
    assert.equal(description.state, 'incomplete');
    assert.deepEqual(description.errors, ['/: The probe request returned HTTP 500']);
    assert.equal(description.schema_version, '1.2.0');
    assert.deepEqual(description.probes['admin-ajax'], {
        path: '/wp-admin/admin-ajax.php?action=rest-nonce',
        complete: true,
        queries: 6,
        writes: 1,
        http: 0,
        queries_max: 9,
        writes_max: 1,
        http_max: 0,
        varies: true,
        total_query_delta: 4,
        hooks: ['(no hook)', 'admin_init'],
        call_sites: [
            site(),
            site({hook: '(no hook)', line: 3, writes: 0}),
            site({line: 4, writes: 0}),
        ],
        omitted_call_sites: 0,
        hook_totals: [
            {hook: 'admin_init', queries: 4, writes: 1, http: 0, files: [{file: 'ungated/ungated.php', queries: 4, writes: 1, http: 0}], omitted_files: 0},
            {hook: '(no hook)', queries: 2, writes: 0, http: 0, files: [{file: 'ungated/ungated.php', queries: 2, writes: 0, http: 0}], omitted_files: 0},
        ],
    });
    assert.equal(description.probes['front-page'].complete, false);
    assert.equal(description.probes['front-page'].hook_totals, null);
});

test('a request with no measured sample is named in the annotation errors', () => {
    const post = everyProbe('post_activation', [footprint(10), footprint(10), footprint(10)]);
    post[0] = probe('admin-ajax', 'post_activation', [1, 2, 3].map(() => new Error('The probe request returned HTTP 500')));
    const description = JSON.parse(buildRequestFootprintAnnotation(buildRequestFootprintEvidence(SUT, everyProbe('baseline', QUIET), post)).description);

    assert.equal(description.state, 'incomplete');
    assert.equal(description.probes['admin-ajax'], undefined);
    assert.deepEqual(description.errors, ['/wp-admin/admin-ajax.php?action=rest-nonce: The probe request returned HTTP 500']);
});

test('every failing request keeps an error within the cap, and a run with nothing measured says so in the log', () => {
    const failures = (prefix) => [1, 2, 3].map((index) => new Error(`${prefix} ${index}`));
    const post = [
        probe('admin-ajax', 'post_activation', failures('ajax')),
        probe('wp-admin-profile', 'post_activation', failures('profile')),
        probe('front-page', 'post_activation', failures('front')),
    ];
    const evidence = buildRequestFootprintEvidence(SUT, everyProbe('baseline', QUIET), post);
    const errors = JSON.parse(buildRequestFootprintAnnotation(evidence).description).errors;

    assert.equal(errors.length, 5);
    assert.ok(errors.includes('/: front 1'), errors.join('\n'));
    assert.equal(
        describeRequestFootprintForLog(evidence),
        'QIT request footprint: not measured. See "Request footprint" in the test report.'
    );
});

test('an unsupported SUT keeps its reason in the annotation and the log', () => {
    const evidence = buildUnsupportedRequestFootprintEvidence(SUT, 'The plugin SUT was already active.');

    assert.deepEqual(JSON.parse(buildRequestFootprintAnnotation(evidence).description), {
        schema_version: '1.2.0',
        state: 'unsupported',
        probes: {},
        errors: ['The plugin SUT was already active.'],
    });
    assert.equal(
        describeRequestFootprintForLog(evidence),
        'QIT request footprint: not measured. The plugin SUT was already active.'
    );
});

test('the annotation keeps ten call sites per probe and counts the rest, and the log points at the report', () => {
    const reads = Array.from({length: 12}, (_, index) => site({line: 100 + index, queries: 1, writes: 0}));
    const sample = footprint(30, reads, 2);
    const evidence = buildRequestFootprintEvidence(SUT, everyProbe('baseline', QUIET), everyProbe('post_activation', [sample, sample, sample]));
    const description = JSON.parse(buildRequestFootprintAnnotation(evidence).description);

    assert.equal(description.probes['front-page'].call_sites.length, 10);
    assert.equal(description.probes['front-page'].omitted_call_sites, 2 + 2);
    assert.equal(
        describeRequestFootprintForLog(evidence),
        'QIT request footprint: recorded (report-only). See "Request footprint" in the test report.'
    );
});

test('selection follows the Playwright command line', () => {
    const argv = (...options) => ['node', 'playwright', 'test', ...options];

    assert.equal(requestFootprintIsSelected(argv()), true);
    assert.equal(requestFootprintIsSelected(argv('--grep=@basic')), true);
    assert.equal(requestFootprintIsSelected(argv('--grep=Activate Plugins|Record request footprint')), true);
    assert.equal(requestFootprintIsSelected(argv('--grep=activation.spec.js')), true);
    assert.equal(requestFootprintIsSelected(argv('--grep=@release-smoke')), false);
    assert.equal(requestFootprintIsSelected(argv('-g', '@host-plan')), false);
    assert.equal(requestFootprintIsSelected(argv('--grep=@basic', '--grep-invert', 'footprint')), false);
});

function fakePage(routes) {
    const requests = [];
    return {
        requests,
        request: {
            async get(url, options = {}) {
                requests.push({url, headers: options.headers || {}});
                const route = routes.find(([pattern]) => url.includes(pattern));
                if (!route) {
                    throw new Error(`Unexpected request ${url}`);
                }
                const [status, body] = typeof route[1] === 'function' ? route[1](url, options) : route[1];
                if (status === 'timeout') {
                    throw new Error('Timeout 10000ms exceeded.');
                }
                return {
                    status: () => status,
                    text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
                    json: async () => body,
                };
            },
        },
    };
}

const SESSION_ROUTES = [
    ['action=rest-nonce&', [200, '']],
    ['/qit-activation-smoke/v1/session', [200, {token: 'token'}]],
];

test('a phase sends every repeat with the footprint header and never throws', async () => {
    const recorded = {type: 'request_footprint', footprint: footprint(12, [site()])};
    const page = fakePage([
        ['/qit-activation-smoke/v1/session', [200, {token: 'token'}]],
        ['/qit-activation-smoke/v1/events/', [200, [recorded]]],
        ['/wp-admin/admin-ajax.php?action=rest-nonce', (url, options) => [200, options.headers?.['X-QIT-Request-Footprint'] ? '' : 'nonce']],
        ['/wp-admin/profile.php', [200, '']],
        ['/', [200, '']],
    ]);

    const probes = await runRequestFootprintPhase(page, 'post_activation', 'ungated');
    const probeRequests = page.requests.filter((request) => request.headers['X-QIT-Request-Footprint']);

    assert.equal(probeRequests.length, REQUEST_FOOTPRINT_PROBES.length * REQUEST_FOOTPRINT_REPEATS);
    assert.ok(probeRequests.every((request) => request.headers['X-QIT-Request-Footprint'] === 'ungated'));
    assert.ok(probes.every((result) => result.samples.every((sample) => sample.footprint && !sample.error)));
});

test('a phase turns every way a sample can go wrong into an error on that sample', async () => {
    const cases = [
        ['session failure', [['action=rest-nonce', [403, '0']]], /REST nonce endpoint returned HTTP 403/],
        ['probe timeout', [...SESSION_ROUTES, ['/', ['timeout']]], /Timeout/],
        ['fatal', [...SESSION_ROUTES, ['/events/', [200, [{type: 'php_fatal', error_message: 'Boom'}]]], ['/', [200, '']]], /PHP fatal: Boom/],
        ['server error', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: footprint(1)}]]], ['/', [500, '']]], /returned HTTP 500/],
        ['no event', [...SESSION_ROUTES, ['/events/', [200, []]], ['/', [200, '']]], /no usable footprint \(events: \[\]\)/],
        ['malformed event', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: {}}]]], ['/', [200, '']]], /no usable footprint/],
        ['event without hook totals', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: withHookTotals(footprint(1), undefined)}]]], ['/', [200, '']]], /no usable footprint/],
        ['malformed hook total', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: withHookTotals(footprint(1), [null])}]]], ['/', [200, '']]], /no usable footprint/],
        ['numeric hook name', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: withHookTotals(footprint(1), [{hook: 123, queries: 1, writes: 0, http: 0, files: [], omitted_files: 0}])}]]], ['/', [200, '']]], /no usable footprint/],
        ['inactive SUT', [...SESSION_ROUTES, ['/events/', [200, [{type: 'request_footprint', footprint: {...footprint(1), plugin_active: false}}]]], ['/', [200, '']]], /"ungated" is not an active plugin directory/],
    ];

    for (const [name, routes, expected] of cases) {
        // The session reads its nonce from the same URL as the admin-ajax probe; probes get the case's own answer.
        const probeRoute = routes.find(([pattern]) => pattern === '/');
        const nonceRoute = routes.find(([pattern]) => pattern === 'action=rest-nonce');
        const page = fakePage(nonceRoute ? routes : [
            ['/wp-admin/admin-ajax.php?action=rest-nonce', (url, options) => (options.headers?.['X-QIT-Request-Footprint'] ? probeRoute[1] : [200, 'nonce'])],
            ...routes.filter(([pattern]) => pattern !== 'action=rest-nonce&'),
        ]);
        const probes = await runRequestFootprintPhase(page, 'post_activation', 'ungated');
        const samples = probes.flatMap((result) => result.samples);

        assert.equal(samples.length, REQUEST_FOOTPRINT_PROBES.length * REQUEST_FOOTPRINT_REPEATS, name);
        assert.ok(samples.every((sample) => sample.footprint === null && expected.test(sample.error)), `${name}: ${samples[0].error}`);
    }
});

test('an inactive SUT is expected before activation', async () => {
    const page = fakePage([
        ['/wp-admin/admin-ajax.php?action=rest-nonce', (url, options) => [200, options.headers?.['X-QIT-Request-Footprint'] ? '' : 'nonce']],
        ...SESSION_ROUTES.slice(1),
        ['/events/', [200, [{type: 'request_footprint', footprint: {...footprint(9), plugin_active: false}}]]],
        ['/', [200, '']],
    ]);
    const probes = await runRequestFootprintPhase(page, 'baseline', 'ungated');

    assert.ok(probes.every((result) => result.samples.every((sample) => sample.footprint?.queries === 9)));
});
