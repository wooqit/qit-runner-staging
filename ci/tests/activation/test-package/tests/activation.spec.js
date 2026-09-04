import {test, expect} from '@playwright/test';
import qit from '@qit/helpers';
import fs from 'fs';
import {
    HOOK_RESILIENCE_ENV_KEY,
    attachHookResilienceEvidence,
    buildHookResilienceEvidence,
    buildUnsupportedHookResilienceEvidence,
    classifyHookResilienceEvidence,
    formatHookResilienceOutcome,
    pluginMatchesSut,
    runHookResilienceProbePhase,
} from './hook-resilience.js';

test.describe.configure({mode: 'serial'});

const sut_slug = process.env.QIT_SUT_SLUG || '';
const sut_type = process.env.QIT_SUT_TYPE || 'plugin';
const sut_entrypoint = process.env.QIT_SUT_ENTRYPOINT || '';
const sut_qit_config = {};  // Currently not used, for future expansion
const plugin_activation_stack = process.env.QIT_PLUGIN_ACTIVATION_STACK ? JSON.parse(process.env.QIT_PLUGIN_ACTIVATION_STACK) : [];

// Pages to visit is empty by default.
qit.setEnv('addedMenuItems', '[]');
qit.setEnv(HOOK_RESILIENCE_ENV_KEY, '');

// Include console errors in the context.
const ignoredErrors = [
    'the server responded with a status of 403',
    'QM error from JS',
    'Soon, cookies without the "SameSite" attribute',
    'Migrate is installed',
];

/**
 * Hard-coded map of hidden or otherwise less obvious dependencies.
 * Key: plugin slug
 * Value: array of plugin names that must be activated first.
 */
const hiddenDependencies = {
    'woocommerce-accommodation-bookings': ['woocommerce-bookings'],
};

/** @type {array<ConsoleMessage>} */
var consoleLogs = [];

/** @type {array<Error>} */
var webErrors = [];

/** @type {array<Request>} */
var requestsFailed = [];

test.beforeEach(async ({page, context}) => {
    page.on('console', async (msg) => {
        // Check if the message is in the list of ignored errors
        if (ignoredErrors.some(error => msg.text().includes(error))) {
            return;
        }
        consoleLogs.push(msg);
    });

    /*
    "pagerror" is triggered when an uncaught JavaScript exception is thrown
    during the execution of the test. We use page.pageerror instead of
    context.weberror as the former is presented better in the report.

    @link https://playwright.dev/docs/api/class-page#page-event-page-error
    @link https://playwright.dev/docs/api/class-browsercontext#browser-context-event-request-failed
    @link https://playwright.dev/docs/api/class-consolemessage
     */
    page.on('pageerror', exception => {
        webErrors.push(exception);
        console.log(`Uncaught exception: "${exception.name} - ${exception.message} - ${exception.stack}"`);
    });

    // Listen for failed requests
    context.on('requestfailed', (request) => {
        requestsFailed.push(request);
        console.log(`Request failed: ${request.url()} - ${request.failure().errorText}`);
    });
});

// Take full-page screenshots on failures. Playwright only takes regular page screenshots on failures.
test.afterEach(async ( { page } ) => {
    var consoleLogs = [];
    var webErrors = [];
    var requestsFailed = [];

    if (test.info().status !== test.info().expectedStatus) {
        await test.info().attach('Full-page test failed screenshot', {
            body: await page.screenshot({
                type: 'jpeg',
                fullPage: true,
                timeout: 90000
            }),
            contentType: 'image/jpeg'
        });
    }
});

// QIT_HOOK_RESILIENCE_PARITY_START activation-test-tag
test('Activate Plugins', { tag: [ '@basic', '@host-plan', '@release-smoke' ] }, async ({page}, testInfo) => {
// QIT_HOOK_RESILIENCE_PARITY_END activation-test-tag
    // If SUT TYPE is "theme", skip plugin activation test.
    if (sut_type === 'theme') {
        return;
    }

    test.setTimeout( 1500000 ); // Increase timeout to 25 minutes.

    // Initialize timestamp tracking
    const startTime = Date.now();
    const getElapsedTime = () => `[${((Date.now() - startTime) / 1000).toFixed(2)}s]`;

    console.log(`${getElapsedTime()} Starting plugin activation test`);
    
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    let plugins = await extractPluginsData(page);

    console.log(`${getElapsedTime()} Extracted plugins data:`);
    plugins.forEach((plugin, index) => {
        console.log(`  ${index + 1}. "${plugin.name}"`);
        console.log(`     - Slug: ${plugin.slug}`);
        console.log(`     - Entry Point: ${plugin.plugin_entrypoint}`);
        console.log(`     - Active: ${plugin.isActive}`);
        console.log(`     - Can Activate: ${plugin.canActivate}`);
        console.log(`     - Dependencies: [${plugin.dependencies.join(', ')}]`);
        if (plugin.activationLink) {
            console.log(`     - Activation Link: ${plugin.activationLink}`);
        }
        console.log('');
    });

    plugins = sortPluginsByDependencies(plugins);
    // QIT_HOOK_RESILIENCE_PARITY_START initial-sut-match
    const initialSutPlugin = plugins.find((plugin) => pluginMatchesSut(plugin, {
        slug: sut_slug,
        entrypoint: sut_entrypoint,
    }));
    // QIT_HOOK_RESILIENCE_PARITY_END initial-sut-match

    console.log(`${getElapsedTime()} Found ${plugins.length} plugins to process`);

    const findPluginByNameOrSlug = (identifier) => plugins.find(p => p.name === identifier || p.slug === identifier);
    const isDependencyActive = (identifier) => {
        let dependency = findPluginByNameOrSlug(identifier);

        if (!dependency) {
            console.log(`${getElapsedTime()} Dependency not found: ${identifier}`);
            return false;
        }

        return dependency.isActive;
    }

    let menuBeforeActivatingSUT = [];  // Declare it outside to ensure broader scope
    // QIT_HOOK_RESILIENCE_PARITY_START activation-probe-state
    let hookResilienceBaseline = null;
    let hookResiliencePostActivation = null;
    // QIT_HOOK_RESILIENCE_PARITY_END activation-probe-state

    let activated = 0;
    let activations = 0;
    console.log(`${getElapsedTime()} Starting activation loop`);

    do {
        activations = 0;
        for (const plugin of plugins.filter(p => !p.isActive && p.canActivate)) {
            if (plugin.dependencies.every(dep => isDependencyActive(dep))) {
                // QIT_HOOK_RESILIENCE_PARITY_START sut-match
                let activating_sut = pluginMatchesSut(plugin, {
                    slug: sut_slug,
                    entrypoint: sut_entrypoint,
                });
                // QIT_HOOK_RESILIENCE_PARITY_END sut-match
                let initialMenuItems = [];

                // QIT_HOOK_RESILIENCE_PARITY_START baseline-probe
                if (activating_sut) {
                    menuBeforeActivatingSUT = await page.evaluate(() =>
                        Array.from(document.querySelectorAll('#adminmenu li a')).map(a => ({
                            url: a.href,
                            display: a.textContent.trim()
                        }))
                    );

                    hookResilienceBaseline = await test.step(
                        'Capture pre-activation global request baseline',
                        async () => runHookResilienceProbePhase(page, 'baseline', sut_entrypoint)
                    );
                }
                // QIT_HOOK_RESILIENCE_PARITY_END baseline-probe

                console.log(`${getElapsedTime()} Navigating to the activation link for "${plugin.name}".`);

                // Navigate to the activation link.
                await page.goto(plugin.activationLink);
                await page.waitForLoadState('networkidle');


                /**
                 * Navigate to the plugins page to circumvent redirects to setup wizards.
                 */
                await page.goto('/wp-admin/plugins.php');
                await page.waitForLoadState('networkidle');

                // Go back to plugins list, as the plugin activation might redirect to an onboarding wizard.
                await page.goto('/wp-admin/plugins.php');
                await page.waitForLoadState('networkidle');

                // Poll the DOM until the plugin is active
                await expect
                    .poll(async () => {
                        return page.evaluate((pluginName) => {
                            const pluginRow = Array
                                .from(document.querySelectorAll('#the-list > tr'))
                                .find(row => row.querySelector('.plugin-title strong')?.textContent === pluginName);

                            // Return true only if the row exists and is NOT "inactive".
                            return !!pluginRow && !pluginRow.classList.contains('inactive');
                        }, plugin.name);
                    }, {
                        timeout: 60_000, // Wait for 60 secs.
                        message: `The plugin "${plugin.name}" never appeared active in the UI.`,
                    })
                    .toBeTruthy();

                console.log(`${getElapsedTime()} Activated "${plugin.name}" successfully.`);
                
                activations++;
                activated++;

                // Return to plugins page to refresh state
                await page.goto('/wp-admin/plugins.php');
                await page.waitForLoadState('networkidle');

                if (activating_sut) {
                    // QIT_HOOK_RESILIENCE_PARITY_START post-probe-evidence
                    hookResiliencePostActivation = await test.step(
                        'Probe global requests after activating the SUT',
                        async () => runHookResilienceProbePhase(page, 'post_activation', sut_entrypoint)
                    );

                    const hookResilienceEvidence = buildHookResilienceEvidence(
                        {
                            slug: sut_slug,
                            type: sut_type,
                            entrypoint: sut_entrypoint,
                        },
                        hookResilienceBaseline,
                        hookResiliencePostActivation
                    );
                    qit.setEnv(HOOK_RESILIENCE_ENV_KEY, JSON.stringify(hookResilienceEvidence));
                    await attachHookResilienceEvidence(testInfo, hookResilienceEvidence);
                    console.log(formatHookResilienceOutcome(hookResilienceEvidence.outcome));
                    // QIT_HOOK_RESILIENCE_PARITY_END post-probe-evidence

                    const menuAfterActivatingSUT = await page.evaluate(() =>
                        Array.from(document.querySelectorAll('#adminmenu li a')).map(a => ({
                            url: a.href,
                            display: a.textContent.trim()
                        }))
                    );

                    // Create a set of initial URLs for quick lookup
                    const initialUrlsSet = new Set(menuBeforeActivatingSUT.map(item => item.url));

                    // Filter to find truly new items by checking URLs not present initially
                    const menuAddedBySUT = menuAfterActivatingSUT.filter(item =>
                        !initialUrlsSet.has(item.url) && item.url.includes('/wp-admin/')
                    );

                    qit.setEnv('addedMenuItems', JSON.stringify(menuAddedBySUT));
                }

                plugins = await extractPluginsData(page);
            } else {
                console.log(`${getElapsedTime()} Cannot activate ${plugin.name} yet. Dependencies not met.`);
            }
        }
    } while (activations > 0);

    // QIT_HOOK_RESILIENCE_PARITY_START unsupported-fallback
    if (sut_type === 'plugin' && !qit.getEnv(HOOK_RESILIENCE_ENV_KEY)) {
        let skipReason = 'The SUT did not pass through the activation probe boundary.';
        if (!initialSutPlugin) {
            skipReason = 'The plugin SUT could not be matched to an installed plugin.';
        } else if (initialSutPlugin.isActive) {
            skipReason = 'The plugin SUT was already active before the activation test.';
        } else if (!initialSutPlugin.canActivate) {
            skipReason = 'The plugin SUT did not expose an activation action.';
        }

        const hookResilienceEvidence = buildUnsupportedHookResilienceEvidence(
            {
                slug: sut_slug,
                type: sut_type,
                entrypoint: sut_entrypoint,
            },
            skipReason
        );
        qit.setEnv(HOOK_RESILIENCE_ENV_KEY, JSON.stringify(hookResilienceEvidence));
        await attachHookResilienceEvidence(testInfo, hookResilienceEvidence);
    }
    // QIT_HOOK_RESILIENCE_PARITY_END unsupported-fallback
    
    console.log(`${getElapsedTime()} Plugin activation test completed. Total activated: ${activated}`);
});

// QIT_HOOK_RESILIENCE_PARITY_START verifier
test('Verify global request resilience', { tag: [ '@basic', '@release-smoke' ] }, async ({}, testInfo) => {
    test.skip(sut_type !== 'plugin', 'The release-smoke hook resilience check currently supports plugin SUTs only.');

    const rawEvidence = qit.getEnv(HOOK_RESILIENCE_ENV_KEY);
    let evidence;

    try {
        evidence = JSON.parse(rawEvidence);
    } catch (error) {
        evidence = buildHookResilienceEvidence(
            {
                slug: sut_slug,
                type: sut_type,
                entrypoint: sut_entrypoint,
            },
            [],
            []
        );
        evidence.evidence_error = error instanceof Error ? error.message : String(error);
    }

    if (evidence.supported === false) {
        await attachHookResilienceEvidence(testInfo, evidence);
        testInfo.annotations.push({
            type: 'qit-outcome',
            description: 'unsupported',
        });
        test.skip(true, evidence.skip_reason || 'The SUT did not pass through the activation probe boundary.');
    }

    evidence.outcome = classifyHookResilienceEvidence(evidence);
    await attachHookResilienceEvidence(testInfo, evidence);

    const summary = formatHookResilienceOutcome(evidence.outcome);
    console.log(summary);

    if (evidence.outcome.status !== 'passed') {
        testInfo.annotations.push({
            type: 'qit-outcome',
            description: evidence.outcome.status === 'baseline_invalid' ? 'inconclusive' : 'regression',
        });
        throw new Error(summary);
    }
});
// QIT_HOOK_RESILIENCE_PARITY_END verifier

/**
 * Sort plugins by dependencies first, dependants second, and SUT last.
 * If some plugin depends on the SUT, we don't push it to last.
 */
function sortPluginsByDependencies(plugins) {
    // 1) Identify the SUT.
    // QIT_HOOK_RESILIENCE_PARITY_START sort-sut-match
    const sutPlugin = plugins.find((plugin) => pluginMatchesSut(plugin, {
        slug: sut_slug,
        entrypoint: sut_entrypoint,
    }));
    // QIT_HOOK_RESILIENCE_PARITY_END sort-sut-match
    const sutName = sutPlugin ? sutPlugin.name : '(No SUT found)';

    // 2) Check if any plugin depends on the SUT.
    let somePluginRequiresSUT = false;
    let requiringPlugins = [];
    if (sutPlugin) {
        requiringPlugins = plugins.filter((p) => ( p.dependencies.includes(sutPlugin.name) || p.dependencies.includes(sutPlugin.slug) ));
        somePluginRequiresSUT = requiringPlugins.length > 0;
    }

    if (somePluginRequiresSUT) {
        console.log(`[INFO] Some plugin(s) depend on the SUT "${sutName}".`);
        requiringPlugins.forEach((rp) => {
            console.log(`[INFO] Plugin "${rp.name}" requires "${sutName}".`);
        });
    }

    // 3) If no other plugin depends on it, remove it now so we can add it last.
    let removedSUT = false;
    let pluginsForSorting = plugins;
    if (sutPlugin && !somePluginRequiresSUT) {
        pluginsForSorting = plugins.filter((p) => p !== sutPlugin);
        removedSUT = true;
    }

    // 4) Sort plugins by dependencies first, dependants second.
    let sorted = [];
    let toBeSorted = pluginsForSorting.slice();
    let changes;

    do {
        changes = false;
        for (let i = 0; i < toBeSorted.length; i++) {
            const plugin = toBeSorted[i];

            // Determine which dependencies are hidden for this plugin
            const hiddenDeps = hiddenDependencies[plugin.slug] || [];

            // Check if all dependencies are satisfied
            const dependenciesSatisfied = plugin.dependencies.every(dep => {
                const matches = sp =>
                    [sp.name.toLowerCase(), sp.slug.toLowerCase()].includes(dep.toLowerCase());

                if (hiddenDeps.includes(dep)) {
                    return sorted.some(matches);
                }
                return sorted.some(matches);
            });

            console.log( `dependenciesSatisfied: ${dependenciesSatisfied} for ${plugin.name}` );

            if (dependenciesSatisfied || plugin.dependencies.length === 0) {
                sorted.push(plugin);
                toBeSorted.splice(i, 1);
                i--;
                changes = true;
            }
        }
    } while (changes && toBeSorted.length > 0);

    // 5) If there's anything left unsorted, it's a circular/unresolved dependency scenario.
    if (toBeSorted.length > 0) {
        console.log(`[ERROR] Unable to order these plugins (circular or unsatisfied dependencies):`);
        toBeSorted.forEach((p) => {
            console.log(` - "${p.name}", depends on: [${p.dependencies.join(', ')}]`);
        });

        console.log( `toBeSorted: ${JSON.stringify(toBeSorted)}` );
        console.log( `sorted: ${JSON.stringify(sorted)}` );
        throw new Error('Unable to resolve all plugin dependencies for sorting.');
    }

    // 6) If we removed the SUT earlier, put it at the end now.
    if (removedSUT) {
        sorted.push(sutPlugin);
    }

    console.log('[INFO] Final sorted plugin list:');
    sorted.forEach((plugin, index) => {
        console.log(
            ` ${index + 1}. "${plugin.name}" (Dependencies: [${plugin.dependencies.join(', ')}])`
        );
    });

    return sorted;
}

/**
 * Grabs plugin data from wp-admin/plugins.php, merges in any known hidden dependencies,
 * and returns the resulting array of plugin objects.
 *
 * The returned objects include:
 * - name: The plugin title
 * - slug: data-slug (e.g. "woocommerce-accommodation-bookings")
 * - plugin_entrypoint: data-plugin (e.g. "woocommerce-accommodation-bookings/woocommerce-accommodation-bookings.php")
 * - isActive: boolean indicating whether the plugin is active
 * - canActivate: boolean indicating whether there's an "Activate" link
 * - activationLink: string with the "Activate" link href (if canActivate is true)
 * - dependencies: array of dependent plugin names
 */
async function extractPluginsData(page) {
    // Wait for plugin rows to appear
    await page.waitForSelector('#the-list > tr[data-plugin]:not(.plugin-update-tr)');

    // 1) Extract the raw plugin data from the DOM
    let plugins = await page.evaluate(() => {
        return Array
            .from(document.querySelectorAll('#the-list > tr[data-plugin]:not(.plugin-update-tr)'))
            .map(row => {
                const nameElement = row.querySelector('.plugin-title strong');
                const activateLinkElement = row.querySelector('.activate a');
                // Extract dependencies from .requires div
                // Handle both linked dependencies (in <a> tags) and plain text dependencies
                let dependencies = [];
                const requiresDiv = row.querySelector('.requires');
                if (requiresDiv) {
                    // Get just the first paragraph that contains the dependencies
                    // Ignore any notice divs that may contain error messages
                    const requiresParagraph = requiresDiv.querySelector('p:first-of-type');
                    if (requiresParagraph && !requiresParagraph.closest('.notice')) {
                        const requiresText = requiresParagraph.textContent;
                        // Remove "Requires:" prefix and clean up
                        let cleanText = requiresText.replace(/^.*?Requires:\s*/i, '').trim();
                        // Split by comma and clean each dependency
                        dependencies = cleanText.split(',').map(dep => dep.trim()).filter(dep => dep && dep.length > 0);
                    }
                }

                return {
                    name: nameElement ? nameElement.textContent : 'Unknown Plugin',
                    slug: row.dataset.slug,
                    plugin_entrypoint: row.dataset.plugin,
                    isActive: !row.classList.contains('inactive'),
                    canActivate: activateLinkElement !== null,
                    activationLink: activateLinkElement ? activateLinkElement.href : null,
                    dependencies: dependencies
                };
            });
    });

    // 2) Merge in hidden dependencies using the slug as the key
    plugins.forEach(plugin => {
        if (hiddenDependencies[plugin.slug]) {
            // Avoid duplicates by using a Set
            plugin.dependencies = [
                ...new Set([
                    ...plugin.dependencies,
                    ...hiddenDependencies[plugin.slug]
                ])
            ];
        }
    });
    
    // Debug log to see what dependencies were extracted
    plugins.forEach(plugin => {
        if (plugin.dependencies.length > 0) {
            console.log(`Plugin "${plugin.name}" dependencies: [${plugin.dependencies.join(', ')}]`);
        }
    });

    // Return the final list
    return plugins;
}

test('Visit wp-admin pages added by the plugin', { tag: [ '@basic' ] }, async ({page, baseURL}, testInfo) => {
    test.setTimeout( 180000 ); // 3 minutes
    let counter = 0;
    const visitedPages = [];
    var debugLog = [];
    var pageVisitErrors = [];

    var pagesToVisit = JSON.parse(qit.getEnv('addedMenuItems'));

    /**
     * Example:
     *
     * {
     *   "activation": {
     *     "skipVisitPages": [
     *       "/wp-admin/admin.php?page=automatewoo-data-upgrade",
     *       "/wp-admin/admin.php?page=automatewoo-preview"
     *     ],
     *     "visitPages": {
     *       "Dashboard": "/wp-admin/admin.php?page=automatewoo"
     *     }
     *   }
     * }
     */

    if (sut_qit_config && sut_qit_config.activation) {
        // Handle "visitPages".
        if (sut_qit_config.activation.visitPages && typeof sut_qit_config.activation.visitPages === 'object') {
            for (const pageKey in sut_qit_config.activation.visitPages) {
                if (sut_qit_config.activation.visitPages.hasOwnProperty(pageKey)) {
                    let pagePath = sut_qit_config.activation.visitPages[pageKey];
                    let cleanBaseURL = baseURL.endsWith('/') ? baseURL.slice(0, -1) : baseURL;
                    let cleanPagePath = pagePath.startsWith('/') ? pagePath.slice(1) : pagePath;
                    let fullURL = cleanBaseURL + '/' + cleanPagePath;

                    console.log(`Adding ${pageKey} to the list of pages to visit.`);

                    pagesToVisit.push({
                        url: fullURL,
                        display: pageKey
                    });
                } else {
                    console.log(`Skipped processing for ${pageKey}: not a direct property.`);
                }
            }
        } else {
            console.log("Visit pages object does not exist or is not an object.");
        }

        // Handle "skipVisitPages".
        if (sut_qit_config.activation.skipVisitPages && Array.isArray(sut_qit_config.activation.skipVisitPages)) {
            for (const skipVisit of sut_qit_config.activation.skipVisitPages) {
                pagesToVisit = pagesToVisit.filter(page => {
                    const shouldSkip = page.url.includes(skipVisit);
                    if (shouldSkip) {
                        console.log(`Skipping ${page.display}`);
                    }
                    return !shouldSkip;
                });
            }
        }
    }

    for (const addedMenuItem of pagesToVisit) {
        if (visitedPages.includes(addedMenuItem.url)) {
            console.log(`Skipping ${addedMenuItem.display} as it was already visited.`);
            continue;
        }
        await test.step(`Visit ${addedMenuItem.display}`, async () => {
            await qit.loginAsAdmin(page);

            debugLog = await qit.individualLogging('start');
            debugLog = debugLog.split('\n');

            consoleLogs = [];
            webErrors = [];
            requestsFailed = [];
            debugLog = [];
            pageVisitErrors = [];

            console.log(`Navigating to ${addedMenuItem.url}`);
            const timeBefore = Date.now();
            await page.goto(addedMenuItem.url);
            const timeToPageLoad = Date.now() - timeBefore;

            // Pages that embed the block editor (or long-poll, or send analytics beacons) may
            // never go network-quiet even though they loaded fine. The idle wait only exists to
            // let the page settle before we capture logs and a screenshot, so a timeout here is
            // recorded in the Timings attachment rather than failing the test.
            let reachedNetworkIdle = true;
            try {
                await page.waitForLoadState('networkidle', {timeout: 20000}); // Give it 20 seconds.
            } catch (error) {
                reachedNetworkIdle = false;
                console.log(`Page did not reach network idle within 20s: ${addedMenuItem.url}`);
            }
            const timeToNetworkIdle = Date.now() - timeBefore;

            debugLog = await qit.individualLogging('stop');
            debugLog = debugLog.split('\n');

            consoleLogs.forEach(log => {
                if (log.type() === 'error' || log.type() === 'warning') {
                    pageVisitErrors.push(`Console ${log.type()}: ${log.text()}`);
                }
            });
            webErrors.forEach(error => {
                pageVisitErrors.push(`Uncaught exception: "${error.name} - ${error.message} - ${error.stack}"`);
            });
            requestsFailed.forEach(request => {
                pageVisitErrors.push(`Request failed: ${request.url} ${request.failure().errorText}`);
            });

            // addedMenuItem.url is something like: "http://qitenvnginx66e186886c79e/wp-admin/admin.php?page=automatewoo-preview"
            // Remove the domain, like this: "/wp-admin/admin.php?page=automatewoo-preview"
            const urlObject = new URL(addedMenuItem.url);
            const pathWithQuery = urlObject.pathname + urlObject.search;

            // Remove empty lines from debugLog
            debugLog = debugLog.filter(line => line.trim() !== '');

            // Prefix with counter so that it keeps the order by which the pages were accessed.
            // eg: 01 AutomateWoo Preview.
            await qit.attachScreenshot(counter.toString().padStart(2, '0') + ' ' + addedMenuItem.display, {
                'Title': [addedMenuItem.display],
                'URL': [pathWithQuery],
                'Timings': [
                    `Time to page load: ${timeToPageLoad / 1000}s`,
                    reachedNetworkIdle
                        ? `Time to network idle: ${timeToNetworkIdle / 1000}s`
                        : 'Did not reach network idle within 20s (page kept making network requests)'
                ],
                'PHP Debug Log': debugLog,
                'JavaScript Console Log': pageVisitErrors,
            }, page, testInfo);

            // Assertion to ensure the body tag is present and loaded
            const bodyHandle = await page.$('body');
            expect(bodyHandle).toBeTruthy();

            // Further checks can be made to ensure the body has content
            const bodyContent = await page.evaluate(body => body.innerHTML, bodyHandle);
            expect(bodyContent.length).toBeGreaterThan(0);

            // There should be no "Fatal Error" in the debug log.
            expect(debugLog.join('\n'), 'There was a fatal error in the debug log').not.toContain('Fatal error');

            visitedPages.push(addedMenuItem.url);

            counter++;
        });
    }
});

test('Activate Theme', { tag: [ '@basic', '@host-plan'] }, async ({page}) => {
    test.setTimeout(120000);
    // If SUT TYPE is not "theme", skip.
    if (sut_type !== 'theme') {
        return;
    }

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/themes.php');

    // Check if the "Install Parent Theme" link is visible and clickable
    const parentThemeLink = page.locator('a:has-text("Install Parent Theme")');
    if (await parentThemeLink.isVisible()) {
        console.log('Parent theme installation required. Installing now.');
        await parentThemeLink.click();
        // Confirm installation and navigate back to the themes page
        await page.waitForLoadState('networkidle');
        await page.goto('/wp-admin/themes.php');
    }

    // Find the theme with the matching SUT slug and attempt to activate it
    const themeSelector = `.theme[data-slug="${sut_slug}"] .theme-actions .activate`;
    const activateButton = page.locator(themeSelector);

    // Ensure the theme activation button is visible
    if (await activateButton.isVisible()) {
        await activateButton.click();
        console.log(`Activated the theme: ${sut_slug}`);

        /**
         * Navigate to the theme page to circumvent redirects to setup wizards.
         */
        await page.waitForLoadState('networkidle');
        await page.goto('/wp-admin/themes.php');

        // Optionally, add an assertion to confirm that the theme is active
        const activeThemeSelector = `.theme.active[data-slug="${sut_slug}"]`;
        await expect(page.locator(activeThemeSelector)).toBeVisible();
        console.log(`Confirmation: ${sut_slug} is now the active theme.`);
    } else {
        console.log(`Error: Activation button for theme '${sut_slug}' not found or not visible.`);
        throw new Error(`Activation button for theme '${sut_slug}' not found or not visible.`);
    }
});

test('Setup Local Pickup', { tag: [ '@basic' ] }, async ({ page }) => {
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=shipping&section=pickup_location');
    await page.getByLabel('Enable local pickup').check();
    await page.getByRole('button', { name: 'Add pickup location' }).click();
    await page.getByLabel('Location name').click();
    await page.getByLabel('Location name').fill('Local Pickup');
    await page.getByPlaceholder('Address').click();
    await page.getByPlaceholder('Address').fill('3 QIT Way');
    await page.getByPlaceholder('City').click();
    await page.getByPlaceholder('City').fill('San Francisco');
    await page.getByPlaceholder('Postcode / ZIP').click();
    await page.getByPlaceholder('Postcode / ZIP').fill('94016');
    await page.getByRole('button', { name: 'Done' }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();

});

test('Set up Cash On Delivery Payment Method', { tag: [ '@basic' ] }, async ({ page }) => {
    test.setTimeout(120000);
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout&section=cod');
    await page.waitForLoadState('networkidle');

    const versionResponse = await qit.wp('plugin get woocommerce --field=version');
    const woocommerceVersion = versionResponse.stdout.trim();
    const [major, minor] = woocommerceVersion.split('.');
    const isVersion99OrHigher = (parseInt(major) > 9) || (parseInt(major) === 9 && parseInt(minor) >= 9);

    // Higher than 9.9.0
    if (isVersion99OrHigher) {

        const isAlreadyChecked = await page.getByLabel('Enable cash on delivery payments').isChecked();

        console.log( `isAlreadyChecked: ${isAlreadyChecked}` );
        
        if (isAlreadyChecked) {
            console.log(`Cash on delivery already enabled, skipping setup steps`);
            return;
        }
        
        console.log( 'Setting up Cash on Delivery' );
        await page.getByLabel('Enable cash on delivery payments').check();
        await page.getByLabel('Accept for virtual orders').uncheck();
        await page.getByLabel('Enable for shipping methods').fill('Local pickup');
        // Select the "Local pickup" option strictly and avoid resolving to multiple elements.
        await page.getByRole('checkbox', { name: /^Local pickup$/ }).check();
        await page.waitForLoadState('domcontentloaded');
        await waitForDomStability(page, 1000); // Wait until the DOM stops changing.
        // Wait for an additional 2 seconds because this is very flaky. It's not a good pattern, it's needed here.
        await page.waitForTimeout(2000);
        // Exit the shipping method dropdown for the save changes button to be visible.
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.locator('.components-snackbar__content')).toHaveText('Settings updated successfully');
        console.log( 'Cash on Delivery setup complete' );
    } else {
        // Less than 9.9.0
        console.log('Using older version of WooCommerce, using legacy method to set up COD.');
        await page.getByLabel('Enable/Disable').check();
        await page.getByLabel('Accept COD if the order is').uncheck();
        await page.getByPlaceholder('Select shipping methods').click();
        await page.locator('li[aria-label*=\'Local pickup\']').click();
        await page.waitForLoadState('domcontentloaded');
        await waitForDomStability(page, 1000); // Wait until the DOM stops changing.

        // Wait for an additional 2 seconds because this is very flaky. It's not a good pattern, it's needed here.
        await page.waitForTimeout(2000);

        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.locator('#mainform')).toContainText('Your settings have been saved.');
    }
});

async function waitForDomStability(page, stabilityThreshold = 500) {
    await page.evaluate((stabilityThreshold) => {
        return new Promise((resolve) => {
            let lastMutationTime = Date.now();
            const observer = new MutationObserver(() => {
                lastMutationTime = Date.now();
            });
            observer.observe(document.body, { attributes: true, childList: true, subtree: true });

            const interval = setInterval(() => {
                if (Date.now() - lastMutationTime > stabilityThreshold) {
                    observer.disconnect();
                    clearInterval(interval);
                    resolve();
                }
            }, 100);
        });
    }, stabilityThreshold);
}

test('Create a Product', { tag: [ '@basic' ] }, async ({ page }) => {

    test.setTimeout(120000);
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/post-new.php?post_type=product');
    await page.locator('input[name="post_title"]').fill('Test Product');
    await page.frameLocator('#content_ifr').locator('#tinymce').click();
    await page.frameLocator('#content_ifr').locator('#tinymce').fill('Test Product');
    await page.locator( 'li.general_options' ).click();
    await page.locator('#_regular_price').click();
    await page.locator('#_regular_price').fill('10');

    // Wait for the Publish button to NOT have the 'disabled' class
    await page.waitForFunction(() => {
        const publishButton = document.querySelector('#publish');
        return publishButton && !publishButton.classList.contains('disabled');
    }, {timeout: 60000});

    // Wait for JS event handlers to be fully attached (pragmatic fix for hydration timing)
    await page.waitForTimeout(2000);

    await page.getByRole('button', { name: 'Publish', exact: true }).click();

    // Wait for WordPress publish success notice (longer timeout for slow CI)
    await expect(
        page.locator('div.notice-success > p').filter({ hasText: 'Product published.' })
    ).toBeVisible({ timeout: 60000 });

    await expect(page.locator('#post-status-display')).toContainText('Published');
    await expect(page.frameLocator('#content_ifr').getByRole('paragraph')).toContainText('Test Product');
});

test('Create a Simple Order', { tag: [ '@basic' ] }, async ({ page }) => {

    test.slow();
    await qit.loginAsAdmin(page);
    await page.goto( 'wp-admin/admin.php?page=wc-orders&action=new' );

    await page
        .locator( '#order_status' )
        .selectOption( 'wc-processing' );

    // Enter billing information
    await page
        .getByRole( 'heading', { name: 'Billing Edit' } )
        .getByRole( 'link' )
        .click();
    await page
        .getByRole( 'textbox', { name: 'First name' } )
        .fill( 'Bart' );
    await page
        .getByRole( 'textbox', { name: 'Last name' } )
        .fill( 'Simpson' );
    await page
        .getByRole( 'textbox', { name: 'Company' } )
        .fill( 'Kwik-E-Mart' );
    await page
        .getByRole( 'textbox', { name: 'Address line 1' } )
        .fill( '742 Evergreen Terrace' );
    await page
        .getByRole( 'textbox', { name: 'City' } )
        .fill( 'Springfield' );
    await page
        .getByRole( 'textbox', { name: 'Postcode' } )
        .fill( '12345' );
    await page
        .getByRole( 'textbox', { name: 'Select an option…' } )
        .click();
    await page.getByRole( 'option', { name: 'Florida' } ).click();
    await page
        .getByRole( 'textbox', { name: 'Email address' } )
        .fill( 'elbarto@example.com' );
    await page
        .getByRole( 'textbox', { name: 'Phone' } )
        .fill( '555-555-5555' );
    await page
        .getByRole( 'textbox', { name: 'Transaction ID' } )
        .fill( '1234567890' );

    // Enter shipping information
    await page
        .getByRole( 'heading', { name: 'Shipping Edit' } )
        .getByRole( 'link' )
        .click();
    page.on( 'dialog', ( dialog ) => dialog.accept() );
    await page
        .getByRole( 'link', { name: 'Copy billing address' } )
        .click();
    await page
        .getByPlaceholder( 'Customer notes about the order' )
        .fill( 'Only asked for a slushie' );

    // Add a product
    await page.getByRole( 'button', { name: 'Add item(s)' } ).click();
    await page
        .getByRole( 'button', { name: 'Add product(s)' } )
        .click();
    // selectWoo keeps a search field inside the closed selection and renders the
    // one you can type in only once the dropdown is open, appended to <body>.
    // Scope to the open container so the invisible one is never matched.
    await page
        .locator( '.wc-backbone-modal' )
        .getByRole( 'combobox', { name: 'Search for a product…' } )
        .click();
    await page
        .locator( '.select2-container--open .select2-search__field' )
        .fill( 'Test' );
    await page
        .getByRole( 'option', { name: 'Test Product' } )
        .click();
    await page.locator( '#btn-ok' ).click();

    // Wait for the order totals to be updated
    await expect(page.locator('.wc-order-totals').first()).toContainText('$10.00', { timeout: 60000 });

    // Create the order
    await page.getByRole( 'button', { name: 'Create' } ).click();

    await expect( page.locator( 'div#message.updated.notice.notice-success' ) ).toContainText(
        'Order updated.'
    );

    // Confirm the details
    await expect(
        page.getByText(
            'Billing Edit Load billing address Bart SimpsonKwik-E-Mart742 Evergreen'
        )
    ).toBeVisible();
    await expect(
        page.getByText(
            'Shipping Edit Load shipping address Copy billing address Bart SimpsonKwik-E-'
        )
    ).toBeVisible();
    await expect(
        page.locator( 'table' ).filter( { hasText: 'Paid: $10.00' } )
    ).toBeVisible();
});

test('Add Product Cart', async ({ page }) => {
    test.setTimeout(120000);
    await page.goto('/shop');
    await page.locator('a[href*="product/test-product"]').first().click();

    await expect(
        page.locator('h1.product_title, h1.wp-block-post-title, h2.wp-block-post-title')
    ).toContainText('Test Product');

    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/cart');
    await expect(page.locator('.wc-block-components-product-name')).toContainText('Test Product');
    await expect(page.locator('td.wc-block-cart-item__total .wc-block-formatted-money-amount')).toContainText('$10.00');
    await expect(page.locator('.wc-block-components-totals-item__value > span')).toContainText('$10.00');
});

test('Can Place Order', async ( { page } ) => {
    test.setTimeout(120000);

    // Add a product to the cart
    await page.goto('/shop');
    await page.locator('a[href*="product/test-product"]').first().click();
    await page.waitForURL('**/product/**');
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/checkout');
    await page.waitForLoadState('networkidle');

    // Reload the page.
    await page.reload();
    await page.waitForLoadState('networkidle');

    // to avoid flakiness, sometimes the email address is not filled
    await page
        .locator(
            '.wc-block-components-order-summary-item__individual-prices'
        )
        .waitFor( { state: 'visible' } );
    await page.locator( '#email' ).click();
    await page.locator( '#email' ).fill( 'elbarto@example.com' );
    await expect( page.locator( '#email' ) ).toHaveValue(
        'elbarto@example.com'
    );

    // fill billing details
    await page
        .getByRole( 'group', { name: 'Billing address' } )
        .getByLabel( 'First name' )
        .fill( 'Bart' );
    await page
        .getByRole( 'group', { name: 'Billing address' } )
        .getByLabel( 'Last name' )
        .fill( 'Simpson' );
    await page
        .getByRole( 'group', { name: 'Billing address' } )
        .getByLabel( 'Address', { exact: true } )
        .fill( '742 Evergreen Terrace' );
    await page
        .getByRole( 'group', { name: 'Billing address' } )
        .getByLabel( 'City' )
        .fill( 'Springfield' );
    await page
        .getByRole( 'group', { name: 'Billing address' } )
        .getByLabel( 'ZIP Code' )
        .fill( '94016' );

    await page.getByLabel( 'Add a note to your order' ).check();
    await page.locator( 'div.wc-block-checkout__add-note > textarea' ).fill( 'This is to avoid flakiness' );

    await page.getByRole( 'button', { name: 'Place order' } ).click();

    await page.waitForURL('**/order-received/**', { timeout: 60000 });

    await expect(
        page.getByText( 'Your order has been received' )
    ).toBeVisible();
});

test('Deactivate Plugin', { tag: [ '@basic', '@host-plan' ] }, async ( { page } ) => {
    test.setTimeout(120000);
    if (sut_type !== 'plugin') {
        return;
    }

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    // Extract current plugin data to understand dependencies
    let plugins = await extractPluginsData(page);

    // Find the SUT plugin
    const sutPlugin = plugins.find(p => p.plugin_entrypoint === sut_entrypoint);
    if (!sutPlugin) {
        console.error(`Could not find SUT plugin with entrypoint: ${sut_entrypoint}`);
        return;
    }

    // Find plugins that depend on the SUT (these must be deactivated first)
    const dependentPlugins = plugins.filter(p =>
        p.isActive &&
        p.plugin_entrypoint !== sut_entrypoint &&
        (p.dependencies.includes(sutPlugin.name) || p.dependencies.includes(sutPlugin.slug))
    );

    console.log(`SUT "${sutPlugin.name}" has ${sutPlugin.dependencies.length} dependencies (requires): [${sutPlugin.dependencies.join(', ')}]`);
    console.log(`Found ${dependentPlugins.length} active plugins that depend on the SUT (must deactivate first)`);
    
    if (dependentPlugins.length > 0) {
        console.log('Plugins that depend on SUT:', dependentPlugins.map(p => p.name).join(', '));
    }

    // Step 1: If other plugins depend on the SUT, try to deactivate it (should fail or be prevented)
    if (dependentPlugins.length > 0) {
        console.log(`Step 1: Attempting to deactivate SUT with active dependent plugins (should prevent deactivation)`);
        
        // Check if deactivate link exists (it shouldn't if dependent plugins prevent it)
        const deactivateLink = page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate a`);
        const linkCount = await deactivateLink.count();
        
        if (linkCount === 0) {
            console.log(`✓ Deactivation link not available for SUT (as expected due to dependent plugins)`);
        } else {
            // If link exists, WordPress might show an error when clicked
            try {
                await deactivateLink.click();
                await page.waitForTimeout(2000);
                
                // Check if plugin is still active (it should be)
                await page.goto('/wp-admin/plugins.php');
                await page.waitForLoadState('networkidle');
                // More robust check: verify deactivate link is still present
                const deactivateLinkStillExists = page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate a`);
                await expect(deactivateLinkStillExists).toHaveCount(1);
                console.log(`✓ SUT remains active after deactivation attempt (deactivate link still present)`);
            } catch (e) {
                console.log(`Deactivation was prevented (as expected): ${e.message}`);
            }
        }
    }

    // Step 2: Deactivate plugins that depend on the SUT first
    for (const depPlugin of dependentPlugins) {
        console.log(`Step 2: Deactivating dependent plugin "${depPlugin.name}"`);
        
        // First deactivate any plugins that depend on this dependent plugin (recursive dependencies)
        const subDependents = plugins.filter(p => 
            p.isActive && 
            p.plugin_entrypoint !== depPlugin.plugin_entrypoint &&
            (p.dependencies.includes(depPlugin.name) || p.dependencies.includes(depPlugin.slug))
        );
        
        for (const subDep of subDependents) {
            console.log(`  - First deactivating sub-dependent "${subDep.name}"`);
            try {
                await page.locator(`tr[data-plugin="${subDep.plugin_entrypoint}"] .deactivate a`).click();
                await page.waitForTimeout(2000);
            } catch (error) {
                console.log(`  - Using CLI fallback for "${subDep.name}"`);
                await qit.wp(`plugin deactivate ${subDep.slug}`);
            }
            await page.goto('/wp-admin/plugins.php');
            await page.waitForLoadState('networkidle');
        }
        
        // Now deactivate the dependent plugin
        try {
            await page.locator(`tr[data-plugin="${depPlugin.plugin_entrypoint}"] .deactivate a`).click();
            await page.waitForTimeout(2000);
            console.log(`✓ Deactivated "${depPlugin.name}"`);
        } catch (error) {
            console.log(`Using CLI fallback for "${depPlugin.name}"`);
            await qit.wp(`plugin deactivate ${depPlugin.slug}`);
        }
        
        await page.goto('/wp-admin/plugins.php');
        await page.waitForLoadState('networkidle');
    }

    // Step 3: Now deactivate the SUT (should work now that dependents are deactivated)
    console.log(`Step 3: Deactivating SUT "${sutPlugin.name}"`);
    
    try {
        // Try to click the deactivate link.
        await page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate a`).click();
        await expect(page.locator('#message.notice')).toContainText('Plugin deactivated.', { timeout: 10000 });
        console.log(`✓ Successfully deactivated SUT "${sutPlugin.name}"`);
    } catch (error) {
        console.error('UI deactivation failed, resorting to CLI:', error);
        // If UI fails, try deactivating via WP CLI.
        // This can happen with plugins that adds deactivation survey pop-ups.
        await qit.wp(`plugin deactivate ${sut_slug}`);
        await page.goto('/wp-admin/plugins.php');
        await expect(page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate`)).toHaveCount(0);
        console.log(`✓ Successfully deactivated SUT "${sutPlugin.name}" via CLI`);
    }
});


test('Activate Other Theme', { tag: [ '@basic' ] }, async ( { page } ) => {
    if (sut_type !== 'theme') {
        return;
    }

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/themes.php');
    const otherTheme = await page.locator('.theme:not(.active)').first();
    await otherTheme.hover();
    await otherTheme.locator('.activate').click();
    await expect(page.locator('#message2.notice')).toContainText('New theme activated.');
});
