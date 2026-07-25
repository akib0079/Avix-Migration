<?php
/**
 * Builds the JSON-serializable records that make up content.json — one
 * function per record type, each a pure "read this one thing from WP and
 * describe it" operation with no side effects, so the job steps that call
 * these can freely chunk/resume without this class needing to know
 * anything about that.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Content_Exporter {

	/** Meta keys that are source-site-specific and never worth carrying across a migration. */
	const META_DENYLIST = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );

	public static function collect_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$author = get_userdata( $post->post_author );

		$meta = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( in_array( $key, self::META_DENYLIST, true ) ) {
				continue;
			}
			$meta[ $key ] = $values; // Raw (still-serialized-if-applicable) strings — Search_Replace handles those safely later.
		}

		return array(
			'source_id'      => (int) $post->ID,
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_name'      => $post->post_name,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'source_parent'  => (int) $post->post_parent,
			'author_login'   => $author ? $author->user_login : '',
			'author_email'   => $author ? $author->user_email : '',
			'terms'          => self::collect_post_terms( $post_id ),
			'meta'           => $meta,
		);
	}

	private static function collect_post_terms( $post_id ) {
		$out = array();
		foreach ( get_post_taxonomies( $post_id ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) ) {
				$out[ $taxonomy ] = wp_list_pluck( $terms, 'term_id' );
			}
		}
		return $out;
	}

	/**
	 * @param int $attachment_id
	 * @return array|null Includes 'relative_path' (relative to the SOURCE
	 *                     uploads dir) so the importer can restore it under
	 *                     the equivalent path in the TARGET's uploads dir;
	 *                     the actual bytes travel as a separate archive
	 *                     file entry, not inline here.
	 */
	public static function collect_attachment( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$upload_dir = wp_get_upload_dir();
		$relative = $file ? ltrim( str_replace( wp_normalize_path( $upload_dir['basedir'] ), '', wp_normalize_path( $file ) ), '/' ) : '';

		$meta = array();
		foreach ( get_post_meta( $attachment_id ) as $key => $values ) {
			if ( in_array( $key, self::META_DENYLIST, true ) ) {
				continue;
			}
			$meta[ $key ] = $values;
		}

		return array(
			'source_id'     => (int) $attachment_id,
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_mime_type' => $post->post_mime_type,
			'source_parent' => (int) $post->post_parent,
			'relative_path' => $relative,
			'meta'          => $meta,
			'file_exists'   => ( '' !== $relative && is_readable( $file ) ),
		);
	}

	public static function collect_term( $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return array(
			'source_id'   => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'source_parent' => (int) $term->parent,
		);
	}

	/**
	 * Every term assigned to any of $post_ids, PLUS their full ancestor
	 * chains — a child category exported without its parent would otherwise
	 * land on the target as an orphaned term with a parent_id that doesn't
	 * exist there.
	 *
	 * @return array Keyed by "{taxonomy}:{term_id}" to avoid needing a
	 *               composite-key lookup structure downstream.
	 */
	public static function collect_all_terms_for_posts( array $post_ids ) {
		$collected = array();

		foreach ( $post_ids as $post_id ) {
			foreach ( get_post_taxonomies( $post_id ) as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					self::collect_term_and_ancestors( $term->term_id, $taxonomy, $collected );
				}
			}
		}

		return $collected;
	}

	private static function collect_term_and_ancestors( $term_id, $taxonomy, array &$collected ) {
		$key = $taxonomy . ':' . $term_id;
		if ( isset( $collected[ $key ] ) ) {
			return;
		}
		$record = self::collect_term( $term_id, $taxonomy );
		if ( ! $record ) {
			return;
		}
		$collected[ $key ] = $record;

		if ( $record['source_parent'] > 0 ) {
			self::collect_term_and_ancestors( $record['source_parent'], $taxonomy, $collected );
		}
	}
}
