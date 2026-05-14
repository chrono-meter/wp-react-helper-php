<?php
namespace ChronoMeter\WpReactHelperPhp\Mod;

class DisableBlockEditor {
	/**
	 * Disable block editor for specified post type.
	 *
	 * @param string $post_type Post type name to disable block editor.
	 */
	public static function for_post_type( string $post_type ): void {
		add_filter(
			'replace_editor',
			fn ( $result, \WP_Post $post ) => get_post_type( $post ) === $post_type ? false : $result,
			PHP_INT_MAX,
			2
		);
		add_filter(
			'use_block_editor_for_post_type',
			fn ( $result, $post_type_name ) => $post_type === $post_type_name ? false : $result,
			PHP_INT_MAX,
			2
		);
		add_filter(
			'use_block_editor_for_post',
			fn ( $result, \WP_Post $post ) => $post_type === $post->post_type ? false : $result,
			PHP_INT_MAX,
			2
		);
	}
}
