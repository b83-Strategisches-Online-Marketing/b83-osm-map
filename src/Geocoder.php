<?php
/**
 * Ermittelt Koordinaten zu Adressen über Nominatim (OpenStreetMap).
 *
 * Ergebnisse werden dauerhaft zwischengespeichert, damit jede Adresse nur
 * einmal abgefragt wird (Nominatim-Nutzungsrichtlinie: max. 1 Anfrage pro
 * Sekunde, eindeutige Kennung). Besucher fragen nie selbst bei Nominatim an –
 * die Abfrage läuft immer über den Server der Website.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

defined( 'ABSPATH' ) || exit;

final class Geocoder {

	public const OPTION_CACHE = 'b83_osm_geocache';
	private const MAX_CACHE   = 1000;
	private const LOCK        = 'b83_osm_geocode_lock';

	/** Höchstens so viele neue Abfragen pro Seitenaufruf (Ladezeit schützen). */
	private const MAX_PER_REQUEST = 5;

	private int $requests = 0;
	private int $limit    = self::MAX_PER_REQUEST;

	/** Mehr Abfragen erlauben (z. B. beim Speichern im Backend). */
	public function set_limit( int $limit ): void {
		$this->limit = max( 1, min( 50, $limit ) );
	}

	/**
	 * Koordinaten einer Adresse.
	 *
	 * @return array{lat:string,lng:string}|null
	 */
	public function lookup( string $address ): ?array {
		$address = trim( preg_replace( '/\s+/', ' ', $address ) );
		if ( '' === $address || mb_strlen( $address ) > 300 ) {
			return null;
		}

		$key   = md5( mb_strtolower( $address ) );
		$cache = $this->cache();
		if ( isset( $cache[ $key ] ) ) {
			return array(
				'lat' => (string) $cache[ $key ][0],
				'lng' => (string) $cache[ $key ][1],
			);
		}

		// Kürzlich erfolglos gesucht? Dann nicht erneut anfragen.
		if ( get_transient( 'b83_osm_geo_miss_' . $key ) ) {
			return null;
		}
		if ( $this->requests >= $this->limit ) {
			return null;
		}

		$result = $this->request( $address );
		if ( null === $result ) {
			set_transient( 'b83_osm_geo_miss_' . $key, 1, 6 * HOUR_IN_SECONDS );
			return null;
		}

		$cache[ $key ] = array( $result['lat'], $result['lng'], time() );
		if ( count( $cache ) > self::MAX_CACHE ) {
			uasort( $cache, static fn( array $a, array $b ): int => (int) ( $b[2] ?? 0 ) <=> (int) ( $a[2] ?? 0 ) );
			$cache = array_slice( $cache, 0, self::MAX_CACHE, true );
		}
		update_option( self::OPTION_CACHE, $cache, false );
		return $result;
	}

	/** @return array<string,array{0:string,1:string,2:int}> */
	private function cache(): array {
		$cache = get_option( self::OPTION_CACHE, array() );
		return is_array( $cache ) ? $cache : array();
	}

	/** @return array{lat:string,lng:string}|null */
	private function request( string $address ): ?array {
		// Eine Anfrage pro Sekunde für die ganze Website.
		if ( get_transient( self::LOCK ) ) {
			sleep( 1 );
		}
		set_transient( self::LOCK, 1, 2 );
		++$this->requests;

		$url = add_query_arg(
			array(
				'format'          => 'jsonv2',
				'limit'           => 1,
				'q'               => rawurlencode( $address ),
				'accept-language' => 'de',
			),
			'https://nominatim.openstreetmap.org/search'
		);

		/**
		 * Adresse des Geocoding-Dienstes (Nominatim-kompatibel, Antwort: [{lat, lon}]).
		 *
		 * @param string $url     Vollständige Abfrage-URL.
		 * @param string $address Gesuchte Adresse.
		 */
		$url = (string) apply_filters( 'b83_osm_geocode_url', $url, $address );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'User-Agent' => 'b83-osm-map/' . B83_OSM_VERSION . ' (' . home_url( '/' ) . '; office@b83.at)',
					'Referer'    => home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$first = is_array( $body ) ? ( $body[0] ?? null ) : null;
		if ( ! is_array( $first ) ) {
			return null;
		}

		$lat = Sanitizer::coordinate( $first['lat'] ?? '', 90 );
		$lng = Sanitizer::coordinate( $first['lon'] ?? '', 180 );
		if ( '' === $lat || '' === $lng ) {
			return null;
		}
		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	public function clear_cache(): void {
		delete_option( self::OPTION_CACHE );
	}
}
