"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.publishPage = exports.transformIntoBlocks = exports.insertBlockByShortcut = exports.insertBlock = exports.goToPostEditor = exports.goToPageEditor = exports.getCanvas = exports.openEditorSettings = exports.disableWelcomeModal = exports.closeChoosePatternModal = void 0;
/**
 * Closes the "Choose a pattern" modal if present.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
const closeChoosePatternModal = async ({ page, }) => {
    const closeModal = page
        .locator('div')
        .filter({ hasText: 'Choose a pattern' })
        .getByLabel('Close');
    await page.addLocatorHandler(closeModal, async () => {
        await closeModal.click();
    });
};
exports.closeChoosePatternModal = closeChoosePatternModal;
/**
 * Disables the Gutenberg welcome modal.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
const disableWelcomeModal = async ({ page, }) => {
    // Further info: https://github.com/woocommerce/woocommerce/pull/45856/
    await page.waitForLoadState('domcontentloaded');
    const isWelcomeGuideActive = await page.evaluate(() => window.wp?.data
        ?.select('core/edit-post')
        ?.isFeatureActive('welcomeGuide'));
    if (isWelcomeGuideActive) {
        await page.evaluate(() => window.wp?.data
            ?.dispatch('core/edit-post')
            ?.toggleFeature('welcomeGuide'));
    }
};
exports.disableWelcomeModal = disableWelcomeModal;
/**
 * Opens the editor settings sidebar if closed.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
const openEditorSettings = async ({ page, }) => {
    // Open Settings sidebar if closed
    if (await page.getByLabel('Editor Settings').isVisible()) {
        console.log('Editor Settings is open, skipping action.');
    }
    else {
        await page.getByLabel('Settings', { exact: true }).click();
    }
};
exports.openEditorSettings = openEditorSettings;
/**
 * Returns the editor canvas frame for Gutenberg interactions.
 *
 * The Gutenberg editor mounts either inside an `iframe[name="editor-canvas"]`
 * (modern site/post editor) or directly on the outer page (non-iframed
 * contexts, e.g. accessibility mode). Recent Gutenberg
 * builds mount the iframe slightly after initial paint, so callers that resolve
 * the canvas before the iframe attaches end up running locators against the
 * outer page and timing out inside the editor.
 *
 * Resolves as soon as either surface becomes visible, using a union selector
 * so neither path incurs a hardcoded probe timeout. If neither surface appears
 * within Playwright's default timeout, the underlying `waitFor` throws and the
 * test fails loudly rather than silently degrading to the wrong document.
 *
 * @param page - The Playwright page object
 * @return The editor canvas frame or the original page
 */
const getCanvas = async (page) => {
    const canvasLocator = page
        .locator('iframe[name="editor-canvas"]:visible, .wp-block-post-content:visible')
        .first();
    await canvasLocator.waitFor();
    const isFramed = await canvasLocator.evaluate((node) => node.tagName === 'IFRAME');
    return isFramed ? canvasLocator.contentFrame() : page;
};
exports.getCanvas = getCanvas;
/**
 * Navigates to the WordPress page editor.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
const goToPageEditor = async ({ page, }) => {
    await page.goto('wp-admin/post-new.php?post_type=page');
    await (0, exports.disableWelcomeModal)({ page });
    await (0, exports.closeChoosePatternModal)({ page });
};
exports.goToPageEditor = goToPageEditor;
/**
 * Navigates to the WordPress post editor.
 *
 * @param context      - Object containing the Playwright page
 * @param context.page - The Playwright page object
 */
const goToPostEditor = async ({ page, }) => {
    await page.goto('wp-admin/post-new.php');
    await (0, exports.disableWelcomeModal)({ page });
};
exports.goToPostEditor = goToPostEditor;
/**
 * Inserts a block using the block inserter.
 *
 * @param page      - The Playwright page object
 * @param blockName - The name of the block to insert
 */
const insertBlock = async (page, blockName) => {
    // Focus on "Empty block" element before inserting a new block.
    // Otherwise, Gutenberg nightly (v19.9-nightly) would display "{Block name} can't be inserted."
    const canvas = await (0, exports.getCanvas)(page);
    const emptyBlock = canvas.getByLabel('Empty block');
    if (await emptyBlock.isVisible()) {
        await emptyBlock.click();
    }
    // With Gutenberg active we have Block Inserter name
    await page
        .getByRole('button', {
        name: /Toggle block inserter|Block Inserter/,
        expanded: false,
    })
        .click();
    await page.getByPlaceholder('Search', { exact: true }).fill(blockName);
    await page.getByRole('option', { name: blockName, exact: true }).click();
    await page
        .getByRole('button', {
        name: 'Close block inserter',
    })
        .click();
};
exports.insertBlock = insertBlock;
/**
 * Inserts a block using the slash command shortcut.
 *
 * @param page      - The Playwright page object
 * @param blockName - The name of the block to insert
 */
const insertBlockByShortcut = async (page, blockName) => {
    const canvas = await (0, exports.getCanvas)(page);
    const emptyBlockField = canvas.getByText('Type / to choose a block').or(canvas.getByRole('document', {
        name: 'Empty block; start writing or type forward slash to choose a block',
    }));
    await emptyBlockField.click();
    await emptyBlockField.pressSequentially(`/${blockName}`);
    await page.getByRole('option', { name: blockName, exact: true }).click();
};
exports.insertBlockByShortcut = insertBlockByShortcut;
/**
 * Transforms classic content into blocks.
 *
 * @param page - The Playwright page object
 */
const transformIntoBlocks = async (page) => {
    const canvas = await (0, exports.getCanvas)(page);
    await canvas
        .getByRole('button')
        .filter({ hasText: 'Transform into blocks' })
        .click();
};
exports.transformIntoBlocks = transformIntoBlocks;
/**
 * Publishes a page or post.
 *
 * @param page      - The Playwright page object
 * @param pageTitle - The title of the page/post being published
 * @param isPost    - Whether this is a post (true) or page (false)
 */
const publishPage = async (page, pageTitle, isPost = false) => {
    await page
        .getByRole('button', { name: 'Publish', exact: true })
        .dispatchEvent('click');
    const createPageResponse = page.waitForResponse((response) => {
        return (response.url().includes(isPost ? '/posts/' : '/pages/') &&
            response.ok() &&
            response.request().method() === 'POST' &&
            response
                .json()
                .then((json) => json.title.rendered === pageTitle &&
                json.status === 'publish'));
    });
    await page
        .getByRole('region', { name: 'Editor publish' })
        .getByRole('button', { name: 'Publish', exact: true })
        .click();
    // Validating that page was published via UI elements is not reliable,
    // installed plugins (e.g. WooCommerce PayPal Payments) can interfere and add flakiness to the flow.
    // In WC context, checking the API response is possibly the most reliable way to ensure the page was published.
    await createPageResponse;
};
exports.publishPage = publishPage;
