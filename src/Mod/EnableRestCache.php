<?php
namespace ChronoMeter\WpReactHelperPhp\Mod;

class EnableRestCache {
	/**
	 * Modify the cache control header for REST API responses.
	 * Default 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' for logged-in users,
	 *         'Cache-Control: no-cache, must-revalidate, max-age=0' for others.
	 *
	 * For effective caching, use `fetch()` with `cache: 'force-cache'` option in the client side.
	 *
	 * @see \wp_get_nocache_headers()
	 * @link https://developer.mozilla.org/docs/Web/HTTP/Headers/Cache-Control
	 * @link https://developer.mozilla.org/docs/Web/API/Request/cache
	 */
	public static function enable(): void {
		add_filter(
			'nocache_headers',
			function ( $headers ) {
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $headers['Cache-Control'] ) ) {
					// Remove the no-store directive.
					// The no-store response directive indicates that any caches of any kind (private or shared) should not store this response.
					$headers['Cache-Control'] = preg_replace( '/\bno-store(,\s*)?\b/', '', $headers['Cache-Control'] );
				}

				return $headers;
			}
		);
	}
}
