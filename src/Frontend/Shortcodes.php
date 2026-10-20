<?php
/**
 * Shortcodes.
 *
 *   [b83_osm id="3"]                                   gespeicherte Karte
 *   [b83_osm address="Markt 165, 5441 Abtenau" title="…"]Text[/b83_osm]
 *   [b83_osm zoom="14"]
 *     [b83_osm_marker title="…" address="…" icon="hotel"]Text[/b83_osm_marker]
 *     [b83_osm_marker title="…" lat="47.5" lng="13.3"]
 *   [/b83_osm]
 *
 * Alle Design-Einstellungen (height, radius, color, style …) lassen sich als
 * Attribut überschreiben, siehe README.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap\Frontend;

use B83\OsmMap\Design;
use B83\OsmMap\Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public const MAP    = 'b83_osm';
	public const MARKER = 'b83_osm_marker';

	/** Attribute einer einzelnen Adresse. */
	private const MARKER_ATTS = array( 'title', 'address', 'lat', 'lng', 'phone', 'email', 'website', 'icon', 'color', 'image', 'text' );

	/** @var array<int,array<string,mixed>>|null Sammelt [b83_osm_marker] innerhalb von [b83_osm]. */
	private ?array $collector = null;

	public function __construct( private Renderer $renderer ) {}

	public function register(): void {
		add_shortcode( self::MAP, array( $this, 'map' ) );
		add_shortcode( self::MARKER, array( $this, 'marker' ) );
	}

	/**
	 * [b83_osm]
	 *
	 * @param array<string,string>|string $atts    Attribute.
	 * @param string|null                 $content Inhalt.
	 */
	public function map( $atts, $content = null ): string {
		$atts    = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();
		$content = (string) $content;

		// „icon“ auf Kartenebene = Pin-Icon für alle Adressen.
		if ( isset( $atts['icon'] ) && ! isset( $atts['pin_icon'] ) ) {
			$atts['pin_icon'] = $atts['icon'];
		}
		$design = $this->design_overrides( $atts );
		$class  = Sanitizer::text( $atts['class'] ?? '', 100 );

		/*
		 * WordPress liest „[b83_osm id=1] … [b83_osm …]Text[/b83_osm]“ als einen
		 * einzigen Shortcode mit Inhalt. Inhalt, der nicht zur Karte gehört
		 * (z. B. eine weitere Karte), wird deshalb nach der Karte ausgegeben.
		 */
		$after = '';
		if ( '' !== trim( $content ) && ( ! empty( $atts['id'] ) || str_contains( $content, '[' . self::MAP . ' ' ) || str_contains( $content, '[' . self::MAP . ']' ) ) ) {
			$after   = do_shortcode( $content );
			$content = '';
		}

		$id = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;
		if ( $id > 0 ) {
			return $this->renderer->render_saved( $id, $design, $class ) . $after;
		}

		$markers = array();

		// Verschachtelte [b83_osm_marker] einsammeln.
		if ( has_shortcode( $content, self::MARKER ) ) {
			$this->collector = array();
			do_shortcode( $content );
			$markers         = $this->collector;
			$this->collector = null;
			$content         = '';
		}

		// Einzelne Adresse direkt im Shortcode.
		if ( isset( $atts['address'] ) || isset( $atts['lat'] ) ) {
			$single = array_intersect_key( $atts, array_flip( self::MARKER_ATTS ) );
			unset( $single['icon'], $single['color'] ); // Gelten hier für die ganze Karte.
			if ( '' !== trim( $content ) ) {
				$single['text'] = $this->clean_content( $content );
			}
			$marker = Sanitizer::marker( $single );
			if ( ! Sanitizer::marker_is_empty( $marker ) ) {
				array_unshift( $markers, $marker );
			}
		}

		if ( empty( $markers ) ) {
			return ( current_user_can( 'edit_posts' )
				? '<div class="b83-osm-notice" role="note">' . esc_html__( 'b83 OpenStreetMap: Bitte id, address oder lat/lng angeben.', 'b83-osm-map' ) . '</div>'
				: '' ) . $after;
		}

		return $this->renderer->render_inline( array_slice( $markers, 0, Sanitizer::MAX_MARKERS ), $design, $class ) . $after;
	}

	/**
	 * [b83_osm_marker] – nur innerhalb von [b83_osm].
	 *
	 * @param array<string,string>|string $atts    Attribute.
	 * @param string|null                 $content Formatierter Text des Info-Fensters.
	 */
	public function marker( $atts, $content = null ): string {
		if ( null === $this->collector ) {
			return '';
		}
		$atts = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();
		$raw  = array_intersect_key( $atts, array_flip( self::MARKER_ATTS ) );
		if ( null !== $content && '' !== trim( $content ) ) {
			$raw['text'] = $this->clean_content( $content );
		}
		$marker = Sanitizer::marker( $raw );
		if ( ! Sanitizer::marker_is_empty( $marker ) ) {
			$this->collector[] = $marker;
		}
		return '';
	}

	/**
	 * Design-Attribute prüfen; unbekannte und ungültige Werte werden ignoriert.
	 *
	 * @param array<string,string> $atts Attribute.
	 * @return array<string,mixed>
	 */
	private function design_overrides( array $atts ): array {
		return Sanitizer::design( array_intersect_key( $atts, Design::fields() ), true );
	}

	/** Entfernt Absatz-Reste, die der Editor um Shortcodes legt. */
	private function clean_content( string $content ): string {
		$content = trim( $content );
		$content = (string) preg_replace( '#^(</p>|<br\s*/?>)\s*#i', '', $content );
		$content = (string) preg_replace( '#\s*(<p>|<br\s*/?>)$#i', '', $content );
		return trim( $content );
	}
}
