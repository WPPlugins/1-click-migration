<?php
/**
 * Finds hosts left behind after the primary siteurl rewrite and builds
 * serialized-safe replacement pairs for the ones an admin confirms.
 */
namespace OCM;

class OCM_Extra_Domains {

	const OPTION = 'ocm_extra_domain_candidates';
	const MAX_CANDIDATES = 40;
	const PAGE_SIZE = 200;
	const MAX_FOUND_HOSTS = 1000;

	/**
	 * Read text columns and return confirmable hosts other than the source and destination siteurls.
	 *
	 * @param object        $wpdb            WordPress database handle.
	 * @param string        $source_siteurl  Siteurl captured from the restored options table.
	 * @param string        $dest_siteurl    Destination site_url().
	 * @param callable|null $on_progress     Called between pages so a worker can heartbeat.
	 * @return array{candidates: array<int, array>, omitted: int}
	 */
	public static function discover( $wpdb, $source_siteurl, $dest_siteurl, $on_progress = null ) {
		$found  = array();
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $tables ) ) {
			return array(
				'candidates' => array(),
				'omitted'    => 0,
			);
		}

		foreach ( $tables as $table ) {
			if ( ! self::is_safe_ident( $table ) ) {
				continue;
			}
			$fields = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`' );
			$text_columns = array();
			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					$name = isset( $field->Field ) ? $field->Field : '';
					$type = isset( $field->Type ) ? $field->Type : '';
					if ( self::is_safe_ident( $name ) && self::is_text_type( $type ) ) {
						$text_columns[] = $name;
					}
				}
			}
			if ( ! $text_columns ) {
				continue;
			}

			$select = array();
			foreach ( $text_columns as $column ) {
				$select[] = '`' . $column . '`';
			}
			$sql_columns = implode( ', ', $select );

			for ( $page = 0; $page < 100000; $page++ ) {
				$offset = $page * self::PAGE_SIZE;
				$rows   = $wpdb->get_results(
					'SELECT ' . $sql_columns . ' FROM `' . $table . '` LIMIT ' . (int) $offset . ', ' . (int) self::PAGE_SIZE,
					ARRAY_A
				);
				if ( ! is_array( $rows ) || ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $row as $value ) {
						self::collect_from_text( $found, $value );
					}
				}
				if ( is_callable( $on_progress ) ) {
					call_user_func( $on_progress );
				}
				if ( count( $rows ) < self::PAGE_SIZE ) {
					break;
				}
			}
		}

		return self::finalize( $found, $source_siteurl, $dest_siteurl );
	}

	/**
	 * @param array  $found
	 * @param string $source_siteurl
	 * @param string $dest_siteurl
	 * @return array{candidates: array<int, array>, omitted: int}
	 */
	public static function finalize( $found, $source_siteurl, $dest_siteurl ) {
		$rows = array();
		foreach ( $found as $search_host => $row ) {
			if ( '__truncated' === $search_host ) {
				continue;
			}
			$host_only = self::host_without_port( $search_host );
			if ( self::is_primary_host( $host_only, $source_siteurl, $dest_siteurl ) ) {
				continue;
			}
			$class = self::classify_host( $host_only );
			$label = $search_host;
			if ( ! empty( $row['path_prefixes'] ) ) {
				$label = sprintf(
					/* translators: 1: host name, 2: comma-separated URL paths on that host */
					__( '%1$s (path %2$s)', '1-click-migration' ),
					$search_host,
					implode( ', ', $row['path_prefixes'] )
				);
			}
			$example = $row['example'];
			if ( strlen( $example ) > 160 ) {
				$example = substr( $example, 0, 157 ) . '...';
			}
			$rows[] = array(
				'id'             => substr( sha1( $search_host ), 0, 12 ),
				'host'           => $search_host,
				'search_host'    => $search_host,
				'path_prefixes'  => array_values( $row['path_prefixes'] ),
				'example'        => $example,
				'class'          => $class,
				'checked'        => false,
				'count'          => (int) $row['count'],
				'label'          => $label,
				'note'           => self::note_for_class( $class ),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				$rank = array(
					'suggest'     => 0,
					'other'       => 1,
					'leave_alone' => 2,
				);
				$ra = isset( $rank[ $a['class'] ] ) ? $rank[ $a['class'] ] : 9;
				$rb = isset( $rank[ $b['class'] ] ) ? $rank[ $b['class'] ] : 9;
				if ( $ra !== $rb ) {
					return $ra - $rb;
				}
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] - $a['count'];
				}
				return strcmp( $a['host'], $b['host'] );
			}
		);

		$omitted = ! empty( $found['__truncated'] ) ? 1 : 0;
		if ( count( $rows ) > self::MAX_CANDIDATES ) {
			$omitted += count( $rows ) - self::MAX_CANDIDATES;
			$rows    = array_slice( $rows, 0, self::MAX_CANDIDATES );
		}

		return array(
			'candidates' => $rows,
			'omitted'    => $omitted,
		);
	}

	/**
	 * @param string $host Hostname without a scheme. A trailing :port is ignored.
	 * @return string suggest|leave_alone|other
	 */
	public static function classify_host( $host ) {
		$host = strtolower( (string) $host );
		$host = self::host_without_port( $host );
		if ( self::host_matches( $host, self::leave_alone_suffixes() ) ) {
			return 'leave_alone';
		}
		if ( self::is_suggested_host( $host ) ) {
			return 'suggest';
		}
		return 'other';
	}

	/**
	 * Primary siteurl → destination pairs, including http/https, www, path
	 * prefixes (cPanel /~user), and JSON-escaped variants.
	 *
	 * @param string $source_siteurl
	 * @param string $dest_siteurl
	 * @return array<int, array{search: string, replace: string, url_boundary?: bool}>
	 */
	public static function primary_replacement_pairs( $source_siteurl, $dest_siteurl ) {
		$source_siteurl = (string) $source_siteurl;
		$dest_siteurl   = (string) $dest_siteurl;
		if ( '' === $source_siteurl || 0 === strcasecmp( $source_siteurl, $dest_siteurl ) ) {
			return array();
		}

		$pairs = array(
			array(
				'search'       => $source_siteurl,
				'replace'      => $dest_siteurl,
				// Always boundary-safe: never rewrite a longer host that merely
				// contains the old site host as a substring (cdn.oldsite.com,
				// example.com.au when searching example.com, etc.).
				'url_boundary' => true,
			),
		);

		$parts = parse_url( $source_siteurl );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$host = strtolower( (string) $parts['host'] );
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}
			$search_host = $host;
			if ( ! empty( $parts['port'] ) ) {
				$search_host .= ':' . (int) $parts['port'];
			}

			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			$path = rtrim( $path, '/' );
			// Subfolder / ~user installs: only scheme and www variants of
			// host+full path. No bare-host pairs (those silently rewrite
			// localhost/otherproject or ~otheruser on shared hosts).
			if ( '' !== $path && '/' !== $path ) {
				$pairs = array_merge(
					$pairs,
					self::path_locked_host_pairs( $search_host, $path, $dest_siteurl )
				);
			} else {
				$path_prefixes = array();
				$pairs         = array_merge(
					$pairs,
					self::replacement_pairs(
						array(
							'search_host'   => $search_host,
							'path_prefixes' => $path_prefixes,
						),
						$dest_siteurl
					)
				);
			}
		}

		return self::with_json_escaped_variants( $pairs );
	}

	/**
	 * Scheme/www variants of host + full site path only (no bare host).
	 *
	 * @param string $search_host Host with optional port, www already stripped.
	 * @param string $path        Absolute path without trailing slash (e.g. /~user or /subfolder).
	 * @param string $dest_siteurl
	 * @return array<int, array{search: string, replace: string, url_boundary: bool}>
	 */
	public static function path_locked_host_pairs( $search_host, $path, $dest_siteurl ) {
		$search_host = strtolower( (string) $search_host );
		$path        = (string) $path;
		if ( ! preg_match( '/^[a-z0-9.:-]+$/', $search_host ) || false !== strpos( $search_host, '..' ) ) {
			return array();
		}
		if ( '' === $path || '/' === $path || $path[0] !== '/' ) {
			return array();
		}
		$dest = self::site_origin( $dest_siteurl );
		// Prefer the full destination URL (may include a path) as the replace
		// target for path-locked pairs, matching the exact primary pair.
		$dest_full = rtrim( (string) $dest_siteurl, '/' );
		if ( '' === $dest_full ) {
			$dest_full = $dest;
		}
		if ( '' === $dest_full ) {
			return array();
		}

		$host_only = self::host_without_port( $search_host );
		$hosts     = array( $search_host );
		if ( self::allow_www_variant( $host_only ) && 0 !== strpos( $host_only, 'www.' ) ) {
			$hosts[] = 'www.' . $search_host;
		}

		$pairs = array();
		foreach ( array( 'http', 'https' ) as $scheme ) {
			foreach ( $hosts as $host ) {
				$search = $scheme . '://' . $host . $path;
				if ( 0 === strcasecmp( $search, $dest_full ) ) {
					continue;
				}
				$pairs[ $search ] = $dest_full;
			}
		}

		$ordered = array();
		foreach ( $pairs as $search => $replace ) {
			$ordered[] = array(
				'search'       => $search,
				'replace'      => $replace,
				'url_boundary' => true,
			);
		}
		usort(
			$ordered,
			function ( $a, $b ) {
				$len = strlen( $b['search'] ) - strlen( $a['search'] );
				if ( 0 !== $len ) {
					return $len;
				}
				return strcmp( $a['search'], $b['search'] );
			}
		);
		return $ordered;
	}

	/**
	 * Add JSON-escaped URL variants (https:\/\/…) alongside plain pairs.
	 *
	 * @param array<int, array{search: string, replace: string, url_boundary?: bool}> $pairs
	 * @return array<int, array{search: string, replace: string, url_boundary?: bool}>
	 */
	public static function with_json_escaped_variants( $pairs ) {
		if ( ! is_array( $pairs ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['search'] ) || '' === $pair['search'] ) {
				continue;
			}
			$search = (string) $pair['search'];
			if ( ! isset( $seen[ $search ] ) ) {
				$seen[ $search ] = true;
				$out[]           = array(
					'search'       => $search,
					'replace'      => isset( $pair['replace'] ) ? (string) $pair['replace'] : '',
					'url_boundary' => ! empty( $pair['url_boundary'] ),
				);
			}
			$esc_search  = str_replace( '/', '\\/', $search );
			$esc_replace = str_replace( '/', '\\/', isset( $pair['replace'] ) ? (string) $pair['replace'] : '' );
			if ( $esc_search !== $search && ! isset( $seen[ $esc_search ] ) ) {
				$seen[ $esc_search ] = true;
				$out[]               = array(
					'search'       => $esc_search,
					'replace'      => $esc_replace,
					'url_boundary' => ! empty( $pair['url_boundary'] ),
				);
			}
		}

		usort(
			$out,
			function ( $a, $b ) {
				$len = strlen( $b['search'] ) - strlen( $a['search'] );
				if ( 0 !== $len ) {
					return $len;
				}
				return strcmp( $a['search'], $b['search'] );
			}
		);
		return $out;
	}

	/**
	 * Longest-first http and https pairs, including a /~user path prefix when one was seen.
	 *
	 * @param array  $candidate
	 * @param string $dest_siteurl
	 * @return array<int, array{search: string, replace: string}>
	 */
	public static function replacement_pairs( $candidate, $dest_siteurl ) {
		if ( ! is_array( $candidate ) ) {
			return array();
		}
		$search_host = isset( $candidate['search_host'] ) ? strtolower( (string) $candidate['search_host'] ) : '';
		if ( ! preg_match( '/^[a-z0-9.:-]+$/', $search_host ) || false !== strpos( $search_host, '..' ) ) {
			return array();
		}
		$dest = self::site_origin( $dest_siteurl );
		if ( '' === $dest ) {
			return array();
		}

		$prefixes = array();
		if ( ! empty( $candidate['path_prefixes'] ) && is_array( $candidate['path_prefixes'] ) ) {
			foreach ( $candidate['path_prefixes'] as $prefix ) {
				if ( is_string( $prefix ) && preg_match( '#^/~[A-Za-z0-9._-]+$#', $prefix ) ) {
					$prefixes[] = $prefix;
				}
			}
		}

		$host_only = self::host_without_port( $search_host );
		$hosts     = array( $search_host );
		if ( self::allow_www_variant( $host_only ) && 0 !== strpos( $host_only, 'www.' ) ) {
			$hosts[] = 'www.' . $search_host;
		}

		$pairs = array();
		foreach ( array( 'http', 'https' ) as $scheme ) {
			foreach ( $hosts as $host ) {
				foreach ( $prefixes as $prefix ) {
					$pairs[ $scheme . '://' . $host . $prefix ] = $dest;
				}
				$pairs[ $scheme . '://' . $host ] = $dest;
			}
		}

		$ordered = array();
		foreach ( $pairs as $search => $replace ) {
			if ( 0 === strcasecmp( $search, $replace ) ) {
				continue;
			}
			$ordered[] = array(
				'search'  => $search,
				'replace' => $replace,
				'url_boundary' => true,
			);
		}
		usort(
			$ordered,
			function ( $a, $b ) {
				$len = strlen( $b['search'] ) - strlen( $a['search'] );
				if ( 0 !== $len ) {
					return $len;
				}
				return strcmp( $a['search'], $b['search'] );
			}
		);
		return $ordered;
	}

	/**
	 * @param string $url
	 * @return string
	 */
	public static function site_origin( $url ) {
		$parts = parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			$scheme = 'https';
		}
		$origin = $scheme . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		if ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) {
			$origin .= rtrim( $parts['path'], '/' );
		}
		return $origin;
	}

	/**
	 * Fields safe to hand to the admin page.
	 *
	 * @param array $candidates
	 * @return array<int, array>
	 */
	public static function public_rows( $candidates ) {
		$public = array();
		if ( ! is_array( $candidates ) ) {
			return $public;
		}
		foreach ( $candidates as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['host'] ) ) {
				continue;
			}
			$public[] = array(
				'id'      => (string) $row['id'],
				'host'    => (string) $row['host'],
				'label'   => isset( $row['label'] ) ? (string) $row['label'] : (string) $row['host'],
				'example' => isset( $row['example'] ) ? (string) $row['example'] : '',
				'class'   => isset( $row['class'] ) ? (string) $row['class'] : 'other',
				'checked' => ! empty( $row['checked'] ) ? 1 : 0,
				'count'   => isset( $row['count'] ) ? (int) $row['count'] : 0,
				// From the class, in the viewer's language: the stored note was
				// written by whichever request found the address.
				'note'    => self::note_for_class( isset( $row['class'] ) ? (string) $row['class'] : 'other' ),
			);
		}
		return $public;
	}

	/**
	 * @param array  $found
	 * @param mixed  $value
	 */
	public static function collect_from_text( &$found, $value ) {
		if ( ! is_string( $value ) || '' === $value || false !== strpos( $value, "\0" ) ) {
			return;
		}
		if ( false === stripos( $value, 'http://' ) && false === stripos( $value, 'https://' ) ) {
			return;
		}
		if ( ! preg_match_all( '#https?://[^\s"\'<>\\\\]+#i', $value, $matches ) ) {
			return;
		}
		foreach ( $matches[0] as $raw ) {
			$url   = rtrim( $raw, ".,);]'\"}" );
			$parts = parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$search_host = strtolower( $parts['host'] );
			if ( 0 === strpos( $search_host, 'www.' ) ) {
				$search_host = substr( $search_host, 4 );
			}
			if ( ! preg_match( '/^[a-z0-9.:-]+$/', $search_host ) ) {
				continue;
			}
			if ( ! empty( $parts['port'] ) ) {
				$search_host .= ':' . (int) $parts['port'];
			}
			if ( ! isset( $found[ $search_host ] ) ) {
				if ( count( $found ) >= self::MAX_FOUND_HOSTS ) {
					$found['__truncated'] = true;
					continue;
				}
				$found[ $search_host ] = array(
					'path_prefixes' => array(),
					'example'       => $url,
					'count'         => 0,
				);
			}
			$found[ $search_host ]['count']++;

			$prefix = '';
			if ( ! empty( $parts['path'] ) && preg_match( '#^/~([A-Za-z0-9._-]+)#', $parts['path'], $path_match ) ) {
				$prefix = '/~' . $path_match[1];
			}
			if ( '' !== $prefix && ! in_array( $prefix, $found[ $search_host ]['path_prefixes'], true ) ) {
				if ( count( $found[ $search_host ]['path_prefixes'] ) < 5 ) {
					$found[ $search_host ]['path_prefixes'][] = $prefix;
				}
			}
			if ( '' !== $prefix && false === strpos( $found[ $search_host ]['example'], $prefix ) ) {
				$found[ $search_host ]['example'] = $url;
			}
		}
	}

	/**
	 * @param string $class
	 * @return string
	 */
	private static function note_for_class( $class ) {
		if ( 'suggest' === $class ) {
			return __( 'Possible old site address', '1-click-migration' );
		}
		if ( 'leave_alone' === $class ) {
			return __( 'CDN, embed, or affiliate address', '1-click-migration' );
		}
		return __( 'Other address', '1-click-migration' );
	}

	/**
	 * @param string $host
	 * @param string $source_siteurl
	 * @param string $dest_siteurl
	 * @return bool
	 */
	private static function is_primary_host( $host, $source_siteurl, $dest_siteurl ) {
		$host = self::bare_host( $host );
		if ( '' === $host ) {
			return true;
		}
		$source = self::bare_host( $source_siteurl );
		$dest   = self::bare_host( $dest_siteurl );
		return ( '' !== $source && $host === $source ) || ( '' !== $dest && $host === $dest );
	}

	/**
	 * @param string $host
	 * @return string
	 */
	private static function host_without_port( $host ) {
		$host = (string) $host;
		if ( 1 === substr_count( $host, ':' ) && preg_match( '/^(.+):\d+$/', $host, $matches ) ) {
			return $matches[1];
		}
		return $host;
	}

	/**
	 * @param string $value URL or host.
	 * @return string
	 */
	private static function bare_host( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( false !== strpos( $value, '://' ) ) {
			$parts = parse_url( $value );
			$value = ( is_array( $parts ) && ! empty( $parts['host'] ) ) ? $parts['host'] : '';
		}
		$value = self::host_without_port( $value );
		if ( 0 === strpos( $value, 'www.' ) ) {
			$value = substr( $value, 4 );
		}
		return $value;
	}

	/**
	 * @param string $host
	 * @return bool
	 */
	private static function is_suggested_host( $host ) {
		if ( 'localhost' === $host || '::1' === $host ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( preg_match( '/^(?:\d{1,3}\.){3}\d{1,3}$/', $host ) ) {
			return true;
		}
		return self::host_matches( $host, self::suggest_suffixes() );
	}

	/**
	 * @param string $host
	 * @return bool
	 */
	private static function allow_www_variant( $host ) {
		if ( 'localhost' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( preg_match( '/^(?:\d{1,3}\.){3}\d{1,3}$/', $host ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param string        $host
	 * @param array<int, string> $suffixes
	 * @return bool
	 */
	private static function host_matches( $host, $suffixes ) {
		foreach ( $suffixes as $suffix ) {
			if ( $host === $suffix ) {
				return true;
			}
			$tail = '.' . $suffix;
			if ( strlen( $host ) > strlen( $tail ) && substr( $host, -strlen( $tail ) ) === $tail ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	private static function is_safe_ident( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
	}

	/**
	 * @param string $type
	 * @return bool
	 */
	private static function is_text_type( $type ) {
		$type = strtolower( (string) $type );
		if ( false !== strpos( $type, 'blob' ) || false !== strpos( $type, 'binary' ) ) {
			return false;
		}
		foreach ( array( 'char', 'text', 'json', 'enum', 'set' ) as $needle ) {
			if ( false !== strpos( $type, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, string>
	 */
	private static function suggest_suffixes() {
		return array(
			'temp.domains',
			'myftpupload.com',
			'wpenginepowered.com',
			'kinsta.cloud',
			'flywheelstaging.com',
			'wpstage.net',
			'cloudwaysapps.com',
			'mystagingwebsite.com',
			'wpcomstaging.com',
			'pantheonsite.io',
			'local',
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function leave_alone_suffixes() {
		return array(
			'youtube.com',
			'youtu.be',
			'vimeo.com',
			'gravatar.com',
			'googleapis.com',
			'gstatic.com',
			'google.com',
			'googleusercontent.com',
			'googletagmanager.com',
			'google-analytics.com',
			'doubleclick.net',
			'facebook.com',
			'facebook.net',
			'fbcdn.net',
			'instagram.com',
			'twitter.com',
			'x.com',
			'twimg.com',
			'amazonaws.com',
			'cloudfront.net',
			'jsdelivr.net',
			'cloudflare.com',
			'cdnjs.cloudflare.com',
			'unpkg.com',
			'wp.com',
			'wordpress.com',
			'paypal.com',
			'stripe.com',
			'amazon.com',
			'shareasale.com',
			'hotjar.com',
			'typekit.net',
			'schema.org',
			'w.org',
		);
	}
}
