/**
 * Internal dependencies
 */
import { tags } from '../../fixtures/fixtures';
const { test, expect } = require( '@playwright/test' );
const { setComingSoon } = require( '../../utils/coming-soon' );

test.describe(
	'Customer-role users are blocked from accessing the WP Dashboard.',
	{ tag: [ tags.PAYMENTS, tags.SERVICES ] },
	() => {
		test.beforeAll( async ( { baseURL } ) => {
			await setComingSoon( { baseURL, enabled: 'no' } );
		} );
		test.use( { storageState: process.env.CUSTOMERSTATE } );

		const dashboardScreens = {
			'WP Admin home': 'wp-admin',
			'WP Admin profile page': 'wp-admin/profile.php',
			'WP Admin using ajax query param': 'wp-admin?wc-ajax=1',
		};

		for ( const [ description, path ] of Object.entries(
			dashboardScreens
		) ) {
			test( `Customer is redirected from ${ description } back to the My Account page.`, async ( {
				page,
			} ) => {
				await page.goto( path );
				expect( page.url() ).toContain( '/my-account/' );
			} );
		}
	}
);
