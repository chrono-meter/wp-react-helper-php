<?php
namespace ChronoMeter\WpReactHelperPhp;

class TranslationsLoader {
	protected string $rest_namespace;
	protected string $rest_route_base;

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *   @type string $rest_namespace  REST API namespace.
	 *   @type string $rest_route_base REST API route base. Default '/translations'.
	 *   @type bool   $autoload        Whether to autoload the translation loader script. Default true.
	 * }
	 */
	public function __construct( array $options ) {
		$this->rest_namespace  = $options['rest_namespace'];
		$this->rest_route_base = $options['rest_route_base'] ?? '/translations';

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'determine_locale', array( $this, 'determine_http_request_locale' ) );

		$autoload = $options['autoload'] ?? true;

		if ( $autoload ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_translation_loader' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_translation_loader' ) );
		}
	}

	/**
	 * REST API endpoint for translations.
	 *
	 * @link https://messageformat.github.io/Jed/
	 * @see \WP_CLI\I18n\JedGenerator
	 * @link https://github.com/wp-cli/i18n-command/blob/main/src/JedGenerator.php
	 * @see \Translations, \Translation_Entry
	 */
	public function register_routes() {
		register_rest_route(
			$this->rest_namespace,
			$this->rest_route_base . '/(?P<domain>[^/]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( $request ) {
					$domain = $request['domain'];

					/**
					 * Load default admin translations.
					 *
					 * @see \load_default_textdomain()
					 */
					if ( 'default' === $domain ) {
						$locale = determine_locale();
						load_textdomain( 'default', WP_LANG_DIR . "/admin-$locale.mo", $locale );
					}

					// todo Hook for custom translations.

					$context_glue = chr( 4 );
					$translations = get_translations_for_domain( $domain );
					$headers      = $translations->headers;
					$entries      = $translations->entries;

					$result = array(
						'' => array(
							'domain'       => $domain,
							'lang'         => $locale ?? $headers['Language'] ?? 'en',
							'plural-forms' => $headers['Plural-Forms'] ?? 'nplurals=2; plural=(n != 1);',
						),
					);

					foreach ( $entries as $entry ) {
						$key = $entry->context ? ( $entry->context . $context_glue . $entry->singular ) : $entry->singular;
						$result[ $key ] = $entry->translations;
					}

					$etag = md5( wp_json_encode( $result ) );

					if ( ! empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) && stripslashes( $_SERVER['HTTP_IF_NONE_MATCH'] ) === '"' . $etag . '"' ) {  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						status_header( 304 );
						exit;
					}

					$response = rest_ensure_response(
						array(
							'domain'      => $domain,
							'locale_data' => array(
								$domain => $result,
							),
						)
					);

					$response->header( 'Cache-Control', 'public' );
					$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
					$response->header( 'ETag', '"' . $etag . '"' );

					add_filter( 'rest_send_nocache_headers', '__return_false' );

					return $response;
				},
			),
		);
	}

    // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag
	/**
	 * Determine guest locale by Accept-Language header.
	 * `\determine_locale()` will be called on any timing when `\translate*()` is called. It may be earlier than `init` action.
	 */
	public function determine_http_request_locale( $locale ) {
		/**
		 * Eary stage exceptions with `query-monitor` makes incomleted environment.
		 */
		try {
			$is_logged_in = is_user_logged_in();
		} catch ( \Throwable $e ) {
			return $locale;
		}

		if ( ! $is_logged_in ) {
			static $user_language = false;

			if ( false === $user_language ) {
				/**
				 * Parse the HTTP Accept-Language header.
				 *
				 * @link https://www.codingwithjesse.com/blog/use-accept-language-header/
				 */
				$langs = array();

				if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
					// Break up string into pieces (languages and q factors).
					preg_match_all( '/([a-z]{1,8}(-[a-z]{1,8})?)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse );  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

					if ( count( $lang_parse[1] ) ) {
						// Create a list like ["en" => 0.8].
						$langs = array_combine( $lang_parse[1], $lang_parse[4] );

						// Set default to 1 for any without q factor.
						foreach ( $langs as $lang => $val ) {
							if ( '' === $val ) {
								$langs[ $lang ] = 1;
							}
						}

						// Sort list based on value.
						arsort( $langs, SORT_NUMERIC );
					}
				}

				$available_languages = get_available_languages();
				$user_languages      = array_filter( $langs, fn( $lang ) => in_array( $lang, $available_languages, true ), ARRAY_FILTER_USE_KEY );
				$user_language       = array_key_first( $user_languages );
			}

			if ( $user_language ) {
				$locale = $user_language;
			}
		} elseif ( ! is_admin() && $is_logged_in && 'en_US' === $locale ) {
			// Some conditions would not call `get_user_locale()` in `determine_locale()` even if user is logged in.
			// todo More investigation needed.
			$locale = get_user_locale();
		}

		return $locale;
	}

	public function enqueue_translation_loader(): void {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;

		ob_start();
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const restPath = '<?php echo esc_js( $this->rest_namespace . $this->rest_route_base ); ?>';

				function install(){
					const loadingDomains = [];
					const loadedDomains = [];

					async function loadTranslationsDomain(domain) {
						domain = domain || 'default';

						if (loadingDomains.includes(domain) || loadedDomains.includes(domain)) {
							return;
						}

						try {
							loadingDomains.push(domain);

							const translations = await wp.apiFetch({
								method: 'GET',
								path: `${restPath}/${domain}`,
								cache: 'force-cache',
							});

							const localeData = translations.locale_data[domain] || translations.locale_data.messages;
							localeData[''].domain = domain;
							wp.i18n.setLocaleData(localeData, domain);

							loadedDomains.push(domain);
							console.debug(`Translations for domain '${domain}' loaded.`);

						} finally {
							loadingDomains.splice(loadingDomains.indexOf(domain), 1);
						}
					}

					// https://github.com/WordPress/gutenberg/blob/trunk/packages/i18n/src/create-i18n.js
					wp.hooks.addFilter('i18n.gettext', 'translations_loader', function (translation, text, domain) {
						loadTranslationsDomain(domain);
						return translation;
					});
					wp.hooks.addFilter('i18n.gettext_with_context', 'translations_loader', function (translation, text, context, domain) {
						loadTranslationsDomain(domain);
						return translation;
					});
					wp.hooks.addFilter('i18n.ngettext', 'translations_loader', function (translation, single, plural, number, domain) {
						loadTranslationsDomain(domain);
						return translation;
					});
					wp.hooks.addFilter('i18n.ngettext_with_context', 'translations_loader', function (translation, single, plural, number, context, domain) {
						loadTranslationsDomain(domain);
						return translation;
					});
				}

				if (wp?.hooks && wp?.apiFetch && wp?.i18n) {
					install();
					console.debug("Initialized translations loader.");

				} else {
					const interval = setInterval(() => {
						if (wp?.hooks && wp?.apiFetch && wp?.i18n) {
							clearInterval(interval);
							install();
						}
					}, 100);
				}
			}, { once: true });
		</script>
		<?php
		$mount_script = ob_get_clean();

		// Enqueue dynamic translations loader related scripts.
		wp_enqueue_script( 'wp-hooks' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-i18n' );
		add_action( 'wp_print_footer_scripts', fn() => print( $mount_script ) );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		add_action( 'admin_print_footer_scripts', fn() => print( $mount_script ) );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
