<?php

namespace Holvek\WordPress\Modules\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BulkMetaEditor: Provides a REST endpoint for reading and batch-saving
 * SEO meta (title, description, noindex) across all posts/pages.
 */
class BulkMetaEditor {

	public function registerRestRoutes(): void {
		register_rest_route(
			'holvek/v1',
			'/bulk-meta',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getPosts' ),
					'permission_callback' => fn() => current_user_can( 'edit_posts' ),
					'args'                => array(
						'page'      => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page'  => array(
							'default'           => 25,
							'sanitize_callback' => 'absint',
						),
						'post_type' => array(
							'default'           => 'post',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'search'    => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveMeta' ),
					'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				),
			)
		);
	}

	public function getPosts( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = max( 1, $request->get_param( 'page' ) );
		$perPage  = min( 100, max( 5, $request->get_param( 'per_page' ) ) );
		$postType = $request->get_param( 'post_type' );
		$search   = $request->get_param( 'search' );

		$args = array(
			'post_type'      => $postType,
			'post_status'    => 'publish',
			'posts_per_page' => $perPage,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$seoTitle = get_post_meta( $post->ID, '_holvek_seo_title', true );
			$seoDesc  = get_post_meta( $post->ID, '_holvek_meta_description', true );
			$noIndex  = get_post_meta( $post->ID, '_holvek_noindex', true );

			// Compute a simple SEO score indicator
			$score = 0;
			if ( ! empty( $seoTitle ) && strlen( $seoTitle ) >= 40 && strlen( $seoTitle ) <= 60 ) {
				$score += 50;
			} elseif ( ! empty( $seoTitle ) ) {
				$score += 25;
			}
			if ( ! empty( $seoDesc ) && strlen( $seoDesc ) >= 120 && strlen( $seoDesc ) <= 160 ) {
				$score += 50;
			} elseif ( ! empty( $seoDesc ) ) {
				$score += 25;
			}

			$items[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post->ID ),
				'url'       => get_permalink( $post->ID ),
				'seo_title' => $seoTitle,
				'seo_desc'  => $seoDesc,
				'noindex'   => $noIndex === 'yes',
				'score'     => $score,
				'modified'  => get_the_modified_date( 'Y-m-d', $post->ID ),
			);
		}

		return rest_ensure_response(
			array(
				'items'      => $items,
				'total'      => (int) $query->found_posts,
				'pages'      => (int) $query->max_num_pages,
				'page'       => $page,
				'post_types' => $this->getEditablePostTypes(),
			)
		);
	}

	public function saveMeta( \WP_REST_Request $request ): \WP_REST_Response {
		$params  = $request->get_json_params();
		$updates = $params['updates'] ?? array();

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return new \WP_Error( 'missing_data', __( 'No updates provided.', 'holvek' ), array( 'status' => 400 ) );
		}

		$saved  = array();
		$errors = array();

		foreach ( $updates as $update ) {
			$postId = absint( $update['id'] ?? 0 );

			if ( ! $postId || ! current_user_can( 'edit_post', $postId ) ) {
				$errors[] = array(
					'id'    => $postId,
					'error' => 'Permission denied or invalid post.',
				);
				continue;
			}

			if ( isset( $update['seo_title'] ) ) {
				update_post_meta( $postId, '_holvek_seo_title', sanitize_text_field( $update['seo_title'] ) );
			}
			if ( isset( $update['seo_desc'] ) ) {
				update_post_meta( $postId, '_holvek_meta_description', sanitize_textarea_field( $update['seo_desc'] ) );
			}
			if ( isset( $update['noindex'] ) ) {
				update_post_meta( $postId, '_holvek_noindex', $update['noindex'] ? 'yes' : 'no' );
			}

			$saved[] = $postId;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'saved'   => $saved,
				'errors'  => $errors,
			)
		);
	}

	private function getEditablePostTypes(): array {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();
		foreach ( $types as $type ) {
			$result[] = array(
				'name'  => $type->name,
				'label' => $type->label,
			);
		}
		return $result;
	}
}
