/**
 * Encode basic auth username and password to be used in HTTP Authorization header.
 */
export const encodeCredentials = ( username: string, password: string ) => {
	return Buffer.from( `${ username }:${ password }` ).toString( 'base64' );
};
