<?php
/**
 * Schema aller Darstellungs-Einstellungen.
 *
 * Eine zentrale Liste für Standards, Karten, Shortcode-Attribute, Prüfung
 * der Eingaben und Backend-Formular. Reihenfolge der Auflösung:
 * Standard-Design → Einstellungen der Karte → Shortcode-Attribute.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

defined( 'ABSPATH' ) || exit;

final class Design {

	/**
	 * Feld-Definitionen.
	 *
	 * type: enum | int | color | bool | text | icon | attachment
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields(): array {
		static $fields = null;
		if ( null !== $fields ) {
			return $fields;
		}

		$fields = array(
			/* Karte */
			'style'         => array(
				'group'   => 'map',
				'type'    => 'enum',
				'label'   => __( 'Kartenstil', 'b83-osm-map' ),
				'options' => Layers::choices(),
				'default' => Layers::DEFAULT,
			),
			'filter'        => array(
				'group'   => 'map',
				'type'    => 'enum',
				'label'   => __( 'Farbfilter', 'b83-osm-map' ),
				'help'    => __( 'Färbt die Kartenkacheln ein – ohne weiteren Anbieter.', 'b83-osm-map' ),
				'options' => array(
					'none'      => __( 'Keiner', 'b83-osm-map' ),
					'grayscale' => __( 'Graustufen', 'b83-osm-map' ),
					'muted'     => __( 'Gedämpft', 'b83-osm-map' ),
					'warm'      => __( 'Warm', 'b83-osm-map' ),
					'dark'      => __( 'Dunkel (Nachtmodus)', 'b83-osm-map' ),
				),
				'default' => 'none',
			),
			'layer_switch'  => array(
				'group'   => 'map',
				'type'    => 'bool',
				'label'   => __( 'Besucher können den Kartenstil wechseln', 'b83-osm-map' ),
				'default' => '0',
			),
			'zoom'          => array(
				'group'   => 'map',
				'type'    => 'int',
				'label'   => __( 'Zoomstufe', 'b83-osm-map' ),
				'help'    => __( '1 = Welt, 15 = Ort, 18 = Straße. Bei mehreren Adressen passt sich die Karte automatisch an, zoomt aber nie näher als hier eingestellt.', 'b83-osm-map' ),
				'min'     => 1,
				'max'     => 19,
				'default' => 15,
			),
			'scroll'        => array(
				'group'   => 'map',
				'type'    => 'enum',
				'label'   => __( 'Bedienung', 'b83-osm-map' ),
				'options' => array(
					'click'  => __( 'Erst nach Klick in die Karte (empfohlen)', 'b83-osm-map' ),
					'always' => __( 'Sofort (Mausrad zoomt die Karte)', 'b83-osm-map' ),
					'never'  => __( 'Feststehend (nur Zoom-Schaltflächen)', 'b83-osm-map' ),
				),
				'default' => 'click',
			),
			'zoom_buttons'  => array(
				'group'   => 'map',
				'type'    => 'bool',
				'label'   => __( 'Zoom-Schaltflächen anzeigen', 'b83-osm-map' ),
				'default' => '1',
			),
			'fullscreen'    => array(
				'group'   => 'map',
				'type'    => 'bool',
				'label'   => __( 'Vollbild-Schaltfläche anzeigen', 'b83-osm-map' ),
				'default' => '0',
			),
			'list'          => array(
				'group'   => 'map',
				'type'    => 'enum',
				'label'   => __( 'Adressliste', 'b83-osm-map' ),
				'help'    => __( 'Klick auf einen Eintrag zeigt die Adresse auf der Karte.', 'b83-osm-map' ),
				'options' => array(
					'none'  => __( 'Nicht anzeigen', 'b83-osm-map' ),
					'below' => __( 'Unter der Karte', 'b83-osm-map' ),
				),
				'default' => 'none',
			),
			'consent'       => array(
				'group'   => 'map',
				'type'    => 'bool',
				'label'   => __( 'Karte erst nach Klick laden (Datenschutz)', 'b83-osm-map' ),
				'help'    => __( 'Bis zum Klick wird keine Verbindung zum Kartenanbieter aufgebaut.', 'b83-osm-map' ),
				'default' => '0',
			),
			'consent_text'  => array(
				'group'   => 'map',
				'type'    => 'text',
				'label'   => __( 'Hinweistext vor dem Laden', 'b83-osm-map' ),
				'max'     => 300,
				'default' => __( 'Beim Laden der Karte wird Ihre IP-Adresse an den Kartenanbieter übertragen.', 'b83-osm-map' ),
			),

			/* Rahmen */
			'height'        => array(
				'group'   => 'frame',
				'type'    => 'int',
				'label'   => __( 'Höhe (px)', 'b83-osm-map' ),
				'min'     => 150,
				'max'     => 1200,
				'default' => 450,
			),
			'height_mobile' => array(
				'group'   => 'frame',
				'type'    => 'int',
				'label'   => __( 'Höhe am Handy (px)', 'b83-osm-map' ),
				'min'     => 150,
				'max'     => 1200,
				'default' => 340,
			),
			'radius'        => array(
				'group'   => 'frame',
				'type'    => 'int',
				'label'   => __( 'Eckenrundung (px)', 'b83-osm-map' ),
				'min'     => 0,
				'max'     => 60,
				'default' => 12,
			),
			'border_width'  => array(
				'group'   => 'frame',
				'type'    => 'int',
				'label'   => __( 'Rahmenstärke (px)', 'b83-osm-map' ),
				'min'     => 0,
				'max'     => 20,
				'default' => 0,
			),
			'border_color'  => array(
				'group'   => 'frame',
				'type'    => 'color',
				'label'   => __( 'Rahmenfarbe', 'b83-osm-map' ),
				'default' => '#ffffff',
			),
			'shadow'        => array(
				'group'   => 'frame',
				'type'    => 'enum',
				'label'   => __( 'Schatten', 'b83-osm-map' ),
				'options' => array(
					'none'   => __( 'Kein Schatten', 'b83-osm-map' ),
					'soft'   => __( 'Weich', 'b83-osm-map' ),
					'strong' => __( 'Deutlich', 'b83-osm-map' ),
				),
				'default' => 'soft',
			),

			/* Pin */
			'color'         => array(
				'group'   => 'pin',
				'type'    => 'color',
				'label'   => __( 'Akzentfarbe (Pin, Link)', 'b83-osm-map' ),
				'default' => '#d96f21',
			),
			'pin_style'     => array(
				'group'   => 'pin',
				'type'    => 'enum',
				'label'   => __( 'Pin-Form', 'b83-osm-map' ),
				'options' => array(
					'drop'   => __( 'Tropfen', 'b83-osm-map' ),
					'circle' => __( 'Kreis', 'b83-osm-map' ),
					'square' => __( 'Abgerundetes Quadrat', 'b83-osm-map' ),
					'icon'   => __( 'Nur Icon', 'b83-osm-map' ),
					'image'  => __( 'Eigenes Bild', 'b83-osm-map' ),
				),
				'default' => 'drop',
			),
			'pin_icon'      => array(
				'group'   => 'pin',
				'type'    => 'icon',
				'label'   => __( 'Pin-Icon', 'b83-osm-map' ),
				'default' => 'dot',
			),
			'icon_color'    => array(
				'group'   => 'pin',
				'type'    => 'color',
				'label'   => __( 'Icon-Farbe im Pin', 'b83-osm-map' ),
				'default' => '#ffffff',
			),
			'pin_size'      => array(
				'group'   => 'pin',
				'type'    => 'int',
				'label'   => __( 'Pin-Größe (px)', 'b83-osm-map' ),
				'min'     => 20,
				'max'     => 72,
				'default' => 36,
			),
			'pin_image'     => array(
				'group'   => 'pin',
				'type'    => 'attachment',
				'label'   => __( 'Eigenes Pin-Bild', 'b83-osm-map' ),
				'help'    => __( 'Nur bei Pin-Form „Eigenes Bild“. Die Unterkante des Bildes zeigt auf die Adresse.', 'b83-osm-map' ),
				'default' => 0,
			),

			/* Info-Fenster */
			'popup_bg'      => array(
				'group'   => 'popup',
				'type'    => 'color',
				'label'   => __( 'Hintergrund', 'b83-osm-map' ),
				'default' => '#ffffff',
			),
			'popup_text'    => array(
				'group'   => 'popup',
				'type'    => 'color',
				'label'   => __( 'Textfarbe', 'b83-osm-map' ),
				'default' => '#1f2328',
			),
			'popup_radius'  => array(
				'group'   => 'popup',
				'type'    => 'int',
				'label'   => __( 'Eckenrundung (px)', 'b83-osm-map' ),
				'min'     => 0,
				'max'     => 30,
				'default' => 10,
			),
			'popup_open'    => array(
				'group'   => 'popup',
				'type'    => 'enum',
				'label'   => __( 'Beim Laden', 'b83-osm-map' ),
				'options' => array(
					'first' => __( 'Info-Fenster der ersten Adresse öffnen', 'b83-osm-map' ),
					'none'  => __( 'Erst beim Klick auf den Pin öffnen', 'b83-osm-map' ),
				),
				'default' => 'first',
			),
			'route'         => array(
				'group'   => 'popup',
				'type'    => 'enum',
				'label'   => __( 'Routen-Link', 'b83-osm-map' ),
				'options' => array(
					'osm'    => __( 'OpenStreetMap', 'b83-osm-map' ),
					'google' => __( 'Google Maps', 'b83-osm-map' ),
					'none'   => __( 'Kein Routen-Link', 'b83-osm-map' ),
				),
				'default' => 'osm',
			),
			'route_label'   => array(
				'group'   => 'popup',
				'type'    => 'text',
				'label'   => __( 'Text des Routen-Links', 'b83-osm-map' ),
				'max'     => 60,
				'default' => __( 'Route planen', 'b83-osm-map' ),
			),
		);

		return $fields;
	}

	/** @return array<string,string> Gruppen-Schlüssel => Überschrift */
	public static function groups(): array {
		return array(
			'map'   => __( 'Karte', 'b83-osm-map' ),
			'frame' => __( 'Rahmen und Größe', 'b83-osm-map' ),
			'pin'   => __( 'Pin', 'b83-osm-map' ),
			'popup' => __( 'Info-Fenster', 'b83-osm-map' ),
		);
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array_map( static fn( array $f ) => $f['default'], self::fields() );
	}

	/**
	 * Werte zusammenführen: leere Werte ('') in $override übernehmen den Wert aus $base.
	 *
	 * @param array<string,mixed> $base     Vollständige Werte.
	 * @param array<string,mixed> $override Überschreibungen (bereits geprüft).
	 * @return array<string,mixed>
	 */
	public static function merge( array $base, array $override ): array {
		foreach ( self::fields() as $key => $field ) {
			if ( array_key_exists( $key, $override ) && '' !== $override[ $key ] && null !== $override[ $key ] ) {
				$base[ $key ] = $override[ $key ];
			}
		}
		return $base;
	}
}
