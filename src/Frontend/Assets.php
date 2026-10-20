<?php
/**
 * Registriert Leaflet (lokal mitgeliefert) und die Plugin-Dateien.
 * Geladen wird nur auf Seiten, auf denen eine Karte ausgegeben wird.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap\Frontend;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const LEAFLET_VERSION = '1.9.4';

	public function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
		// Elementor-Editor: Vorschau des Shortcode-Widgets braucht die Dateien sofort.
		add_action( 'elementor/preview/enqueue_scripts', array( self::class, 'enqueue' ) );
		// Steht die Karte im Inhalt, CSS schon im <head> laden (kein Springen des Layouts).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ) );
	}

	public function maybe_enqueue_early(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$found = has_shortcode( (string) $post->post_content, Shortcodes::MAP )
			|| str_contains( (string) get_post_meta( $post->ID, '_elementor_data', true ), '[' . Shortcodes::MAP );

		/**
		 * Dateien der Karte im <head> laden (true) – z. B. wenn die Karte in einem Widget oder Template steckt.
		 *
		 * @param bool     $found Karte im Inhalt gefunden.
		 * @param \WP_Post $post  Aktuelle Seite.
		 */
		if ( apply_filters( 'b83_osm_enqueue_early', $found, $post ) ) {
			self::register_assets();
			wp_enqueue_style( 'b83-osm' );
		}
	}

	public static function register_assets(): void {
		if ( wp_script_is( 'b83-osm', 'registered' ) ) {
			return;
		}
		$vendor = B83_OSM_URL . 'assets/vendor/leaflet/';
		wp_register_style( 'b83-osm-leaflet', $vendor . 'leaflet.css', array(), self::LEAFLET_VERSION );
		wp_register_script( 'b83-osm-leaflet', $vendor . 'leaflet.js', array(), self::LEAFLET_VERSION, true );

		wp_register_style( 'b83-osm', B83_OSM_URL . 'assets/css/frontend.css', array( 'b83-osm-leaflet' ), B83_OSM_VERSION );
		wp_register_script( 'b83-osm', B83_OSM_URL . 'assets/js/frontend.js', array( 'b83-osm-leaflet' ), B83_OSM_VERSION, true );
		wp_localize_script(
			'b83-osm',
			'b83OsmI18n',
			array(
				'hintClick'      => __( 'Zum Zoomen in die Karte klicken', 'b83-osm-map' ),
				'hintTouch'      => __( 'Zum Bedienen auf die Karte tippen', 'b83-osm-map' ),
				'fullscreen'     => __( 'Vollbild', 'b83-osm-map' ),
				'exitFullscreen' => __( 'Vollbild beenden', 'b83-osm-map' ),
				'zoomIn'         => __( 'Vergrößern', 'b83-osm-map' ),
				'zoomOut'        => __( 'Verkleinern', 'b83-osm-map' ),
			)
		);
	}

	public static function enqueue(): void {
		self::register_assets();
		wp_enqueue_style( 'b83-osm' );
		wp_enqueue_script( 'b83-osm' );
	}
}
