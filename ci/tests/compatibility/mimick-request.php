<?php

function create_mimick_server_script( string $filename, string $url = '/' ) {
	$server  = var_export( mimick_server( $url ), true );

	if ( $url === 'failed' ) {
		$extra = <<<'PHP'
global $wp_filter;

$wp_filter['init'][10]['cd_deactivate_plugin_stderr'] = [
	'function'      => static function() {
		trigger_error( 'Failed to activate plugin. After invoking "wp plugin activate", the plugin was not listed as active in the "active_plugins" option.', E_USER_ERROR );
	},
	'accepted_args' => 1,
];
PHP;
	} else {
		$extra = '';
	}

	$written = file_put_contents( $filename, <<<PHP
<?php
\$_SERVER = $server;

$extra
require '/var/www/html/index.php';
PHP
	);

	if ( $written === false ) {
		echo sprintf( 'Could not write go-to-frontpage.php. Is dir %s writable? %s', dirname( $filename ), is_writable( dirname( $filename ) ) ? 'Yes' : 'No' );
		die( 1 );
	}
}

/**
 * @param string $url Which URL to mimick. Defaults to "/" (Home)
 *                    Eg: my-account, cart, etc.
 *
 * @return array A $_SERVER array mimicking a request to the given URL of a WordPress website
 *               coming from a Chrome browser through Nginx. It can only work as unauthenticated
 *               requests from a logged-out user.
 */
function mimick_server( string $url = '/' ): array {
	if ( $url !== '/' ) {
		// foo => /foo/
		$url = '/' . trim( $url, '/' ) . '/';
	}

	return [
		'HOSTNAME'                       => 'e6ce62927a1a',
		'PHP_VERSION'                    => '7.4.28',
		'PHP_INI_DIR'                    => '/usr/local/etc/php',
		'PHP_LDFLAGS'                    => '-Wl,-O1 -pie',
		'PWD'                            => '/var/www/html',
		'HOME'                           => '/home/docker',
		'PHP_SHA256'                     => '9cc3b6f6217b60582f78566b3814532c4b71d517876c25013ae51811e65d8fce',
		'PHPIZE_DEPS'                    => 'autoconf 		dpkg-dev 		file 		g++ 		gcc 		libc-dev 		make 		pkg-config 		re2c',
		'PHP_URL'                        => 'https://www.php.net/distributions/php-7.4.28.tar.xz',
		'SHLVL'                          => '0',
		'PHP_CFLAGS'                     => '-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64',
		'WP_CLI_ALLOW_ROOT'              => '1',
		'PATH'                           => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
		'PHP_ASC_URL'                    => 'https://www.php.net/distributions/php-7.4.28.tar.xz.asc',
		'PHP_CPPFLAGS'                   => '-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64',
		'USER'                           => 'docker',
		'HTTP_COOKIE'                    => '',
		'HTTP_ACCEPT_ENCODING'           => 'gzip, deflate',
		'HTTP_ACCEPT_LANGUAGE'           => 'en;q=0.7',
		'HTTP_SEC_GPC'                   => '1',
		'HTTP_ACCEPT'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
		'HTTP_USER_AGENT'                => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36',
		'HTTP_UPGRADE_INSECURE_REQUESTS' => '1',
		'HTTP_DNT'                       => '1',
		'HTTP_CACHE_CONTROL'             => 'max-age=0',
		'HTTP_CONNECTION'                => 'keep-alive',
		'HTTP_HOST'                      => 'localhost',
		'PATH_INFO'                      => '',
		'SCRIPT_FILENAME'                => '/var/www/html/index.php',
		'REDIRECT_STATUS'                => '200',
		'SERVER_NAME'                    => 'localhost',
		'SERVER_PORT'                    => '80',
		'SERVER_ADDR'                    => '172.22.0.4',
		'REMOTE_PORT'                    => '41716',
		'REMOTE_ADDR'                    => '172.22.0.1',
		'SERVER_SOFTWARE'                => 'nginx/1.21.6',
		'GATEWAY_INTERFACE'              => 'CGI/1.1',
		'REQUEST_SCHEME'                 => 'http',
		'SERVER_PROTOCOL'                => 'HTTP/1.1',
		'DOCUMENT_ROOT'                  => '/var/www/html',
		'DOCUMENT_URI'                   => '/index.php',
		'REQUEST_URI'                    => $url,
		'SCRIPT_NAME'                    => '/index.php',
		'CONTENT_LENGTH'                 => '',
		'CONTENT_TYPE'                   => '',
		'REQUEST_METHOD'                 => 'GET',
		'QUERY_STRING'                   => '',
		'FCGI_ROLE'                      => 'RESPONDER',
		'PHP_SELF'                       => '/index.php',
		'REQUEST_TIME_FLOAT'             => 1664384552.534237,
		'REQUEST_TIME'                   => 1664384552,
		'argv'                           => array(),
		'argc'                           => 0,
	];
}