<?php
/**
 * Speichert Einstellungen und Karten (WordPress-Optionen).
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public const OPTION_SETTINGS = 'b83_osm_settings';
	public const OPTION_MAPS     = 'b83_osm_maps';
	public const OPTION_NEXT_ID  = 'b83_osm_next_id';

	/** @var array<string,mixed>|null */
	private ?array $settings = null;

	/** @var array<int,array<string,mixed>>|null */
	private ?array $maps = null;

	/* ---------------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------------- */

	/** @return array<string,mixed> */
	public function get_settings(): array {
		if ( null === $this->settings ) {
			$this->settings = Sanitizer::settings( (array) get_option( self::OPTION_SETTINGS, array() ) );
		}
		return $this->settings;
	}

	/** @return array<string,mixed> Vollständiges Standard-Design. */
	public function default_design(): array {
		return $this->get_settings()['design'];
	}

	/** @param array<string,mixed> $settings Bereits geprüfte Einstellungen. */
	public function save_settings( array $settings ): void {
		$settings = Sanitizer::settings( $settings );
		update_option( self::OPTION_SETTINGS, $settings, false );
		$this->settings = $settings;

		/**
		 * Nach dem Speichern der Einstellungen.
		 *
		 * @param array<string,mixed> $settings Einstellungen.
		 */
		do_action( 'b83_osm_settings_saved', $settings );
	}

	/* ---------------------------------------------------------------------
	 * Karten
	 * ------------------------------------------------------------------- */

	/** @return array<int,array<string,mixed>> ID => Karte */
	public function get_maps(): array {
		if ( null === $this->maps ) {
			$this->maps = array();
			foreach ( (array) get_option( self::OPTION_MAPS, array() ) as $id => $map ) {
				if ( is_array( $map ) && (int) $id > 0 ) {
					$this->maps[ (int) $id ] = Sanitizer::map( $map ) + array( 'id' => (int) $id );
					$this->maps[ (int) $id ]['updated'] = (int) ( $map['updated'] ?? 0 );
				}
			}
		}
		return $this->maps;
	}

	/** @return array<string,mixed>|null */
	public function get_map( int $id ): ?array {
		return $this->get_maps()[ $id ] ?? null;
	}

	/**
	 * Speichert eine Karte und gibt ihre ID zurück.
	 *
	 * @param int                 $id  0 = neue Karte.
	 * @param array<string,mixed> $map Rohwerte (werden geprüft).
	 */
	public function save_map( int $id, array $map ): int {
		$maps = $this->get_maps();
		if ( $id <= 0 || ! isset( $maps[ $id ] ) ) {
			$id = $this->next_id();
		}
		$clean            = Sanitizer::map( $map );
		$clean['updated'] = time();
		$maps[ $id ]      = $clean + array( 'id' => $id );

		$this->store( $maps );

		/**
		 * Nach dem Speichern einer Karte.
		 *
		 * @param int                 $id  Karten-ID.
		 * @param array<string,mixed> $map Karte.
		 */
		do_action( 'b83_osm_map_saved', $id, $maps[ $id ] );
		return $id;
	}

	public function delete_map( int $id ): bool {
		$maps = $this->get_maps();
		if ( ! isset( $maps[ $id ] ) ) {
			return false;
		}
		unset( $maps[ $id ] );
		$this->store( $maps );

		/**
		 * Nach dem Löschen einer Karte.
		 *
		 * @param int $id Karten-ID.
		 */
		do_action( 'b83_osm_map_deleted', $id );
		return true;
	}

	public function duplicate_map( int $id ): int {
		$map = $this->get_map( $id );
		if ( null === $map ) {
			return 0;
		}
		/* translators: %s: Name der Karte */
		$map['name'] = sprintf( __( '%s (Kopie)', 'b83-osm-map' ), $map['name'] );
		return $this->save_map( 0, $map );
	}

	/** @param array<int,array<string,mixed>> $maps Karten. */
	private function store( array $maps ): void {
		ksort( $maps );
		$plain = array();
		foreach ( $maps as $map_id => $map ) {
			unset( $map['id'] );
			$plain[ $map_id ] = $map;
		}
		update_option( self::OPTION_MAPS, $plain, false );
		$this->maps = null;
	}

	private function next_id(): int {
		$next = max( (int) get_option( self::OPTION_NEXT_ID, 1 ), 1 );
		$used = array_keys( $this->get_maps() );
		while ( in_array( $next, $used, true ) ) {
			++$next;
		}
		update_option( self::OPTION_NEXT_ID, $next + 1, false );
		return $next;
	}
}
