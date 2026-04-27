<?php
/**
 * ASAE CAE Roster — Wicket API client.
 *
 * Strictly serial, rate-conscious wrapper over wp_remote_get(). All HTTP
 * goes through request() so the per-run request budget and inter-request
 * delay are enforced in one place.
 *
 * The client intentionally does NOT retry forever. On 429 or 5xx it uses a
 * small exponential backoff (1s, 2s, 4s) then throws. Callers decide whether
 * to continue with the next item or abort. This matches _start.md's "low
 * priority, never block another system" requirement.
 *
 * Adapted from asae-group-rosters/includes/class-asae-gr-wicket-client.php.
 *
 * @package ASAE_CAE_Roster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASAE_CAE_Wicket_Exception extends Exception {
	public $status_code = 0;
	public function __construct( $message, $status_code = 0 ) {
		parent::__construct( $message );
		$this->status_code = (int) $status_code;
	}
}

class ASAE_CAE_Wicket_Client {

	/** @var string */
	private $base_url;

	/** @var string HMAC secret used to sign JWTs. */
	private $secret;

	/** @var string Wicket person UUID used as the JWT `sub` claim. */
	private $person_id;

	/** @var string|null Built JWT, cached for the lifetime of this client. */
	private $cached_jwt = null;

	/** @var int */
	private $request_budget;

	/** @var int */
	private $delay_ms;

	/** @var int */
	private $requests_made = 0;

	/**
	 * Build a client. All args fall back to ASAE_CAE_Settings when null.
	 */
	public function __construct( $base_url = null, $secret = null, $person_id = null, $request_budget = null, $delay_ms = null ) {
		$this->base_url       = rtrim( null !== $base_url ? $base_url : ASAE_CAE_Settings::get_base_url(), '/' );
		$this->secret         = null !== $secret ? $secret : ASAE_CAE_Settings::get_secret();
		$this->person_id      = null !== $person_id ? $person_id : ASAE_CAE_Settings::get_person_id();
		$this->request_budget = null !== $request_budget ? (int) $request_budget : ASAE_CAE_Settings::get_request_budget();
		$this->delay_ms       = null !== $delay_ms ? (int) $delay_ms : ASAE_CAE_Settings::get_request_delay_ms();
	}

	public function requests_made() {
		return $this->requests_made;
	}

	public function reset_budget() {
		$this->requests_made = 0;
	}

	public function is_configured() {
		return '' !== $this->base_url && '' !== $this->secret && '' !== $this->person_id;
	}

	/**
	 * Build (and cache) an HS256 JWT from the secret + person_id. Claims follow
	 * the wicket-sdk-php convention: sub, iat, exp — signed with the shared
	 * secret. Cached for the lifetime of this client so a full sync reuses one
	 * token rather than signing per request.
	 */
	private function build_jwt() {
		if ( null !== $this->cached_jwt ) {
			return $this->cached_jwt;
		}
		$iat     = time();
		$header  = self::b64url( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$payload = self::b64url(
			wp_json_encode(
				array(
					'sub' => $this->person_id,
					'iat' => $iat,
					'exp' => $iat + ( 8 * HOUR_IN_SECONDS ),
				)
			)
		);
		$sig              = self::b64url( hash_hmac( 'sha256', $header . '.' . $payload, $this->secret, true ) );
		$this->cached_jwt = $header . '.' . $payload . '.' . $sig;
		return $this->cached_jwt;
	}

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Low-level request. Returns the decoded JSON body array on success.
	 *
	 * @throws ASAE_CAE_Wicket_Exception
	 */
	public function request( $path, $query = array(), $timeout = 20 ) {
		if ( ! $this->is_configured() ) {
			throw new ASAE_CAE_Wicket_Exception( 'Wicket client is not configured (base URL, secret, and person ID are all required).' );
		}

		if ( $this->requests_made >= $this->request_budget ) {
			throw new ASAE_CAE_Wicket_Exception( 'Per-run request budget reached (' . $this->request_budget . ').' );
		}

		// Inter-request courtesy delay (skipped for the very first call).
		if ( $this->requests_made > 0 && $this->delay_ms > 0 ) {
			usleep( $this->delay_ms * 1000 );
		}

		// Path may already be absolute (e.g. Wicket's `links.next`), in which
		// case we send it as-is. Otherwise prepend the base URL.
		$url = preg_match( '#^https?://#i', $path ) ? $path : $this->base_url . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'timeout' => (int) $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->build_jwt(),
				'Accept'        => 'application/vnd.api+json',
				'User-Agent'    => 'ASAE-CAE-Roster/' . ASAE_CAE_VERSION,
			),
		);

		$attempts    = 0;
		$max_retries = 3;
		$backoff     = 1;

		while ( true ) {
			$this->requests_made++;
			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempts < $max_retries ) {
					$attempts++;
					sleep( $backoff );
					$backoff *= 2;
					continue;
				}
				throw new ASAE_CAE_Wicket_Exception( 'HTTP error: ' . $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				$decoded = json_decode( $body, true );
				if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
					throw new ASAE_CAE_Wicket_Exception( 'Invalid JSON response.', $code );
				}
				return $decoded;
			}

			if ( 401 === $code || 403 === $code ) {
				throw new ASAE_CAE_Wicket_Exception( 'Authentication rejected (' . $code . ').', $code );
			}

			if ( ( 429 === $code || ( $code >= 500 && $code < 600 ) ) && $attempts < $max_retries ) {
				$attempts++;
				sleep( $backoff );
				$backoff *= 2;
				continue;
			}

			$snippet = mb_substr( (string) $body, 0, 300 );
			throw new ASAE_CAE_Wicket_Exception( 'HTTP ' . $code . ' from Wicket: ' . $snippet, $code );
		}
	}

	/**
	 * Paginate through a JSON:API collection, returning all `data` rows plus
	 * any `included` resources (sideloaded relationships). Uses `links.next`
	 * when present, otherwise increments `page[number]` until a short page.
	 *
	 * @param string $path
	 * @param array  $query base query args (e.g. filters, include)
	 * @param int    $page_size
	 * @return array{ data: array, included: array }
	 */
	public function get_all( $path, $query = array(), $page_size = 25 ) {
		$out      = array();
		$included = array();
		$page     = 1;
		$next_url = null;

		while ( true ) {
			$paged_query = array_merge(
				$query,
				array(
					'page[size]'   => $page_size,
					'page[number]' => $page,
				)
			);

			$response = $next_url
				? $this->request( $next_url )
				: $this->request( $path, $paged_query );

			if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
				foreach ( $response['data'] as $row ) {
					$out[] = $row;
				}
			}
			if ( isset( $response['included'] ) && is_array( $response['included'] ) ) {
				foreach ( $response['included'] as $row ) {
					$included[] = $row;
				}
			}

			$links = isset( $response['links'] ) && is_array( $response['links'] ) ? $response['links'] : array();
			$self  = isset( $links['self'] ) ? $links['self'] : null;
			if ( ! empty( $links['next'] ) && $links['next'] !== $self ) {
				$next_url = $links['next'];
				$page++;
				continue;
			}

			// No `links.next`: stop when the last page returned fewer rows than
			// page_size (a reliable JSON:API idiom).
			$count = isset( $response['data'] ) && is_array( $response['data'] ) ? count( $response['data'] ) : 0;
			if ( $count < $page_size ) {
				break;
			}
			$page++;
			$next_url = null;
		}

		return array(
			'data'     => $out,
			'included' => $included,
		);
	}

	/**
	 * One-shot test call, used by the Settings "Test Connection" button.
	 *
	 * @return array{0:bool,1:string} [ok, message]
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return array( false, __( 'Base URL, secret, and person ID are all required.', 'asae-cae-roster' ) );
		}
		try {
			// A tiny call that should always be cheap regardless of data volume.
			$this->request(
				'people',
				array(
					'page[size]'   => 1,
					'page[number]' => 1,
				)
			);
			return array( true, __( 'Connection successful.', 'asae-cae-roster' ) );
		} catch ( ASAE_CAE_Wicket_Exception $e ) {
			return array( false, $e->getMessage() );
		}
	}
}
