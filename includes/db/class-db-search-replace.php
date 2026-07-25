<?php
/**
 * Serialization-safe, JSON-aware search-replace over a single column value.
 * This is the highest-risk piece of the whole import path: a naive
 * str_replace() on a serialized PHP string corrupts every length prefix
 * after the replacement point the moment the replacement isn't the exact
 * same byte length as the original — which it almost never is, since we're
 * replacing one domain with another. Every recursive call below either
 * leaves a value untouched or fully re-serializes it, never patches bytes
 * in place.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Db_Search_Replace {

	/**
	 * Builds the full set of (from => to) pairs for a URL/path migration —
	 * plain, protocol-relative, and the escaped-slash + URL-encoded forms
	 * that JSON-based page builders (Elementor, etc.) actually store on
	 * disk. Order matters: put_/get_ callers must apply longer, more
	 * specific froms before shorter ones so a match isn't shadowed by a
	 * substring of itself (handled by replace_scalar() sorting by length).
	 *
	 * @param string $from_url e.g. https://old.example.com
	 * @param string $to_url   e.g. https://new.example.com
	 * @param string $from_path Absolute filesystem path on the source (ABSPATH or uploads dir).
	 * @param string $to_path   Same, on the target.
	 * @return array<string,string>
	 */
	public static function build_pairs( $from_url, $to_url, $from_path = '', $to_path = '' ) {
		$pairs = array();

		$add_url_variants = function ( $from, $to ) use ( &$pairs ) {
			if ( '' === $from || $from === $to ) {
				return;
			}
			$pairs[ $from ] = $to;
			$pairs[ addcslashes( $from, '/' ) ] = addcslashes( $to, '/' ); // https:\/\/old.example.com
			$pairs[ rawurlencode( $from ) ]     = rawurlencode( $to );     // https%3A%2F%2Fold.example.com
			// Protocol-relative (//old.example.com), only if both start with a scheme.
			$from_no_scheme = preg_replace( '#^https?:#i', '', $from );
			$to_no_scheme   = preg_replace( '#^https?:#i', '', $to );
			if ( $from_no_scheme !== $from ) {
				$pairs[ $from_no_scheme ] = $to_no_scheme;
			}
			// http <-> https of the same host, both directions, in case the
			// export happened over one scheme and the target runs the other.
			if ( 0 === stripos( $from, 'https://' ) ) {
				$http_from = 'http://' . substr( $from, 8 );
				$pairs[ $http_from ] = $to;
			} elseif ( 0 === stripos( $from, 'http://' ) ) {
				$https_from = 'https://' . substr( $from, 7 );
				$pairs[ $https_from ] = $to;
			}
		};

		$add_url_variants( untrailingslashit( $from_url ), untrailingslashit( $to_url ) );

		if ( '' !== $from_path && $from_path !== $to_path ) {
			$pairs[ $from_path ] = $to_path;
			$pairs[ addcslashes( $from_path, '/' ) ] = addcslashes( $to_path, '/' );
		}

		return array_filter( $pairs, function ( $to, $from ) { return $from !== ''; }, ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * Recursively replaces every occurrence of each `from` with its `to`
	 * inside $value, which may be a plain string, a serialized PHP value,
	 * or a JSON document — re-serializing/re-encoding rather than patching
	 * bytes in place so length prefixes always stay correct.
	 *
	 * @param mixed              $value
	 * @param array<string,string> $pairs   Longest-`from`-first is enforced internally.
	 * @param array               $stats   By reference; bumps ['replacements'] and optionally ['warnings'][].
	 * @return mixed
	 */
	public static function replace( $value, array $pairs, array &$stats = array() ) {
		if ( ! isset( $stats['replacements'] ) ) {
			$stats['replacements'] = 0;
		}
		if ( ! isset( $stats['warnings'] ) ) {
			$stats['warnings'] = array();
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( self::looks_serialized( $value ) ) {
			return self::replace_serialized( $value, $pairs, $stats );
		}

		if ( self::looks_json( $value ) ) {
			return self::replace_json( $value, $pairs, $stats );
		}

		return self::replace_scalar( $value, $pairs, $stats );
	}

	/**
	 * Plain string replacement, longest `from` first so e.g. the
	 * slash-escaped variant of a URL isn't partially shadowed by a shorter
	 * unescaped variant matching a prefix of it.
	 */
	private static function replace_scalar( $value, array $pairs, array &$stats ) {
		$froms = array_keys( $pairs );
		usort( $froms, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );

		foreach ( $froms as $from ) {
			if ( '' === $from ) {
				continue;
			}
			$count = 0;
			$value = str_replace( $from, $pairs[ $from ], $value, $count );
			$stats['replacements'] += $count;
		}
		return $value;
	}

	/**
	 * Cheap syntactic pre-check only — deliberately does NOT call
	 * unserialize() here. The actual attempt (and its success/failure
	 * handling) happens exactly once, in replace_serialized() below. An
	 * earlier version of this method also called unserialize() here to
	 * "confirm" the match, which meant a genuinely corrupt value could
	 * never reach replace_serialized()'s own failure branch at all — that
	 * branch existed but was unreachable dead code. Worse, if this
	 * pre-check is ever tightened further, a value that only *looks*
	 * serialized (e.g. plain text starting with "s:" or "i:" that isn't
	 * actually valid serialization syntax) must still fall through to a
	 * plain-scalar replace rather than being returned untouched — which is
	 * exactly what replace_serialized()'s failure branch now does.
	 */
	private static function looks_serialized( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) < 2 ) {
			return false;
		}
		if ( 'N;' === $value ) {
			return true;
		}
		// Deliberately broad (just the type-tag prefix, not a full syntax
		// check) rather than precise: a false positive here costs one
		// harmless failed unserialize() attempt that falls back to a plain
		// scalar replace (see replace_serialized()); a false NEGATIVE would
		// send genuinely serialized data through a naive string replace
		// instead, corrupting its length prefixes — the exact failure mode
		// this whole class exists to prevent. Better to over-match.
		return (bool) preg_match( '/^[adObis]:/', $value );
	}

	private static function looks_json( $value ) {
		$trimmed = ltrim( $value );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return false;
		}
		json_decode( $value );
		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * True if $data is, or contains anywhere in its tree, a
	 * __PHP_Incomplete_Class — what unserialize() returns for a serialized
	 * object whose class isn't loaded in this process (which, with
	 * allowed_classes => false, is EVERY serialized object).
	 */
	private static function contains_incomplete_object( $data ) {
		if ( $data instanceof __PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $val ) {
				if ( self::contains_incomplete_object( $val ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Rewrites the string values inside an already-serialized blob without
	 * ever unserializing it, recomputing each `s:LEN:` prefix from the
	 * replaced bytes.
	 *
	 * This exists for one specific case: serialized objects. With
	 * allowed_classes => false (which we keep, because allowing arbitrary
	 * class instantiation during an import is a PHP object-injection hole),
	 * unserialize() hands back a __PHP_Incomplete_Class, and PHP throws a
	 * fatal Error the moment anything writes a property to one — so the
	 * normal unserialize/recurse/re-serialize path physically cannot walk
	 * object payloads. Operating on the serialized bytes sidesteps that
	 * entirely: object structure and class names are preserved byte-for-byte
	 * (only `s:` string tokens are touched, never the `O:` class-name token),
	 * while URLs inside those objects still get migrated.
	 *
	 * Parsing is length-driven, not delimiter-driven: after reading the
	 * declared LEN bytes we verify the very next two bytes are `";`. That
	 * check is what makes this safe against string CONTENT that merely looks
	 * like a token — note `https:` itself contains the `s:` sequence this
	 * scans for, so false candidates are the normal case, not an edge case.
	 * Real tokens are skipped past wholesale, so content is never re-scanned.
	 *
	 * @return string|null Rewritten blob, or null if the structure failed to
	 *                     validate (caller then leaves the value untouched
	 *                     rather than risk emitting corrupt serialization).
	 */
	private static function rewrite_serialized_strings( $value, array $pairs, array &$stats ) {
		$out = '';
		$i   = 0;
		$len = strlen( $value );

		while ( $i < $len ) {
			$pos = strpos( $value, 's:', $i );
			if ( false === $pos ) {
				$out .= substr( $value, $i );
				break;
			}

			// Parse the declared length: s:<digits>:"
			$cursor = $pos + 2;
			$digits = '';
			while ( $cursor < $len && ctype_digit( $value[ $cursor ] ) ) {
				$digits .= $value[ $cursor ];
				$cursor++;
			}

			$is_token = '' !== $digits
				&& isset( $value[ $cursor ], $value[ $cursor + 1 ] )
				&& ':' === $value[ $cursor ]
				&& '"' === $value[ $cursor + 1 ];

			if ( $is_token ) {
				$content_start = $cursor + 2;
				$declared      = (int) $digits;
				$close         = $content_start + $declared;

				// The length-driven validation described above.
				if ( ! ( isset( $value[ $close ], $value[ $close + 1 ] ) && '"' === $value[ $close ] && ';' === $value[ $close + 1 ] ) ) {
					$is_token = false;
				} else {
					$out .= substr( $value, $i, $pos - $i );
					$content = substr( $value, $content_start, $declared );
					// Full replace(), NOT replace_scalar(): a string token's
					// content can itself be serialized or JSON (double-
					// serialized values are common in plugin meta). A plain
					// scalar replace would rewrite the inner payload's text
					// while leaving that payload's OWN length prefixes stale
					// — the outer length would come out right and the inner
					// data would be silently corrupt. Recursing terminates:
					// content is always a strict substring of $value.
					$new_content = self::replace( $content, $pairs, $stats );
					$out        .= 's:' . strlen( $new_content ) . ':"' . $new_content . '";';
					$i           = $close + 2;
					continue;
				}
			}

			// Not a real string token (e.g. the `s:` inside "https:") —
			// emit it verbatim and resume scanning one byte later.
			$out .= substr( $value, $i, $pos - $i + 1 );
			$i    = $pos + 1;
		}

		// Cheap structural sanity check: whatever we produced must still
		// unserialize. If it doesn't, something about this blob defeated the
		// scanner and the safe move is to leave the original alone.
		if ( false === @unserialize( $out, array( 'allowed_classes' => false ) ) && 'b:0;' !== $out ) {
			return null;
		}

		return $out;
	}

	/**
	 * True if the serialized blob contains an object-ish token anywhere:
	 * `O:` (normal object), `C:` (Serializable::serialize payload) or `E:`
	 * (enum, PHP 8.1+). Matched only where a token can legally begin — at the
	 * very start, or straight after `;`, `{` or `:` — so the same sequence
	 * appearing inside string CONTENT can't trigger a false positive.
	 */
	private static function has_object_token( $value ) {
		return 1 === preg_match( '/(?:^|[;{:])[OCE]:\d+:"/', $value );
	}

	private static function replace_serialized( $value, array $pairs, array &$stats ) {
		// Route object payloads to the byte rewriter WITHOUT unserializing
		// them first. Previously this relied on unserialize() handing back a
		// __PHP_Incomplete_Class that could then be detected — but that means
		// the dangerous value has already been materialised as a PHP object
		// before anything checks it, and anything that subsequently touches
		// it (here or in code this calls) can trigger the fatal
		// "modify a property on an incomplete object" Error. Deciding from
		// the raw bytes means an object payload is never unserialized at all,
		// so that Error has no way to occur on this path.
		if ( self::has_object_token( $value ) ) {
			$rewritten = self::rewrite_serialized_strings( $value, $pairs, $stats );
			if ( null === $rewritten ) {
				$stats['warnings'][] = 'A serialized object could not be safely rewritten and was left unchanged — any URLs inside it still point at the source site.';
				return $value;
			}
			return $rewritten;
		}

		$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) );

		if ( false === $unserialized && 'b:0;' !== $value ) {
			// Two possibilities land here: genuine corruption (or a
			// serialized object using a class not present on this site —
			// allowed_classes=>false always returns an incomplete
			// __PHP_Incomplete_Class rather than false for those, so a hard
			// `false` here means real corruption, not just a missing
			// class), OR looks_serialized()'s deliberately-broad pre-check
			// matched plain text that merely starts with a type-tag prefix
			// (e.g. a value that happens to start with "s:" or "i:") and
			// isn't serialized data at all. Either way, re-serializing is
			// unsafe — but the value still needs its plain-text replace
			// pass, or a genuine URL inside non-serialized "false positive"
			// text would silently never get rewritten.
			$stats['warnings'][] = 'A value looked serialized but failed to unserialize safely — treated as plain text instead.';
			return self::replace_scalar( $value, $pairs, $stats );
		}

		// Backstop for anything object-ish that has_object_token() didn't
		// recognise from the bytes (an encoding this parser doesn't know).
		// Kept deliberately: the token check above is the primary defence,
		// this catches the unknown-unknown rather than letting it reach a
		// property write.
		if ( self::contains_incomplete_object( $unserialized ) ) {
			$rewritten = self::rewrite_serialized_strings( $value, $pairs, $stats );
			if ( null === $rewritten ) {
				$stats['warnings'][] = 'A serialized object could not be safely rewritten and was left unchanged — any URLs inside it still point at the source site.';
				return $value;
			}
			return $rewritten;
		}

		$replaced = self::replace_recursive( $unserialized, $pairs, $stats );

		$reserialized = serialize( $replaced );
		return $reserialized;
	}

	private static function replace_json( $value, array $pairs, array &$stats ) {
		$decoded = json_decode( $value, true );
		if ( null === $decoded && 'null' !== trim( $value ) ) {
			return self::replace_scalar( $value, $pairs, $stats ); // Not actually valid JSON after all.
		}

		$replaced = self::replace_recursive( $decoded, $pairs, $stats );

		// Preserve Elementor's own escaped-slash convention: it always
		// stores unescaped-slash-free JSON (json_encode's default), so
		// re-encoding with the same default flags round-trips correctly.
		$reencoded = wp_json_encode( $replaced );
		return false === $reencoded ? $value : $reencoded;
	}

	private static function replace_recursive( $data, array $pairs, array &$stats ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $val ) {
				// Keys can themselves be serialized-looking strings in some
				// plugins' meta storage; scalar-replace keys (cheap, and
				// keys are never themselves full serialized blobs in
				// practice) while fully recursing values.
				$new_key = is_string( $key ) ? self::replace_scalar( $key, $pairs, $stats ) : $key;
				$out[ $new_key ] = self::replace_recursive( $val, $pairs, $stats );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			// Belt-and-braces: replace_serialized() already diverts anything
			// containing an incomplete object to the byte-level rewriter, so
			// reaching here with one would mean a new caller bypassed that.
			// Assigning a property to an incomplete object is a FATAL Error
			// (not a catchable warning), which would abort a half-finished
			// import — cheap to guard, expensive to get wrong.
			if ( $data instanceof __PHP_Incomplete_Class ) {
				$stats['warnings'][] = 'Skipped an object whose class is not loaded on this site; URLs inside it were left unchanged.';
				return $data;
			}
			foreach ( $data as $key => $val ) {
				$data->$key = self::replace_recursive( $val, $pairs, $stats );
			}
			return $data;
		}

		if ( is_string( $data ) ) {
			return self::replace( $data, $pairs, $stats );
		}

		return $data; // int/float/bool/null — nothing to replace.
	}
}
