let config = require( '../../playwright.config.js' );
const { tags } = require( '../../fixtures/fixtures' );

const grepInvert = new RegExp(
	`${ tags.SKIP_ON_PRESSABLE }|${ tags.SKIP_ON_EXTERNAL_ENV }|${ tags.COULD_BE_LOWER_LEVEL_TEST }|${ tags.NON_CRITICAL }|${ tags.TO_BE_REMOVED }`
);

config = {
	...config,
	projects: [
		{
			name: 'ui',
			testIgnore: [
				'**/api-tests/**',
				'**/customize-store/**',
				'**/js-file-monitor/**',
			],
			grepInvert,
		},
		{
			name: 'api',
			testMatch: [ '**/api-tests/**' ],
			grepInvert,
		},
	],
};

module.exports = config;
