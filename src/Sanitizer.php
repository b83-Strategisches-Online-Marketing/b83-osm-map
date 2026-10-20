<?php
/**
 * Zentrale Prüfung aller Eingaben (Whitelist, Längenbegrenzung).
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {

	public const MAX_MARKERS = 200;

	/** Regulärer Ausdruck für Font-Awesome-Klassen wie „fa-solid fa-hotel“. */
	private const FA_PATTERN = '/^(fa|fas|far|fab|fal|fat|fad|fa-solid|fa-regular|fa-brands|fa-light|fa-thin|fa-duotone|fa-sharp)( fa-[a-z0-9-]+){1,3}$/';

	/**
	 * Erlaubte Formatierungen im Text des Info-Fensters.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_html(): array {
		$allowed = array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'small'  => array(),
			'span'   => array( 'class' => true ),
			'h3'     => array(),
			'h4'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);

		/**
		 * Erlaubte HTML-Elemente im Text des Info-Fensters (Format wie wp_kses).
		 *
		 * @param array<string,array<string,bool>> $allowed Elemente und Attribute.
		 */
		return (array) apply_filters( 'b83_osm_allowed_html', $allowed );
	}

	/* ---------------------------------------------------------------------
	 * Einzelwerte
	 * ------------------------------------------------------------------- */

	public static function text( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return mb_substr( $value, 0, $max );
	}

	public static function textarea( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		return mb_substr( $value, 0, $max );
	}

	public static function rich_text( mixed $value, int $max = 3000 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = mb_substr( (string) $value, 0, $max * 2 );
		$value = trim( wp_kses( $value, self::allowed_html() ) );
		return mb_substr( $value, 0, $max );
	}

	/** Hex-Farbe (#abc oder #aabbcc) oder ''. */
	public static function color( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value ) ? $value : '';
	}

	/** '1', '0' oder null bei ungültigem Wert. */
	public static function bool( mixed $value ): ?string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return match ( $value ) {
			'1', 'yes', 'ja', 'true', 'on' => '1',
			'0', 'no', 'nein', 'false', 'off' => '0',
			default => null,
		};
	}

	/** Icon: 'dot', mitgelieferter Schlüssel oder Font-Awesome-Klasse; sonst ''. */
	public static function icon( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) ) : '';
		if ( '' === $value || strlen( $value ) > 80 ) {
			return '';
		}
		if ( 'dot' === $value || 'none' === $value || Icons::exists( $value ) ) {
			return $value;
		}
		return 1 === preg_match( self::FA_PATTERN, $value ) ? $value : '';
	}

	public static function is_fa_class( string $icon ): bool {
		return 1 === preg_match( self::FA_PATTERN, $icon );
	}

	/** Koordinate als String mit max. 7 Nachkommastellen oder ''. */
	public static function coordinate( mixed $value, float $limit ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = str_replace( ',', '.', trim( (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$float = (float) $value;
		if ( $float < -$limit || $float > $limit ) {
			return '';
		}
		return rtrim( rtrim( number_format( $float, 7, '.', '' ), '0' ), '.' );
	}

	public static function phone( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		$value = preg_replace( '/[^0-9+()\/\-. ]/', '', $value );
		return mb_substr( (string) $value, 0, 40 );
	}

	public static function email( mixed $value ): string {
		$value = is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
		return ( '' !== $value && is_email( $value ) ) ? mb_substr( $value, 0, 120 ) : '';
	}

	public static function url( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			// Anderes Schema (javascript:, data: …) nie zulassen.
			if ( preg_match( '#^[a-z][a-z0-9+.\-]*:(?!\d)#i', $value ) ) {
				return '';
			}
			$value = 'https://' . $value;
		}
		$value = esc_url_raw( $value, array( 'http', 'https' ) );
		return strlen( $value ) <= 300 ? $value : '';
	}

	/* ---------------------------------------------------------------------
	 * Design
	 * ------------------------------------------------------------------- */

	/**
	 * Prüft Design-Werte.
	 *
	 * @param array<string,mixed> $input   Rohwerte.
	 * @param bool                $inherit true: leere/ungültige Werte werden '' (= Standard übernehmen),
	 *                                     false: vollständiger Satz mit Standardwerten.
	 * @return array<string,mixed>
	 */
	public static function design( array $input, bool $inherit ): array {
		$out = array();
		foreach ( Design::fields() as $key => $field ) {
			$raw      = $input[ $key ] ?? null;
			$fallback = $inherit ? '' : $field['default'];

			if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
				// Leere Textfelder im Standard-Design bleiben leer, wenn bewusst geleert.
				$out[ $key ] = ( ! $inherit && 'text' === $field['type'] && null !== $raw ) ? '' : $fallback;
				continue;
			}

			$value = match ( $field['type'] ) {
				'enum'       => is_scalar( $raw ) && array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : null,
				'int'        => is_numeric( $raw ) ? max( (int) $field['min'], min( (int) $field['max'], (int) round( (float) $raw ) ) ) : null,
				'color'      => self::color( $raw ) ?: null,
				'bool'       => self::bool( $raw ),
				'text'       => self::text( $raw, (int) ( $field['max'] ?? 200 ) ),
				'icon'       => self::icon( $raw ) ?: null,
				'attachment' => is_numeric( $raw ) && (int) $raw > 0 ? (int) $raw : null,
				default      => null,
			};

			$out[ $key ] = null === $value ? $fallback : $value;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Karten und Adressen
	 * ------------------------------------------------------------------- */

	/**
	 * @param array<string,mixed> $input Rohwerte einer Adresse.
	 * @return array<string,mixed>
	 */
	public static function marker( array $input ): array {
		return array(
			'title'   => self::text( $input['title'] ?? '', 150 ),
			'address' => self::textarea( $input['address'] ?? '', 300 ),
			'lat'     => self::coordinate( $input['lat'] ?? '', 90 ),
			'lng'     => self::coordinate( $input['lng'] ?? '', 180 ),
			'text'    => self::rich_text( $input['text'] ?? '' ),
			'phone'   => self::phone( $input['phone'] ?? '' ),
			'email'   => self::email( $input['email'] ?? '' ),
			'website' => self::url( $input['website'] ?? '' ),
			'icon'    => self::icon( $input['icon'] ?? '' ),
			'color'   => self::color( $input['color'] ?? '' ),
			'image'   => isset( $input['image'] ) && is_numeric( $input['image'] ) ? max( 0, (int) $input['image'] ) : 0,
		);
	}

	public static function marker_is_empty( array $marker ): bool {
		return '' === $marker['title'] && '' === $marker['address'] && ( '' === $marker['lat'] || '' === $marker['lng'] );
	}

	/**
	 * @param array<string,mixed> $input Rohwerte einer Karte.
	 * @return array<string,mixed>
	 */
	public static function map( array $input ): array {
		$markers = array();
		foreach ( array_slice( (array) ( $input['markers'] ?? array() ), 0, self::MAX_MARKERS ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$marker = self::marker( $raw );
			if ( ! self::marker_is_empty( $marker ) ) {
				$markers[] = $marker;
			}
		}

		$name = self::text( $input['name'] ?? '', 100 );

		return array(
			'name'    => '' !== $name ? $name : __( 'Neue Karte', 'b83-osm-map' ),
			'markers' => $markers,
			'design'  => self::design( (array) ( $input['design'] ?? array() ), true ),
		);
	}

	/**
	 * @param array<string,mixed> $input Rohwerte der Einstellungen.
	 * @return array<string,mixed>
	 */
	public static function settings( array $input ): array {
		return array(
			'design'              => self::design( (array) ( $input['design'] ?? array() ), false ),
			'purge_cache'         => self::bool( $input['purge_cache'] ?? '1' ) ?? '1',
			'delete_on_uninstall' => self::bool( $input['delete_on_uninstall'] ?? '0' ) ?? '0',
		);
	}
}
