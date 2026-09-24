/**
 * Internal dependencies
 */
import {
	test,
	expect,
	request as apiRequest,
	tags,
} from '../../../fixtures/api-tests-fixtures';
import { setOption } from '../../../utils/options';

const { BASE_URL } = process.env;

const enableEmailImprovementsFeature = async () => {
	await setOption(
		apiRequest,
		BASE_URL,
		'woocommerce_feature_email_improvements_enabled',
		'yes'
	);
};

const disableEmailImprovementsFeature = async () => {
	await setOption(
		apiRequest,
		BASE_URL,
		'woocommerce_feature_email_improvements_enabled',
		'no'
	);
};

test.describe( 'Settings API tests: CRUD', () => {
	test.describe( 'List all settings groups', () => {
		test.beforeAll( disableEmailImprovementsFeature );
		test( 'can retrieve all settings groups', async ( { request } ) => {
			// call API to retrieve all settings groups
			const response = await request.get( './wp-json/wc/v3/settings' );
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON.length ).toBeGreaterThan( 0 );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'wc_admin',
						label: 'WooCommerce Admin',
						description: expect.stringContaining(
							'Settings for WooCommerce admin reporting'
						),
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'general',
						label: 'General',
						description: '',
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'products',
						label: 'Products',
						description: '',
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'tax',
						label: 'Tax',
						description: '',
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'shipping',
						label: 'Shipping',
						description: '',
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'checkout',
						label: 'Payments',
						description: '',
						parent_id: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'account',
						label: 'Accounts &amp; Privacy',
						description: '',
						parent_id: '',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email',
						label: 'Emails',
						description: '',
						parent_id: '',
						sub_groups: expect.arrayContaining( [
							'email_new_order',
						] ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'integration',
						label: 'Integration',
						description: '',
						parent_id: '',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'advanced',
						label: 'Advanced',
						description: '',
						parent_id: '',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_new_order',
						label: 'New order',
						description: expect.stringContaining(
							'New order emails are sent to chosen recipient(s) when a new order is received'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_cancelled_order',
						label: 'Cancelled order',
						description: expect.stringContaining(
							'Cancelled order emails are sent to chosen recipient(s) when orders have been marked cancelled'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_failed_order',
						label: 'Failed order',
						description: expect.stringContaining(
							'Failed order emails are sent to chosen recipient(s) when orders have been marked failed'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_on_hold_order',
						label: 'Order on-hold',
						description: expect.stringContaining(
							'This is an order notification sent to customers containing order details after an order is placed on-hold from Pending, Cancelled or Failed order status'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_processing_order',
						label: 'Processing order',
						description: expect.stringContaining(
							'This is an order notification sent to customers containing order details after payment'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_completed_order',
						label: 'Completed order',
						description: expect.stringContaining(
							'Order complete emails are sent to customers when their orders are marked completed and usually indicate that their orders have been shipped'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_refunded_order',
						label: 'Refunded order',
						description: expect.stringContaining(
							'Order refunded emails are sent to customers when their orders are refunded'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_invoice',
						label: 'Order details',
						description: expect.stringContaining(
							'Order detail emails can be sent to customers containing their order information and payment links'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_note',
						label: 'Customer note',
						description: expect.stringContaining(
							'Customer note emails are sent when you add a note to an order'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_reset_password',
						label: 'Reset password',
						description: expect.stringContaining(
							'Send an email to customers notifying them that their password has been reset'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_customer_new_account',
						label: 'New account',
						description: expect.stringContaining(
							'Send an email to customers notifying them that they have created an account'
						),
						parent_id: 'email',
						sub_groups: expect.arrayContaining( [] ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all settings options', () => {
		test( 'can retrieve all general settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/general'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON.length ).toBeGreaterThan( 0 );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_store_address',
						label: 'Address line 1',
						description: expect.stringContaining(
							'The street address for your business location'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'The street address for your business location'
						),
						value: expect.any( String ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_store_address_2',
						label: 'Address line 2',
						description: expect.stringContaining(
							'An additional, optional address line for your business location'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'An additional, optional address line for your business location'
						),
						value: '',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_store_city',
						label: 'City',
						description: expect.stringContaining(
							'The city in which your business is located'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'The city in which your business is located'
						),
						value: expect.any( String ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_default_country',
						label: 'Country / State',
						description: expect.stringContaining(
							'The country and state or province, if any, in which your business is located'
						),
						type: 'select',
						default: 'US:CA',
						tip: expect.stringContaining(
							'The country and state or province, if any, in which your business is located'
						),
						value: 'US:CA',
						options: expect.objectContaining( {
							'US:CA': 'United States (US) - California',
							'US:NY': 'United States (US) - New York',
							'CA:ON': 'Canada - Ontario',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_store_postcode',
						label: 'Postcode / ZIP',
						description: expect.stringContaining(
							'The postal code, if any, in which your business is located'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'The postal code, if any, in which your business is located'
						),
						value: expect.any( String ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_allowed_countries',
						label: 'Selling location(s)',
						description: expect.stringContaining(
							'This option lets you limit which countries you are willing to sell to'
						),
						type: 'select',
						default: 'all',
						tip: expect.stringContaining(
							'This option lets you limit which countries you are willing to sell to'
						),
						value: 'all',
						options: expect.objectContaining( {
							all: 'Sell to all countries',
							all_except:
								'Sell to all countries, except for&hellip;',
							specific: 'Sell to specific countries',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_all_except_countries',
						label: 'Sell to all countries, except for&hellip;',
						description: '',
						type: 'multiselect',
						default: '',
						value: expect.anything(),
						options: expect.objectContaining( {
							US: 'United States (US)',
							CA: 'Canada',
							GB: 'United Kingdom (UK)',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_specific_allowed_countries',
						label: 'Sell to specific countries',
						description: '',
						type: 'multiselect',
						default: '',
						value: expect.anything(),
						options: expect.objectContaining( {
							US: 'United States (US)',
							CA: 'Canada',
							GB: 'United Kingdom (UK)',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_ship_to_countries',
						label: 'Shipping location(s)',
						description: expect.stringContaining(
							'Choose which countries you want to ship to, or choose to ship to all locations you sell to'
						),
						type: 'select',
						default: '',
						tip: expect.stringContaining(
							'Choose which countries you want to ship to, or choose to ship to all locations you sell to'
						),
						value: expect.any( String ),
						options: expect.objectContaining( {
							'': 'Ship to all countries you sell to',
							all: 'Ship to all countries',
							specific: 'Ship to specific countries only',
							disabled:
								'Disable shipping &amp; shipping calculations',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_specific_ship_to_countries',
						label: 'Ship to specific countries',
						description: '',
						type: 'multiselect',
						default: '',
						value: expect.anything(),
						options: expect.objectContaining( {
							US: 'United States (US)',
							CA: 'Canada',
							GB: 'United Kingdom (UK)',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_default_customer_address',
						label: 'Default customer location',
						description: '',
						type: 'select',
						default: 'base',
						tip: expect.stringContaining(
							'This option determines a customers default location'
						),
						value: 'base',
						options: expect.objectContaining( {
							'': 'No location by default',
							base: 'Shop country/region',
							geolocation: 'Geolocate',
							geolocation_ajax:
								'Geolocate (with page caching support)',
						} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_calc_taxes',
						label: 'Enable taxes',
						description: expect.stringContaining(
							'Enable tax rates and calculations'
						),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringContaining(
							'Rates will be configurable and taxes will be calculated during checkout'
						),
						value: expect.any( String ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_coupons',
						label: 'Enable coupons',
						description: expect.stringContaining(
							'Enable the use of coupon codes'
						),
						type: 'checkbox',
						default: 'yes',
						tip: expect.stringContaining(
							'Coupons can be applied from the cart and checkout pages'
						),
						value: 'yes',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_calc_discounts_sequentially',
						label: '',
						description: expect.stringContaining(
							'Calculate coupon discounts sequentially'
						),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringContaining(
							'When applying multiple coupons, apply the first coupon to the full price and the second coupon to the discounted price and so on'
						),
						value: 'no',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_currency',
						label: 'Currency',
						description: expect.stringContaining(
							'This controls what currency prices are listed at in the catalog and which currency gateways will take payments in'
						),
						type: 'select',
						default: 'USD',
						options: expect.objectContaining( {
							USD: 'United States (US) dollar (&#36;) — USD',
							EUR: 'Euro (&euro;) — EUR',
							GBP: 'Pound sterling (&pound;) — GBP',
						} ),
						tip: expect.stringContaining(
							'This controls what currency prices are listed at in the catalog and which currency gateways will take payments in'
						),
						value: 'USD',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_currency_pos',
						label: 'Currency position',
						description: expect.stringContaining(
							'This controls the position of the currency symbol'
						),
						type: 'select',
						default: 'left',
						options: expect.objectContaining( {
							left: 'Left',
							right: 'Right',
							left_space: 'Left with space',
							right_space: 'Right with space',
						} ),
						tip: expect.stringContaining(
							'This controls the position of the currency symbol'
						),
						value: 'left',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_price_thousand_sep',
						label: 'Thousand separator',
						description: expect.stringContaining(
							'This sets the thousand separator of displayed prices'
						),
						type: 'text',
						default: ',',
						tip: expect.stringContaining(
							'This sets the thousand separator of displayed prices'
						),
						value: ',',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_price_decimal_sep',
						label: 'Decimal separator',
						description: expect.stringContaining(
							'This sets the decimal separator of displayed prices'
						),
						type: 'text',
						default: '.',
						tip: expect.stringContaining(
							'This sets the decimal separator of displayed prices'
						),
						value: '.',
					} ),
				] )
			);
		} );
	} );

	test.describe( 'Retrieve a settings option', () => {
		test( 'can retrieve a settings option', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/general/woocommerce_allowed_countries'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( responseJSON ).toEqual(
				expect.objectContaining( {
					id: 'woocommerce_allowed_countries',
					label: 'Selling location(s)',
					description: expect.stringContaining(
						'This option lets you limit which countries you are willing to sell to'
					),
					type: 'select',
					default: 'all',
					options: expect.objectContaining( {
						all: 'Sell to all countries',
						all_except: 'Sell to all countries, except for&hellip;',
						specific: 'Sell to specific countries',
					} ),
					tip: expect.stringContaining(
						'This option lets you limit which countries you are willing to sell to'
					),
					value: 'all',
					group_id: 'general',
				} )
			);
		} );
	} );

	test.describe( 'Update a settings option', () => {
		test( 'can update a settings option', async ( { request } ) => {
			// call API to update settings options
			const response = await request.put(
				'./wp-json/wc/v3/settings/general/woocommerce_allowed_countries',
				{
					data: {
						value: 'all_except',
					},
				}
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( responseJSON ).toEqual(
				expect.objectContaining( {
					id: 'woocommerce_allowed_countries',
					label: 'Selling location(s)',
					description: expect.stringContaining(
						'This option lets you limit which countries you are willing to sell to'
					),
					type: 'select',
					default: 'all',
					options: expect.objectContaining( {
						all: 'Sell to all countries',
						all_except: 'Sell to all countries, except for&hellip;',
						specific: 'Sell to specific countries',
					} ),
					tip: expect.stringContaining(
						'This option lets you limit which countries you are willing to sell to'
					),
					value: 'all_except',
					group_id: 'general',
				} )
			);
		} );
	} );

	test.describe( 'Batch Update a settings option', () => {
		test( 'can batch update settings options', async ( { request } ) => {
			// call API to update settings options
			const response = await request.post(
				'./wp-json/wc/v3/settings/general/batch',
				{
					data: {
						update: [
							{
								id: 'woocommerce_allowed_countries',
								value: 'all_except',
							},
							{
								id: 'woocommerce_currency',
								value: 'GBP',
							},
						],
					},
				}
			);
			expect( response.status() ).toEqual( 200 );

			// retrieve the updated settings values
			const countriesUpdatedResponse = await request.get(
				'./wp-json/wc/v3/settings/general/woocommerce_allowed_countries'
			);
			const countriesUpdatedResponseJSON =
				await countriesUpdatedResponse.json();
			expect( countriesUpdatedResponseJSON.value ).toEqual(
				'all_except'
			);

			const currencyUpdatedResponse = await request.get(
				'./wp-json/wc/v3/settings/general/woocommerce_currency'
			);
			const currencyUpdatedResponseJSON =
				await currencyUpdatedResponse.json();
			expect( currencyUpdatedResponseJSON.value ).toEqual( 'GBP' );

			// call API to restore the settings options
			await request.put( './wp-json/wc/v3/settings/general/batch', {
				data: {
					update: [
						{
							id: 'woocommerce_allowed_countries',
							value: 'all',
						},
						{
							id: 'woocommerce_currency',
							value: 'USD',
						},
					],
				},
			} );
		} );
	} );

	test.describe( 'List all Products settings options', () => {
		test( 'can retrieve all products settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/products'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON.length ).toBeGreaterThan( 0 );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_shop_page_id',
						label: 'Shop page',
						type: 'select',
						default: '',
						tip: expect.stringContaining(
							'This sets the base page of your shop - this is where your product archive will be'
						),
						value: expect.any( String ),
						options: expect.objectContaining( {} ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_cart_redirect_after_add',
						label: 'Add to cart behaviour',
						description: expect.stringContaining(
							'Redirect to the cart page after successful addition'
						),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_ajax_add_to_cart',
						label: '',
						description: expect.stringContaining(
							'Enable AJAX add to cart buttons on archives'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_placeholder_image',
						label: 'Placeholder image',
						description: '',
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'This is the attachment ID, or image URL, used for placeholder images in the product catalog'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_weight_unit',
						label: 'Weight unit',
						description: expect.stringContaining(
							'This controls what unit you will define weights in'
						),
						type: 'select',
						default: 'lbs',
						options: expect.objectContaining( {
							kg: 'kg',
							g: 'g',
							lbs: 'lbs',
							oz: 'oz',
						} ),
						tip: expect.stringContaining(
							'This controls what unit you will define weights in'
						),
						value: 'lbs',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_dimension_unit',
						label: 'Dimensions unit',
						description: expect.stringContaining(
							'This controls what unit you will define lengths in'
						),
						type: 'select',
						default: 'in',
						options: expect.objectContaining( {
							m: 'm',
							cm: 'cm',
							mm: 'mm',
							in: 'in',
							yd: 'yd',
						} ),
						tip: expect.stringContaining(
							'This controls what unit you will define lengths in'
						),
						value: 'in',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_reviews',
						label: 'Enable reviews',
						description: expect.stringContaining( 'Enable product reviews' ),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_review_rating_verification_label',
						label: '',
						description: expect.stringContaining(
							'Show "verified owner" label on customer reviews'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_review_rating_verification_required',
						label: '',
						description: expect.stringContaining(
							'Reviews can only be left by "verified owners"'
						),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_review_rating',
						label: 'Product ratings',
						description: expect.stringContaining(
							'Enable star rating on reviews'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_review_rating_required',
						label: '',
						description: expect.stringContaining(
							'Star ratings should be required, not optional'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_manage_stock',
						label: 'Manage stock',
						description: expect.stringContaining( 'Enable stock management' ),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_hold_stock_minutes',
						label: 'Hold stock (minutes)',
						description: expect.stringContaining(
							'Hold stock (for unpaid orders) for x minutes'
						),
						type: 'number',
						default: '60',
						// `value` is the live option value, which extensions such as
						// WooCommerce Subscriptions legitimately override. Assert the
						// immutable core `default` ('60') but not the mutable value.
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_notify_low_stock',
						label: 'Notifications',
						description: expect.stringContaining(
							'Enable low stock notifications'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_notify_no_stock',
						label: '',
						description: expect.stringContaining(
							'Enable out of stock notifications'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_stock_email_recipient',
						label: 'Notification recipient(s)',
						description: expect.stringContaining(
							'Enter recipients'
						),
						type: 'text',
						default: expect.any( String ),
						tip: expect.stringContaining( 'Enter recipients' ),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_notify_low_stock_amount',
						label: 'Low stock threshold',
						description: expect.stringContaining(
							'When product stock reaches this amount you will be notified via email'
						),
						type: 'number',
						default: '2',
						tip: expect.stringContaining(
							'When product stock reaches this amount you will be notified via email'
						),
						value: '2',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_notify_no_stock_amount',
						label: 'Out of stock threshold',
						description: expect.stringContaining(
							'When product stock reaches this amount the stock status will change to "out of stock" and you will be notified via email'
						),
						type: 'number',
						default: '0',
						tip: expect.stringContaining(
							'When product stock reaches this amount the stock status will change to "out of stock" and you will be notified via email'
						),
						value: '0',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_hide_out_of_stock_items',
						label: 'Out of stock visibility',
						description: expect.stringContaining(
							'Hide out of stock items from the catalog'
						),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_stock_format',
						label: 'Stock display format',
						description: expect.stringContaining(
							'This controls how stock quantities are displayed on the frontend'
						),
						type: 'select',
						default: '',
						options: expect.objectContaining( {
							'': 'Always show quantity remaining in stock e.g. "12 in stock"',
							low_amount:
								'Only show quantity remaining in stock when low e.g. "Only 2 left in stock"',
							no_amount: 'Never show quantity remaining in stock',
						} ),
						tip: expect.stringContaining(
							'This controls how stock quantities are displayed on the frontend'
						),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_file_download_method',
						label: 'File download method',
						description: expect.stringContaining(
							'If you are using X-Accel-Redirect download method along with NGINX server'
						),
						type: 'select',
						default: 'force',
						options: expect.objectContaining( {
							force: 'Force downloads',
							xsendfile: 'X-Accel-Redirect/X-Sendfile',
							redirect: 'Redirect only (Insecure)',
						} ),
						tip: expect.stringContaining(
							'Forcing downloads will keep URLs hidden, but some servers may serve large files unreliably. If supported,'
						),
						value: 'force',
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Tax settings options', () => {
		test( 'can retrieve all tax settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/tax'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON.length ).toBeGreaterThan( 0 );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_prices_include_tax',
						label: 'Prices entered with tax',
						description: '',
						type: 'radio',
						default: 'no',
						options: expect.objectContaining( {
							yes: 'Yes, I will enter prices inclusive of tax',
							no: 'No, I will enter prices exclusive of tax',
						} ),
						tip: expect.stringContaining(
							'This option is important as it will affect how you input prices'
						),
						value: 'no',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_based_on',
						label: 'Calculate tax based on',
						description: '',
						type: 'select',
						default: 'shipping',
						options: expect.objectContaining( {
							shipping: 'Customer shipping address',
							billing: 'Customer billing address',
							base: 'Shop base address',
						} ),
						tip: expect.stringContaining(
							'This option determines which address is used to calculate tax'
						),
						value: 'shipping',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_shipping_tax_class',
						label: 'Shipping tax class',
						description: expect.stringContaining(
							'Optionally control which tax class shipping gets, or leave it so shipping tax is based on the cart items themselves'
						),
						type: 'select',
						default: 'inherit',
						options: expect.objectContaining( {
							inherit: 'Shipping tax class based on cart items',
							'': 'Standard',
						} ),
						tip: expect.stringContaining(
							'Optionally control which tax class shipping gets, or leave it so shipping tax is based on the cart items themselves'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_round_at_subtotal',
						label: 'Rounding',
						description: expect.stringContaining(
							'Round tax at subtotal level, instead of rounding per line'
						),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_classes',
						label: 'Additional tax classes',
						description: '',
						type: 'textarea',
						default: '',
						tip: expect.stringContaining(
							'List additional tax classes you need below'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_display_shop',
						label: 'Display prices in the shop',
						description: '',
						type: 'select',
						default: 'excl',
						options: expect.objectContaining( {
							incl: 'Including tax',
							excl: 'Excluding tax',
						} ),
						value: 'excl',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_display_cart',
						label: 'Display prices during cart and checkout',
						description: '',
						type: 'select',
						default: 'excl',
						options: expect.objectContaining( {
							incl: 'Including tax',
							excl: 'Excluding tax',
						} ),
						value: 'excl',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_price_display_suffix',
						label: 'Price display suffix',
						description: '',
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Define text to show after your product prices'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_tax_total_display',
						label: 'Display tax totals',
						description: '',
						type: 'select',
						default: 'itemized',
						options: expect.objectContaining( {
							single: 'As a single total',
							itemized: 'Itemized',
						} ),
						value: 'itemized',
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Shipping settings options', () => {
		test( 'can retrieve all shipping settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/shipping'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON.length ).toBeGreaterThan( 0 );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_shipping_calc',
						label: 'Calculations',
						description: expect.stringContaining(
							'Enable the shipping calculator on the cart page'
						),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_shipping_cost_requires_address',
						label: '',
						description: expect.stringContaining(
							'Hide shipping costs until an address is entered'
						),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_ship_to_destination',
						label: 'Shipping destination',
						description: expect.stringContaining(
							'This controls which shipping address is used by default'
						),
						type: 'radio',
						default: 'billing',
						options: expect.objectContaining( {
							shipping: 'Default to customer shipping address',
							billing: 'Default to customer billing address',
							billing_only:
								'Force shipping to the customer billing address',
						} ),
						tip: expect.stringContaining(
							'This controls which shipping address is used by default'
						),
						value: 'billing',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_shipping_debug_mode',
						label: 'Debug mode',
						description: expect.stringContaining( 'Enable debug mode' ),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringContaining(
							'Enable shipping debug mode to show matching shipping zones and to bypass shipping rate cache'
						),
						value: 'no',
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Checkout settings options', () => {
		test( 'can retrieve all checkout settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/checkout'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual( expect.arrayContaining( [] ) );
		} );
	} );

	test.describe( 'List all Account settings options', () => {
		test( 'can retrieve all account settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/account'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_guest_checkout',
						label: 'Checkout',
						description: expect.stringContaining( 'Enable guest checkout' ),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_checkout_login_reminder',
						label: 'Login',
						description: expect.stringContaining(
							'Enable log-in during checkout'
						),
						type: 'checkbox',
						default: 'no',
						value: expect.stringMatching( /no|yes/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_signup_and_login_from_checkout',
						label: 'Account creation',
						description: expect.stringContaining( 'During checkout' ),
						type: 'checkbox',
						default: 'no',
						value: expect.stringMatching( /no|yes/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_enable_myaccount_registration',
						label: 'Account creation',
						description: expect.stringContaining( 'On "My account" page' ),
						type: 'checkbox',
						default: 'no',
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_registration_generate_password',
						label: 'Account creation options',
						description: expect.stringContaining( 'Send password setup link' ),
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_erasure_request_removes_order_data',
						label: 'Account erasure requests',
						description: expect.stringContaining(
							'Remove personal data from orders on request'
						),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringMatching(
							'When handling an <a href="[^"]*wp-admin/erase-personal-data.php">account erasure request</a>, should personal data within orders be retained or removed?'
						),
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_erasure_request_removes_download_data',
						label: '',
						description: expect.stringContaining(
							'Remove access to downloads on request'
						),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringMatching(
							'When handling an <a href="[^"]*wp-admin/erase-personal-data.php">account erasure request</a>, should access to downloadable files be revoked and download logs cleared?'
						),
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_allow_bulk_remove_personal_data',
						label: 'Personal data removal',
						description: expect.stringContaining(
							'Allow personal data to be removed in bulk from orders'
						),
						type: 'checkbox',
						default: 'no',
						tip: expect.stringContaining(
							'Adds an option to the orders screen for removing personal data in bulk'
						),
						value: 'no',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_registration_privacy_policy_text',
						label: 'Registration privacy policy',
						description: '',
						type: 'textarea',
						default:
							'Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our [privacy_policy].',
						tip: expect.stringContaining(
							'Optionally add some text about your store privacy policy to show on account registration forms'
						),
						value: 'Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our [privacy_policy].',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_checkout_privacy_policy_text',
						label: 'Checkout privacy policy',
						description: '',
						type: 'textarea',
						default:
							'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our [privacy_policy].',
						tip: expect.stringContaining(
							'Optionally add some text about your store privacy policy to show during checkout'
						),
						value: 'Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our [privacy_policy].',
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email settings options', () => {
		test.skip( 'can retrieve all email settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_from_name',
						label: '"From" name',
						description: expect.any( String ),
						type: 'text',
						default: expect.any( String ),
						tip: expect.any( String ),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_from_address',
						label: '"From" address',
						description: '',
						type: 'email',
						default: expect.any( String ),
						tip: '',
						value: expect.any( String ),
					} ),
				] )
			);
			// woocommerce_email_header_image is custom slotfill and not included in the response
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_header_image_width',
						label: 'Logo width (px)',
						type: 'number',
						default: '120',
						value: expect.anything(), // value could be number or string depending on environment
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_header_alignment',
						label: 'Header alignment',
						description: '',
						type: 'select',
						default: 'left',
						value: 'left',
					} ),
				] )
			);
			// woocommerce_email_font_family is custom slotfill and not included in the response
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_footer_text',
						label: 'Footer text',
						description: expect.stringContaining(
							'This text will appear in the footer of all of your WooCommerce emails'
						),
						type: 'textarea',
						default: '{site_title}<br />{store_address}',
						tip: expect.stringContaining(
							'This text will appear in the footer of all of your WooCommerce emails'
						),
						value: '{site_title}<br />{store_address}',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_base_color',
						label: 'Accent',
						description: expect.stringContaining(
							'Customize the color of your buttons and links'
						),
						type: 'color',
						default: '#720eec',
						tip: expect.stringContaining(
							'Customize the color of your buttons and links'
						),
						value: expect.stringMatching( /^#[0-9A-Fa-f]{6}$/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_background_color',
						label: 'Email background',
						description: expect.stringContaining(
							'Select a color for the background of your emails'
						),
						type: 'color',
						default: '#f7f7f7',
						tip: expect.stringContaining(
							'Select a color for the background of your emails'
						),
						value: expect.stringMatching( /^#[0-9A-Fa-f]{6}$/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_body_background_color',
						label: 'Content background',
						description: expect.stringContaining(
							'Choose a background color for the content area of your emails'
						),
						type: 'color',
						default: '#ffffff',
						tip: expect.stringContaining(
							'Choose a background color for the content area of your emails'
						),
						value: expect.stringMatching( /^#[0-9A-Fa-f]{6}$/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_text_color',
						label: 'Heading & text',
						description: expect.stringContaining(
							'Set the color of your headings and text'
						),
						type: 'color',
						default: '#3c3c3c',
						tip: expect.stringContaining(
							'Set the color of your headings and text'
						),
						value: expect.stringMatching( /^#[0-9A-Fa-f]{6}$/ ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_email_footer_text_color',
						label: 'Secondary text',
						description: expect.stringContaining(
							'Choose a color for your secondary text, such as your footer content'
						),
						type: 'color',
						default: '#3c3c3c',
						tip: expect.stringContaining(
							'Choose a color for your secondary text, such as your footer content'
						),
						value: expect.stringMatching( /^#[0-9A-Fa-f]{6}$/ ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Advanced settings options', () => {
		test( 'can retrieve all advanced settings', async ( { request } ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/advanced'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_cart_page_id',
						label: 'Cart page',
						description: expect.stringContaining(
							'Page where shoppers review their shopping cart'
						),
						type: 'select',
						default: '',
						tip: expect.stringContaining(
							'Page where shoppers review their shopping cart'
						),
						value: expect.any( String ),
						options: expect.any( Object ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_checkout_page_id',
						label: 'Checkout page',
						description: expect.stringContaining(
							'Page where shoppers go to finalize their purchase'
						),
						type: 'select',
						default: expect.any( Number ),
						tip: expect.stringContaining(
							'Page where shoppers go to finalize their purchase'
						),
						value: expect.any( String ),
						options: expect.any( Object ),
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_page_id',
						label: 'My account page',
						description: expect.stringContaining(
							'Page contents: [woocommerce_my_account]'
						),
						type: 'select',
						default: '',
						tip: expect.stringContaining(
							'Page contents: [woocommerce_my_account]'
						),
						value: expect.any( String ),
						options: expect.any( Object ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_checkout_pay_endpoint',
						label: 'Pay',
						description: expect.stringContaining(
							'Endpoint for the "Checkout'
						),
						type: 'text',
						default: 'order-pay',
						tip: expect.stringContaining( 'Endpoint for the "Checkout' ),
						value: 'order-pay',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_checkout_order_received_endpoint',
						label: 'Order received',
						description: expect.stringContaining(
							'Endpoint for the "Checkout'
						),
						type: 'text',
						default: 'order-received',
						tip: expect.stringContaining( 'Endpoint for the "Checkout' ),
						value: 'order-received',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_add_payment_method_endpoint',
						label: 'Add payment method',
						description: expect.stringContaining(
							'Endpoint for the "Checkout'
						),
						type: 'text',
						default: 'add-payment-method',
						tip: expect.stringContaining( 'Endpoint for the "Checkout' ),
						value: 'add-payment-method',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_delete_payment_method_endpoint',
						label: 'Delete payment method',
						description: expect.stringContaining(
							'Endpoint for the delete payment method page'
						),
						type: 'text',
						default: 'delete-payment-method',
						tip: expect.stringContaining(
							'Endpoint for the delete payment method page'
						),
						value: 'delete-payment-method',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_orders_endpoint',
						label: 'Orders',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'orders',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'orders',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_view_order_endpoint',
						label: 'View order',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'view-order',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'view-order',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_downloads_endpoint',
						label: 'Downloads',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'downloads',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'downloads',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_edit_account_endpoint',
						label: 'Edit account',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'edit-account',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'edit-account',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_edit_address_endpoint',
						label: 'Addresses',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'edit-address',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'edit-address',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_payment_methods_endpoint',
						label: 'Payment methods',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'payment-methods',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'payment-methods',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_myaccount_lost_password_endpoint',
						label: 'Lost password',
						description: expect.stringContaining(
							'Endpoint for the "My account'
						),
						type: 'text',
						default: 'lost-password',
						tip: expect.stringContaining( 'Endpoint for the "My account' ),
						value: 'lost-password',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'woocommerce_logout_endpoint',
						label: 'Logout',
						description: expect.stringContaining(
							'Endpoint for the triggering logout. You can add this to your menus via a custom link:'
						),
						type: 'text',
						default: 'customer-logout',
						tip: expect.stringContaining(
							'Endpoint for the triggering logout. You can add this to your menus via a custom link:'
						),
						value: 'customer-logout',
					} ),
				] )
			);

			// Skip these tests in WPCOM because they're not configurable there by design.
			// eslint-disable-next-line playwright/no-conditional-in-test
			if ( ! process.env.IS_WPCOM ) {
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'woocommerce_allow_tracking',
							label: 'Enable tracking',
							description: expect.stringContaining(
								'Allow usage of WooCommerce to be tracked'
							),
							type: 'checkbox',
							default: 'no',
							tip: expect.stringContaining(
								'To opt out, leave this box unticked'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'woocommerce_show_marketplace_suggestions',
							label: 'Show Suggestions',
							description: expect.stringContaining(
								'Display suggestions within WooCommerce'
							),
							type: 'checkbox',
							default: 'yes',
							tip: expect.stringContaining(
								'Leave this box unchecked if you do not want to pull suggested extensions from WooCommerce.com'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'woocommerce_analytics_enabled',
							label: expect.stringContaining( 'Analytics' ),
							description: expect.stringContaining(
								'Enable WooCommerce Analytics'
							),
							type: 'checkbox',
							default: 'yes',
							value: expect.any( String ),
						} ),
					] )
				);
			}
		} );
	} );

	test.describe( 'List all Email New Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test(
			'can retrieve all email new order settings',
			{ tag: [ tags.SKIP_ON_PRESSABLE, tags.SKIP_ON_WPCOM ] },
			async ( { request } ) => {
				// call API to retrieve all settings options
				const response = await request.get(
					'./wp-json/wc/v3/settings/email_new_order'
				);
				const responseJSON = await response.json();
				expect( response.status() ).toEqual( 200 );
				expect( Array.isArray( responseJSON ) ).toBe( true );
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'enabled',
							label: 'Enable/Disable',
							description: '',
							type: 'checkbox',
							default: 'yes',
							value: 'yes',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'recipient',
							label: 'Recipient(s)',
							description: expect.stringContaining(
								'Enter recipients (comma separated) for this email. Defaults to'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter recipients (comma separated) for this email. Defaults to'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'subject',
							label: 'Subject',
							description: expect.stringContaining(
								'Available placeholders:'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining( 'Available placeholders:' ),
							value: '',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'heading',
							label: 'Email heading',
							description: expect.stringContaining(
								'Available placeholders:'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining( 'Available placeholders:' ),
							value: '',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'additional_content',
							label: 'Additional content',
							description: expect.stringContaining(
								'Text to appear below the main email content'
							),
							type: 'textarea',
							default: 'Congratulations on the sale!',
							tip: expect.stringContaining(
								'Text to appear below the main email content'
							),
							value: 'Congratulations on the sale!',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'email_type',
							label: 'Email type',
							description: expect.stringContaining(
								'Choose which format of email to send'
							),
							type: 'select',
							default: 'html',
							options: expect.objectContaining( {
								plain: 'Plain text',
								html: 'HTML',
								multipart: 'Multipart',
							} ),
							tip: expect.stringContaining(
								'Choose which format of email to send'
							),
							value: 'html',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'cc',
							label: 'Cc(s)',
							description: expect.stringContaining(
								'Enter Cc recipients (comma-separated) for this email.'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter Cc recipients (comma-separated) for this email.'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'bcc',
							label: 'Bcc(s)',
							description: expect.stringContaining(
								'Enter Bcc recipients (comma-separated) for this email.'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter Bcc recipients (comma-separated) for this email.'
							),
							value: expect.any( String ),
						} ),
					] )
				);
			}
		);
	} );

	test.describe( 'List all Email Failed Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test(
			'can retrieve all email failed order settings',
			{ tag: [ tags.SKIP_ON_PRESSABLE, tags.SKIP_ON_WPCOM ] },
			async ( { request } ) => {
				// call API to retrieve all settings options
				const response = await request.get(
					'./wp-json/wc/v3/settings/email_failed_order'
				);
				const responseJSON = await response.json();
				expect( response.status() ).toEqual( 200 );
				expect( Array.isArray( responseJSON ) ).toBe( true );
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'enabled',
							label: 'Enable/Disable',
							description: '',
							type: 'checkbox',
							default: 'yes',
							value: 'yes',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'recipient',
							label: 'Recipient(s)',
							description: expect.stringContaining(
								'Enter recipients (comma separated) for this email. Defaults to'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter recipients (comma separated) for this email. Defaults to'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'subject',
							label: 'Subject',
							description: expect.stringContaining(
								'Available placeholders:'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining( 'Available placeholders:' ),
							value: '',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'heading',
							label: 'Email heading',
							description: expect.stringContaining(
								'Available placeholders:'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining( 'Available placeholders:' ),
							value: '',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'additional_content',
							label: 'Additional content',
							description: expect.stringContaining(
								'Text to appear below the main email content'
							),
							type: 'textarea',
							default:
								'We hope they’ll be back soon! Read more about <a href="https://woocommerce.com/document/managing-orders/">troubleshooting failed payments</a>.',
							tip: expect.stringContaining(
								'Text to appear below the main email content'
							),
							value: 'We hope they’ll be back soon! Read more about <a href="https://woocommerce.com/document/managing-orders/">troubleshooting failed payments</a>.',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'email_type',
							label: 'Email type',
							description: expect.stringContaining(
								'Choose which format of email to send'
							),
							type: 'select',
							default: 'html',
							options: expect.objectContaining( {
								plain: 'Plain text',
								html: 'HTML',
								multipart: 'Multipart',
							} ),
							tip: expect.stringContaining(
								'Choose which format of email to send'
							),
							value: 'html',
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'cc',
							label: 'Cc(s)',
							description: expect.stringContaining(
								'Enter Cc recipients (comma-separated) for this email.'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter Cc recipients (comma-separated) for this email.'
							),
							value: expect.any( String ),
						} ),
					] )
				);
				expect( responseJSON ).toEqual(
					expect.arrayContaining( [
						expect.objectContaining( {
							id: 'bcc',
							label: 'Bcc(s)',
							description: expect.stringContaining(
								'Enter Bcc recipients (comma-separated) for this email.'
							),
							type: 'text',
							default: '',
							tip: expect.stringContaining(
								'Enter Bcc recipients (comma-separated) for this email.'
							),
							value: expect.any( String ),
						} ),
					] )
				);
			}
		);
	} );

	test.describe( 'List all Email Customer On Hold Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer on hold order settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_on_hold_order'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'Thanks again! If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks again! If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Processing Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer processing order settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_processing_order'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'Thanks again! If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks again! If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Completed Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer completed order settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_completed_order'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'Thanks again! If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks again! If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Refunded Order settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer refunded order settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_refunded_order'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject_full',
						label: 'Full refund subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject_partial',
						label: 'Partial refund subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading_full',
						label: 'Full refund email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading_partial',
						label: 'Partial refund email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Invoice settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer invoice settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_invoice'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject_paid',
						label: 'Subject (paid)',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading_paid',
						label: 'Email heading (paid)',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);

			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'Thanks again! If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks again! If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Note settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer note settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_note'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default:
							'Thanks again! If you need any help with your order, please contact us at {store_email}.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks again! If you need any help with your order, please contact us at {store_email}.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer Reset Password settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer reset password settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_reset_password'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default: 'Thanks for reading.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'Thanks for reading.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );

	test.describe( 'List all Email Customer New Account settings', () => {
		test.beforeAll( enableEmailImprovementsFeature );
		test.afterAll( disableEmailImprovementsFeature );
		test( 'can retrieve all email customer new account settings', async ( {
			request,
		} ) => {
			// call API to retrieve all settings options
			const response = await request.get(
				'./wp-json/wc/v3/settings/email_customer_new_account'
			);
			const responseJSON = await response.json();
			expect( response.status() ).toEqual( 200 );
			expect( Array.isArray( responseJSON ) ).toBe( true );
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'enabled',
						label: 'Enable/Disable',
						description: '',
						type: 'checkbox',
						default: 'yes',
						value: 'yes',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'subject',
						label: 'Subject',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'heading',
						label: 'Email heading',
						description: expect.stringContaining(
							'Available placeholders:'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining( 'Available placeholders:' ),
						value: '',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'additional_content',
						label: 'Additional content',
						description: expect.stringContaining(
							'Text to appear below the main email content'
						),
						type: 'textarea',
						default: 'We look forward to seeing you soon.',
						tip: expect.stringContaining(
							'Text to appear below the main email content'
						),
						value: 'We look forward to seeing you soon.',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'email_type',
						label: 'Email type',
						description: expect.stringContaining(
							'Choose which format of email to send'
						),
						type: 'select',
						default: 'html',
						options: expect.objectContaining( {
							plain: 'Plain text',
							html: 'HTML',
							multipart: 'Multipart',
						} ),
						tip: expect.stringContaining(
							'Choose which format of email to send'
						),
						value: 'html',
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'cc',
						label: 'Cc(s)',
						description: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Cc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
			expect( responseJSON ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( {
						id: 'bcc',
						label: 'Bcc(s)',
						description: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						type: 'text',
						default: '',
						tip: expect.stringContaining(
							'Enter Bcc recipients (comma-separated) for this email.'
						),
						value: expect.any( String ),
					} ),
				] )
			);
		} );
	} );
} );
