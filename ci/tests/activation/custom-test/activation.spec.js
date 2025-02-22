import {test, expect} from '@playwright/test';
import qit from '/qitHelpers';
import fs from 'fs';

test.describe.configure({mode: 'serial'});

const testInfo = JSON.parse(fs.readFileSync('/qitHelpers/test-info.json', 'utf8'));

const sut_slug = testInfo.SUT_SLUG;
const sut_type = testInfo.SUT_TYPE;
const sut_entrypoint = testInfo.SUT_ENTRYPOINT;
const sut_qit_config = testInfo.SUT_QIT_CONFIG;
const plugin_activation_stack = testInfo.PLUGIN_ACTIVATION_STACK;

// Pages to visit is empty by default.
qit.setEnv('addedMenuItems', '[]');

// Include console errors in the context.
const ignoredErrors = [
    'the server responded with a status of 403',
    'QM error from JS',
    'Soon, cookies without the “SameSite” attribute',
    'Migrate is installed',
];

/** @type {array<ConsoleMessage>} */
var consoleLogs = [];

/** @type {array<Error>} */
var webErrors = [];

/** @type {array<Request>} */
var requestsFailed = [];

test.beforeAll(async () => {
    await qit.wp('option update woocommerce_coming_soon no');
    await qit.wp('option update woocommerce_store_pages_only no');
    console.log('Coming soon mode disabled in beforeAll.');
});

test.beforeEach(async ({page, context}) => {
    page.on('console', async (msg) => {
        // Check if the message is in the list of ignored errors
        if (ignoredErrors.some(error => msg.text().includes(error))) {
            return;
        }
        consoleLogs.push(msg);
        console.log(`Console ${msg.type()}: ${msg.text()}`);
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

test('Activate Plugins', async ({page}, testInfo) => {
    test.setTimeout( 600000 ); // Increase timeout to 10 minutes.
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/plugins.php');
    await page.waitForLoadState('networkidle');

    let plugins = await extractPluginsData(page);
    plugins = sortPluginsByDependencies(plugins);

    const findPluginByName = (name) => plugins.find(p => p.name === name);
    const isDependencyActive = (name) => {
        let dependency = findPluginByName(name);

        if (!dependency) {
            console.log(`Dependency not found: ${name}`);
            return false;
        }

        return dependency.isActive;
    }

    let menuBeforeActivatingSUT = [];  // Declare it outside to ensure broader scope

    let activated = 0;
    let activations = 0;
    do {
        activations = 0;
        for (const plugin of plugins.filter(p => !p.isActive && p.canActivate)) {
            if (plugin.dependencies.every(dep => isDependencyActive(dep))) {
                let activating_sut = sut_entrypoint === plugin.plugin_entrypoint;
                let initialMenuItems = [];

                if (activating_sut) {
                    menuBeforeActivatingSUT = await page.evaluate(() =>
                        Array.from(document.querySelectorAll('#adminmenu li a')).map(a => ({
                            url: a.href,
                            display: a.textContent.trim()
                        }))
                    );
                }

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

                // Check to confirm activation was successful.
                await page.waitForSelector('#the-list'); // Wait for the plugin list to reload
                const isSuccess = await page.evaluate((pluginName) => {
                    const pluginRow = Array.from(document.querySelectorAll('#the-list > tr')).find(
                        row => row.querySelector('.plugin-title strong')?.textContent === pluginName
                    );
                    return pluginRow && !pluginRow.classList.contains('inactive');
                }, plugin.name);

                expect(isSuccess).toBe(true);
                console.log(`Activated ${plugin.name}`);
                activations++;
                activated++;

                // Return to plugins page to refresh state
                await page.goto('/wp-admin/plugins.php');
                await page.waitForLoadState('networkidle');

                if (activating_sut) {
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
                console.log(`Cannot activate ${plugin.name} yet. Dependencies not met.`);
            }
        }
    } while (activations > 0);
});

function sortPluginsByDependencies(plugins) {
    let sorted = [];
    let toBeSorted = plugins.slice(); // Clone to manage the sorting without mutating the original array
    let changes;

    do {
        changes = false;
        for (let i = 0; i < toBeSorted.length; i++) {
            const plugin = toBeSorted[i];
            // Check if all dependencies of the plugin are already in the sorted array
            const dependenciesSatisfied = plugin.dependencies.every(dep =>
                sorted.some(p => p.name === dep && p.isActive)
            );

            if (dependenciesSatisfied || plugin.dependencies.length === 0) {
                sorted.push(plugin);
                toBeSorted.splice(i, 1); // Remove the plugin from toBeSorted
                i--; // Adjust index since we've modified the array
                changes = true;
            }
        }
    } while (changes && toBeSorted.length > 0);

    // In case there are remaining plugins that couldn't be sorted (circular or unresolved dependencies)
    if (toBeSorted.length > 0) {
        throw new Error('Unable to resolve all plugin dependencies for sorting.');
    }

    return sorted;
}

async function extractPluginsData(page) {
    await page.waitForSelector('#the-list > tr[data-plugin]:not(.plugin-update-tr)');
    return page.evaluate(() => {
        return Array.from(document.querySelectorAll('#the-list > tr[data-plugin]:not(.plugin-update-tr)')).map(row => {
            const nameElement = row.querySelector('.plugin-title strong');
            const activateLinkElement = row.querySelector('.activate a');
            const dependencyElements = Array.from(row.querySelectorAll('.requires a'));
            return {
                name: nameElement ? nameElement.textContent : 'Unknown Plugin',
                slug: row.dataset.slug,
                plugin_entrypoint: row.dataset.plugin,
                isActive: !row.classList.contains('inactive'),
                canActivate: activateLinkElement !== null,
                activationLink: activateLinkElement ? activateLinkElement.href : null,
                dependencies: dependencyElements.map(link => link.textContent || 'Unknown Dependency')
            };
        });
    });
}

test('Visit wp-admin pages added by the plugin', async ({page, baseURL}, testInfo) => {
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
            await page.waitForLoadState('networkidle', {timeout: 20000}); // Give it 20 seconds.
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
                    `Time to network idle: ${timeToNetworkIdle / 1000}s`
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

test('Activate Theme', async ({page}) => {
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

test('Setup Local Pickup', async ({ page }) => {
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

test('Set up Cash On Delivery Payment Method', async ({ page }) => {
    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=wc-settings&tab=checkout');
    await page.waitForLoadState('networkidle');
    await page.locator('a[href*=\'section=cod\'] > span').click();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Set up the "Cash on delivery').click();
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

test('Create a Product', async ({ page }) => {

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/post-new.php?post_type=product');
    await page.locator('input[name="post_title"]').fill('Test Product');
    await page.frameLocator('#content_ifr').locator('#tinymce').click();
    await page.frameLocator('#content_ifr').locator('#tinymce').fill('Test Product');
    await page.locator( 'li.general_options' ).click();
    await page.locator('#_regular_price').click();
    await page.locator('#_regular_price').fill('10');
    await page.locator('#general_product_data').click();
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.locator('#post-status-display')).toContainText('Published');
    await expect(page.frameLocator('#content_ifr').getByRole('paragraph')).toContainText('Test Product');
});

test('Create a Simple Order', async ({ page }) => {

    test.slow();
    await qit.loginAsAdmin(page);
    await page.goto( 'wp-admin/admin.php?page=wc-orders&action=new' );

    const orderText = await page
        .locator( 'h2.woocommerce-order-data__heading' )
        .textContent();
    let orderId = orderText.match( /([0-9])\w+/ );
    orderId = orderId[ 0 ].toString();

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
    await page.getByText( 'Search for a product…' ).click();
    await page
        .locator( 'span > .select2-search__field' )
        .fill( 'Test' );
    await page
        .getByRole( 'option', { name: 'Test Product' } )
        .click();
    await page.locator( '#btn-ok' ).click();

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
    await page.goto('/shop');
    page.locator('a[href*="product/test-product"]').first().click();

    await expect(
        page.locator('h1.product_title, h1.wp-block-post-title')
    ).toContainText('Test Product');

    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.waitForLoadState('networkidle');

    await page.goto('/cart');
    await expect(page.locator('.wc-block-components-product-name')).toContainText('Test Product');
    await expect(page.locator('td.wc-block-cart-item__total .wc-block-formatted-money-amount')).toContainText('$10.00');
    await expect(page.locator('.wc-block-components-totals-item__value > span')).toContainText('$10.00');
});

test('Can Place Order', async ( { page } ) => {

    // Add a product to the cart
    await page.goto('/shop');
    page.locator('a[href*="product/test-product"]').first().click();
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

    await page.waitForLoadState('networkidle');

    await expect(
        page.getByText( 'Your order has been received' )
    ).toBeVisible();
});

test('Deactivate Plugin', async ( { page } ) => {
    if (sut_type !== 'plugin') {
        return;
    }

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/plugins.php');

    try {
        // Try to click the deactivate link.
        await page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate a`).click();
        await expect(page.locator('#message.notice')).toContainText('Plugin deactivated.', { timeout: 10000 });
    } catch (error) {
        console.error('UI deactivation failed, resorting to CLI:', error);
        // If UI fails, try deactivating via WP CLI.
        // This can happen with plugins that adds deactivation survey pop-ups.
        await qit.wp(`plugin deactivate ${sut_slug}`);
        await page.goto('/wp-admin/plugins.php');
        await expect(page.locator(`tr[data-plugin="${sut_entrypoint}"] .deactivate`)).toHaveCount(0);
    }
});


test('Activate Other Theme', async ( { page } ) => {
    if (sut_type !== 'theme') {
        return;
    }

    await qit.loginAsAdmin(page);
    await page.goto('/wp-admin/themes.php');
    await page.getByLabel('Activate Twenty Twenty-Four').click();
    await expect(page.locator('#message2.notice')).toContainText('New theme activated.');
});