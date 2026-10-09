<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FrontendSeoInspector
 *
 * Injects a floating SEO audit overlay for logged-in editors.
 * Operates in Shadow DOM isolation to prevent styling conflicts.
 *
 * BL-019
 */
class FrontendSeoInspector {

	public function register(): void {
		add_action( 'wp_footer', array( $this, 'injectOverlay' ), 99 );
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/inspector/audit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'runAudit' ),
				'permission_callback' => array( $this, 'canAuditRequest' ),
			)
		);
	}

	/**
	 * Editors may inspect only posts they can edit. Missing IDs are 404.
	 * Users who cannot edit posts are rejected before a lookup, so they cannot
	 * probe which IDs exist.
	 *
	 * @return true|\WP_Error
	 */
	public function canAuditRequest( \WP_REST_Request $request ) {
		$decision = $this->authorizePost( $this->requestedPostId( $request ) );
		if ( is_wp_error( $decision ) ) {
			return $decision;
		}

		return true;
	}

	public function runAudit( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$decision = $this->authorizePost( $this->requestedPostId( $request ) );
		if ( is_wp_error( $decision ) ) {
			return $decision;
		}

		return rest_ensure_response( $this->auditPost( (int) $decision ) );
	}

	/**
	 * @return int|\WP_Error Authorized post ID, or an error that must be returned as-is.
	 */
	private function authorizePost( int $postId ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to inspect posts.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		if ( $postId < 1 ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'A post ID is required.', 'holvek' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $postId );
		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Post not found.', 'holvek' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to inspect this post.', 'holvek' ),
				array( 'status' => 403 )
			);
		}

		return $postId;
	}

	private function requestedPostId( \WP_REST_Request $request ): int {
		$params = $request->get_json_params();
		if ( is_array( $params ) && isset( $params['post_id'] ) ) {
			return absint( $params['post_id'] );
		}

		return absint( $request->get_param( 'post_id' ) );
	}

	public function auditPost( int $postId ): array {
		$post = get_post( $postId );
		if ( ! $post ) {
			return array(
				'post_id' => $postId,
				'checks'  => array(),
				'summary' => array(
					'pass'  => 0,
					'warn'  => 0,
					'fail'  => 0,
					'score' => 0,
				),
			);
		}

		$results = array();

		$metaTitle = (string) get_post_meta( $postId, '_holvek_meta_title', true );
		$metaDesc  = (string) get_post_meta( $postId, '_holvek_meta_description', true );
		$focusKw   = (string) get_post_meta( $postId, '_holvek_focus_keyword', true );
		$canonical = (string) get_post_meta( $postId, '_holvek_canonical_url', true );
		$noindex   = get_post_meta( $postId, '_holvek_noindex', true ) === 'yes';
		$thumbnail = has_post_thumbnail( $postId );
		$content   = wp_strip_all_tags( (string) $post->post_content );
		$wordCount = str_word_count( $content );

		// Meta title
		$titleLen              = mb_strlen( $metaTitle );
		$results['meta_title'] = array(
			'status'  => empty( $metaTitle ) ? 'fail' : ( $titleLen < 10 || $titleLen > 70 ? 'warn' : 'pass' ),
			'value'   => $metaTitle ?: __( 'Not set', 'holvek' ),
			'message' => empty( $metaTitle ) ? __( 'Meta title is missing.', 'holvek' ) : ( $titleLen > 70 ? __( 'Title exceeds 70 characters.', 'holvek' ) : __( 'OK', 'holvek' ) ),
		);

		// Meta description
		$descLen                     = mb_strlen( $metaDesc );
		$results['meta_description'] = array(
			'status'  => empty( $metaDesc ) ? 'fail' : ( $descLen < 50 || $descLen > 165 ? 'warn' : 'pass' ),
			'value'   => $metaDesc ?: __( 'Not set', 'holvek' ),
			'message' => empty( $metaDesc ) ? __( 'Meta description is missing.', 'holvek' ) : ( $descLen > 165 ? __( 'Description exceeds 165 characters.', 'holvek' ) : __( 'OK', 'holvek' ) ),
		);

		// Focus keyword
		$results['focus_keyword'] = array(
			'status'  => empty( $focusKw ) ? 'warn' : 'pass',
			'value'   => $focusKw ?: __( 'Not set', 'holvek' ),
			'message' => empty( $focusKw ) ? __( 'No focus keyword set.', 'holvek' ) : __( 'OK', 'holvek' ),
		);

		// Canonical URL
		$results['canonical'] = array(
			'status'  => empty( $canonical ) ? 'warn' : 'pass',
			'value'   => $canonical ?: get_permalink( $postId ),
			'message' => __( 'OK', 'holvek' ),
		);

		// Robots — noindex should not be set unless intentional
		$results['robots'] = array(
			'status'  => $noindex ? 'warn' : 'pass',
			'value'   => $noindex ? 'noindex' : 'index',
			'message' => $noindex ? __( 'Post is set to noindex. Verify this is intentional.', 'holvek' ) : __( 'OK', 'holvek' ),
		);

		// Featured image
		$results['featured_image'] = array(
			'status'  => $thumbnail ? 'pass' : 'warn',
			'value'   => $thumbnail ? __( 'Present', 'holvek' ) : __( 'Missing', 'holvek' ),
			'message' => $thumbnail ? __( 'OK', 'holvek' ) : __( 'No featured image. Add one for OG/Twitter cards.', 'holvek' ),
		);

		// Word count
		$results['word_count'] = array(
			'status'  => $wordCount < 300 ? 'warn' : 'pass',
			'value'   => $wordCount,
			'message' => $wordCount < 300 ? __( 'Content is too thin (under 300 words).', 'holvek' ) : __( 'OK', 'holvek' ),
		);

		// Summary
		$pass  = count( array_filter( $results, fn( $r ) => $r['status'] === 'pass' ) );
		$warns = count( array_filter( $results, fn( $r ) => $r['status'] === 'warn' ) );
		$fails = count( array_filter( $results, fn( $r ) => $r['status'] === 'fail' ) );

		return array(
			'post_id' => $postId,
			'checks'  => $results,
			'summary' => array(
				'pass'  => $pass,
				'warn'  => $warns,
				'fail'  => $fails,
				'score' => (int) round( ( $pass / count( $results ) ) * 100 ),
			),
		);
	}

	/**
	 * Inject floating inspector overlay (Shadow DOM isolated, editor-only).
	 */
	public function injectOverlay(): void {
		$postId = (int) get_the_ID();
		if ( ! is_singular() || $postId < 1 || ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$apiBase = rest_url( 'holvek/v1' );
		$nonce   = wp_create_nonce( 'wp_rest' );
		?>
		<div id="holvek-inspector-host" style="position:fixed;bottom:20px;right:20px;z-index:2147483647;font-family:sans-serif"></div>
		<?php
		// Pass data safely via wp_json_encode instead of inline PHP-in-JS
		$inspector_data = wp_json_encode(
			array(
				'apiBase' => $apiBase,
				'nonce'   => $nonce,
				'postId'  => (int) $postId,
				'title'   => __( 'Holvek Inspector', 'holvek' ),
				'toggle'  => __( 'SEO', 'holvek' ),
				'label'   => __( 'SEO Inspector', 'holvek' ),
				'error'   => __( 'Error loading audit.', 'holvek' ),
			)
		);

		$js = <<<JS
(function(){
	'use strict';
	var cfg = {$inspector_data};
	var host = document.getElementById('holvek-inspector-host');
	if (!host || !host.attachShadow) return;
	var shadow = host.attachShadow({mode:'open'});

	var style = ':host { all: initial; }' +
		'#toggle { width:48px;height:48px;border-radius:50%;background:#2563eb;color:#fff;border:none;cursor:pointer;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.25); }' +
		'#toggle:focus-visible { outline:2px solid #1e293b; outline-offset:2px; }' +
		'#panel { display:none;position:absolute;bottom:58px;right:0;width:340px;max-height:70vh;overflow-y:auto;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);padding:16px; }' +
		'#panel.open { display:block; }' +
		'h3 { margin:0 0 12px;font-size:14px;font-weight:700;color:#1e293b; }' +
		'.row { display:flex;justify-content:space-between;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px; }' +
		'.label { color:#64748b;flex:1; }' +
		'.badge { padding:2px 8px;border-radius:99px;font-weight:600;font-size:11px; }' +
		'.pass { background:#dcfce7;color:#15803d; }' +
		'.warn { background:#fef9c3;color:#854d0e; }' +
		'.fail { background:#fee2e2;color:#b91c1c; }' +
		'.score { font-size:28px;font-weight:800;margin:0 0 12px;color:#2563eb; }';

	shadow.innerHTML = '<style>' + style + '</style>' +
		'<div id="panel" role="dialog" aria-label="' + escapeAttr(cfg.title) + '">' +
		'<h3>' + escapeAttr(cfg.title) + '</h3>' +
		'<div class="score" id="score">–</div>' +
		'<div id="checks"></div>' +
		'</div>' +
		'<button id="toggle" type="button" aria-expanded="false" aria-label="' + escapeAttr(cfg.label) + '">' + escapeAttr(cfg.toggle) + '</button>';

	var toggle = shadow.getElementById('toggle');
	var panel  = shadow.getElementById('panel');
	var checks = shadow.getElementById('checks');
	var score  = shadow.getElementById('score');
	var loaded = false;

	function escapeAttr(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	toggle.addEventListener('click', function() {
		var isOpen = panel.classList.toggle('open');
		toggle.setAttribute('aria-expanded', String(isOpen));
		if (!loaded) {
			loaded = true;
			fetch(cfg.apiBase + '/inspector/audit', {
				method:'POST',
				headers:{'Content-Type':'application/json','X-WP-Nonce': cfg.nonce},
				body: JSON.stringify({post_id: cfg.postId})
			})
			.then(function(r){ return r.json(); })
			.then(function(data){
				score.textContent = data.summary.score + '%';
				checks.innerHTML = Object.entries(data.checks).map(function(entry){
					var k = entry[0], v = entry[1];
					return '<div class="row">' +
						'<span class="label">' + escapeAttr(k.replace(/_/g,' ')) + '</span>' +
						'<span class="badge ' + escapeAttr(v.status) + '" title="' + escapeAttr(v.message) + '">' + escapeAttr(v.status.toUpperCase()) + '</span>' +
						'</div>';
				}).join('');
			})
			.catch(function(){ checks.innerHTML='<p role="alert">' + escapeAttr(cfg.error) + '</p>'; });
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && panel.classList.contains('open')) {
			panel.classList.remove('open');
			toggle.setAttribute('aria-expanded', 'false');
			toggle.focus();
		}
	});
})();
JS;

		wp_register_script( 'holvek-inspector', false, array(), HOLVEK_VERSION, true );
		wp_enqueue_script( 'holvek-inspector' );
		wp_add_inline_script( 'holvek-inspector', $js );
	}
}
