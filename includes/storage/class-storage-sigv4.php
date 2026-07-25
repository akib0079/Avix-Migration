<?php
/**
 * AWS Signature Version 4 request signing, implemented from the published
 * spec (no SDK dependency, per the design brief) and kept as a pure,
 * side-effect-free class specifically so it can be verified against AWS's
 * own published test vectors independently of any real network call — see
 * the test suite's sigv4 checks, which assert this produces the EXACT
 * signature AWS's documentation says a known request/key must produce.
 *
 * Covers what S3-compatible object storage (AWS S3, Cloudflare R2, Wasabi,
 * DigitalOcean Spaces, MinIO) actually needs: signing a single request
 * (used for bucket/object HEAD, GET, DELETE, and each individual multipart
 * upload part) and computing a canonical query string for presigned-URL
 * style requests should that ever be needed.
 *
 * @package Avix_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avix_Migration_Storage_Sigv4 {

	/**
	 * @param array $request {
	 *     @type string $method       e.g. 'PUT', 'GET', 'DELETE', 'POST'.
	 *     @type string $host         Bare host, e.g. "bucket.s3.amazonaws.com".
	 *     @type string $path         Absolute path, e.g. "/test.txt" (already URI-encoded per segment).
	 *     @type string $query_string Raw query string without leading '?', or ''.
	 *     @type array  $headers      Additional headers to sign, e.g. ['range' => 'bytes=0-9'] — lowercase keys.
	 *     @type string $payload_hash Hex sha256 of the body, or 'UNSIGNED-PAYLOAD'.
	 *     @type string $amz_date     ISO8601 basic format, e.g. "20130524T000000Z".
	 * }
	 * @param string $access_key
	 * @param string $secret_key
	 * @param string $region
	 * @param string $service Always 's3' for object storage.
	 * @return array{authorization:string, headers:array} Headers includes x-amz-date/x-amz-content-sha256/host, merged with the caller's own — pass all of these on the actual HTTP request.
	 */
	public static function sign( array $request, $access_key, $secret_key, $region, $service = 's3' ) {
		$amz_date  = $request['amz_date'];
		$date      = substr( $amz_date, 0, 8 );

		$headers = array_merge(
			array( 'host' => $request['host'] ),
			$request['headers'] ?? array(),
			array(
				'x-amz-content-sha256' => $request['payload_hash'],
				'x-amz-date'           => $amz_date,
			)
		);

		list( $canonical_headers, $signed_headers ) = self::canonicalize_headers( $headers );

		$canonical_request = implode(
			"\n",
			array(
				$request['method'],
				self::canonical_uri( $request['path'] ),
				self::canonical_query_string( $request['query_string'] ?? '' ),
				$canonical_headers,
				$signed_headers,
				$request['payload_hash'],
			)
		);

		$credential_scope = "{$date}/{$region}/{$service}/aws4_request";

		$string_to_sign = implode(
			"\n",
			array(
				'AWS4-HMAC-SHA256',
				$amz_date,
				$credential_scope,
				hash( 'sha256', $canonical_request ),
			)
		);

		$signing_key = self::signing_key( $secret_key, $date, $region, $service );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		return array(
			'authorization' => $authorization,
			'headers'       => $headers,
		);
	}

	private static function signing_key( $secret_key, $date, $region, $service ) {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Each path SEGMENT is URI-encoded (RFC 3986 unreserved chars kept
	 * literal), but the separating '/' characters are not — encoding those
	 * would produce a different (wrong) canonical URI than what S3 expects.
	 */
	private static function canonical_uri( $path ) {
		if ( '' === $path || '/' === $path ) {
			return '/';
		}
		$segments = explode( '/', $path );
		$encoded  = array_map(
			function ( $segment ) {
				return self::rfc3986_encode( rawurldecode( $segment ) );
			},
			$segments
		);
		return implode( '/', $encoded );
	}

	/**
	 * Query parameters sorted by key (SigV4 requirement), each key/value
	 * RFC 3986-encoded independently.
	 */
	private static function canonical_query_string( $query_string ) {
		if ( '' === $query_string ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query_string ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$key   = self::rfc3986_encode( rawurldecode( $parts[0] ) );
			$value = isset( $parts[1] ) ? self::rfc3986_encode( rawurldecode( $parts[1] ) ) : '';
			$pairs[] = array( $key, $value );
		}
		usort(
			$pairs,
			function ( $a, $b ) {
				return $a[0] === $b[0] ? strcmp( $a[1], $b[1] ) : strcmp( $a[0], $b[0] );
			}
		);
		return implode(
			'&',
			array_map(
				function ( $p ) {
					return $p[0] . '=' . $p[1];
				},
				$pairs
			)
		);
	}

	/**
	 * @return array{0:string,1:string} [canonical_headers_block, signed_headers_list]
	 */
	private static function canonicalize_headers( array $headers ) {
		$normalized = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( trim( $name ) );
			// Collapse internal whitespace runs, matching SigV4's header
			// value normalization rule.
			$value = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
			$normalized[ $name ] = $value;
		}
		ksort( $normalized );

		$lines = array();
		foreach ( $normalized as $name => $value ) {
			$lines[] = $name . ':' . $value;
		}

		return array( implode( "\n", $lines ) . "\n", implode( ';', array_keys( $normalized ) ) );
	}

	/** RFC 3986 unreserved characters (A-Za-z0-9 and -._~) pass through literally; everything else is percent-encoded. */
	private static function rfc3986_encode( $raw ) {
		return str_replace( '%7E', '~', rawurlencode( $raw ) );
	}
}
