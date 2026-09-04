import crypto from 'crypto';

export const HOOK_RESILIENCE_ENV_KEY = 'activationHookResilienceEvidence';
export const HOOK_RESILIENCE_SCHEMA_VERSION = '1.0.0';

const REQUEST_TIMEOUT_MS = 10_000;
const SMOKE_HEADER = 'X-QIT-Activation-Smoke';
const REQUEST_ID_HEADER = 'X-QIT-Activation-Smoke-Request-Id';
const TOKEN_HEADER = 'X-QIT-Activation-Smoke-Token';
const CONTRACT_HEADER = 'X-QIT-Hook-Contract';
const NULL_REST_CONTRACT = 'rest_pre_serve_request:null';
const PRE_HTTP_REQUEST_ERROR_CONTRACT = 'pre_http_request:wp_error';
const REST_AUTHENTICATION_ERROR_CONTRACT = 'rest_authentication_errors:wp_error';

export const HOOK_RESILIENCE_PROBES = [
    {
        id: 'front-page',
        scenario: 'normal',
        path: '/',
    },
    {
        id: 'wp-admin',
        scenario: 'normal',
        path: '/wp-admin/',
    },
    {
        id: 'rest-index-pretty',
        scenario: 'normal',
        path: '/wp-json/',
    },
    {
        id: 'rest-index-query',
        scenario: 'normal',
        path: '/?rest_route=/',
    },
    {
        id: 'product-category-taxonomy',
        scenario: 'normal',
        path: '/wp-json/wp/v2/taxonomies/product_cat?context=edit&_locale=user',
    },
    {
        id: 'woocommerce-products',
        scenario: 'normal',
        path: '/wp-json/wc/v3/products?per_page=1&context=edit',
    },
    {
        id: 'woocommerce-settings',
        scenario: 'normal',
        path: '/wp-admin/admin.php?page=wc-settings',
    },
    {
        id: 'wp-mail-pipeline',
        scenario: 'normal',
        path: '/wp-json/qit-activation-smoke/v1/probes/wp-mail',
        expected_statuses: [200],
        expected_json: {
            completed: true,
            mail_accepted: true,
            delivery_safely_intercepted: true,
        },
    },
    {
        id: 'outbound-http',
        scenario: PRE_HTTP_REQUEST_ERROR_CONTRACT,
        path: '/wp-json/qit-activation-smoke/v1/probes/pre-http-request',
        expected_statuses: [200],
        expected_json: {
            completed: true,
            result_is_wp_error: true,
            result_error_code: 'qit_activation_smoke_http_error',
        },
    },
    {
        id: 'rest-index-pretty',
        scenario: REST_AUTHENTICATION_ERROR_CONTRACT,
        path: '/wp-json/',
        expected_statuses: [401],
    },
    {
        id: 'rest-index-pretty',
        scenario: NULL_REST_CONTRACT,
        path: '/wp-json/',
    },
    {
        id: 'product-category-taxonomy',
        scenario: NULL_REST_CONTRACT,
        path: '/wp-json/wp/v2/taxonomies/product_cat?context=edit&_locale=user',
    },
];

function createRequestId() {
    return crypto.randomBytes(16).toString('hex');
}

function probeKey(probe) {
    return `${probe.scenario}:${probe.id}`;
}

function isFatalEvent(event) {
    return event && event.type === 'php_fatal';
}

function isRest5xxEvent(event) {
    return event && event.type === 'rest_5xx';
}

function eventFingerprint(event) {
    return [
        event.type || '',
        event.error_type || '',
        event.error_message || '',
        event.error_file || '',
        event.error_line || '',
        event.response_status || '',
        event.route || '',
    ].join('|');
}

export function deduplicateEvents(events) {
    const unique = new Map();

    for (const event of Array.isArray(events) ? events : []) {
        const fingerprint = eventFingerprint(event);
        if (!unique.has(fingerprint)) {
            unique.set(fingerprint, event);
        }
    }

    return Array.from(unique.values());
}

export function validateProbeResponse(payload, expected) {
    if (!expected) {
        return null;
    }

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return 'Probe response was not a JSON object.';
    }

    for (const [field, expectedValue] of Object.entries(expected)) {
        if (payload[field] !== expectedValue) {
            return `Probe response field "${field}" was ` +
                `${JSON.stringify(payload[field])}; expected ${JSON.stringify(expectedValue)}.`;
        }
    }

    return null;
}

export function getSutDirectory(sutEntrypoint) {
    const installPath = getSutInstallPath(sutEntrypoint);
    return installPath.endsWith('.php') ? '' : installPath;
}

export function getSutInstallPath(sutEntrypoint) {
    const normalized = String(sutEntrypoint || '')
        .replaceAll('\\', '/')
        .replace(/^\/+/, '');

    return normalized.includes('/') ? normalized.split('/')[0] : normalized;
}

export function pluginMatchesSut(plugin, sut) {
    const pluginEntrypoint = String(plugin?.plugin_entrypoint || '')
        .replaceAll('\\', '/')
        .replace(/^\/+/, '')
        .toLowerCase();
    const sutEntrypoint = String(sut?.entrypoint || '')
        .replaceAll('\\', '/')
        .replace(/^\/+/, '')
        .toLowerCase();
    const pluginSlug = String(plugin?.slug || '').toLowerCase();
    const sutSlug = String(sut?.slug || '').toLowerCase();

    if (pluginEntrypoint && sutEntrypoint && pluginEntrypoint === sutEntrypoint) {
        return true;
    }

    if (
        pluginEntrypoint &&
        sutEntrypoint &&
        getSutInstallPath(pluginEntrypoint).toLowerCase() ===
            getSutInstallPath(sutEntrypoint).toLowerCase()
    ) {
        return true;
    }

    return Boolean(pluginSlug && sutSlug && pluginSlug === sutSlug);
}

export function eventIsAttributedToSut(event, sutInstallPath) {
    if (!sutInstallPath) {
        return false;
    }

    const normalizedInstallPath = String(sutInstallPath).replaceAll('\\', '/').replace(/^\/+/, '');
    const pluginPath = normalizedInstallPath.endsWith('.php')
        ? `/wp-content/plugins/${normalizedInstallPath}`
        : `/wp-content/plugins/${normalizedInstallPath}/`;
    const evidence = [
        event?.error_file || '',
        event?.error_trace || '',
        event?.error_message || '',
    ].join('\n').replaceAll('\\', '/');

    return evidence.includes(pluginPath);
}

function probeFailureReasons(probe) {
    const reasons = [];

    if (probe.transport_error) {
        reasons.push('transport_error');
    }

    if (probe.instrumentation_error || probe.event_fetch_error) {
        reasons.push('instrumentation_error');
    }

    if (typeof probe.status === 'number' && probe.status >= 500) {
        reasons.push(`http_${probe.status}`);
    }

    if ((probe.events || []).some(isFatalEvent)) {
        reasons.push('php_fatal');
    }

    if ((probe.events || []).some(isRest5xxEvent)) {
        reasons.push('rest_5xx');
    }

    if (
        reasons.length === 0 &&
        Array.isArray(probe.expected_statuses) &&
        !probe.expected_statuses.includes(probe.status)
    ) {
        reasons.push('unexpected_http_status');
    }

    if (reasons.length === 0 && validateProbeResponse(probe.response_payload, probe.expected_json)) {
        reasons.push('unexpected_probe_response');
    }

    return Array.from(new Set(reasons));
}

function failureFromProbe(probe, reasons) {
    return {
        id: probe.id,
        phase: probe.phase,
        scenario: probe.scenario,
        path: probe.path,
        url: probe.url || probe.path,
        status: probe.status,
        reasons,
        attributed_to_sut: Boolean(probe.attributed_to_sut),
        expected_statuses: Array.isArray(probe.expected_statuses) ? probe.expected_statuses : [],
        expected_json: probe.expected_json || null,
        response_payload: probe.response_payload ?? null,
        response_validation_error: probe.response_validation_error ||
            validateProbeResponse(probe.response_payload, probe.expected_json),
        events: Array.isArray(probe.events) ? probe.events : [],
    };
}

export function classifyHookResilienceEvidence(evidence) {
    if (evidence?.supported === false) {
        return {
            status: 'unsupported',
            code: 'QIT_ACTIVATION_SMOKE_UNSUPPORTED',
            inconclusive: true,
            attributed_to_sut: false,
            failures: [],
        };
    }

    const probes = Array.isArray(evidence?.probes) ? evidence.probes : [];
    const baseline = new Map(
        probes
            .filter((probe) => probe.phase === 'baseline')
            .map((probe) => [probeKey(probe), probe])
    );
    const postActivation = new Map(
        probes
            .filter((probe) => probe.phase === 'post_activation')
            .map((probe) => [probeKey(probe), probe])
    );
    const baselineFailures = [];
    const regressions = [];
    const instrumentationFailures = probes
        .filter((probe) => probe.instrumentation_error || probe.event_fetch_error)
        .map((probe) => failureFromProbe(
            {
                ...probe,
                attributed_to_sut: false,
                events: (probe.events || []).map((event) => ({
                    ...event,
                    attributed_to_sut: false,
                })),
            },
            ['instrumentation_error']
        ));

    if (instrumentationFailures.length > 0) {
        return {
            status: 'baseline_invalid',
            code: 'QIT_ACTIVATION_SMOKE_BASELINE_INVALID',
            inconclusive: true,
            attributed_to_sut: false,
            failures: instrumentationFailures,
        };
    }

    for (const definition of HOOK_RESILIENCE_PROBES) {
        const key = probeKey(definition);
        const baselineProbe = baseline.get(key);
        const postActivationProbe = postActivation.get(key);

        if (!baselineProbe) {
            baselineFailures.push(failureFromProbe({
                ...definition,
                phase: 'baseline',
                status: null,
                attributed_to_sut: false,
            }, ['missing_probe']));
            continue;
        }

        const baselineReasons = probeFailureReasons(baselineProbe);
        if (baselineReasons.length > 0) {
            baselineFailures.push(failureFromProbe({
                ...baselineProbe,
                attributed_to_sut: false,
                events: (baselineProbe.events || []).map((event) => ({
                    ...event,
                    attributed_to_sut: false,
                })),
            }, baselineReasons));
            continue;
        }

        if (!postActivationProbe) {
            regressions.push(failureFromProbe({
                ...definition,
                phase: 'post_activation',
                status: null,
                attributed_to_sut: false,
            }, ['missing_probe']));
            continue;
        }

        const postActivationReasons = probeFailureReasons(postActivationProbe);
        if (postActivationReasons.length > 0) {
            regressions.push(failureFromProbe(postActivationProbe, postActivationReasons));
        }
    }

    if (baselineFailures.length > 0) {
        return {
            status: 'baseline_invalid',
            code: 'QIT_ACTIVATION_SMOKE_BASELINE_INVALID',
            inconclusive: true,
            attributed_to_sut: false,
            failures: baselineFailures,
        };
    }

    if (regressions.length > 0) {
        return {
            status: 'regression',
            code: 'QIT_ACTIVATION_SMOKE_REGRESSION',
            inconclusive: false,
            attributed_to_sut: regressions.some((failure) => failure.attributed_to_sut),
            failures: regressions,
        };
    }

    return {
        status: 'passed',
        code: 'QIT_ACTIVATION_SMOKE_PASSED',
        inconclusive: false,
        attributed_to_sut: false,
        failures: [],
    };
}

async function getRestNonce(page) {
    const response = await page.request.get(
        '/wp-admin/admin-ajax.php?action=rest-nonce',
        {
            failOnStatusCode: false,
            timeout: REQUEST_TIMEOUT_MS,
        }
    );

    if (response.status() >= 400) {
        throw new Error(`REST nonce endpoint returned HTTP ${response.status()}`);
    }

    const nonce = (await response.text()).trim();
    if (!nonce || nonce === '-1' || nonce === '0') {
        throw new Error('REST nonce endpoint did not return an authenticated nonce');
    }

    return nonce;
}

async function getInstrumentationSession(page) {
    const nonce = await getRestNonce(page);
    const response = await page.request.get(
        '/wp-json/qit-activation-smoke/v1/session',
        {
            failOnStatusCode: false,
            headers: {'X-WP-Nonce': nonce},
            timeout: REQUEST_TIMEOUT_MS,
        }
    );

    if (response.status() >= 400) {
        throw new Error(`Instrumentation session endpoint returned HTTP ${response.status()}`);
    }

    const body = await response.json();
    if (!body || typeof body.token !== 'string' || !body.token) {
        throw new Error('Instrumentation session endpoint returned an invalid payload');
    }

    return {
        nonce,
        token: body.token,
    };
}

async function fetchEvents(page, requestId, nonce) {
    try {
        const response = await page.request.get(
            `/wp-json/qit-activation-smoke/v1/events/${encodeURIComponent(requestId)}`,
            {
                failOnStatusCode: false,
                headers: nonce ? {'X-WP-Nonce': nonce} : {},
                timeout: REQUEST_TIMEOUT_MS,
            }
        );

        if (response.status() >= 400) {
            return {
                events: [],
                error: `Event endpoint returned HTTP ${response.status()}`,
            };
        }

        const body = await response.json();
        if (!Array.isArray(body)) {
            return {
                events: [],
                error: 'Event endpoint returned an invalid payload',
            };
        }

        return {
            events: deduplicateEvents(body),
            error: null,
        };
    } catch (error) {
        return {
            events: [],
            error: error instanceof Error ? error.message : String(error),
        };
    }
}

export async function runHookResilienceProbePhase(page, phase, sutEntrypoint) {
    const sutInstallPath = getSutInstallPath(sutEntrypoint);
    let nonce = '';
    let token = '';
    let instrumentationError = null;

    try {
        const session = await getInstrumentationSession(page);
        nonce = session.nonce;
        token = session.token;
    } catch (error) {
        instrumentationError = error instanceof Error ? error.message : String(error);
    }

    const results = [];

    for (const definition of HOOK_RESILIENCE_PROBES) {
        const requestId = createRequestId();
        const startedAt = Date.now();
        const url = new URL(definition.path, page.url()).toString();
        const headers = {
            [SMOKE_HEADER]: '1',
            [REQUEST_ID_HEADER]: requestId,
        };

        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }

        if (token) {
            headers[TOKEN_HEADER] = token;
        }

        if (definition.scenario !== 'normal') {
            headers[CONTRACT_HEADER] = definition.scenario;
        }

        let status = null;
        let transportError = null;
        let responsePayload = null;
        let responseValidationError = null;

        try {
            const response = await page.request.get(definition.path, {
                failOnStatusCode: false,
                headers,
                timeout: REQUEST_TIMEOUT_MS,
            });
            status = response.status();

            if (definition.expected_json) {
                try {
                    responsePayload = await response.json();
                    responseValidationError = validateProbeResponse(
                        responsePayload,
                        definition.expected_json
                    );
                } catch (error) {
                    responseValidationError = error instanceof Error
                        ? error.message
                        : String(error);
                }
            }
        } catch (error) {
            transportError = error instanceof Error ? error.message : String(error);
        }

        const eventResult = nonce && token
            ? await fetchEvents(page, requestId, nonce)
            : {events: [], error: null};
        const events = eventResult.events.map((event) => ({
            ...event,
            attributed_to_sut: phase === 'post_activation' &&
                eventIsAttributedToSut(event, sutInstallPath),
        }));
        const attributedToSut = events.some((event) => event.attributed_to_sut);

        const result = {
            ...definition,
            url,
            phase,
            request_id: requestId,
            status,
            duration_ms: Date.now() - startedAt,
            transport_error: transportError,
            instrumentation_error: instrumentationError,
            event_fetch_error: eventResult.error,
            events,
            attributed_to_sut: attributedToSut,
            response_payload: responsePayload,
            response_validation_error: responseValidationError,
        };

        console.log(
            `[QIT activation smoke] ${phase} ${definition.scenario} ${definition.path} ` +
            `status=${status ?? 'none'} events=${events.length} duration=${result.duration_ms}ms`
        );
        results.push(result);
    }

    return results;
}

export function buildHookResilienceEvidence(sut, baseline, postActivation) {
    const evidence = {
        schema_version: HOOK_RESILIENCE_SCHEMA_VERSION,
        sut: {
            slug: sut.slug || '',
            type: sut.type || 'plugin',
            entrypoint: sut.entrypoint || '',
            directory: getSutDirectory(sut.entrypoint),
            install_path: getSutInstallPath(sut.entrypoint),
        },
        supported: true,
        probes: [
            ...(Array.isArray(baseline) ? baseline : []),
            ...(Array.isArray(postActivation) ? postActivation : []),
        ],
    };

    evidence.outcome = classifyHookResilienceEvidence(evidence);
    return evidence;
}

export function buildUnsupportedHookResilienceEvidence(sut, reason) {
    const evidence = {
        schema_version: HOOK_RESILIENCE_SCHEMA_VERSION,
        sut: {
            slug: sut.slug || '',
            type: sut.type || 'plugin',
            entrypoint: sut.entrypoint || '',
            directory: getSutDirectory(sut.entrypoint),
            install_path: getSutInstallPath(sut.entrypoint),
        },
        supported: false,
        skip_reason: reason || 'The SUT did not pass through the activation probe boundary.',
        probes: [],
    };

    evidence.outcome = classifyHookResilienceEvidence(evidence);
    return evidence;
}

export function formatHookResilienceOutcome(outcome) {
    if (outcome?.status === 'unsupported') {
        return 'QIT_ACTIVATION_SMOKE_UNSUPPORTED: the SUT did not pass through the activation probe boundary.';
    }

    if (!outcome || outcome.status === 'passed') {
        return 'QIT_ACTIVATION_SMOKE_PASSED: all baseline and post-activation probes were healthy.';
    }

    const details = outcome.failures.map((failure) => {
        const status = failure.status === null ? 'none' : failure.status;
        const attribution = failure.attributed_to_sut ? ' sut-attributed' : '';
        const eventSummary = failure.events
            .map((event) => {
                const location = event.error_file
                    ? ` at ${event.error_file}${event.error_line ? `:${event.error_line}` : ''}`
                    : '';
                return `${event.error_type || event.type}${location}: ` +
                    `${event.error_message || ''}`;
            })
            .join(' | ')
            .slice(0, 500);
        return `- ${failure.phase} ${failure.scenario} ${failure.path} ` +
            `status=${status} reasons=${failure.reasons.join(',')}${attribution}` +
            (eventSummary ? ` events=${eventSummary}` : '');
    });

    return `${outcome.code}: ${outcome.status}\n${details.join('\n')}`;
}

export function formatHookResilienceProbeSummary(evidence) {
    return (evidence?.probes || []).map((probe) => {
        const status = probe.status === null ? 'none' : probe.status;
        const attribution = probe.attributed_to_sut ? 'sut-attributed' : 'not-attributed';
        const transport = probe.transport_error ? ` transport=${probe.transport_error}` : '';
        const instrumentation = probe.instrumentation_error
            ? ` instrumentation=${probe.instrumentation_error}`
            : '';
        const responseValidation = probe.response_validation_error
            ? ` response_validation=${probe.response_validation_error}`
            : '';

        return `${probe.phase} ${probe.scenario} ${probe.url || probe.path} ` +
            `status=${status} duration=${probe.duration_ms ?? 0}ms ` +
            `events=${probe.events?.length || 0} ${attribution}` +
            `${transport}${instrumentation}${responseValidation}`;
    }).join('\n');
}

export async function attachHookResilienceEvidence(testInfo, evidence) {
    await testInfo.attach('global-surface-resilience.json', {
        body: Buffer.from(JSON.stringify(evidence, null, 2)),
        contentType: 'application/json',
    });
    await testInfo.attach('global-surface-resilience.txt', {
        body: Buffer.from(formatHookResilienceProbeSummary(evidence)),
        contentType: 'text/plain',
    });
}
