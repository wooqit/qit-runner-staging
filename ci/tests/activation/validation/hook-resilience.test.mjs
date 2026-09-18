import assert from 'node:assert/strict';
import test from 'node:test';

import {
    HOOK_RESILIENCE_PROBES,
    buildUnsupportedHookResilienceEvidence,
    classifyHookResilienceEvidence,
    deduplicateEvents,
    eventIsAttributedToSut,
    formatHookResilienceOutcome,
    getSutInstallPath,
    pluginMatchesSut,
    validateProbeResponse,
} from '../test-package/tests/hook-resilience.js';

function phaseProbes(phase, overrides = {}) {
    return HOOK_RESILIENCE_PROBES.map((probe) => {
        const key = `${probe.scenario}:${probe.id}`;
        return {
            ...probe,
            phase,
            status: probe.expected_statuses?.[0] ?? 200,
            transport_error: null,
            instrumentation_error: null,
            event_fetch_error: null,
            events: [],
            attributed_to_sut: false,
            response_payload: probe.expected_json ? {...probe.expected_json} : null,
            response_validation_error: null,
            ...(overrides[key] || {}),
        };
    });
}

function evidence(baselineOverrides = {}, postActivationOverrides = {}) {
    return {
        probes: [
            ...phaseProbes('baseline', baselineOverrides),
            ...phaseProbes('post_activation', postActivationOverrides),
        ],
    };
}

test('healthy baseline and post-activation probes pass', () => {
    const outcome = classifyHookResilienceEvidence(evidence());

    assert.equal(outcome.status, 'passed');
    assert.equal(outcome.code, 'QIT_ACTIVATION_SMOKE_PASSED');
});

test('the blocking catalog includes settings, mail, and both WP_Error contracts', () => {
    const probeKeys = HOOK_RESILIENCE_PROBES.map((probe) => `${probe.scenario}:${probe.id}`);

    assert.equal(HOOK_RESILIENCE_PROBES.length, 12);
    assert.equal(probeKeys.includes('normal:woocommerce-settings'), true);
    assert.equal(probeKeys.includes('normal:wp-mail-pipeline'), true);
    assert.equal(probeKeys.includes('pre_http_request:wp_error:outbound-http'), true);
    assert.equal(
        probeKeys.includes('rest_authentication_errors:wp_error:rest-index-pretty'),
        true
    );
});

test('a SUT outside the activation boundary is unsupported instead of baseline-invalid', () => {
    const evidence = buildUnsupportedHookResilienceEvidence({
        slug: 'already-active-plugin',
        type: 'plugin',
        entrypoint: 'already-active-plugin/plugin.php',
    }, 'The plugin SUT was already active before the activation test.');

    assert.equal(evidence.supported, false);
    assert.equal(evidence.outcome.status, 'unsupported');
    assert.equal(evidence.outcome.code, 'QIT_ACTIVATION_SMOKE_UNSUPPORTED');
    assert.equal(evidence.outcome.inconclusive, true);
    assert.deepEqual(evidence.probes, []);
});

test('duplicate fatal events are collapsed by their stable fields', () => {
    const fatal = {
        type: 'php_fatal',
        error_type: 'TypeError',
        error_message: 'Expected bool, null given',
        error_file: '/wp-content/plugins/example/example.php',
        error_line: 10,
        route: '/wp-json/',
    };

    assert.deepEqual(deduplicateEvents([fatal, {...fatal}]), [fatal]);
});

test('an unhealthy baseline blocks as inconclusive', () => {
    const outcome = classifyHookResilienceEvidence(evidence({
        'normal:rest-index-pretty': {
            status: 500,
            attributed_to_sut: true,
        },
    }));

    assert.equal(outcome.status, 'baseline_invalid');
    assert.equal(outcome.code, 'QIT_ACTIVATION_SMOKE_BASELINE_INVALID');
    assert.equal(outcome.inconclusive, true);
    assert.equal(outcome.attributed_to_sut, false);
    assert.equal(outcome.failures[0].attributed_to_sut, false);
    assert.deepEqual(outcome.failures[0].reasons, ['http_500']);
});

test('a new post-activation 5xx is a regression', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:rest-index-query': {status: 503},
    }));

    assert.equal(outcome.status, 'regression');
    assert.equal(outcome.code, 'QIT_ACTIVATION_SMOKE_REGRESSION');
    assert.equal(outcome.inconclusive, false);
    assert.deepEqual(outcome.failures[0].reasons, ['http_503']);
});

test('a captured fatal fails even when the HTTP status is 200', () => {
    const fatal = {
        type: 'php_fatal',
        error_type: 'TypeError',
        error_message: 'Argument #1 must be of type bool, null given',
        error_file: '/wp-content/plugins/google-listings-and-ads/src/ImageProxy.php',
        error_line: 316,
        error_trace: '',
        attributed_to_sut: true,
    };
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'rest_pre_serve_request:null:product-category-taxonomy': {
            status: 200,
            events: [fatal],
            attributed_to_sut: true,
        },
    }));

    assert.equal(outcome.status, 'regression');
    assert.deepEqual(outcome.failures[0].reasons, ['php_fatal']);
    assert.equal(outcome.failures[0].attributed_to_sut, true);
    assert.match(
        formatHookResilienceOutcome(outcome),
        /google-listings-and-ads\/src\/ImageProxy\.php:316/
    );
});

test('a post-activation transport error is a regression', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:front-page': {
            status: null,
            transport_error: 'socket closed',
        },
    }));

    assert.equal(outcome.status, 'regression');
    assert.deepEqual(outcome.failures[0].reasons, ['transport_error']);
});

test('an unavailable event collector invalidates the baseline', () => {
    const outcome = classifyHookResilienceEvidence(evidence({
        'normal:front-page': {
            event_fetch_error: 'Event endpoint returned HTTP 403',
        },
    }));

    assert.equal(outcome.status, 'baseline_invalid');
    assert.deepEqual(outcome.failures[0].reasons, ['instrumentation_error']);
});

test('a post-activation instrumentation failure is inconclusive, not a regression', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:front-page': {
            instrumentation_error: 'REST nonce endpoint returned HTTP 403',
        },
    }));

    assert.equal(outcome.status, 'baseline_invalid');
    assert.equal(outcome.code, 'QIT_ACTIVATION_SMOKE_BASELINE_INVALID');
    assert.equal(outcome.inconclusive, true);
    assert.equal(outcome.attributed_to_sut, false);
    assert.deepEqual(outcome.failures[0].reasons, ['instrumentation_error']);
});

test('ordinary 4xx responses do not fail the resilience check', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:woocommerce-products': {status: 401},
    }));

    assert.equal(outcome.status, 'passed');
});

test('the settings probe permits redirects and a WooCommerce-inactive baseline', () => {
    const wooInactiveOutcome = classifyHookResilienceEvidence(evidence({
        'normal:woocommerce-settings': {status: 404},
    }, {
        'normal:woocommerce-settings': {status: 200},
    }));
    const redirectedOutcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:woocommerce-settings': {status: 302},
    }));

    assert.equal(wooInactiveOutcome.status, 'passed');
    assert.equal(redirectedOutcome.status, 'passed');
});

test('the mail probe requires an accepted, safely intercepted result', () => {
    const expected = {
        completed: true,
        mail_accepted: true,
        delivery_safely_intercepted: true,
    };

    assert.equal(validateProbeResponse(expected, expected), null);

    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:wp-mail-pipeline': {
            response_payload: {
                ...expected,
                mail_accepted: false,
            },
        },
    }));

    assert.equal(outcome.status, 'regression');
    assert.deepEqual(outcome.failures[0].reasons, ['unexpected_probe_response']);
    assert.match(outcome.failures[0].response_validation_error || '', /mail_accepted/);

    const unsafeOutcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:wp-mail-pipeline': {
            response_payload: {
                ...expected,
                delivery_safely_intercepted: false,
            },
        },
    }));

    assert.equal(unsafeOutcome.status, 'regression');
    assert.match(
        unsafeOutcome.failures[0].response_validation_error || '',
        /delivery_safely_intercepted/
    );
});

test('a legal pre_wp_mail short-circuit that blocks the mail still passes', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'normal:wp-mail-pipeline': {
            response_payload: {
                completed: true,
                mail_result: false,
                transport_intercepted: false,
                mail_preempted: true,
                mail_accepted: true,
                delivery_safely_intercepted: true,
            },
        },
    }));

    assert.equal(outcome.status, 'passed');
});

test('the outbound HTTP probe requires the exact injected WP_Error', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'pre_http_request:wp_error:outbound-http': {
            response_payload: {
                completed: true,
                result_is_wp_error: true,
                result_error_code: 'another_error',
            },
        },
    }));

    assert.equal(outcome.status, 'regression');
    assert.deepEqual(outcome.failures[0].reasons, ['unexpected_probe_response']);
    assert.match(outcome.failures[0].response_validation_error || '', /result_error_code/);
});

test('a contract probe must return its documented status', () => {
    const outcome = classifyHookResilienceEvidence(evidence({}, {
        'rest_authentication_errors:wp_error:rest-index-pretty': {
            status: 200,
        },
    }));

    assert.equal(outcome.status, 'regression');
    assert.deepEqual(outcome.failures[0].reasons, ['unexpected_http_status']);
    assert.deepEqual(outcome.failures[0].expected_statuses, [401]);
});

test('SUT attribution recognizes the error file and trace', () => {
    assert.equal(eventIsAttributedToSut({
        error_file: '/var/www/html/wp-content/plugins/google-listings-and-ads/src/ImageProxy.php',
    }, 'google-listings-and-ads'), true);

    assert.equal(eventIsAttributedToSut({
        error_file: '/var/www/html/wp-includes/class-wp-hook.php',
        error_trace: '#0 /var/www/html/wp-content/plugins/google-listings-and-ads/src/ImageProxy.php(316)',
    }, 'google-listings-and-ads'), true);

    assert.equal(eventIsAttributedToSut({
        error_file: '/var/www/html/wp-includes/class-wp-hook.php',
        error_trace: '',
    }, 'google-listings-and-ads'), false);
});

test('SUT attribution recognizes a single-file plugin entrypoint', () => {
    assert.equal(getSutInstallPath('hello.php'), 'hello.php');
    assert.equal(eventIsAttributedToSut({
        error_file: '/var/www/html/wp-content/plugins/hello.php',
    }, 'hello.php'), true);

    assert.equal(eventIsAttributedToSut({
        error_file: '/var/www/html/wp-content/plugins/hello-dolly/hello.php',
    }, 'hello.php'), false);
});

test('SUT matching tolerates entrypoint metadata differences', () => {
    assert.equal(pluginMatchesSut({
        slug: 'google-listings-and-ads',
        plugin_entrypoint: 'google-listings-and-ads/google-listings-and-ads.php',
    }, {
        slug: 'google-listings-and-ads',
        entrypoint: '',
    }), true);

    assert.equal(pluginMatchesSut({
        slug: 'hello-dolly',
        plugin_entrypoint: 'hello.php',
    }, {
        slug: '',
        entrypoint: 'hello.php',
    }), true);
});
