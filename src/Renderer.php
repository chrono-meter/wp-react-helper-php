<?php
namespace ChronoMeter\WpReactHelperPhp;

class Renderer {
	public string $path;
	public string $plugin_dir;

	/**
	 * Renders a React component from a given asset file path.
	 *
	 * @param string $path       The path to the asset file, relative to the plugin directory.
	 * @param string $plugin_dir The absolute path to the plugin directory.
	 * @return object An object with a render method that can be used to render the React component.
	 *
	 * To render a React component from PHP code, you can use the following code:
	 * ```php
	 * $renderer = (require 'path/to/render.php')( 'relative/path/to/asset/file.asset.php', __DIR__ );
	 * $renderer->render( $props );  // $props is an optional array of props to pass to the React component.
	 * ```
	 * Note that this method only allows you to render the React component once.
	 * To repeatedly render for the same root node, it's easier to use [@r2wc/react-to-web-component](https://www.npmjs.com/package/@r2wc/react-to-web-component).
	 */
	public function __construct( string $path, string $plugin_dir ) {
		$this->path       = $path;
		$this->plugin_dir = $plugin_dir;
	}

	public function get_url(): string {
		// Infer asset file path.
		$asset_path = null;
		if ( str_ends_with( $this->path, '.asset.php' ) ) {
			$asset_path = $this->path;
		} elseif ( str_ends_with( $this->path, '.js' ) ) {
			$asset_path = preg_replace( '/\.js$/', '.asset.php', $this->path );
		} else {
			$asset_path = $this->path . '.asset.php';
		}

		// Load asset file.
		$asset = require $this->plugin_dir . DIRECTORY_SEPARATOR . $asset_path;

		// Infer script and style file paths.
		$script_file = preg_replace( '/\.asset\.php$/', '.js', $asset_path );
		$style_file  = preg_replace( '/\.asset\.php$/', '.css', $asset_path );
		if ( is_rtl() && file_exists( $this->plugin_dir . DIRECTORY_SEPARATOR . preg_replace( '/\.css$/', '-rtl.css', $style_file ) ) ) {
			$style_file = preg_replace( '/\.css$/', '-rtl.css', $style_file );
		}

		// wp_enqueue_script( 'fslightbox-cdn', 'https://cdn.jsdelivr.net/npm/fslightbox@3.7.4/index.min.js', array(), '3.7.4', true );

		// Enqueue dependencies.
		foreach ( $asset['dependencies'] as $dependency ) {
			wp_enqueue_script( $dependency );
		}

		// Enqueue styles.
		if ( file_exists( $this->plugin_dir . DIRECTORY_SEPARATOR . $style_file ) ) {
			wp_enqueue_style(
				md5( $style_file ),
				plugins_url( $style_file, $this->plugin_dir . '/index.php' ),
				array(),
				$asset['version'],
			);
		}

		return plugins_url( $script_file, $this->plugin_dir . '/index.php' ) . '?ver=' . $asset['version'];
	}

	public function render( ...$props ) {
		$tmpid = uniqid( '_' );
		?>
		<div id="<?php echo esc_attr( $tmpid ); ?>">
		<?php esc_html_e( 'Loading page, please wait.' ); ?>
			<script type="module">
				import Component from <?php echo wp_json_encode( $this->get_url() ); ?>;
				const root = document.getElementById(<?php echo wp_json_encode( $tmpid ); ?>);
				const props = <?php echo wp_json_encode( $props ); ?>;
				wp.element.createRoot(root).render(React.createElement(Component, props));
			</script>
		</div>
		<?php
	}

	public function add_admin_post_page( string $action, string $title, bool $allow_guest = true ) {
		$renderer = function () use ( $title ) {
			_wp_admin_html_begin();
			$module_url = $this->get_url();
			echo '<title>' . esc_html( $title ) . '</title>';
			wp_print_head_styles();
			wp_print_head_scripts();
			printf( '<link rel="modulepreload" href="%s" />', esc_url( $module_url ) );
			echo '</head><body>';
			$this->render();
			wp_print_footer_styles();
			wp_print_footer_scripts();
			echo '</body></html>';
		};

		add_action( "admin_post_{$action}", $renderer );
		$allow_guest && add_action( "admin_post_nopriv_{$action}", $renderer );
	}
}
