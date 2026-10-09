<?php

namespace Holvek\WordPress\Modules\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles secure server-side fetching and parsing of competitor JSON-LD schemas.
 */
class CompetitorScraper {

	/**
	 * Scrapes a URL, extracts JSON-LD blocks, and maps them to Holvek schema structures.
	 */
	public static function scrapeUrl( string $url ): array {
		return ( new self() )->fetch( $url );
	}

	/**
	 * Fetch one public page. Redirects are checked one hop at a time.
	 */
	public function fetch( string $url ): array {
		$current = $this->normalizeRemoteUrl( $url );
		if ( null === $current ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid competitor URL supplied.', 'holvek' ),
			);
		}

		$response = null;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			if ( ! $this->hostIsPublic( $current ) ) {
				return array(
					'success' => false,
					'message' => __( 'That URL points at a private or local address, so it was not fetched.', 'holvek' ),
				);
			}

			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 2 * MB_IN_BYTES,
					'user-agent'          => $this->userAgent(),
					'headers'             => array(
						'Accept' => 'text/html',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: error message from the HTTP client. */
						__( 'Failed to fetch URL: %s', 'holvek' ),
						$response->get_error_message()
					),
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				break;
			}

			if ( 3 === $hop ) {
				return array(
					'success' => false,
					'message' => __( 'The competitor URL redirected too many times.', 'holvek' ),
				);
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			$next     = $this->resolveRedirect( $current, is_string( $location ) ? $location : '' );
			if ( null === $next ) {
				return array(
					'success' => false,
					'message' => __( 'Invalid competitor URL supplied.', 'holvek' ),
				);
			}
			$current = $next;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Competitor URL returned status code %d.', 'holvek' ),
					$code
				),
			);
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return array(
				'success' => false,
				'message' => __( 'Competitor URL returned empty content.', 'holvek' ),
			);
		}

		// Find all JSON-LD blocks
		if ( ! preg_match_all( '/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return array(
				'success' => false,
				'message' => __( 'No JSON-LD schemas found on the competitor page.', 'holvek' ),
			);
		}

		$schemas = array();
		foreach ( $matches[1] as $jsonText ) {
			$jsonText = trim( $jsonText );
			// strip HTML comments or CDATA wrapper inside script tags if present
			$jsonText = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $jsonText );
			$jsonText = preg_replace( '/^\s*<!--|-->\s*$/s', '', $jsonText );

			$decoded = json_decode( $jsonText, true );
			if ( is_array( $decoded ) ) {
				$schemas[] = $decoded;
			}
		}

		if ( empty( $schemas ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not decode any valid JSON-LD graphs.', 'holvek' ),
			);
		}

		// Try to locate relevant target schemas from the graphs
		$extracted = self::locateTargetSchemas( $schemas );

		return array(
			'success' => true,
			'schemas' => $extracted,
		);
	}

	private function userAgent(): string {
		$version = defined( 'HOLVEK_VERSION' ) ? HOLVEK_VERSION : '1.0.0';
		return 'Holvek-WP/' . $version . ' (' . home_url( '/' ) . ')';
	}

	private function normalizeRemoteUrl( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url || str_contains( $url, '\\' ) || preg_match( '/[[:cntrl:][:space:]]/', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		if ( ! $this->isAcceptableHost( $host ) ) {
			return null;
		}

		$port = '';
		if ( isset( $parts['port'] ) ) {
			$portNumber = (int) $parts['port'];
			if ( $portNumber < 1 || $portNumber > 65535 ) {
				return null;
			}
			$port = ':' . $portNumber;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( ! str_starts_with( $path, '/' ) ) {
			return null;
		}

		$query       = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$displayHost = str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
		return $scheme . '://' . $displayHost . $port . $path . $query;
	}

	private function resolveRedirect( string $current, string $location ): ?string {
		$location = trim( $location );
		if ( '' === $location ) {
			return null;
		}

		if ( str_starts_with( $location, '//' ) ) {
			$scheme   = wp_parse_url( $current, PHP_URL_SCHEME );
			$location = ( is_string( $scheme ) && '' !== $scheme ? $scheme : 'https' ) . ':' . $location;
		} elseif ( str_starts_with( $location, '/' ) ) {
			$parts = wp_parse_url( $current );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return null;
			}
			$host = (string) $parts['host'];
			if ( str_contains( $host, ':' ) ) {
				$host = '[' . $host . ']';
			}
			$port     = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
			$location = $parts['scheme'] . '://' . $host . $port . $location;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			$base     = (string) preg_replace( '#/[^/]*$#', '/', $current );
			$location = $base . $location;
		}

		return $this->normalizeRemoteUrl( $location );
	}

	private function hostIsPublic( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || 'metadata.google.internal' === rtrim( $host, '.' ) ) {
			return false;
		}

		$addresses = $this->resolveHost( $host );
		if ( array() === $addresses ) {
			return false;
		}

		foreach ( $addresses as $address ) {
			if ( $this->isBlockedAddress( (string) $address ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Addresses for a host. Includes every A and AAAA answer so one private address blocks the fetch.
	 *
	 * @return array<int, string>
	 */
	protected function resolveHost( string $host ): array {
		$host = strtolower( trim( $host, '[]' ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$addresses = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$type    = DNS_A;
			$type   |= defined( 'DNS_AAAA' ) ? DNS_AAAA : 0;
			$records = dns_get_record( $host, $type );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! is_array( $record ) ) {
						continue;
					}
					if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
						$addresses[] = $record['ip'];
					}
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		if ( array() === $addresses && function_exists( 'gethostbynamel' ) ) {
			$records = gethostbynamel( $host );
			if ( is_array( $records ) ) {
				$addresses = $records;
			}
		}

		return $addresses;
	}

	private function isAcceptableHost( string $host ): bool {
		if ( '' === $host || str_contains( $host, '%' ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( ! str_contains( $host, '.' ) ) {
			return false;
		}
		return 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host );
	}

	private function isBlockedAddress( string $ip ): bool {
		$ip = strtolower( trim( $ip, '[]' ) );
		if ( str_starts_with( $ip, '::ffff:' ) ) {
			$mapped = substr( $ip, 7 );
			if ( filter_var( $mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $this->isBlockedAddress( $mapped );
			}
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return $this->isCarrierGradeNat( $ip );
	}

	private function isCarrierGradeNat( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		$packed = inet_pton( $ip );
		if ( ! is_string( $packed ) || 4 !== strlen( $packed ) ) {
			return false;
		}

		$bytes  = array_values( unpack( 'C4', $packed ) );
		$ipLong = ( $bytes[0] * 16777216 ) + ( $bytes[1] * 65536 ) + ( $bytes[2] * 256 ) + $bytes[3];
		$block  = ( 100 * 16777216 ) + ( 64 * 65536 );
		return $ipLong >= $block && $ipLong < ( $block + 4194304 );
	}

	/**
	 * Searches through the found graphs to find supported schema types.
	 */
	private static function locateTargetSchemas( array $schemas ): array {
		$result = array();

		// Flatten the graphs if any schemas use @graph
		$flat = array();
		foreach ( $schemas as $s ) {
			if ( isset( $s['@graph'] ) && is_array( $s['@graph'] ) ) {
				foreach ( $s['@graph'] as $node ) {
					$flat[] = $node;
				}
			} else {
				$flat[] = $s;
			}
		}

		foreach ( $flat as $node ) {
			if ( ! isset( $node['@type'] ) ) {
				continue;
			}

			$type = strtolower( $node['@type'] );

			// 1. Product mapping
			if ( $type === 'product' && ! isset( $result['product'] ) ) {
				$offers       = isset( $node['offers'] ) ? $node['offers'] : array();
				$price        = '';
				$currency     = 'USD';
				$availability = 'InStock';

				if ( is_array( $offers ) ) {
					if ( isset( $offers['price'] ) ) {
						$price = $offers['price'];
					}
					if ( isset( $offers['priceCurrency'] ) ) {
						$currency = $offers['priceCurrency'];
					}
					if ( isset( $offers['availability'] ) ) {
						$availability = str_replace( 'https://schema.org/', '', $offers['availability'] );
					}
				}

				$rating = '';
				if ( isset( $node['aggregateRating']['ratingValue'] ) ) {
					$rating = $node['aggregateRating']['ratingValue'];
				}

				$result['product'] = array(
					'name'         => isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : '',
					'description'  => isset( $node['description'] ) ? sanitize_text_field( $node['description'] ) : '',
					'price'        => sanitize_text_field( $price ),
					'currency'     => sanitize_text_field( $currency ),
					'rating'       => sanitize_text_field( $rating ),
					'availability' => sanitize_text_field( $availability ),
				);
			}

			// 2. FAQ mapping
			if ( ( $type === 'faqpage' || $type === 'faq' ) && ! isset( $result['faq'] ) ) {
				$qaList = array();
				if ( isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
					foreach ( $node['mainEntity'] as $q ) {
						if ( isset( $q['name'] ) && isset( $q['acceptedAnswer']['text'] ) ) {
							$qaList[] = array(
								'question' => sanitize_text_field( $q['name'] ),
								'answer'   => wp_kses_post( $q['acceptedAnswer']['text'] ),
							);
						}
					}
				}
				if ( ! empty( $qaList ) ) {
					$result['faq'] = array(
						'questions' => $qaList,
					);
				}
			}

			// 3. HowTo mapping
			if ( $type === 'howto' && ! isset( $result['howto'] ) ) {
				$steps = array();
				if ( isset( $node['step'] ) && is_array( $node['step'] ) ) {
					foreach ( $node['step'] as $s ) {
						$stepText = '';
						if ( isset( $s['text'] ) ) {
							$stepText = $s['text'];
						} elseif ( isset( $s['itemListElement'][0]['text'] ) ) {
							$stepText = $s['itemListElement'][0]['text'];
						}

						if ( ! empty( $stepText ) ) {
							$steps[] = array(
								'name' => isset( $s['name'] ) ? sanitize_text_field( $s['name'] ) : '',
								'text' => wp_kses_post( $stepText ),
							);
						}
					}
				}

				$supplies = array();
				if ( isset( $node['supply'] ) && is_array( $node['supply'] ) ) {
					foreach ( $node['supply'] as $sup ) {
						if ( isset( $sup['name'] ) ) {
							$supplies[] = $sup['name'];
						}
					}
				}

				$tools = array();
				if ( isset( $node['tool'] ) && is_array( $node['tool'] ) ) {
					foreach ( $node['tool'] as $t ) {
						if ( isset( $t['name'] ) ) {
							$tools[] = $t['name'];
						}
					}
				}

				$result['howto'] = array(
					'name'        => isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : '',
					'description' => isset( $node['description'] ) ? sanitize_text_field( $node['description'] ) : '',
					'steps'       => $steps,
					'supplies'    => implode( ', ', $supplies ),
					'tools'       => implode( ', ', $tools ),
				);
			}

			// 4. LocalBusiness mapping
			if ( $type === 'localbusiness' && ! isset( $result['localbusiness'] ) ) {
				$address                 = isset( $node['address'] ) ? $node['address'] : array();
				$result['localbusiness'] = array(
					'name'      => isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : '',
					'telephone' => isset( $node['telephone'] ) ? sanitize_text_field( $node['telephone'] ) : '',
					'street'    => isset( $address['streetAddress'] ) ? sanitize_text_field( $address['streetAddress'] ) : '',
					'city'      => isset( $address['addressLocality'] ) ? sanitize_text_field( $address['addressLocality'] ) : '',
					'postal'    => isset( $address['postalCode'] ) ? sanitize_text_field( $address['postalCode'] ) : '',
					'country'   => isset( $address['addressCountry'] ) ? sanitize_text_field( $address['addressCountry'] ) : 'US',
				);
			}

			// 5. Recipe mapping
			if ( $type === 'recipe' && ! isset( $result['recipe'] ) ) {
				$ingredients = isset( $node['recipeIngredient'] ) ? $node['recipeIngredient'] : array();
				$steps       = array();
				if ( isset( $node['recipeInstructions'] ) && is_array( $node['recipeInstructions'] ) ) {
					foreach ( $node['recipeInstructions'] as $idx => $s ) {
						$text = '';
						if ( is_string( $s ) ) {
							$text = $s;
						} elseif ( isset( $s['text'] ) ) {
							$text = $s['text'];
						}
						if ( ! empty( $text ) ) {
							$steps[] = array(
								'name' => isset( $s['name'] ) ? sanitize_text_field( $s['name'] ) : ( 'Step ' . ( $idx + 1 ) ),
								'text' => wp_kses_post( $text ),
							);
						}
					}
				}

				$calories = '';
				if ( isset( $node['nutrition']['calories'] ) ) {
					$calories = preg_replace( '/[^\d]/', '', $node['nutrition']['calories'] );
				}

				$result['recipe'] = array(
					'name'         => isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : '',
					'description'  => isset( $node['description'] ) ? sanitize_text_field( $node['description'] ) : '',
					'ingredients'  => is_array( $ingredients ) ? implode( "\n", array_map( 'sanitize_text_field', $ingredients ) ) : sanitize_text_field( $ingredients ),
					'instructions' => $steps,
					'prep_time'    => isset( $node['prepTime'] ) ? sanitize_text_field( $node['prepTime'] ) : '',
					'cook_time'    => isset( $node['cookTime'] ) ? sanitize_text_field( $node['cookTime'] ) : '',
					'calories'     => sanitize_text_field( $calories ),
				);
			}

			// 6. Event mapping
			if ( $type === 'event' && ! isset( $result['event'] ) ) {
				$location = isset( $node['location'] ) ? $node['location'] : array();
				$venue    = '';
				$address  = '';
				if ( isset( $location['name'] ) ) {
					$venue = $location['name'];
				}
				if ( isset( $location['address']['streetAddress'] ) ) {
					$address = $location['address']['streetAddress'];
				}

				$price    = '';
				$currency = 'USD';
				if ( isset( $node['offers']['price'] ) ) {
					$price = $node['offers']['price'];
				}
				if ( isset( $node['offers']['priceCurrency'] ) ) {
					$currency = $node['offers']['priceCurrency'];
				}

				$result['event'] = array(
					'name'       => isset( $node['name'] ) ? sanitize_text_field( $node['name'] ) : '',
					'start_date' => isset( $node['startDate'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( $node['startDate'] ) ) : '',
					'end_date'   => isset( $node['endDate'] ) ? gmdate( 'Y-m-d\TH:i', strtotime( $node['endDate'] ) ) : '',
					'venue'      => sanitize_text_field( $venue ),
					'address'    => sanitize_text_field( $address ),
					'performer'  => isset( $node['performer']['name'] ) ? sanitize_text_field( $node['performer']['name'] ) : '',
					'price'      => sanitize_text_field( $price ),
					'currency'   => sanitize_text_field( $currency ),
				);
			}
		}

		return $result;
	}
}
