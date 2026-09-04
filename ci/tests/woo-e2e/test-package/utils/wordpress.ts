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

const getVersionWPLatestMinusOne = async ( {
	core,
	github,
}: {
	core: { setOutput: ( name: string, value: string ) => void };
	github: {
		request: (
			url: string
		) => Promise< { data: Record< string, string > } >;
	};
} ) => {
	const URL_WP_STABLE_VERSION_CHECK =
		'https://api.wordpress.org/core/stable-check/1.0/';

	const response = await github.request( URL_WP_STABLE_VERSION_CHECK );

	const body = response.data;
	const allVersions = Object.keys( body );
	const previousStableVersions = allVersions
		.filter( ( version ) => body[ version ] === 'outdated' )
		.sort()
		.reverse();
	const latestVersion = allVersions.find(
		( version ) => body[ version ] === 'latest'
	);
	if ( ! latestVersion ) {
		throw new Error( 'No latest WordPress version found in API response' );
	}
	const match = latestVersion.match( /^\d+\.\d+/ );
	if ( ! match ) {
		throw new Error( `Unexpected version format: ${ latestVersion }` );
	}
	const latestMajorAndMinorNumbers = match[ 0 ];

	const latestMinus1 = previousStableVersions.find(
		( version ) => ! version.startsWith( latestMajorAndMinorNumbers )
	);

	if ( ! latestMinus1 ) {
		throw new Error(
			'Unable to find the previous stable WordPress version'
		);
	}

	core.setOutput( 'version', latestMinus1 );
};

/**
 * Read the WordPress version of the site under test.
 *
 * Upstream shells out to `wp-env run cli -- wp core version`. QIT has no
 * wp-env, so this reads the same value from the `e2e-environment/info` route in
 * `bootstrap/e2e-test-helper.php` — the port of upstream's always-on helper
 * plugin, which reports it as `Core`. Signature and return type match upstream's
 * so callers need no change.
 *
 * @return The version as a float, e.g. `6.9`.
 */
const getInstalledWordPressVersion = async () => {
	try {
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

		const response = await apiContext.get(
			'./wp-json/e2e-environment/info',
			{ failOnStatusCode: true }
		);

		return Number.parseFloat( ( await response.json() ).Core );
	} catch ( error ) {
		throw new Error(
			`Error getting WordPress version: ${ error.message }`
		);
	}
};

export { getVersionWPLatestMinusOne, getInstalledWordPressVersion };
