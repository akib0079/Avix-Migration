<?php
/**
 * The insert/update side of a content import: terms (parent-first), then
 * attachments (with light dedup so re-running the same import doesn't pile
 * up duplicate media), then posts (inserted with post_parent=0 and fixed
 * up in a second pass once every post's new ID is known — this sidesteps
 * needing the source array to already be in parent-before-child order,
 * which content.json makes no guarantee of).
 *
 * Every rewritten text/serialized/JSON value goes through
 * Db_Search_Replace — the same safe transform the full-site importer uses
 * — for URL rewriting; attachment ID references (which are numbers, not
 * URL substrings, so Search_Replace can't touch them) are remapped
 * separately via remap_elementor_ids() and the direct field handling
 * below.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Content_Importer {

	const SOURCE_ID_META = '_avix_source_id';

	const CONFLICT_SKIP      = 'skip';
	const CONFLICT_OVERWRITE = 'overwrite';
	const CONFLICT_DUPLICATE = 'duplicate';

	/**
	 * URL/path replacement pairs for a content-import job, built the same
	 * way the full-site importer builds them — from the manifest's
	 * recorded source site info vs. this site's own Sysinfo snapshot.
	 */
	public static function url_pairs_for_manifest( array $manifest ) {
		$source = $manifest['site'] ?? array();
		$target = Avix_Migration_Util_Sysinfo::snapshot();

		return Avix_Migration_Db_Search_Replace::build_pairs(
			$source['site_url'] ?? '',
			$target['site_url'],
			$source['abspath'] ?? '',
			$target['abspath']
		);
	}

	/**
	 * Inserts/reuses terms in an order that guarantees each term's parent
	 * (if any) is already resolved — a simple repeated-pass algorithm
	 * rather than a full topological sort, since term trees here are at
	 * most a few levels deep.
	 *
	 * @param array $term_records Keyed by "{taxonomy}:{source_id}", see Content_Exporter::collect_all_terms_for_posts().
	 * @return array<string,int> Same keys, mapped to the resulting target term_id.
	 */
	public static function import_terms( array $term_records ) {
		$map      = array();
		$pending  = $term_records;
		$progress = true;

		while ( ! empty( $pending ) && $progress ) {
			$progress = false;

			foreach ( $pending as $key => $record ) {
				$parent_source_key = $record['source_parent'] > 0 ? $record['taxonomy'] . ':' . $record['source_parent'] : null;

				if ( null !== $parent_source_key && ! isset( $map[ $parent_source_key ] ) && isset( $term_records[ $parent_source_key ] ) ) {
					continue; // Parent is in this export but not yet resolved — wait for it.
				}

				$target_parent = 0;
				if ( null !== $parent_source_key && isset( $map[ $parent_source_key ] ) ) {
					$target_parent = $map[ $parent_source_key ];
				}

				$map[ $key ] = self::import_one_term( $record, $target_parent );
				unset( $pending[ $key ] );
				$progress = true;
			}
		}

		// Anything still pending has a parent chain the loop couldn't
		// resolve (shouldn't normally happen — collect_all_terms_for_posts
		// always includes ancestors) — import as top-level rather than
		// dropping it.
		foreach ( $pending as $key => $record ) {
			$map[ $key ] = self::import_one_term( $record, 0 );
		}

		return $map;
	}

	private static function import_one_term( array $record, $target_parent ) {
		$existing = get_term_by( 'slug', $record['slug'], $record['taxonomy'] );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term(
			$record['name'],
			$record['taxonomy'],
			array(
				'slug'        => $record['slug'],
				'description' => $record['description'],
				'parent'      => $target_parent,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Slug collision with a mismatched taxonomy, or similar — fall
			// back to whatever term already owns that slug in this
			// taxonomy rather than failing the whole import over one term.
			$fallback = get_term_by( 'slug', $record['slug'], $record['taxonomy'] );
			return $fallback && ! is_wp_error( $fallback ) ? (int) $fallback->term_id : 0;
		}

		return (int) $result['term_id'];
	}

	/**
	 * @param array  $record     From Content_Exporter::collect_attachment().
	 * @param string $extracted_path Absolute path where the media-extraction
	 *                              step already wrote this attachment's file
	 *                              on the target (under its own uploads dir).
	 * @param array  $url_pairs  From Db_Search_Replace::build_pairs().
	 * @return int New (or reused) attachment post ID, 0 on failure.
	 */
	public static function import_attachment( array $record, $extracted_path, array $url_pairs ) {
		global $wpdb;

		if ( ! is_readable( $extracted_path ) ) {
			return 0;
		}

		// Reuse an existing attachment if one already sits at the exact
		// same relative path with the same size — the common case when an
		// operator re-runs the same content export against a site they
		// already partially imported it onto.
		$upload_dir = wp_get_upload_dir();
		$reused     = self::find_matching_attachment( $record['relative_path'], filesize( $extracted_path ) );
		if ( $reused ) {
			return $reused;
		}

		$filetype = wp_check_filetype( basename( $extracted_path ), null );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $record['post_title'],
				'post_content'   => $record['post_content'],
				'post_excerpt'   => $record['post_excerpt'],
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : $record['post_mime_type'],
				'post_status'    => 'inherit',
			),
			$extracted_path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return 0;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $extracted_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		foreach ( $record['meta'] as $key => $values ) {
			if ( self::SOURCE_ID_META === $key ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $attachment_id, $key, Avix_Migration_Db_Search_Replace::replace( $value, $url_pairs ) );
			}
		}
		update_post_meta( $attachment_id, self::SOURCE_ID_META, $record['source_id'] );

		return (int) $attachment_id;
	}

	private static function find_matching_attachment( $relative_path, $size ) {
		global $wpdb;
		if ( '' === $relative_path ) {
			return 0;
		}

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
				$relative_path
			)
		);
		if ( ! $post_id ) {
			return 0;
		}

		$existing_file = get_attached_file( (int) $post_id );
		if ( $existing_file && is_readable( $existing_file ) && filesize( $existing_file ) === $size ) {
			return (int) $post_id;
		}
		return 0;
	}

	/**
	 * Inserts or updates one post per $conflict_mode, WITHOUT setting
	 * post_parent yet (see class docblock — that's a second pass). Content
	 * is rewritten for URLs (Db_Search_Replace) and attachment ID
	 * references (wp-image-N classes, gallery shortcode ids) before saving.
	 *
	 * @return array{id:int, source_id:int, source_parent:int}
	 */
	public static function import_post( array $record, array $attachment_id_map, array $term_id_map, array $url_pairs, $conflict_mode, $default_author_id ) {
		$existing_id = self::find_by_source_id( $record['source_id'] );

		if ( $existing_id && self::CONFLICT_SKIP === $conflict_mode ) {
			return array( 'id' => $existing_id, 'source_id' => $record['source_id'], 'source_parent' => $record['source_parent'] );
		}

		$content = self::remap_content_ids( $record['post_content'], $attachment_id_map );
		$content = Avix_Migration_Db_Search_Replace::replace( $content, $url_pairs );

		$author_id = self::resolve_author( $record['author_login'], $default_author_id );

		$postarr = array(
			'post_type'      => $record['post_type'],
			'post_title'     => $record['post_title'],
			'post_name'      => $record['post_name'],
			'post_content'   => $content,
			'post_excerpt'   => Avix_Migration_Db_Search_Replace::replace( $record['post_excerpt'], $url_pairs ),
			'post_status'    => $record['post_status'],
			'post_date'      => $record['post_date'],
			'post_date_gmt'  => $record['post_date_gmt'],
			'menu_order'     => $record['menu_order'],
			'comment_status' => $record['comment_status'],
			'ping_status'    => $record['ping_status'],
			'post_author'    => $author_id,
		);

		if ( $existing_id && self::CONFLICT_OVERWRITE === $conflict_mode ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return array( 'id' => 0, 'source_id' => $record['source_id'], 'source_parent' => $record['source_parent'] );
		}

		self::apply_meta( $post_id, $record['meta'], $attachment_id_map, $url_pairs );
		update_post_meta( $post_id, self::SOURCE_ID_META, $record['source_id'] );

		foreach ( $record['terms'] as $taxonomy => $source_term_ids ) {
			$mapped = array();
			foreach ( $source_term_ids as $source_term_id ) {
				$key = $taxonomy . ':' . $source_term_id;
				if ( isset( $term_id_map[ $key ] ) ) {
					$mapped[] = $term_id_map[ $key ];
				}
			}
			if ( ! empty( $mapped ) ) {
				wp_set_post_terms( $post_id, $mapped, $taxonomy );
			}
		}

		return array( 'id' => (int) $post_id, 'source_id' => $record['source_id'], 'source_parent' => $record['source_parent'] );
	}

	/** Second pass: now that every post in this batch has a target ID, fix up post_parent. */
	public static function fixup_parent( $new_post_id, $mapped_parent_id ) {
		if ( ! $new_post_id ) {
			return;
		}
		wp_update_post( array( 'ID' => $new_post_id, 'post_parent' => (int) $mapped_parent_id ), true );
	}

	private static function find_by_source_id( $source_id ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key = %s AND meta_value = %d",
				self::SOURCE_ID_META,
				$source_id
			)
		);
		return $id ? (int) $id : 0;
	}

	private static function resolve_author( $author_login, $default_author_id ) {
		if ( '' !== $author_login ) {
			$user = get_user_by( 'login', $author_login );
			if ( $user ) {
				return (int) $user->ID;
			}
		}
		return (int) $default_author_id;
	}

	private static function apply_meta( $post_id, array $meta, array $attachment_id_map, array $url_pairs ) {
		foreach ( $meta as $key => $values ) {
			if ( self::SOURCE_ID_META === $key ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				if ( '_thumbnail_id' === $key ) {
					if ( isset( $attachment_id_map[ (int) $value ] ) ) {
						update_post_meta( $post_id, '_thumbnail_id', $attachment_id_map[ (int) $value ] );
					}
					continue;
				}

				if ( '_elementor_data' === $key ) {
					$rewritten = self::remap_elementor_ids( $value, $attachment_id_map );
					$rewritten = Avix_Migration_Db_Search_Replace::replace( $rewritten, $url_pairs );
					update_post_meta( $post_id, '_elementor_data', $rewritten );
					continue;
				}

				add_post_meta( $post_id, $key, Avix_Migration_Db_Search_Replace::replace( $value, $url_pairs ) );
			}
		}
	}

	/**
	 * Rewrites wp-image-{ID} classes and [gallery ids="..."] shortcode
	 * attributes against the attachment id map — the ID-remap half of
	 * post_content migration; the URL half is handled separately by
	 * Db_Search_Replace since that's a text substitution, not a lookup.
	 */
	public static function remap_content_ids( $content, array $attachment_id_map ) {
		$content = preg_replace_callback(
			'/wp-image-(\d+)/',
			function ( $m ) use ( $attachment_id_map ) {
				$old = (int) $m[1];
				return isset( $attachment_id_map[ $old ] ) ? 'wp-image-' . $attachment_id_map[ $old ] : $m[0];
			},
			$content
		);

		$content = preg_replace_callback(
			'/(\[gallery[^\]]*\bids=["\'])([\d,\s]+)(["\'])/',
			function ( $m ) use ( $attachment_id_map ) {
				$new_ids = array_map(
					function ( $id ) use ( $attachment_id_map ) {
						$id = (int) trim( $id );
						return isset( $attachment_id_map[ $id ] ) ? $attachment_id_map[ $id ] : $id;
					},
					explode( ',', $m[2] )
				);
				return $m[1] . implode( ',', $new_ids ) . $m[3];
			},
			$content
		);

		return $content;
	}

	/**
	 * Mirrors Content_Dependency_Resolver::scan_elementor_tree()'s shape
	 * detection, but rewrites rather than just collecting: any {"id":N,
	 * "url":...} pair belonging to a wp-content/uploads URL gets its id
	 * swapped via the attachment map (the url itself is fixed separately by
	 * the generic Db_Search_Replace pass over this same value).
	 */
	public static function remap_elementor_ids( $json_string, array $attachment_id_map ) {
		$decoded = json_decode( $json_string, true );
		if ( ! is_array( $decoded ) ) {
			return $json_string;
		}
		self::remap_elementor_node( $decoded, $attachment_id_map );
		$reencoded = wp_json_encode( $decoded );
		return false === $reencoded ? $json_string : $reencoded;
	}

	private static function remap_elementor_node( &$node, array $attachment_id_map ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['url'], $node['id'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] )
			&& false !== strpos( $node['url'], 'wp-content/uploads' )
		) {
			$old = (int) $node['id'];
			if ( isset( $attachment_id_map[ $old ] ) ) {
				$node['id'] = $attachment_id_map[ $old ];
			}
		}

		foreach ( $node as &$value ) {
			if ( is_array( $value ) ) {
				self::remap_elementor_node( $value, $attachment_id_map );
			}
		}
	}
}
