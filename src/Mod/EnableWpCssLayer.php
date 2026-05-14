<?php
namespace ChronoMeter\WpReactHelperPhp\Mod;

class EnableWpCssLayer {
	/**
	 * Enable CSS layer.
	 * This function replaces default style loader with loader that enables CSS layers.
	 *
	 * CAUTION: Use under strict conditions as side effects are expected.
	 */
	public static function enable(): void {
		/**
		 * Replace default style loader with layer-ed style loader.
		 *
		 * @see \wp_default_styles()
		 */
		add_filter(
			'style_loader_tag',
			function ( string $tag, string $handle, string $href, string $media ) {
				if (
					str_starts_with( $href, site_url( '/wp-includes/css/' ) )
					||
					str_starts_with( $href, site_url( '/wp-admin/css/' ) )
				) {
					$tag = sprintf(
						'<style id=\'%s-css\' media=\'%s\'>@import "%s" layer(core);</style>',
						$handle,
						$media,
						esc_url( $href )
					);
				}

				return $tag;
			},
			10,
			4
		);

		/**
		 * Replace default concatenated script loader with layer-ed script loader.
		 *
		 * @see \_print_styles()
		 */
		add_filter(
			'print_admin_styles',
			function () {
				global $compress_css;

				$wp_styles = wp_styles();

				$zip = $compress_css ? 1 : 0;
				if ( $zip && defined( 'ENFORCE_GZIP' ) && ENFORCE_GZIP ) {
					$zip = 'gzip';
				}

				$concat    = trim( $wp_styles->concat, ', ' );
				$type_attr = current_theme_supports( 'html5', 'style' ) ? '' : ' type="text/css"';

				if ( $concat ) {
					$dir = $wp_styles->text_direction;
					$ver = $wp_styles->default_version;

					$concat       = str_split( $concat, 128 );
					$concatenated = '';

					foreach ( $concat as $key => $chunk ) {
						$concatenated .= "&load%5Bchunk_{$key}%5D={$chunk}";
					}

					$href = $wp_styles->base_url . "/wp-admin/load-styles.php?c={$zip}&dir={$dir}" . $concatenated . '&ver=' . $ver;
					printf( '<style media="all"%s>@import "%s" layer(core);</style>', $type_attr, $href );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

					if ( ! empty( $wp_styles->print_code ) ) {
						echo "<style{$type_attr}>\n";  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $wp_styles->print_code;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo "\n</style>\n";
					}
				}

				if ( ! empty( $wp_styles->print_html ) ) {
					echo $wp_styles->print_html;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}

				return false;
			},
			PHP_INT_MAX
		);
	}
}
