/**
 * External dependencies
 */
import { defineConfig } from '@playwright/test';
import dotenv from 'dotenv';

dotenv.config( { path: __dirname + '/.env' } );

// QIT environment provides QIT_BASE_URL; fall back to BASE_URL or default.
if ( ! process.env.BASE_URL ) {
	process.env.BASE_URL =
		process.env.QIT_BASE_URL ||
		'http://localhost:' + ( process.env.WP_ENV_TESTS_PORT || '8086' );
	console.log(
		'BASE_URL is not set. Using: ' + process.env.BASE_URL
	);
}

const { BASE_URL, CI, E2E_MAX_FAILURES } = process.env;

const TESTS_ROOT_PATH = __dirname;
const TESTS_RESULTS_PATH = `${ TESTS_ROOT_PATH }/test-results`;

const reporter: any[] = [
	[
		'playwright-ctrf-json-reporter',
		{
			outputDir: process.env.CTRF_OUTPUT_DIR || './results',
			outputFile: 'ctrf-report.json',
		},
	],
	[ 'list' ],
	[
		'allure-playwright',
		{
			resultsDir: './results/allure',
			detail: true,
			suiteTitle: true,
		},
	],
	[
		'json',
		{
			outputFile: './results/test-results.json',
		},
	],
];

export default defineConfig( {
	timeout: 120 * 1000,
	expect: { timeout: CI ? 20 * 1000 : 10 * 1000 },
	outputDir: TESTS_RESULTS_PATH,
	testDir: `${ TESTS_ROOT_PATH }/tests`,
	testMatch: '**/api-tests/**',
	retries: CI ? 1 : 0,
	workers: 1,
	reportSlowTests: { max: 5, threshold: 30 * 1000 },
	reporter,
	maxFailures: E2E_MAX_FAILURES ? Number( E2E_MAX_FAILURES ) : 0,
	forbidOnly: !! CI,
	use: {
		baseURL: `${ BASE_URL }/`.replace( /\/+$/, '/' ),
		trace: 'retain-on-failure',
		actionTimeout: CI ? 20 * 1000 : 10 * 1000,
		navigationTimeout: CI ? 20 * 1000 : 10 * 1000,
	},
	projects: [
		{
			name: 'api',
			testMatch: '**/api-tests/**',
		},
	],
} );
