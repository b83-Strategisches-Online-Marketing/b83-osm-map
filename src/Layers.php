<?php
/**
 * Kartenstile (Kachel-Layer).
 *
 * Alle Anbieter erhalten beim Laden der Kacheln die IP-Adresse der Besucher –
 * deshalb in der Datenschutzerklärung nennen oder „Karte erst nach Klick
 * laden“ aktivieren. CARTO, OpenTopoMap und Esri haben eigene
 * Nutzungsbedingungen (siehe README).
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

defined( 'ABSPATH' ) || exit;

final class Layers {

	public const DEFAULT = 'osm';

	/**
	 * @return array<string,array{label:string,url:string,attribution:string,maxZoom:int,subdomains:string,note:string}>
	 */
	public static function all(): array {
		static $layers = null;
		if ( null !== $layers ) {
			return $layers;
		}

		$osm = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>-Mitwirkende';

		$layers = array(
			'osm'           => array(
				'label'       => __( 'OpenStreetMap Standard', 'b83-osm-map' ),
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => $osm,
				'maxZoom'     => 19,
				'subdomains'  => '',
				'note'        => '',
			),
			'osm-de'        => array(
				'label'       => __( 'OpenStreetMap Deutschland (deutsche Beschriftung)', 'b83-osm-map' ),
				'url'         => 'https://tile.openstreetmap.de/{z}/{x}/{y}.png',
				'attribution' => $osm,
				'maxZoom'     => 18,
				'subdomains'  => '',
				'note'        => '',
			),
			'carto-voyager' => array(
				'label'       => __( 'Modern (CARTO Voyager)', 'b83-osm-map' ),
				'url'         => 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
				'attribution' => $osm . ' &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
				'maxZoom'     => 20,
				'subdomains'  => 'abcd',
				'note'        => 'carto',
			),
			'carto-light'   => array(
				'label'       => __( 'Hell (CARTO Positron)', 'b83-osm-map' ),
				'url'         => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
				'attribution' => $osm . ' &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
				'maxZoom'     => 20,
				'subdomains'  => 'abcd',
				'note'        => 'carto',
			),
			'carto-dark'    => array(
				'label'       => __( 'Dunkel (CARTO Dark Matter)', 'b83-osm-map' ),
				'url'         => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
				'attribution' => $osm . ' &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
				'maxZoom'     => 20,
				'subdomains'  => 'abcd',
				'note'        => 'carto',
			),
			'topo'          => array(
				'label'       => __( 'Topografisch (OpenTopoMap)', 'b83-osm-map' ),
				'url'         => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
				'attribution' => $osm . ', SRTM | &copy; <a href="https://opentopomap.org" target="_blank" rel="noopener">OpenTopoMap</a> (CC-BY-SA)',
				'maxZoom'     => 17,
				'subdomains'  => 'abc',
				'note'        => 'topo',
			),
			'satellite'     => array(
				'label'       => __( 'Satellit (Esri World Imagery)', 'b83-osm-map' ),
				'url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
				'attribution' => 'Kacheln &copy; Esri &mdash; Quelle: Esri, Maxar, Earthstar Geographics und die GIS User Community',
				'maxZoom'     => 19,
				'subdomains'  => '',
				'note'        => 'esri',
			),
		);

		/**
		 * Kartenstile ergänzen oder entfernen. Jeder Eintrag braucht label,
		 * url (https, Platzhalter {z} {x} {y}), attribution und maxZoom.
		 *
		 * @param array<string,array<string,mixed>> $layers Kartenstile.
		 */
		$filtered = (array) apply_filters( 'b83_osm_layers', $layers );
		$layers   = array();
		foreach ( $filtered as $key => $layer ) {
			$key = sanitize_key( (string) $key );
			$url = is_array( $layer ) ? (string) ( $layer['url'] ?? '' ) : '';
			if ( '' === $key || ! str_starts_with( $url, 'https://' ) || ! str_contains( $url, '{z}' ) ) {
				continue;
			}
			$layers[ $key ] = array(
				'label'       => (string) ( $layer['label'] ?? $key ),
				'url'         => $url,
				'attribution' => (string) ( $layer['attribution'] ?? '' ),
				'maxZoom'     => max( 1, min( 22, (int) ( $layer['maxZoom'] ?? 19 ) ) ),
				'subdomains'  => preg_replace( '/[^a-z0-9]/', '', (string) ( $layer['subdomains'] ?? '' ) ),
				'note'        => (string) ( $layer['note'] ?? '' ),
			);
		}
		if ( empty( $layers ) ) {
			// Ohne Kartenstil funktioniert nichts – Standard immer behalten.
			$layers = array_slice( self::defaults_only(), 0, 1, true );
		}
		return $layers;
	}

	/** @return array<string,array<string,mixed>> */
	private static function defaults_only(): array {
		return array(
			self::DEFAULT => array(
				'label'       => 'OpenStreetMap',
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>-Mitwirkende',
				'maxZoom'     => 19,
				'subdomains'  => '',
				'note'        => '',
			),
		);
	}

	/** Daten eines Stils für das Frontend (ohne Bezeichnung und Hinweis). */
	public static function for_js( string $key ): array {
		$all   = self::all();
		$layer = $all[ $key ] ?? reset( $all );
		return array(
			'url'         => $layer['url'],
			'attribution' => wp_kses( $layer['attribution'], array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ),
			'maxZoom'     => $layer['maxZoom'],
			'subdomains'  => $layer['subdomains'],
		);
	}

	/** @return array<string,string> Schlüssel => Bezeichnung */
	public static function choices(): array {
		return array_map( static fn( array $l ): string => $l['label'], self::all() );
	}
}
