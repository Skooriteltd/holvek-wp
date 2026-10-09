<?php

namespace Holvek\WordPress\Modules\TechnicalSeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Holvek rules to the robots.txt document WordPress and other plugins built.
 * A saved document replaces that file only after an administrator confirms it.
 *
 * BL-010: Editable custom AI bot list (merged with built-in AI_BOTS at runtime).
 * BL-032: LLM training data opt-out (NoAI directive + TDM-Reservation HTTP header).
 */
class RobotsTxtEditor {

	private const OPTION_KEY           = 'holvek_robots_txt';
	private const REPLACE_OPTION       = 'holvek_robots_txt_replaces_generated';
	private const CUSTOM_BOTS_OPTION   = 'holvek_custom_ai_bots';
	private const TRAINING_OPT_OUT_OPT = 'holvek_ai_training_optout';
	private const MAX_ROBOTS_BYTES     = 100000;

	// List of major AI scraper/crawler User-Agents (built-in, always applied)
	private const AI_BOTS = array(
		'GPTBot',
		'ChatGPT-User',
		'CCBot',
		'Google-Extended',
		'Anthropic-AI',
		'Claude-Web',
		'ClaudeBot',
		'cohere-ai',
		'Omgilibot',
		'Omgili',
		'PerplexityBot',
		'YouBot',
	);

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'robots_txt', array( $this, 'filterRobotsTxt' ), 999, 2 );
		add_action( 'init', array( $this, 'proactiveBlockAiBots' ) );
		add_action( 'send_headers', array( $this, 'addTrainingOptOutHeaders' ) );
	}

	/**
	 * Register REST routes for the robots.txt editor.
	 */
	public function registerRoutes(): void {
		$adminCap = fn() => current_user_can( 'manage_seo' ) || current_user_can( 'manage_options' );

		register_rest_route(
			'holvek/v1',
			'/robots-txt',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getRobotsTxt' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateRobotsTxt' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		// BL-010: Editable AI bot list
		register_rest_route(
			'holvek/v1',
			'/ai-bots',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getAiBots' ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateAiBots' ),
					'permission_callback' => $adminCap,
				),
			)
		);

		// BL-032: Training data opt-out toggle
		register_rest_route(
			'holvek/v1',
			'/ai-training-optout',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( array( 'enabled' => get_option( self::TRAINING_OPT_OUT_OPT, 'no' ) === 'yes' ) ),
					'permission_callback' => $adminCap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => function ( \WP_REST_Request $req ) {
						$p = $req->get_json_params();
						update_option( self::TRAINING_OPT_OUT_OPT, ! empty( $p['enabled'] ) ? 'yes' : 'no', true );
						return rest_ensure_response( array( 'success' => true ) );
					},
					'permission_callback' => $adminCap,
				),
			)
		);
	}

	// ── BL-010: Bot list management ───────────────────────────────────────────

	/**
	 * Merge the built-in AI_BOTS with any custom additions from wp_options.
	 */
	public function getMergedBotList(): array {
		$custom = (array) get_option( self::CUSTOM_BOTS_OPTION, array() );
		$bots   = array();

		foreach ( array_merge( self::AI_BOTS, $custom ) as $bot ) {
			$token = $this->botToken( $bot );
			if ( '' !== $token ) {
				$bots[] = $token;
			}
		}

		return array_values( array_unique( $bots ) );
	}

	public function getAiBots( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'builtin' => self::AI_BOTS,
				'custom'  => (array) get_option( self::CUSTOM_BOTS_OPTION, array() ),
				'merged'  => $this->getMergedBotList(),
			)
		);
	}

	public function updateAiBots( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = $request->get_json_params();
		$customBots = array_map( 'sanitize_text_field', (array) ( $params['custom_bots'] ?? array() ) );
		// Letters, numbers, dots, underscores, and hyphens. 4 to 100 characters. At most 50 bots.
		$customBots = array_slice(
			array_values(
				array_filter(
					array_map( array( $this, 'botToken' ), $customBots ),
					static fn( $bot ) => '' !== $bot
				)
			),
			0,
			50
		);

		update_option( self::CUSTOM_BOTS_OPTION, array_values( $customBots ), true );

		return rest_ensure_response(
			array(
				'success' => true,
				'merged'  => $this->getMergedBotList(),
			)
		);
	}

	// ── robots.txt endpoints ──────────────────────────────────────────────────

	public function getRobotsTxt( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'content'           => (string) get_option( self::OPTION_KEY, '' ),
				'replace_generated' => $this->replacesGeneratedFile(),
				'block_ai_bots'     => get_option( 'holvek_block_ai_bots', 'no' ) === 'yes',
				'training_optout'   => get_option( self::TRAINING_OPT_OUT_OPT, 'no' ) === 'yes',
			)
		);
	}

	/**
	 * Save robots.txt settings.
	 *
	 * A stored document replaces WordPress and other plugins only when the
	 * request also acknowledges that replacement.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function updateRobotsTxt( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$hasContent = array_key_exists( 'content', $params );
		if ( $hasContent && ! is_string( $params['content'] ) ) {
			return new \WP_Error(
				'invalid_robots_txt',
				__( 'robots.txt content must be text.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$nextContent = $hasContent
			? sanitize_textarea_field( $params['content'] )
			: (string) get_option( self::OPTION_KEY, '' );

		if ( strlen( $nextContent ) > self::MAX_ROBOTS_BYTES ) {
			return new \WP_Error(
				'invalid_robots_txt',
				__( 'robots.txt is too large.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$hasReplace = array_key_exists( 'replace_generated', $params );
		$replace    = $hasReplace
			? $this->isAffirmative( $params['replace_generated'] )
			: ( get_option( self::REPLACE_OPTION, 'no' ) === 'yes' );

		if ( $replace && '' === trim( $nextContent ) ) {
			return new \WP_Error(
				'robots_replace_empty',
				__( 'Add robots.txt rules before replacing the generated file.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		if ( $hasContent ) {
			update_option( self::OPTION_KEY, $nextContent, false );
		}

		if ( $hasReplace ) {
			update_option( self::REPLACE_OPTION, ( $replace && '' !== trim( $nextContent ) ) ? 'yes' : 'no', true );
		}

		if ( isset( $params['block_ai_bots'] ) ) {
			update_option( 'holvek_block_ai_bots', ! empty( $params['block_ai_bots'] ) ? 'yes' : 'no', true );
		}

		return rest_ensure_response(
			array(
				'success'           => true,
				'replace_generated' => $this->replacesGeneratedFile(),
			)
		);
	}

	// ── robots.txt filter ─────────────────────────────────────────────────────

	public function filterRobotsTxt( string $output, bool $isPublic ): string {
		$replacing = $this->replacesGeneratedFile();
		$document  = $replacing ? (string) get_option( self::OPTION_KEY, '' ) : $output;

		return $this->appendHolvekRules( $document, $isPublic && ! $replacing );
	}

	// ── BL-032: HTTP headers for training opt-out ─────────────────────────────

	public function addTrainingOptOutHeaders(): void {
		if ( get_option( self::TRAINING_OPT_OUT_OPT, 'no' ) !== 'yes' ) {
			return;
		}

		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		header( 'TDM-Reservation: 1' );
		header( 'X-Robots-Tag: noai, noimageai', false );
	}

	/**
	 * Returns a static method that MetaTagsGenerator can call to add a per-post AI meta tag.
	 * Usage in MetaTagsGenerator::getMetaTags(): echo RobotsTxtEditor::getPerPostAiMeta(get_the_ID());
	 */
	public static function getPerPostAiMeta( int $postId ): string {
		$allowAi = get_post_meta( $postId, '_holvek_ai_training_allow', true );
		if ( $allowAi === 'yes' ) {
			return '<meta name="robots" content="ai">' . "\n";
		}

		return '';
	}

	// ── Proactive HTTP-level AI bot blocking ──────────────────────────────────

	public function proactiveBlockAiBots(): void {
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( get_option( 'holvek_block_ai_bots', 'no' ) !== 'yes' ) {
			return;
		}

		$userAgent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $userAgent || ! $this->userAgentMatchesAiBot( $userAgent ) ) {
			return;
		}

		status_header( 403 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex', false );

		// Site name, URL, and visible strings are escaped in blockedResponseHtml().
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->blockedResponseHtml();
		exit;
	}

	/**
	 * Whether the user agent contains an AI crawler token.
	 *
	 * A short name such as Omgili matches Omgili/1.0 and does not match a longer
	 * product token such as NotOmgiliBrowser.
	 */
	public function userAgentMatchesAiBot( string $userAgent ): bool {
		if ( '' === $userAgent ) {
			return false;
		}

		foreach ( $this->getMergedBotList() as $bot ) {
			$pattern = '/(^|[^A-Za-z0-9])' . preg_quote( $bot, '/' ) . '([^A-Za-z0-9]|$)/i';
			if ( 1 === preg_match( $pattern, $userAgent ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * HTML shown when an AI crawler is blocked.
	 *
	 * The lang attribute is included only for an English site locale. A non-English
	 * locale does not claim a language, because this document may still be English
	 * until a translation is loaded.
	 */
	public function blockedResponseHtml(): string {
		$siteName = esc_html( get_bloginfo( 'name' ) );
		$homeUrl  = esc_url( home_url( '/' ) );
		$lang     = $this->englishLanguageTag();

		$title = sprintf(
			/* translators: %s: site name. */
			esc_html__( '403 Forbidden — %s', 'holvek' ),
			$siteName
		);
		$heading = esc_html__( 'Access Denied', 'holvek' );
		$message = sprintf(
			/* translators: %s: site name. */
			esc_html__( 'Automated AI crawlers and scrapers are not permitted to access %s.', 'holvek' ),
			'<strong>' . $siteName . '</strong>'
		);
		$back    = esc_html__( 'Return to homepage', 'holvek' );
		$htmlTag = '' === $lang ? '<html>' : '<html lang="' . esc_attr( $lang ) . '">';

		return '<!DOCTYPE html>' . "\n"
			. $htmlTag . "\n"
			. "<head>\n"
			. "<meta charset=\"UTF-8\">\n"
			. "<meta name=\"robots\" content=\"noindex\">\n"
			. '<title>' . $title . "</title>\n"
			. "<style>\n"
			. "body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}\n"
			. ".wrap{max-width:480px;text-align:center;padding:40px 24px}\n"
			. "h1{font-size:4rem;font-weight:800;color:#3b82f6;margin:0 0 8px}\n"
			. "h2{font-size:1.25rem;font-weight:600;margin:0 0 16px}\n"
			. "p{color:#64748b;line-height:1.6;margin:0 0 24px}\n"
			. "a{display:inline-block;padding:10px 24px;background:#3b82f6;color:#fff;border-radius:6px;text-decoration:none;font-weight:600}\n"
			. "a:hover{background:#2563eb}\n"
			. "a:focus{outline:2px solid #1e293b;outline-offset:2px}\n"
			. "</style>\n"
			. "</head>\n"
			. "<body>\n"
			. "<div class=\"wrap\">\n"
			. "<h1>403</h1>\n"
			. '<h2>' . $heading . "</h2>\n"
			. '<p>' . $message . "</p>\n"
			. '<a href="' . $homeUrl . '">&larr; ' . $back . "</a>\n"
			. "</div>\n"
			. "</body>\n"
			. '</html>';
	}

	/**
	 * Append sitemap, AI crawler, and training opt-out rules.
	 */
	private function appendHolvekRules( string $document, bool $includeSitemaps ): string {
		$blocks = array();

		if ( $includeSitemaps ) {
			$missing = array();
			foreach ( $this->holvekSitemapLines() as $line ) {
				if ( ! str_contains( $document, $line ) ) {
					$missing[] = $line;
				}
			}
			if ( array() !== $missing ) {
				$blocks[] = implode( "\n", $missing );
			}
		}

		if ( get_option( 'holvek_block_ai_bots', 'no' ) === 'yes' ) {
			$lines   = array( '# Block AI Crawlers and LLM Bots (Holvek AI Crawler Manager)' );
			$lines[] = '';
			foreach ( $this->getMergedBotList() as $bot ) {
				$lines[] = 'User-agent: ' . $bot;
				$lines[] = 'Disallow: /';
				$lines[] = '';
			}
			$blocks[] = rtrim( implode( "\n", $lines ), "\n" );
		}

		if ( get_option( self::TRAINING_OPT_OUT_OPT, 'no' ) === 'yes' ) {
			$blocks[] = "# AI Training Data Opt-Out (Holvek)\nUser-agent: *\nNoAI: 1\nNoImageAI: 1";
		}

		if ( array() === $blocks ) {
			return $document;
		}

		$suffix = implode( "\n\n", $blocks );
		if ( '' === trim( $document ) ) {
			return $suffix . "\n";
		}

		return rtrim( $document, "\r\n" ) . "\n\n" . $suffix . "\n";
	}

	/**
	 * @return array<int, string>
	 */
	private function holvekSitemapLines(): array {
		$lines = array();

		foreach ( array( '/sitemap.xml', '/news-sitemap.xml' ) as $path ) {
			$url = esc_url_raw( home_url( $path ) );
			if ( ! is_string( $url ) || 1 !== preg_match( '#^https?://[^\s]+$#', $url ) ) {
				continue;
			}
			$lines[] = 'Sitemap: ' . $url;
		}

		return $lines;
	}

	private function replacesGeneratedFile(): bool {
		if ( get_option( self::REPLACE_OPTION, 'no' ) !== 'yes' ) {
			return false;
		}

		return '' !== trim( (string) get_option( self::OPTION_KEY, '' ) );
	}

	/**
	 * @param mixed $value Request flag.
	 */
	private function isAffirmative( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value;
	}

	/**
	 * A crawler token: letters, numbers, and . _ - , at least four characters.
	 *
	 * @param mixed $bot Raw bot name.
	 */
	private function botToken( $bot ): string {
		if ( ! is_string( $bot ) ) {
			return '';
		}

		$bot = trim( $bot );
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{4,100}$/', $bot ) ) {
			return '';
		}

		return $bot;
	}

	/**
	 * BCP 47 language tag when the site locale is English.
	 */
	private function englishLanguageTag(): string {
		$locale = function_exists( 'get_locale' ) ? get_locale() : '';
		if ( ! is_string( $locale ) || 1 !== preg_match( '/^en(?:[_-][A-Za-z0-9]{2,8})*$/', $locale ) ) {
			return '';
		}

		$parts = preg_split( '/[_-]/', str_replace( '_', '-', $locale ) );
		if ( ! is_array( $parts ) || ! isset( $parts[0] ) ) {
			return '';
		}

		$tag = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$tag .= '-' . strtoupper( $parts[1] );
		}

		return $tag;
	}
}
