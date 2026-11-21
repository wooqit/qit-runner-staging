import crypto from 'crypto';

import {getSutInstallPath} from './hook-resilience.js';

export const REQUEST_FOOTPRINT_ENV_KEY = 'activationRequestFootprintEvidence';
export const REQUEST_FOOTPRINT_SCHEMA_VERSION = '1.2.0';
export const REQUEST_FOOTPRINT_ANNOTATION = 'qit-request-footprint';
export const REQUEST_FOOTPRINT_TEST_TITLE = 'Record request footprint';
export const REQUEST_FOOTPRINT_TEST_TAGS = ['@basic'];
export const REQUEST_FOOTPRINT_REPEATS = 3;

// Requests on which an extension should do almost nothing. admin-ajax is the one that
// catches `admin_init` work missing a `wp_doing_ajax()` gate.
export const REQUEST_FOOTPRINT_PROBES = [
    {id: 'admin-ajax', path: '/wp-admin/admin-ajax.php?action=rest-nonce'},
    {id: 'wp-admin-profile', path: '/wp-admin/profile.php'},
    {id: 'front-page', path: '/'},
];

const METRICS = ['queries', 'writes', 'http'];
const MAX_REPORTED_CALL_SITES = 10;
const MAX_REPORTED_ERRORS = 5;
const REQUEST_TIMEOUT_MS = 10_000;
const SMOKE_HEADER = 'X-QIT-Activation-Smoke';
const REQUEST_ID_HEADER = 'X-QIT-Activation-Smoke-Request-Id';
const TOKEN_HEADER = 'X-QIT-Activation-Smoke-Token';
const FOOTPRINT_HEADER = 'X-QIT-Request-Footprint';

export const REQUEST_FOOTPRINT_SELECTED_ENV = 'QIT_REQUEST_FOOTPRINT_SELECTED';

function cliPattern(value) {
    // Playwright's own conversion of a --grep value.
    const literal = /^\/(.*)\/([gimyus]*)$/.exec(value);
    return literal ? new RegExp(literal[1], literal[2]) : new RegExp(value, 'gi');
}

function cliOption(argv, names) {
    let value = null;
    argv.forEach((argument, index) => {
        for (const name of names) {
            if (argument === name && index + 1 < argv.length) {
                value = argv[index + 1];
            } else if (argument.startsWith(`${name}=`)) {
                value = argument.slice(name.length + 1);
            }
        }
    });
    return value;
}

/**
 * Whether the Playwright command line selects the report test. The measurement runs inside
 * `Activate Plugins`, which release-smoke and host-plan runs select too, and they must not
 * pay for a report they drop. Workers can't see the command line, so playwright.config.js
 * decides this once and hands it down in REQUEST_FOOTPRINT_SELECTED_ENV.
 */
export function requestFootprintIsSelected(argv) {
    // What Playwright matches --grep against: project, file, title and tags.
    const title = `chromium activation.spec.js ${REQUEST_FOOTPRINT_TEST_TITLE} ${REQUEST_FOOTPRINT_TEST_TAGS.join(' ')}`;
    const grep = cliOption(argv, ['--grep', '-g']);
    const grepInvert = cliOption(argv, ['--grep-invert', '-gv']);

    return (grep === null || cliPattern(grep).test(title)) &&
        (grepInvert === null || !cliPattern(grepInvert).test(title));
}

// The session helpers in hook-resilience.js are private, and that file must stay
// byte-identical to the legacy suite's copy, so the few lines needed here are repeated.
async function getInstrumentationSession(page) {
    const nonceResponse = await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce', {
        failOnStatusCode: false,
        timeout: REQUEST_TIMEOUT_MS,
    });
    const nonce = (await nonceResponse.text()).trim();
    if (nonceResponse.status() >= 400 || !nonce || nonce === '-1' || nonce === '0') {
        throw new Error(`REST nonce endpoint returned HTTP ${nonceResponse.status()} without a nonce`);
    }

    const sessionResponse = await page.request.get('/wp-json/qit-activation-smoke/v1/session', {
        failOnStatusCode: false,
        headers: {'X-WP-Nonce': nonce},
        timeout: REQUEST_TIMEOUT_MS,
    });
    const body = sessionResponse.status() < 400 ? await sessionResponse.json() : null;
    if (!body || typeof body.token !== 'string' || !body.token) {
        throw new Error(`Instrumentation session endpoint returned HTTP ${sessionResponse.status()}`);
    }

    return {nonce, token: body.token};
}

function isFootprint(value) {
    return Boolean(value) &&
        Number.isInteger(value.queries) &&
        typeof value.plugin_active === 'boolean' &&
        Boolean(value.sut) &&
        METRICS.every((metric) => Number.isInteger(value.sut[metric])) &&
        Array.isArray(value.sut.call_sites) &&
        Array.isArray(value.sut.hook_totals) &&
        value.sut.hook_totals.every(isHookTotal);
}

function isHookTotal(hook) {
    const counted = (entry) => METRICS.every((metric) => Number.isInteger(entry?.[metric]));
    return counted(hook) &&
        typeof hook.hook === 'string' &&
        Number.isInteger(hook.omitted_files) &&
        Array.isArray(hook.files) &&
        hook.files.every((file) => counted(file) && typeof file.file === 'string');
}

/**
 * Reads the recorder's event for one probe request and turns everything that makes the
 * numbers untrustworthy into an error: a fatal, a missing or malformed event, and a SUT
 * that isn't active, which would otherwise read as a clean zero.
 */
export async function readFootprintSample(page, requestId, nonce, phase, status) {
    const response = await page.request.get(
        `/wp-json/qit-activation-smoke/v1/events/${encodeURIComponent(requestId)}`,
        {
            failOnStatusCode: false,
            headers: {'X-WP-Nonce': nonce},
            timeout: REQUEST_TIMEOUT_MS,
        }
    );
    if (response.status() >= 400) {
        throw new Error(`Event endpoint returned HTTP ${response.status()}`);
    }

    const events = await response.json();
    const types = Array.isArray(events) ? events.map((event) => event?.type) : [];
    const fatal = Array.isArray(events) ? events.find((event) => event?.type === 'php_fatal') : null;
    if (fatal) {
        throw new Error(`The probe request hit a PHP fatal: ${fatal.error_message || fatal.error_type}`);
    }
    if (status >= 500) {
        throw new Error(`The probe request returned HTTP ${status}`);
    }

    const footprint = Array.isArray(events)
        ? events.find((event) => event?.type === 'request_footprint')?.footprint
        : null;
    if (!isFootprint(footprint)) {
        throw new Error(
            `The request recorded no usable footprint (events: ${JSON.stringify(types)}); ` +
            'check that bootstrap/request-footprint.php is installed as an mu-plugin'
        );
    }
    if (phase === 'post_activation' && !footprint.plugin_active) {
        throw new Error(`"${footprint.plugin}" is not an active plugin directory, so nothing can be attributed to it`);
    }

    return footprint;
}

/**
 * Sends every probe REQUEST_FOOTPRINT_REPEATS times. Never throws: a failure is recorded on
 * the sample, so collecting a footprint can't fail the activation test it runs inside.
 */
export async function runRequestFootprintPhase(page, phase, sutInstallPath) {
    let session = null;
    let sessionError = null;

    try {
        session = await getInstrumentationSession(page);
    } catch (error) {
        sessionError = error instanceof Error ? error.message : String(error);
    }

    const probes = [];
    for (const probe of REQUEST_FOOTPRINT_PROBES) {
        const samples = [];

        for (let repeat = 0; repeat < REQUEST_FOOTPRINT_REPEATS; repeat++) {
            if (!session) {
                samples.push({status: null, footprint: null, error: sessionError});
                continue;
            }

            const requestId = crypto.randomBytes(16).toString('hex');
            let status = null;

            try {
                const response = await page.request.get(probe.path, {
                    failOnStatusCode: false,
                    maxRedirects: 0,
                    headers: {
                        [SMOKE_HEADER]: '1',
                        [REQUEST_ID_HEADER]: requestId,
                        [TOKEN_HEADER]: session.token,
                        [FOOTPRINT_HEADER]: sutInstallPath,
                    },
                    timeout: REQUEST_TIMEOUT_MS,
                });
                status = response.status();
                const footprint = await readFootprintSample(page, requestId, session.nonce, phase, status);
                samples.push({status, footprint, error: null});
            } catch (error) {
                samples.push({
                    status,
                    footprint: null,
                    error: error instanceof Error ? error.message : String(error),
                });
            }
        }

        probes.push({...probe, phase, samples});
    }

    return probes;
}

function range(values) {
    return {min: Math.min(...values), max: Math.max(...values)};
}

function callSiteKey(site) {
    return `${site.hook}|${site.file}:${site.line}`;
}

/**
 * Entries seen on every sample, each at its smallest count, so one-off work such as an
 * expired transient being rebuilt on a single request doesn't show up as steady load.
 * A recorded entry always counts something, so a minimum of zero means a sample lacked it.
 */
function steadyEntries(samples, keyOf) {
    const [first, ...rest] = samples.map((entries) => new Map(entries.map((entry) => [keyOf(entry), entry])));
    const steady = [];

    for (const [key, entry] of first) {
        const matches = [entry, ...rest.map((entries) => entries.get(key))];
        const counts = Object.fromEntries(
            METRICS.map((metric) => [metric, Math.min(...matches.map((match) => match?.[metric] || 0))])
        );
        if (METRICS.some((metric) => counts[metric] > 0)) {
            steady.push({key, entry, counts});
        }
    }

    return steady.sort((a, b) =>
        b.counts.writes - a.counts.writes || b.counts.http - a.counts.http || b.counts.queries - a.counts.queries ||
        a.key.localeCompare(b.key)
    );
}

function steadyCallSites(sutFootprints) {
    return steadyEntries(sutFootprints.map((sut) => sut.call_sites), callSiteKey).map(({entry, counts}) => (
        {hook: entry.hook, file: entry.file, line: entry.line, ...counts, sample: entry.sample || ''}
    ));
}

// A hook whose work moves between files across repeats keeps its total, though no file is steady.
function steadyHookTotals(sutFootprints) {
    const hooksBySample = sutFootprints.map((sut) => new Map(sut.hook_totals.map((hook) => [hook.hook, hook])));

    return steadyEntries(sutFootprints.map((sut) => sut.hook_totals), (hook) => hook.hook).map(({key, counts}) => {
        const samples = hooksBySample.map((hooks) => hooks.get(key));
        return {
            hook: key,
            ...counts,
            files: steadyEntries(samples.map((hook) => hook?.files || []), (file) => file.file)
                .map((file) => ({file: file.key, ...file.counts})),
            omitted_files: Math.max(...samples.map((hook) => hook?.omitted_files || 0)),
        };
    });
}

export function summarizeRequestFootprintProbe(baselineProbe, postActivationProbe) {
    const baselineSamples = (baselineProbe?.samples || []).filter((sample) => sample.footprint);
    const postSamples = (postActivationProbe?.samples || []).filter((sample) => sample.footprint);
    const errors = [...(baselineProbe?.samples || []), ...(postActivationProbe?.samples || [])]
        .map((sample) => sample.error)
        .filter(Boolean);
    const postComplete = postSamples.length === REQUEST_FOOTPRINT_REPEATS;

    const summary = {
        id: postActivationProbe?.id || baselineProbe?.id || '',
        path: postActivationProbe?.path || baselineProbe?.path || '',
        complete: postComplete && baselineSamples.length === REQUEST_FOOTPRINT_REPEATS,
        errors: Array.from(new Set(errors)),
    };

    if (postSamples.length === 0) {
        return summary;
    }

    const sutFootprints = postSamples.map((sample) => sample.footprint.sut);
    const sut = {};
    for (const metric of METRICS) {
        sut[metric] = range(sutFootprints.map((footprint) => footprint[metric]));
    }
    // "Seen on every repeat" only means something when every repeat was measured.
    const callSites = postComplete ? steadyCallSites(sutFootprints) : [];

    summary.sut = {
        ...sut,
        varies: METRICS.some((metric) => sut[metric].min !== sut[metric].max),
        call_sites: callSites.slice(0, MAX_REPORTED_CALL_SITES),
        omitted_call_sites: Math.max(0, callSites.length - MAX_REPORTED_CALL_SITES) +
            Math.max(...postSamples.map((sample) => sample.footprint.dropped_call_sites || 0)),
        // Null, not empty: a probe with a repeat missing can't say which work is steady.
        hook_totals: postComplete ? steadyHookTotals(sutFootprints) : null,
    };

    const postTotal = Math.min(...postSamples.map((sample) => sample.footprint.queries));
    summary.total_queries = postTotal;
    summary.total_query_delta = null;
    if (baselineSamples.length > 0) {
        const baselineTotal = Math.min(...baselineSamples.map((sample) => sample.footprint.queries));
        summary.baseline_total_queries = baselineTotal;
        summary.total_query_delta = postTotal - baselineTotal;
    }

    return summary;
}

export function buildRequestFootprintEvidence(sut, baseline, postActivation) {
    const sutInstallPath = getSutInstallPath(sut.entrypoint);
    if (!sutInstallPath) {
        return buildUnsupportedRequestFootprintEvidence(sut, 'The plugin SUT has no entrypoint to attribute work to.');
    }

    const baselineById = new Map((baseline || []).map((probe) => [probe.id, probe]));
    const probes = (postActivation || []).map((probe) =>
        summarizeRequestFootprintProbe(baselineById.get(probe.id), probe)
    );

    return {
        schema_version: REQUEST_FOOTPRINT_SCHEMA_VERSION,
        sut: {slug: sut.slug || '', entrypoint: sut.entrypoint || '', install_path: sutInstallPath},
        supported: true,
        repeats: REQUEST_FOOTPRINT_REPEATS,
        complete: probes.length === REQUEST_FOOTPRINT_PROBES.length && probes.every((probe) => probe.complete),
        probes,
    };
}

export function buildUnsupportedRequestFootprintEvidence(sut, reason) {
    return {
        schema_version: REQUEST_FOOTPRINT_SCHEMA_VERSION,
        sut: {slug: sut.slug || '', entrypoint: sut.entrypoint || '', install_path: getSutInstallPath(sut.entrypoint)},
        supported: false,
        skip_reason: reason,
        repeats: REQUEST_FOOTPRINT_REPEATS,
        complete: false,
        probes: [],
    };
}

/**
 * The form the Manager reads from the CTRF result, for the report and the metric.
 * Attachments don't survive the job, so everything a consumer needs has to be in here:
 * steady-state (minimum) counts and their maximums, the call sites and hook totals behind
 * them, and why a measurement is missing or partial. A request with no measured sample has
 * no probe entry, so its errors carry its path.
 */
export function buildRequestFootprintAnnotation(evidence) {
    const unsupported = evidence?.supported === false;
    const probes = {};

    for (const probe of evidence?.probes || []) {
        if (!probe.sut) {
            continue;
        }

        probes[probe.id] = {
            path: probe.path,
            complete: probe.complete,
            queries: probe.sut.queries.min,
            writes: probe.sut.writes.min,
            http: probe.sut.http.min,
            queries_max: probe.sut.queries.max,
            writes_max: probe.sut.writes.max,
            http_max: probe.sut.http.max,
            varies: probe.sut.varies,
            total_query_delta: probe.total_query_delta,
            hooks: (probe.sut.hook_totals || []).map((hook) => hook.hook).sort(),
            call_sites: probe.sut.call_sites,
            omitted_call_sites: probe.sut.omitted_call_sites,
            hook_totals: probe.sut.hook_totals,
        };
    }

    // Each request's first error goes ahead of any second one, so the cap below can't hide
    // an unmeasured request behind another request's errors.
    const errorsByProbe = (evidence?.probes || []).map((probe) =>
        Array.from(new Set(probe.errors)).map((error) => `${probe.path}: ${error}`)
    );
    const errors = unsupported
        ? [evidence.skip_reason || 'The request footprint could not be measured.']
        : [...errorsByProbe.map((list) => list[0]).filter(Boolean), ...errorsByProbe.flatMap((list) => list.slice(1))];

    return {
        type: REQUEST_FOOTPRINT_ANNOTATION,
        description: JSON.stringify({
            schema_version: REQUEST_FOOTPRINT_SCHEMA_VERSION,
            state: unsupported ? 'unsupported' : (evidence?.complete ? 'complete' : 'incomplete'),
            probes,
            errors: errors.slice(0, MAX_REPORTED_ERRORS).map((error) => String(error).slice(0, 200)),
        }),
    };
}

// The numbers live in the test report; the log only says where to find them.
export function describeRequestFootprintForLog(evidence) {
    if (evidence?.supported === false) {
        return `QIT request footprint: not measured. ${evidence.skip_reason || ''}`.trim();
    }

    return (evidence?.probes || []).some((probe) => probe.sut)
        ? 'QIT request footprint: recorded (report-only). See "Request footprint" in the test report.'
        : 'QIT request footprint: not measured. See "Request footprint" in the test report.';
}

export async function attachRequestFootprintEvidence(testInfo, evidence) {
    await testInfo.attach('request-footprint.json', {
        body: Buffer.from(JSON.stringify(evidence, null, 2)),
        contentType: 'application/json',
    });
}
