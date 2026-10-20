<?php
/**
 * Erzeugt das HTML einer Karte samt Konfiguration für das Frontend-Skript.
 *
 * Info-Fenster und Pins werden hier serverseitig aufgebaut und escaped;
 * das Skript übernimmt nur noch fertiges, geprüftes Markup.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap\Frontend;

use B83\OsmMap\Design;
use B83\OsmMap\Geocoder;
use B83\OsmMap\Icons;
use B83\OsmMap\Layers;
use B83\OsmMap\Repository;
use B83\OsmMap\Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	private static int $counter = 0;

	public function __construct( private Repository $repository, private Geocoder $geocoder ) {}

	/* ---------------------------------------------------------------------
	 * Öffentliche Einstiege
	 * ------------------------------------------------------------------- */

	/**
	 * Gespeicherte Karte ausgeben.
	 *
	 * @param array<string,mixed> $overrides Geprüfte Design-Überschreibungen (z. B. aus dem Shortcode).
	 */
	public function render_saved( int $id, array $overrides = array(), string $class = '' ): string {
		$map = $this->repository->get_map( $id );
		if ( null === $map ) {
			/* translators: %d: Karten-ID */
			return $this->editor_notice( sprintf( __( 'b83 OpenStreetMap: Karte %d wurde nicht gefunden.', 'b83-osm-map' ), $id ) );
		}
		$design = Design::merge( Design::merge( $this->repository->default_design(), $map['design'] ), $overrides );
		return $this->render( $map['markers'], $design, $map['name'], $class );
	}

	/**
	 * Karte aus Adressen ausgeben (Shortcode ohne gespeicherte Karte).
	 *
	 * @param array<int,array<string,mixed>> $markers   Geprüfte Adressen.
	 * @param array<string,mixed>            $overrides Geprüfte Design-Überschreibungen.
	 */
	public function render_inline( array $markers, array $overrides = array(), string $class = '' ): string {
		$design = Design::merge( $this->repository->default_design(), $overrides );
		return $this->render( $markers, $design, '', $class );
	}

	/**
	 * Konfiguration für die Live-Vorschau im Backend.
	 *
	 * @param array<string,mixed> $map Geprüfte Karte.
	 * @return array{config:array<string,mixed>,vars:string,classes:string,missing:int}
	 */
	public function preview( array $map ): array {
		return $this->preview_with_defaults( $map, $this->repository->default_design() );
	}

	/**
	 * Vorschau mit vorgegebenem Standard-Design (Seite „Standard-Design“).
	 *
	 * @param array<string,mixed> $map      Geprüfte Karte.
	 * @param array<string,mixed> $defaults Vollständiges Design.
	 * @return array{config:array<string,mixed>,vars:string,classes:string,missing:int}
	 */
	public function preview_with_defaults( array $map, array $defaults ): array {
		$design = Design::merge( $defaults, $map['design'] );
		// Keine Adresssuche während des Tippens – nur vorhandene Koordinaten.
		$markers = $this->resolve_markers( $map['markers'], false );
		return array(
			'config'  => $this->config( $markers, $design ),
			'vars'    => $this->css_vars( $design ),
			'classes' => $this->classes( $design ),
			'missing' => count( $map['markers'] ) - count( $markers ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Ausgabe
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<int,array<string,mixed>> $markers Adressen.
	 * @param array<string,mixed>            $design  Vollständiges Design.
	 */
	private function render( array $markers, array $design, string $name, string $class ): string {
		$markers = $this->resolve_markers( $markers );
		if ( empty( $markers ) ) {
			return $this->editor_notice( __( 'b83 OpenStreetMap: Für diese Karte gibt es keine Adresse mit Koordinaten. Bitte Adresse prüfen oder Koordinaten eintragen.', 'b83-osm-map' ) );
		}

		Assets::enqueue();

		$id     = 'b83-osm-' . ( ++self::$counter );
		$config = $this->config( $markers, $design );
		$titles = array_filter( array_map( static fn( array $m ): string => $m['title'] ?: $m['address'], $markers ) );
		$label  = '' !== $name ? $name : implode( ', ', array_slice( $titles, 0, 3 ) );
		/* translators: %s: Name der Karte oder Adressen */
		$aria = '' !== $label ? sprintf( __( 'Karte: %s', 'b83-osm-map' ), $label ) : __( 'Karte', 'b83-osm-map' );

		$extra = trim( implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', $class ) ?: array() ) ) );

		$html  = '<div class="b83-osm-wrap' . ( '' !== $extra ? ' ' . esc_attr( $extra ) : '' ) . '" style="' . esc_attr( $this->css_vars( $design ) ) . '">';
		$html .= '<div id="' . esc_attr( $id ) . '" class="' . esc_attr( $this->classes( $design ) ) . '" role="region" aria-label="' . esc_attr( $aria ) . '"';
		// JSON_HEX_*: keine &, <, >, " im Attribut – sonst würde der Browser Entities im Markup vorzeitig auflösen.
		$json  = (string) wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$html .= ' data-b83-osm="' . esc_attr( $json ) . '">';

		if ( '1' === $design['consent'] ) {
			$html .= $this->consent_placeholder( $design );
		}
		$html .= '<noscript>' . $this->fallback_links( $markers, (int) $design['zoom'] ) . '</noscript>';
		$html .= '</div>';

		if ( 'below' === $design['list'] ) {
			$html .= $this->address_list( $markers, $id );
		}
		$html .= '</div>';

		/**
		 * Fertiges HTML einer Karte.
		 *
		 * @param string                         $html    HTML.
		 * @param array<int,array<string,mixed>> $markers Adressen mit Koordinaten.
		 * @param array<string,mixed>            $design  Design.
		 */
		return (string) apply_filters( 'b83_osm_render', $html, $markers, $design );
	}

	/**
	 * Fehlende Koordinaten über die Adresse ermitteln, Adressen ohne Koordinaten entfernen.
	 *
	 * @param array<int,array<string,mixed>> $markers Adressen.
	 * @param bool                           $geocode Fehlende Koordinaten suchen.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_markers( array $markers, bool $geocode = true ): array {
		$out = array();
		foreach ( $markers as $marker ) {
			if ( $geocode && ( '' === $marker['lat'] || '' === $marker['lng'] ) && '' !== $marker['address'] ) {
				$found = $this->geocoder->lookup( $marker['address'] );
				if ( null !== $found ) {
					$marker['lat'] = $found['lat'];
					$marker['lng'] = $found['lng'];
				}
			}
			if ( '' !== $marker['lat'] && '' !== $marker['lng'] ) {
				$out[] = $marker;
			}
		}
		return $out;
	}

	/**
	 * Konfiguration für assets/js/frontend.js.
	 *
	 * @param array<int,array<string,mixed>> $markers Adressen mit Koordinaten.
	 * @param array<string,mixed>            $design  Design.
	 * @return array<string,mixed>
	 */
	private function config( array $markers, array $design ): array {
		$layers = array();
		if ( '1' === $design['layer_switch'] ) {
			foreach ( Layers::choices() as $key => $label ) {
				$layers[] = array(
					'key'   => $key,
					'label' => $label,
				) + Layers::for_js( $key );
			}
		}

		$config = array(
			'layer'       => array( 'key' => $design['style'] ) + Layers::for_js( (string) $design['style'] ),
			'layers'      => $layers,
			'zoom'        => (int) $design['zoom'],
			'scroll'      => $design['scroll'],
			'zoomButtons' => '1' === $design['zoom_buttons'],
			'fullscreen'  => '1' === $design['fullscreen'],
			'popupOpen'   => $design['popup_open'],
			'markers'     => array_map( fn( array $m ): array => $this->marker_config( $m, $design ), $markers ),
		);

		/**
		 * Konfiguration einer Karte für das Frontend-Skript.
		 *
		 * @param array<string,mixed>            $config  Konfiguration.
		 * @param array<int,array<string,mixed>> $markers Adressen.
		 * @param array<string,mixed>            $design  Design.
		 */
		return (array) apply_filters( 'b83_osm_map_config', $config, $markers, $design );
	}

	/**
	 * @param array<string,mixed> $marker Adresse.
	 * @param array<string,mixed> $design Design.
	 * @return array<string,mixed>
	 */
	private function marker_config( array $marker, array $design ): array {
		return array(
			'lat'   => (float) $marker['lat'],
			'lng'   => (float) $marker['lng'],
			'title' => $marker['title'] ?: $marker['address'],
			'popup' => $this->popup_html( $marker, $design ),
			'pin'   => $this->pin( $marker, $design ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Pin
	 * ------------------------------------------------------------------- */

	/**
	 * Markup und Maße eines Pins.
	 *
	 * @param array<string,mixed> $marker Adresse.
	 * @param array<string,mixed> $design Design.
	 * @return array{html:string,size:int[],anchor:int[],popup:int[]}
	 */
	private function pin( array $marker, array $design ): array {
		$style = (string) $design['pin_style'];
		$size  = (int) $design['pin_size'];
		$color = '' !== $marker['color'] ? $marker['color'] : (string) $design['color'];
		$icon  = '' !== $marker['icon'] ? $marker['icon'] : (string) $design['pin_icon'];

		// Eigenes Bild.
		if ( 'image' === $style && (int) $design['pin_image'] > 0 ) {
			$src = wp_get_attachment_image_src( (int) $design['pin_image'], 'medium' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$h = (int) round( $size * 1.3 );
				$w = ( (int) $src[2] > 0 ) ? max( 8, (int) round( $h * (int) $src[1] / (int) $src[2] ) ) : $h;
				return array(
					'html'   => '<img class="b83-osm__pin-img" src="' . esc_url( $src[0] ) . '" width="' . $w . '" height="' . $h . '" alt="">',
					'size'   => array( $w, $h ),
					'anchor' => array( (int) round( $w / 2 ), $h ),
					'popup'  => array( 0, -$h + 4 ),
				);
			}
			$style = 'drop';
		} elseif ( 'image' === $style ) {
			$style = 'drop';
		}

		if ( 'icon' === $style && in_array( $icon, array( 'dot', 'none' ), true ) ) {
			$icon = 'location-dot';
		}

		$vars = sprintf( '--b83-pin:%1$s;--b83-pin-icon:%2$s;--b83-pin-size:%3$dpx', $color, (string) $design['icon_color'], $size );
		$html = '<span class="b83-osm__pin b83-osm__pin--' . esc_attr( $style ) . '" style="' . esc_attr( $vars ) . '">'
			. '<span class="b83-osm__pin-icon">' . $this->icon_html( $icon ) . '</span></span>';

		$half = (int) round( $size / 2 );
		return match ( $style ) {
			'drop'  => array(
				'html'   => $html,
				'size'   => array( $size, $size ),
				'anchor' => array( $half, (int) round( $size * 1.21 ) ),
				'popup'  => array( 0, -(int) round( $size * 1.05 ) ),
			),
			'icon'  => array(
				'html'   => $html,
				'size'   => array( $size, $size ),
				'anchor' => array( $half, $size ),
				'popup'  => array( 0, -$size + 2 ),
			),
			default => array(
				'html'   => $html,
				'size'   => array( $size, $size ),
				'anchor' => array( $half, $half ),
				'popup'  => array( 0, -$half - 2 ),
			),
		};
	}

	private function icon_html( string $icon ): string {
		if ( 'none' === $icon ) {
			return '';
		}
		if ( 'dot' === $icon ) {
			return '<span class="b83-osm__pin-dot"></span>';
		}
		if ( Sanitizer::is_fa_class( $icon ) ) {
			return '<i class="' . esc_attr( $icon ) . '" aria-hidden="true"></i>';
		}
		return Icons::svg( $icon );
	}

	/* ---------------------------------------------------------------------
	 * Info-Fenster
	 * ------------------------------------------------------------------- */

	/**
	 * Formatierter Textblock einer Adresse.
	 *
	 * @param array<string,mixed> $marker Adresse.
	 * @param array<string,mixed> $design Design.
	 */
	public function popup_html( array $marker, array $design ): string {
		$html = '<div class="b83-osm__popup">';

		if ( $marker['image'] > 0 ) {
			$html .= wp_get_attachment_image(
				(int) $marker['image'],
				'medium',
				false,
				array(
					'class'   => 'b83-osm__img',
					'loading' => 'lazy',
				)
			);
		}
		if ( '' !== $marker['title'] ) {
			$html .= '<span class="b83-osm__title">' . esc_html( $marker['title'] ) . '</span>';
		}
		if ( '' !== $marker['address'] ) {
			$html .= '<span class="b83-osm__address">' . nl2br( esc_html( $marker['address'] ) ) . '</span>';
		}
		if ( '' !== $marker['text'] ) {
			$html .= '<div class="b83-osm__text">' . wp_kses( wpautop( $marker['text'] ), Sanitizer::allowed_html() ) . '</div>';
		}

		$contact = array();
		if ( '' !== $marker['phone'] ) {
			$tel       = preg_replace( '/[^0-9+]/', '', $marker['phone'] );
			$contact[] = '<li class="b83-osm__phone">' . Icons::svg( 'phone' ) . '<a href="' . esc_url( 'tel:' . $tel, array( 'tel' ) ) . '">' . esc_html( $marker['phone'] ) . '</a></li>';
		}
		if ( '' !== $marker['email'] ) {
			$contact[] = '<li class="b83-osm__email">' . Icons::svg( 'envelope' ) . '<a href="' . esc_attr( 'mailto:' . antispambot( $marker['email'] ) ) . '">' . esc_html( antispambot( $marker['email'] ) ) . '</a></li>';
		}
		if ( '' !== $marker['website'] ) {
			$host      = (string) wp_parse_url( $marker['website'], PHP_URL_HOST );
			$contact[] = '<li class="b83-osm__website">' . Icons::svg( 'globe' ) . '<a href="' . esc_url( $marker['website'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '/^www\./', '', $host ) ) . '</a></li>';
		}
		if ( $contact ) {
			$html .= '<ul class="b83-osm__contact">' . implode( '', $contact ) . '</ul>';
		}

		$route = $this->route_url( $marker, (string) $design['route'] );
		if ( '' !== $route && '' !== $design['route_label'] ) {
			$html .= '<a class="b83-osm__route" href="' . esc_url( $route ) . '" target="_blank" rel="noopener">' . esc_html( (string) $design['route_label'] ) . '</a>';
		}
		$html .= '</div>';

		/**
		 * HTML des Info-Fensters einer Adresse.
		 *
		 * @param string              $html   HTML.
		 * @param array<string,mixed> $marker Adresse.
		 * @param array<string,mixed> $design Design.
		 */
		return (string) apply_filters( 'b83_osm_popup_html', $html, $marker, $design );
	}

	/** @param array<string,mixed> $marker Adresse mit Koordinaten. */
	private function route_url( array $marker, string $provider ): string {
		$dest = $marker['lat'] . ',' . $marker['lng'];
		return match ( $provider ) {
			'osm'    => 'https://www.openstreetmap.org/directions?to=' . rawurlencode( $dest ),
			'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest ),
			default  => '',
		};
	}

	/* ---------------------------------------------------------------------
	 * Teile
	 * ------------------------------------------------------------------- */

	/** @param array<string,mixed> $design Design. */
	private function css_vars( array $design ): string {
		$vars = array(
			'--b83-osm-h'             => (int) $design['height'] . 'px',
			'--b83-osm-h-m'           => (int) $design['height_mobile'] . 'px',
			'--b83-osm-radius'        => (int) $design['radius'] . 'px',
			'--b83-osm-border'        => (int) $design['border_width'] . 'px',
			'--b83-osm-border-color'  => (string) $design['border_color'],
			'--b83-osm-color'         => (string) $design['color'],
			'--b83-osm-popup-bg'      => (string) $design['popup_bg'],
			'--b83-osm-popup-text'    => (string) $design['popup_text'],
			'--b83-osm-popup-radius'  => (int) $design['popup_radius'] . 'px',
		);
		$css = '';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		return $css;
	}

	/** @param array<string,mixed> $design Design. */
	private function classes( array $design ): string {
		return 'b83-osm b83-osm--shadow-' . sanitize_html_class( (string) $design['shadow'] )
			. ' b83-osm--filter-' . sanitize_html_class( (string) $design['filter'] );
	}

	/** @param array<string,mixed> $design Design. */
	private function consent_placeholder( array $design ): string {
		return '<div class="b83-osm__consent">'
			. '<span class="b83-osm__consent-icon">' . Icons::svg( 'location-dot' ) . '</span>'
			. ( '' !== $design['consent_text'] ? '<p>' . esc_html( (string) $design['consent_text'] ) . '</p>' : '' )
			. '<button type="button" class="b83-osm__consent-btn">' . esc_html__( 'Karte laden', 'b83-osm-map' ) . '</button>'
			. '<label class="b83-osm__consent-remember"><input type="checkbox"> ' . esc_html__( 'Karten auf dieser Website immer laden', 'b83-osm-map' ) . '</label>'
			. '</div>';
	}

	/** @param array<int,array<string,mixed>> $markers Adressen. */
	private function fallback_links( array $markers, int $zoom ): string {
		$html = '<ul class="b83-osm__fallback">';
		foreach ( $markers as $m ) {
			$url   = sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $m['lat'], $m['lng'], $zoom );
			$html .= '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $m['title'] ?: $m['address'] ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/** @param array<int,array<string,mixed>> $markers Adressen. */
	private function address_list( array $markers, string $map_id ): string {
		$html = '<ul class="b83-osm-list" data-b83-osm-for="' . esc_attr( $map_id ) . '">';
		foreach ( $markers as $i => $m ) {
			$html .= '<li><button type="button" class="b83-osm-list__item" data-index="' . (int) $i . '" aria-controls="' . esc_attr( $map_id ) . '">';
			if ( '' !== $m['title'] ) {
				$html .= '<span class="b83-osm-list__title">' . esc_html( $m['title'] ) . '</span>';
			}
			if ( '' !== $m['address'] ) {
				$html .= '<span class="b83-osm-list__address">' . esc_html( str_replace( "\n", ', ', $m['address'] ) ) . '</span>';
			}
			$html .= '</button></li>';
		}
		return $html . '</ul>';
	}

	/** Hinweis nur für angemeldete Redakteure, Besucher sehen nichts. */
	private function editor_notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<div class="b83-osm-notice" role="note">' . esc_html( $message ) . '</div>';
	}
}
