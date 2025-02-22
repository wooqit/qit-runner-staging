const { devices } = require( '@playwright/test' );
require( 'dotenv' ).config( { path: __dirname + '/.env' } );

const testsRootPath = __dirname;
const testsResultsPath = `${ testsRootPath }/test-results`;

if ( ! process.env.BASE_URL ) {
	console.log( 'BASE_URL is not set. Using default.' );
	process.env.BASE_URL = 'http://localhost:8086';
}

const {
	ALLURE_RESULTS_DIR,
	BASE_URL,
	CI,
	DEFAULT_TIMEOUT_OVERRIDE,
	E2E_MAX_FAILURES,
	REPEAT_EACH,
} = process.env;

const reporter = [ [ 'playwright-ctrf-json-reporter', { outputDir: process.env.CTRF_OUTPUT_DIR, outputFile: 'ctrf-report.json' } ],
	[ 'list' ],
	[
		'allure-playwright',
		{
			outputFolder:
				ALLURE_RESULTS_DIR ??
				'./e2e/allure-results',
			detail: true,
			suiteTitle: true,
		},
	],
	[
		'json',
		{
			outputFile: './e2e/test-results.json',
		},
	],
	[
		`${ testsRootPath }/reporters/environment-reporter.js`,
		{ outputFolder: ALLURE_RESULTS_DIR ?? './e2e/allure-results' },
	],
	[
		`${ testsRootPath }/reporters/flaky-tests-reporter.js`,
		{ outputFolder: ALLURE_RESULTS_DIR ?? './e2e/allure-results' },
	],
];

if ( process.env.CI ) {
	// reporter.push( [ 'buildkite-test-collector/playwright/reporter' ] );
	reporter.push( [ `${ testsRootPath }/reporters/skipped-tests.js` ] );
} else {
	reporter.push( [
		'html',
		{
			outputFolder: `${ testsRootPath }/playwright-report`,
			open: 'on-failure',
		},
	] );
}

const config = {
	timeout: DEFAULT_TIMEOUT_OVERRIDE
		? Number( DEFAULT_TIMEOUT_OVERRIDE )
		: 120 * 1000,
	expect: { timeout: 30 * 1000 },
	outputDir: testsResultsPath,
	globalSetup: require.resolve( './global-setup' ),
	// globalTeardown: require.resolve( './global-teardown' ),
	testDir: `${ testsRootPath }/tests`,
	retries: CI ? 1 : 0,
	repeatEach: REPEAT_EACH ? Number( REPEAT_EACH ) : 1,
	workers: 1,
	reportSlowTests: { max: 5, threshold: 30 * 1000 }, // 30 seconds threshold
	reporter,
	maxFailures: E2E_MAX_FAILURES ? Number( E2E_MAX_FAILURES ) : 0,
	forbidOnly: !! CI,
	use: {
		baseURL: `${ BASE_URL }/`.replace( /\/+$/, '/' ),
		screenshot: { mode: 'only-on-failure', fullPage: true },
		stateDir: `${ testsRootPath }/.state/`,
		trace:
			/^https?:\/\/localhost/.test( BASE_URL ) || ! CI
				? 'retain-on-first-failure'
				: 'retain-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 30 * 1000,
		navigationTimeout: 30 * 1000,
		channel: 'chrome',
		...devices[ 'Desktop Chrome' ],
	},
	snapshotPathTemplate: '{testDir}/{testFilePath}-snapshots/{arg}',
	projects: [
		{
			name: 'ui',
			testIgnore: '**/api-tests/**',
		},
		{
			name: 'api',
			testMatch: '**/api-tests/**',
		},
	],
};

module.exports = config;
