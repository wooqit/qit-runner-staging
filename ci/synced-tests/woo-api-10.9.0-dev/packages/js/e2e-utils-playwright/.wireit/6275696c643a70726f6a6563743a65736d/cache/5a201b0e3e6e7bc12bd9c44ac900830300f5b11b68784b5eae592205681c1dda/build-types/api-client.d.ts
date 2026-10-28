/**
 * Internal dependencies
 */
import type { Auth, ApiClient } from './types';
export type { BasicAuth, OAuth1Auth, Auth } from './types';
/**
 * Create an API client instance with the given configuration.
 *
 * @param baseURL - Base URL for the API
 * @param auth    - Auth object: { type: 'basic', username, password } or { type: 'oauth1', consumerKey, consumerSecret }
 * @return API client instance with HTTP methods
 */
export declare function createClient(baseURL: string, auth: Auth): ApiClient;
export declare const WC_API_PATH = "wc/v3";
export declare const WC_ADMIN_API_PATH = "wc-admin";
export declare const WP_API_PATH = "wp/v2";
//# sourceMappingURL=api-client.d.ts.map