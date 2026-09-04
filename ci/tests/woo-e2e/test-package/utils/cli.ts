/**
 * External dependencies
 */
import { request } from '@playwright/test';

/**
 * Internal dependencies
 */
import { admin } from '../test-data/data';
import { encodeCredentials } from './plugin-utils';
import playwrightConfig from '../playwright.config';

/**
 * Run a WP-CLI command against the site under test.
 *
 * Keeps WooCommerce Core's signature so ported specs call it unmodified, but
 * routes through the `e2e-cli` REST bridge in `bootstrap/wp-cli-bridge.php`
 * rather than `wp-env run cli`: QIT has no wp-env, WP-CLI is only available
 * during `qit-test.json` globalSetup, and Playwright runs on the host rather
 * than inside the container.
 *
 * The bridge implements the commands the specs actually issue and rejects
 * anything else, so an unported command fails loudly here instead of silently
 * doing nothing.
 *
 * @param {string} command The command, e.g. `wp option delete foo`.
 * @return Its stdout and stderr, as WP-CLI would have reported them.
 */
const wpCLI = async ( command: string ) => {
	const apiContext = await request.newContext( {
		baseURL: playwrightConfig.use.baseURL,
		extraHTTPHeaders: {
			Authorization: `Basic ${ encodeCredentials(
				admin.username,
				admin.password
			) }`,
			cookie: '',
		},
	} );

	const response = await apiContext.post( './wp-json/e2e-cli/run', {
		data: { command },
	} );

	const body = await response.json();

	if ( ! response.ok() ) {
		throw new Error(
			`wpCLI failed for "${ command }": ${
				body?.message ?? response.status()
			}`
		);
	}

	return { stdout: body.stdout as string, stderr: body.stderr as string };
};

export { wpCLI };
