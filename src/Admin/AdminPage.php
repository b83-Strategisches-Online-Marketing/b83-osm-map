<?php
/**
 * Backend: Karten verwalten, Standard-Design, Hilfe.
 * Hängt sich als Unterpunkt in das gemeinsame Menü „b83“ ein.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap\Admin;

use B83\OsmMap\Design;
use B83\OsmMap\Frontend\Assets;
use B83\OsmMap\Frontend\Renderer;
use B83\OsmMap\Geocoder;
use B83\OsmMap\Icons;
use B83\OsmMap\Integrations\CachePurge;
use B83\OsmMap\Layers;
use B83\OsmMap\Repository;
use B83\OsmMap\Sanitizer;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const SLUG = 'b83-osm-map';

	public function __construct(
		private Repository $repository,
		private Renderer $renderer,
		private Geocoder $geocoder
	) {}

	/**
	 * Hinweise per festem Schlüssel in der URL (nie Freitext aus der URL ausgeben).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function notices(): array {
		return array(
			'saved'      => array( 'success', __( 'Karte gespeichert.', 'b83-osm-map' ) ),
			'saved_miss' => array( 'warning', __( 'Karte gespeichert. Für mindestens eine Adresse wurden keine Koordinaten gefunden – bitte Adresse genauer angeben oder Koordinaten eintragen.', 'b83-osm-map' ) ),
			'deleted'    => array( 'success', __( 'Karte gelöscht.', 'b83-osm-map' ) ),
			'duplicated' => array( 'success', __( 'Karte dupliziert.', 'b83-osm-map' ) ),
			'settings'   => array( 'success', __( 'Einstellungen gespeichert.', 'b83-osm-map' ) ),
			'geocache'   => array( 'success', __( 'Gespeicherte Adress-Koordinaten wurden geleert.', 'b83-osm-map' ) ),
			'not_found'  => array( 'error', __( 'Die Karte wurde nicht gefunden.', 'b83-osm-map' ) ),
		);
	}

	public static function capability(): string {
		/**
		 * Berechtigung für die Kartenverwaltung.
		 *
		 * @param string $capability Standard: manage_options.
		 */
		return (string) apply_filters( 'b83_osm_capability', 'manage_options' );
	}

	public function register(): void {
		( new B83Menu( B83_OSM_URL, B83_OSM_DIR ) )->register();

		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_filter( 'b83_admin_plugin_links', array( $this, 'overview_link' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( B83_OSM_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'admin_post_b83_osm_save_map', array( $this, 'handle_save_map' ) );
		add_action( 'admin_post_b83_osm_delete_map', array( $this, 'handle_delete_map' ) );
		add_action( 'admin_post_b83_osm_duplicate_map', array( $this, 'handle_duplicate_map' ) );
		add_action( 'admin_post_b83_osm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_b83_osm_clear_geocache', array( $this, 'handle_clear_geocache' ) );

		add_action( 'wp_ajax_b83_osm_geocode', array( $this, 'ajax_geocode' ) );
		add_action( 'wp_ajax_b83_osm_preview', array( $this, 'ajax_preview' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			B83Menu::SLUG,
			__( 'OpenStreetMap', 'b83-osm-map' ),
			__( 'OpenStreetMap', 'b83-osm-map' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string,string> $links Plugin-Datei => URL.
	 * @return array<string,string>
	 */
	public function overview_link( array $links ): array {
		$links[ plugin_basename( B83_OSM_FILE ) ] = $this->url();
		return $links;
	}

	/**
	 * @param string[] $links Links in der Plugin-Liste.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Karten', 'b83-osm-map' ) . '</a>' );
		return $links;
	}

	/** @param array<string,string|int> $args Zusätzliche Parameter. */
	private function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Dateien
	 * ------------------------------------------------------------------- */

	public function enqueue( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Seitenerkennung.
		if ( ! str_ends_with( $hook, '_page_' . self::SLUG ) && ( $_GET['page'] ?? '' ) !== self::SLUG ) {
			return;
		}
		Assets::enqueue();
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'b83-osm-admin', B83_OSM_URL . 'assets/css/admin.css', array( 'b83-osm', 'wp-color-picker' ), B83_OSM_VERSION );
		wp_enqueue_script( 'b83-osm-admin', B83_OSM_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker', 'b83-osm', 'jquery-ui-sortable' ), B83_OSM_VERSION, true );

		$icons = array();
		foreach ( Icons::all() as $key => $icon ) {
			$icons[ $key ] = array(
				'label' => $icon['label'],
				'svg'   => Icons::svg( $key ),
			);
		}

		wp_localize_script(
			'b83-osm-admin',
			'b83OsmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'b83_osm_ajax' ),
				'icons'   => $icons,
				'i18n'    => array(
					'confirmDelete'   => __( 'Diese Karte wirklich löschen? Seiten, die den Shortcode verwenden, zeigen dann keine Karte mehr.', 'b83-osm-map' ),
					'confirmRemove'   => __( 'Diese Adresse entfernen?', 'b83-osm-map' ),
					'searching'       => __( 'Suche …', 'b83-osm-map' ),
					'notFound'        => __( 'Adresse nicht gefunden. Bitte genauer angeben (Straße, PLZ, Ort) oder Koordinaten eintragen.', 'b83-osm-map' ),
					'found'           => __( 'Koordinaten gefunden.', 'b83-osm-map' ),
					'addressMissing'  => __( 'Bitte zuerst eine Adresse eingeben.', 'b83-osm-map' ),
					'copied'          => __( 'Kopiert!', 'b83-osm-map' ),
					'chooseImage'     => __( 'Bild wählen', 'b83-osm-map' ),
					'useImage'        => __( 'Bild verwenden', 'b83-osm-map' ),
					'missingCoords'   => __( 'Adressen ohne Koordinaten werden nicht angezeigt:', 'b83-osm-map' ),
					'previewEmpty'    => __( 'Vorschau erscheint, sobald eine Adresse mit Koordinaten vorhanden ist.', 'b83-osm-map' ),
					'newAddress'      => __( 'Neue Adresse', 'b83-osm-map' ),
					'iconDot'         => __( 'Punkt', 'b83-osm-map' ),
					'iconNone'        => __( 'Kein Icon', 'b83-osm-map' ),
					'iconInherit'     => __( 'Standard', 'b83-osm-map' ),
					'iconSearch'      => __( 'Icon suchen …', 'b83-osm-map' ),
					'linkUrl'         => __( 'Link-Adresse (https://…):', 'b83-osm-map' ),
					'noteCarto'       => __( 'CARTO: kostenlos nur eingeschränkt – für gewerbliche Websites mit viel Verkehr die Nutzungsbedingungen von CARTO prüfen.', 'b83-osm-map' ),
					'noteTopo'        => __( 'OpenTopoMap: ideal für Wander- und Bergkarten; wird ehrenamtlich betrieben und lädt manchmal langsamer.', 'b83-osm-map' ),
					'noteEsri'        => __( 'Esri: Nutzungsbedingungen von Esri beachten.', 'b83-osm-map' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Seiten
	 * ------------------------------------------------------------------- */

	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'b83-osm-map' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
		$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'maps' ) );
		$map_id = absint( $_GET['map'] ?? 0 );
		$notice = sanitize_key( wp_unslash( $_GET['notice'] ?? '' ) );
		// phpcs:enable

		echo '<div class="wrap b83-osm-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'b83 OpenStreetMap', 'b83-osm-map' ) . '</h1>';
		if ( 'maps' === $tab ) {
			echo ' <a class="page-title-action" href="' . esc_url( $this->url( array( 'tab' => 'edit' ) ) ) . '">' . esc_html__( 'Neue Karte', 'b83-osm-map' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';

		$notices = $this->notices();
		if ( isset( $notices[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notices[ $notice ][0] ),
				esc_html( $notices[ $notice ][1] )
			);
		}

		$tabs = array(
			'maps'   => __( 'Karten', 'b83-osm-map' ),
			'design' => __( 'Standard-Design', 'b83-osm-map' ),
			'help'   => __( 'Shortcodes & Hilfe', 'b83-osm-map' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$active = $key === $tab || ( 'edit' === $tab && 'maps' === $key );
			echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $this->url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		match ( $tab ) {
			'edit'   => $this->render_editor( $map_id ),
			'design' => $this->render_design(),
			'help'   => $this->render_help(),
			default  => $this->render_list(),
		};

		echo '<p class="b83-osm-admin__foot">' . esc_html( sprintf( 'b83 OpenStreetMap %s · Leaflet %s · ', B83_OSM_VERSION, Assets::LEAFLET_VERSION ) ) . '<a href="https://b83.at" target="_blank" rel="noopener">b83.at</a></p>';
		echo '</div>';
	}

	private function render_list(): void {
		$maps = $this->repository->get_maps();

		if ( empty( $maps ) ) {
			echo '<div class="b83-osm-empty">';
			echo '<h2>' . esc_html__( 'Noch keine Karte angelegt', 'b83-osm-map' ) . '</h2>';
			echo '<p>' . esc_html__( 'Lege eine Karte mit einer oder mehreren Adressen an und binde sie mit dem Shortcode ein – oder verwende den Shortcode direkt mit einer Adresse (siehe „Shortcodes & Hilfe“).', 'b83-osm-map' ) . '</p>';
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( $this->url( array( 'tab' => 'edit' ) ) ) . '">' . esc_html__( 'Erste Karte anlegen', 'b83-osm-map' ) . '</a></p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped b83-osm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'b83-osm-map' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'b83-osm-map' ) . '</th>';
		echo '<th>' . esc_html__( 'Adressen', 'b83-osm-map' ) . '</th>';
		echo '<th>' . esc_html__( 'Geändert', 'b83-osm-map' ) . '</th>';
		echo '<th>' . esc_html__( 'Aktionen', 'b83-osm-map' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $maps as $id => $map ) {
			$edit      = $this->url(
				array(
					'tab' => 'edit',
					'map' => $id,
				)
			);
			$duplicate = wp_nonce_url( add_query_arg( array( 'action' => 'b83_osm_duplicate_map', 'map' => $id ), admin_url( 'admin-post.php' ) ), 'b83_osm_duplicate_map_' . $id );
			$delete    = wp_nonce_url( add_query_arg( array( 'action' => 'b83_osm_delete_map', 'map' => $id ), admin_url( 'admin-post.php' ) ), 'b83_osm_delete_map_' . $id );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $map['name'] ) . '</a></strong></td>';
			echo '<td>' . $this->shortcode_box( $id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in shortcode_box escaped.
			echo '<td>' . esc_html( (string) count( $map['markers'] ) ) . '</td>';
			echo '<td>' . esc_html( $map['updated'] ? wp_date( get_option( 'date_format' ) . ' H:i', $map['updated'] ) : '–' ) . '</td>';
			echo '<td class="b83-osm-table__actions">';
			echo '<a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Bearbeiten', 'b83-osm-map' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( $duplicate ) . '">' . esc_html__( 'Duplizieren', 'b83-osm-map' ) . '</a> ';
			echo '<a class="button button-small button-link-delete b83-osm-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Löschen', 'b83-osm-map' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function shortcode_box( int $id ): string {
		$code = '[b83_osm id="' . $id . '"]';
		return '<span class="b83-osm-code"><input type="text" readonly value="' . esc_attr( $code ) . '" size="' . ( strlen( $code ) + 1 ) . '" aria-label="' . esc_attr__( 'Shortcode', 'b83-osm-map' ) . '">'
			. '<button type="button" class="button button-small b83-osm-copy">' . esc_html__( 'Kopieren', 'b83-osm-map' ) . '</button></span>';
	}

	/* ---------------------------------------------------------------------
	 * Karten-Editor
	 * ------------------------------------------------------------------- */

	private function render_editor( int $map_id ): void {
		$map = $map_id > 0 ? $this->repository->get_map( $map_id ) : null;
		if ( $map_id > 0 && null === $map ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Die Karte wurde nicht gefunden.', 'b83-osm-map' ) . '</p></div>';
			return;
		}
		$map ??= array(
			'name'    => '',
			'markers' => array(),
			'design'  => Sanitizer::design( array(), true ),
		);
		$defaults = $this->repository->default_design();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b83-osm-editor" id="b83-osm-form" data-preview="map">';
		wp_nonce_field( 'b83_osm_save_map' );
		echo '<input type="hidden" name="action" value="b83_osm_save_map">';
		echo '<input type="hidden" name="map_id" value="' . esc_attr( (string) $map_id ) . '">';

		echo '<div class="b83-osm-editor__main">';

		echo '<div class="b83-osm-card">';
		echo '<label class="b83-osm-label" for="b83-osm-name">' . esc_html__( 'Name der Karte', 'b83-osm-map' ) . '</label>';
		echo '<input type="text" class="large-text" id="b83-osm-name" name="name" maxlength="100" required value="' . esc_attr( $map['name'] ) . '" placeholder="' . esc_attr__( 'z. B. Anfahrt Tourismusverband', 'b83-osm-map' ) . '">';
		echo '<p class="description">' . esc_html__( 'Nur für die Übersicht im Backend und als Beschriftung für Screenreader.', 'b83-osm-map' ) . '</p>';
		echo '</div>';

		// Adressen.
		echo '<div class="b83-osm-card">';
		echo '<h2>' . esc_html__( 'Adressen', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Adresse eingeben und „Koordinaten ermitteln“ klicken – oder Breiten-/Längengrad direkt eintragen (z. B. aus OpenStreetMap: Rechtsklick → „Adresse anzeigen“). Reihenfolge per Ziehen ändern.', 'b83-osm-map' ) . '</p>';
		echo '<div class="b83-osm-markers" id="b83-osm-markers">';
		$markers = $map['markers'] ?: array( Sanitizer::marker( array() ) );
		foreach ( $markers as $i => $marker ) {
			$this->render_marker( (string) $i, $marker, 0 === $i );
		}
		echo '</div>';
		echo '<template id="b83-osm-marker-template">';
		$this->render_marker( '__i__', Sanitizer::marker( array() ), true );
		echo '</template>';
		echo '<p><button type="button" class="button" id="b83-osm-add-marker"><span class="dashicons dashicons-plus-alt2"></span> ' . esc_html__( 'Adresse hinzufügen', 'b83-osm-map' ) . '</button></p>';
		echo '</div>';

		// Design.
		echo '<div class="b83-osm-card">';
		echo '<h2>' . esc_html__( 'Design dieser Karte', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: Link zum Standard-Design */
				__( 'Leere Felder übernehmen das <a href="%s">Standard-Design</a>.', 'b83-osm-map' ),
				esc_url( $this->url( array( 'tab' => 'design' ) ) )
			),
			array( 'a' => array( 'href' => true ) )
		) . '</p>';
		$this->render_design_fields( 'design', $map['design'], $defaults, true );
		echo '</div>';

		echo '</div>'; // main

		echo '<aside class="b83-osm-editor__side">';
		echo '<div class="b83-osm-card b83-osm-sticky">';
		echo '<h2>' . esc_html__( 'Vorschau', 'b83-osm-map' ) . '</h2>';
		$this->render_preview_box();
		if ( $map_id > 0 ) {
			echo '<p class="b83-osm-label">' . esc_html__( 'Shortcode', 'b83-osm-map' ) . '</p>';
			echo $this->shortcode_box( $map_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in shortcode_box escaped.
		} else {
			echo '<p class="description">' . esc_html__( 'Der Shortcode erscheint nach dem ersten Speichern.', 'b83-osm-map' ) . '</p>';
		}
		echo '<p class="b83-osm-actions">';
		submit_button( __( 'Karte speichern', 'b83-osm-map' ), 'primary large', 'submit', false );
		echo ' <a class="button button-large" href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Zur Übersicht', 'b83-osm-map' ) . '</a>';
		echo '</p>';
		echo '</div>';
		echo '</aside>';

		echo '</form>';
	}

	private function render_preview_box(): void {
		echo '<div class="b83-osm-preview">';
		echo '<div class="b83-osm-wrap" id="b83-osm-preview-wrap"><div class="b83-osm" id="b83-osm-preview" role="region" aria-label="' . esc_attr__( 'Vorschau', 'b83-osm-map' ) . '"></div></div>';
		echo '<p class="b83-osm-preview__msg" id="b83-osm-preview-msg" aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Eine Adresse im Editor.
	 *
	 * @param string              $i      Index oder Platzhalter.
	 * @param array<string,mixed> $marker Adresse.
	 * @param bool                $open   Aufgeklappt.
	 */
	private function render_marker( string $i, array $marker, bool $open ): void {
		$name  = static fn( string $field ): string => 'markers[' . $i . '][' . $field . ']';
		$id    = static fn( string $field ): string => 'b83-osm-m-' . $i . '-' . $field;
		$title = $marker['title'] ?: ( $marker['address'] ? strtok( $marker['address'], "\n" ) : __( 'Neue Adresse', 'b83-osm-map' ) );

		echo '<details class="b83-osm-marker"' . ( $open ? ' open' : '' ) . '>';
		echo '<summary><span class="b83-osm-marker__handle dashicons dashicons-move" aria-hidden="true"></span>';
		echo '<span class="b83-osm-marker__title">' . esc_html( (string) $title ) . '</span>';
		echo '<span class="b83-osm-marker__coords' . ( ( '' !== $marker['lat'] && '' !== $marker['lng'] ) ? ' has-coords' : '' ) . '"></span>';
		echo '<button type="button" class="button-link b83-osm-marker__remove" aria-label="' . esc_attr__( 'Adresse entfernen', 'b83-osm-map' ) . '"><span class="dashicons dashicons-trash"></span></button>';
		echo '</summary>';

		echo '<div class="b83-osm-marker__body">';
		echo '<div class="b83-osm-grid">';

		echo '<p class="b83-osm-col-2"><label for="' . esc_attr( $id( 'title' ) ) . '">' . esc_html__( 'Titel', 'b83-osm-map' ) . '</label>';
		echo '<input type="text" class="widefat b83-osm-marker__title-input" id="' . esc_attr( $id( 'title' ) ) . '" name="' . esc_attr( $name( 'title' ) ) . '" maxlength="150" value="' . esc_attr( $marker['title'] ) . '" placeholder="' . esc_attr__( 'z. B. Tourismusverband Abtenau', 'b83-osm-map' ) . '"></p>';

		echo '<p class="b83-osm-col-2"><label for="' . esc_attr( $id( 'address' ) ) . '">' . esc_html__( 'Adresse', 'b83-osm-map' ) . '</label>';
		echo '<textarea class="widefat b83-osm-marker__address" rows="2" id="' . esc_attr( $id( 'address' ) ) . '" name="' . esc_attr( $name( 'address' ) ) . '" maxlength="300" placeholder="' . esc_attr__( 'Markt 165, 5441 Abtenau', 'b83-osm-map' ) . '">' . esc_textarea( $marker['address'] ) . '</textarea></p>';

		echo '<p><label for="' . esc_attr( $id( 'lat' ) ) . '">' . esc_html__( 'Breitengrad', 'b83-osm-map' ) . '</label>';
		echo '<input type="text" inputmode="decimal" class="widefat b83-osm-marker__lat" id="' . esc_attr( $id( 'lat' ) ) . '" name="' . esc_attr( $name( 'lat' ) ) . '" value="' . esc_attr( $marker['lat'] ) . '" placeholder="47.56395"></p>';
		echo '<p><label for="' . esc_attr( $id( 'lng' ) ) . '">' . esc_html__( 'Längengrad', 'b83-osm-map' ) . '</label>';
		echo '<input type="text" inputmode="decimal" class="widefat b83-osm-marker__lng" id="' . esc_attr( $id( 'lng' ) ) . '" name="' . esc_attr( $name( 'lng' ) ) . '" value="' . esc_attr( $marker['lng'] ) . '" placeholder="13.34424"></p>';
		echo '<p class="b83-osm-col-2 b83-osm-geocode"><button type="button" class="button b83-osm-geocode__btn"><span class="dashicons dashicons-location"></span> ' . esc_html__( 'Koordinaten aus Adresse ermitteln', 'b83-osm-map' ) . '</button> <span class="b83-osm-geocode__msg" aria-live="polite"></span></p>';

		echo '<div class="b83-osm-col-2"><label for="' . esc_attr( $id( 'text' ) ) . '">' . esc_html__( 'Text im Info-Fenster', 'b83-osm-map' ) . '</label>';
		echo '<div class="b83-osm-toolbar" role="toolbar">';
		foreach ( array(
			'strong' => array( 'editor-bold', __( 'Fett', 'b83-osm-map' ) ),
			'em'     => array( 'editor-italic', __( 'Kursiv', 'b83-osm-map' ) ),
			'a'      => array( 'admin-links', __( 'Link', 'b83-osm-map' ) ),
			'ul'     => array( 'editor-ul', __( 'Liste', 'b83-osm-map' ) ),
			'br'     => array( 'editor-break', __( 'Zeilenumbruch', 'b83-osm-map' ) ),
		) as $tag => $btn ) {
			echo '<button type="button" class="button button-small" data-tag="' . esc_attr( $tag ) . '" title="' . esc_attr( $btn[1] ) . '" aria-label="' . esc_attr( $btn[1] ) . '"><span class="dashicons dashicons-' . esc_attr( $btn[0] ) . '"></span></button>';
		}
		echo '</div>';
		echo '<textarea class="widefat b83-osm-marker__text" rows="4" id="' . esc_attr( $id( 'text' ) ) . '" name="' . esc_attr( $name( 'text' ) ) . '">' . esc_textarea( $marker['text'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'z. B. Öffnungszeiten. Leerzeilen werden zu Absätzen; erlaubt sind Fett, Kursiv, Links und Listen.', 'b83-osm-map' ) . '</p></div>';

		echo '<p><label for="' . esc_attr( $id( 'phone' ) ) . '">' . esc_html__( 'Telefon', 'b83-osm-map' ) . '</label>';
		echo '<input type="tel" class="widefat" id="' . esc_attr( $id( 'phone' ) ) . '" name="' . esc_attr( $name( 'phone' ) ) . '" maxlength="40" value="' . esc_attr( $marker['phone'] ) . '"></p>';
		echo '<p><label for="' . esc_attr( $id( 'email' ) ) . '">' . esc_html__( 'E-Mail', 'b83-osm-map' ) . '</label>';
		echo '<input type="email" class="widefat" id="' . esc_attr( $id( 'email' ) ) . '" name="' . esc_attr( $name( 'email' ) ) . '" maxlength="120" value="' . esc_attr( $marker['email'] ) . '"></p>';
		echo '<p class="b83-osm-col-2"><label for="' . esc_attr( $id( 'website' ) ) . '">' . esc_html__( 'Website', 'b83-osm-map' ) . '</label>';
		echo '<input type="url" class="widefat" id="' . esc_attr( $id( 'website' ) ) . '" name="' . esc_attr( $name( 'website' ) ) . '" maxlength="300" value="' . esc_attr( $marker['website'] ) . '" placeholder="https://"></p>';

		echo '<div><span class="b83-osm-label">' . esc_html__( 'Pin-Icon', 'b83-osm-map' ) . '</span>';
		$this->icon_picker( $name( 'icon' ), $marker['icon'], true, __( 'Wie Karte', 'b83-osm-map' ) );
		echo '</div>';
		echo '<p><label for="' . esc_attr( $id( 'color' ) ) . '">' . esc_html__( 'Pin-Farbe', 'b83-osm-map' ) . '</label>';
		echo '<input type="text" class="b83-osm-color" id="' . esc_attr( $id( 'color' ) ) . '" name="' . esc_attr( $name( 'color' ) ) . '" value="' . esc_attr( $marker['color'] ) . '" data-placeholder="' . esc_attr__( 'Wie Karte', 'b83-osm-map' ) . '"></p>';

		echo '<div class="b83-osm-col-2"><span class="b83-osm-label">' . esc_html__( 'Bild im Info-Fenster', 'b83-osm-map' ) . '</span>';
		$this->media_field( $name( 'image' ), (int) $marker['image'] );
		echo '</div>';

		echo '</div></div></details>';
	}

	/**
	 * Icon-Auswahl.
	 *
	 * @param bool $inherit Option „Standard/Wie Karte“ anbieten.
	 */
	private function icon_picker( string $name, string $value, bool $inherit, string $inherit_label ): void {
		echo '<div class="b83-osm-icon-picker" data-inherit="' . ( $inherit ? '1' : '0' ) . '" data-inherit-label="' . esc_attr( $inherit_label ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		echo '<button type="button" class="button b83-osm-icon-picker__toggle" aria-expanded="false"><span class="b83-osm-icon-picker__current"></span><span class="b83-osm-icon-picker__label"></span><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
		echo '</div>';
	}

	private function media_field( string $name, int $value ): void {
		$src = $value > 0 ? wp_get_attachment_image_url( $value, 'thumbnail' ) : '';
		echo '<div class="b83-osm-media">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value > 0 ? (string) $value : '' ) . '">';
		echo '<span class="b83-osm-media__preview">' . ( $src ? '<img src="' . esc_url( $src ) . '" alt="">' : '' ) . '</span>';
		echo '<button type="button" class="button b83-osm-media__choose">' . esc_html__( 'Bild wählen', 'b83-osm-map' ) . '</button> ';
		echo '<button type="button" class="button-link b83-osm-media__remove"' . ( $src ? '' : ' hidden' ) . '>' . esc_html__( 'Entfernen', 'b83-osm-map' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Design-Felder, gruppiert.
	 *
	 * @param string              $prefix   Feldname-Präfix.
	 * @param array<string,mixed> $values   Aktuelle Werte.
	 * @param array<string,mixed> $defaults Standard-Design (für Platzhalter).
	 * @param bool                $inherit  Leere Werte = Standard übernehmen.
	 */
	private function render_design_fields( string $prefix, array $values, array $defaults, bool $inherit ): void {
		$fields = Design::fields();
		foreach ( Design::groups() as $group => $heading ) {
			echo '<details class="b83-osm-group" ' . ( 'map' === $group ? 'open' : '' ) . '><summary>' . esc_html( $heading ) . '</summary><div class="b83-osm-grid">';
			foreach ( $fields as $key => $field ) {
				if ( $field['group'] !== $group ) {
					continue;
				}
				$this->render_design_field( $prefix, $key, $field, $values[ $key ] ?? '', $defaults[ $key ] ?? $field['default'], $inherit );
			}
			echo '</div></details>';
		}
	}

	/**
	 * @param array<string,mixed> $field Feld-Definition.
	 */
	private function render_design_field( string $prefix, string $key, array $field, mixed $value, mixed $default, bool $inherit ): void {
		$name  = $prefix . '[' . $key . ']';
		$id    = 'b83-osm-' . $prefix . '-' . $key;
		$wide  = in_array( $field['type'], array( 'text', 'icon', 'attachment' ), true ) ? ' b83-osm-col-2' : '';
		$value = (string) $value;

		echo '<div class="b83-osm-field b83-osm-field--' . esc_attr( $key ) . esc_attr( $wide ) . '">';

		if ( 'bool' === $field['type'] && ! $inherit ) {
			echo '<label class="b83-osm-check"><input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( '1', $value, false ) . '> ' . esc_html( $field['label'] ) . '</label>';
		} else {
			echo '<label class="b83-osm-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';

			switch ( $field['type'] ) {
				case 'enum':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					if ( $inherit ) {
						/* translators: %s: Standardwert */
						echo '<option value="">' . esc_html( sprintf( __( 'Standard (%s)', 'b83-osm-map' ), (string) ( $field['options'][ $default ] ?? $default ) ) ) . '</option>';
					}
					foreach ( $field['options'] as $opt => $label ) {
						echo '<option value="' . esc_attr( (string) $opt ) . '"' . selected( $value, (string) $opt, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					if ( 'style' === $key ) {
						echo '<span class="description b83-osm-layer-note" data-notes="' . esc_attr( (string) wp_json_encode( array_map( static fn( array $l ): string => $l['note'], Layers::all() ) ) ) . '"></span>';
					}
					break;

				case 'bool':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					/* translators: %s: Ja/Nein */
					echo '<option value="">' . esc_html( sprintf( __( 'Standard (%s)', 'b83-osm-map' ), '1' === (string) $default ? __( 'Ja', 'b83-osm-map' ) : __( 'Nein', 'b83-osm-map' ) ) ) . '</option>';
					echo '<option value="1"' . selected( $value, '1', false ) . '>' . esc_html__( 'Ja', 'b83-osm-map' ) . '</option>';
					echo '<option value="0"' . selected( $value, '0', false ) . '>' . esc_html__( 'Nein', 'b83-osm-map' ) . '</option>';
					echo '</select>';
					break;

				case 'int':
					printf(
						'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1"%6$s>',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( $value ),
						(int) $field['min'],
						(int) $field['max'],
						$inherit ? ' placeholder="' . esc_attr( (string) $default ) . '"' : ' required'
					);
					break;

				case 'color':
					printf(
						'<input type="text" class="b83-osm-color" id="%1$s" name="%2$s" value="%3$s" data-default-color="%4$s"%5$s>',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( $value ),
						esc_attr( $inherit ? '' : (string) $field['default'] ),
						$inherit ? ' data-placeholder="' . esc_attr( (string) $default ) . '"' : ''
					);
					if ( $inherit && '' !== (string) $default ) {
						/* translators: %s: Farbwert */
						echo '<span class="description b83-osm-inherit-hint">' . esc_html( sprintf( __( 'Leer = Standard (%s)', 'b83-osm-map' ), (string) $default ) ) . '</span>';
					}
					break;

				case 'text':
					printf(
						'<input type="text" class="widefat" id="%1$s" name="%2$s" value="%3$s" maxlength="%4$d"%5$s>',
						esc_attr( $id ),
						esc_attr( $name ),
						esc_attr( $value ),
						(int) ( $field['max'] ?? 200 ),
						$inherit ? ' placeholder="' . esc_attr( (string) $default ) . '"' : ''
					);
					break;

				case 'icon':
					$this->icon_picker( $name, $value, $inherit, __( 'Standard', 'b83-osm-map' ) );
					break;

				case 'attachment':
					$this->media_field( $name, (int) $value );
					break;
			}
		}

		if ( ! empty( $field['help'] ) ) {
			echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
		}
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Standard-Design
	 * ------------------------------------------------------------------- */

	private function render_design(): void {
		$settings = $this->repository->get_settings();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b83-osm-editor" id="b83-osm-form" data-preview="design">';
		wp_nonce_field( 'b83_osm_save_settings' );
		echo '<input type="hidden" name="action" value="b83_osm_save_settings">';

		echo '<div class="b83-osm-editor__main">';
		echo '<div class="b83-osm-card">';
		echo '<h2>' . esc_html__( 'Standard-Design aller Karten', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Gilt für alle Karten, solange in der Karte oder im Shortcode nichts anderes eingestellt ist.', 'b83-osm-map' ) . '</p>';
		$this->render_design_fields( 'design', $settings['design'], $settings['design'], false );
		echo '</div>';

		echo '<div class="b83-osm-card">';
		echo '<h2>' . esc_html__( 'Allgemein', 'b83-osm-map' ) . '</h2>';
		$cache = CachePurge::detected_plugin();
		echo '<p><label class="b83-osm-check"><input type="hidden" name="purge_cache" value="0"><input type="checkbox" name="purge_cache" value="1"' . checked( '1', $settings['purge_cache'], false ) . '> ';
		echo esc_html__( 'Nach dem Speichern den Cache der Seiten mit Karten leeren', 'b83-osm-map' ) . '</label>';
		if ( '' !== $cache ) {
			/* translators: %s: Name des Cache-Plugins */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Erkannt: %s', 'b83-osm-map' ), $cache ) ) . '</span>';
		}
		echo '</p>';
		echo '<p><label class="b83-osm-check"><input type="hidden" name="delete_on_uninstall" value="0"><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( '1', $settings['delete_on_uninstall'], false ) . '> ';
		echo esc_html__( 'Beim Löschen des Plugins alle Karten und Einstellungen entfernen', 'b83-osm-map' ) . '</label></p>';
		echo '</div>';
		echo '</div>';

		echo '<aside class="b83-osm-editor__side"><div class="b83-osm-card b83-osm-sticky">';
		echo '<h2>' . esc_html__( 'Vorschau', 'b83-osm-map' ) . '</h2>';
		$this->render_preview_box();
		echo '<p class="b83-osm-actions">';
		submit_button( __( 'Einstellungen speichern', 'b83-osm-map' ), 'primary large', 'submit', false );
		echo '</p></div></aside>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b83-osm-card b83-osm-small-form">';
		wp_nonce_field( 'b83_osm_clear_geocache' );
		echo '<input type="hidden" name="action" value="b83_osm_clear_geocache">';
		echo '<h2>' . esc_html__( 'Gespeicherte Koordinaten', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Adressen aus Shortcodes werden einmalig über OpenStreetMap (Nominatim) gesucht und gespeichert. Leeren, wenn eine Adresse falsch angezeigt wurde.', 'b83-osm-map' ) . '</p>';
		submit_button( __( 'Gespeicherte Koordinaten leeren', 'b83-osm-map' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Hilfe
	 * ------------------------------------------------------------------- */

	private function render_help(): void {
		$examples = array(
			__( 'Gespeicherte Karte', 'b83-osm-map' )            => '[b83_osm id="1"]',
			__( 'Eine Adresse direkt', 'b83-osm-map' )           => '[b83_osm address="Markt 165, 5441 Abtenau" title="Tourismusverband Abtenau"]Mo–Fr 8–12 Uhr[/b83_osm]',
			__( 'Mit Koordinaten und Design', 'b83-osm-map' )    => '[b83_osm lat="47.56395" lng="13.34424" title="Tourismusverband" height="400" radius="20" color="#0f766e" style="carto-light" icon="landmark"]',
			__( 'Mehrere Adressen', 'b83-osm-map' )              => "[b83_osm list=\"below\" zoom=\"14\"]\n[b83_osm_marker title=\"Hotel Post\" address=\"Markt 1, 5441 Abtenau\" icon=\"hotel\"]<strong>Rezeption</strong> 0–24 Uhr[/b83_osm_marker]\n[b83_osm_marker title=\"Parkplatz\" lat=\"47.5632\" lng=\"13.3458\" icon=\"square-parking\" color=\"#2563eb\"]\n[/b83_osm]",
			__( 'Gespeicherte Karte, anders gestaltet', 'b83-osm-map' ) => '[b83_osm id="1" filter="grayscale" border_width="4" border_color="#ffffff" shadow="strong"]',
		);

		echo '<div class="b83-osm-card b83-osm-help">';
		echo '<h2>' . esc_html__( 'Beispiele', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shortcodes funktionieren im Block-Editor (Block „Shortcode“), in Elementor (Widget „Shortcode“ oder „Text-Editor“) und in Textwidgets.', 'b83-osm-map' ) . '</p>';
		foreach ( $examples as $label => $code ) {
			echo '<h3>' . esc_html( $label ) . '</h3><pre class="b83-osm-pre"><code>' . esc_html( $code ) . '</code></pre>';
		}

		echo '<h2>' . esc_html__( 'Attribute für eine Adresse', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'In [b83_osm] (eine Adresse) und in [b83_osm_marker] (mehrere Adressen). Text zwischen Start- und End-Tag erscheint formatiert im Info-Fenster.', 'b83-osm-map' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( array(
			'title'   => __( 'Titel (fett)', 'b83-osm-map' ),
			'address' => __( 'Adresse – Koordinaten werden automatisch ermittelt und gespeichert', 'b83-osm-map' ),
			'lat/lng' => __( 'Koordinaten (genauer und schneller als die Adresssuche)', 'b83-osm-map' ),
			'phone'   => __( 'Telefon (anklickbar)', 'b83-osm-map' ),
			'email'   => __( 'E-Mail (anklickbar, vor Spam geschützt)', 'b83-osm-map' ),
			'website' => __( 'Website', 'b83-osm-map' ),
			'icon'    => __( 'Pin-Icon (siehe Liste unten); in [b83_osm] gilt es für alle Adressen', 'b83-osm-map' ),
			'color'   => __( 'Pin-Farbe dieser Adresse (nur [b83_osm_marker]), z. B. #2563eb', 'b83-osm-map' ),
			'image'   => __( 'Bild im Info-Fenster: ID aus der Mediathek', 'b83-osm-map' ),
		) as $att => $desc ) {
			echo '<tr><th scope="row"><code>' . esc_html( $att ) . '</code></th><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Design-Attribute für [b83_osm]', 'b83-osm-map' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attribut', 'b83-osm-map' ) . '</th><th>' . esc_html__( 'Bedeutung', 'b83-osm-map' ) . '</th><th>' . esc_html__( 'Werte', 'b83-osm-map' ) . '</th></tr></thead><tbody>';
		foreach ( Design::fields() as $key => $field ) {
			$values = match ( $field['type'] ) {
				'enum'       => implode( ', ', array_keys( $field['options'] ) ),
				'int'        => $field['min'] . '–' . $field['max'],
				'color'      => '#rrggbb',
				'bool'       => '1 / 0',
				'icon'       => __( 'Icon-Name, „dot“ oder Font-Awesome-Klasse', 'b83-osm-map' ),
				'attachment' => __( 'Mediathek-ID', 'b83-osm-map' ),
				default      => __( 'Text', 'b83-osm-map' ),
			};
			echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( $field['label'] ) . '</td><td><code>' . esc_html( $values ) . '</code></td></tr>';
		}
		echo '<tr><td><code>class</code></td><td>' . esc_html__( 'Eigene CSS-Klasse', 'b83-osm-map' ) . '</td><td>' . esc_html__( 'Text', 'b83-osm-map' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Mitgelieferte Icons', 'b83-osm-map' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Font Awesome Free (CC BY 4.0), als SVG eingebunden – es wird keine Schrift geladen. Lädt die Website Font Awesome bereits (z. B. über Elementor), funktionieren auch Klassen wie „fa-solid fa-hotel“.', 'b83-osm-map' ) . '</p>';
		echo '<ul class="b83-osm-icon-list">';
		foreach ( Icons::all() as $key => $icon ) {
			echo '<li>' . Icons::svg( $key ) . '<code>' . esc_html( $key ) . '</code><span>' . esc_html( $icon['label'] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg escaped.
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'Datenschutz', 'b83-osm-map' ) . '</h2>';
		echo '<p>' . esc_html__( 'Leaflet und die Icons liegen auf der eigenen Website. Die Kartenbilder (Kacheln) kommen vom gewählten Anbieter, der dabei die IP-Adresse der Besucher erhält. Den Anbieter daher in der Datenschutzerklärung nennen oder „Karte erst nach Klick laden“ aktivieren.', 'b83-osm-map' ) . '</p>';
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------- */

	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'b83-osm-map' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/** @param array<string,string|int> $args Parameter. */
	private function redirect( array $args ): never {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	public function handle_save_map(): void {
		$this->guard( 'b83_osm_save_map' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- in guard() geprüft.
		$id  = absint( wp_unslash( $_POST['map_id'] ?? 0 ) );
		$raw = array(
			'name'    => wp_unslash( $_POST['name'] ?? '' ),
			'markers' => isset( $_POST['markers'] ) && is_array( $_POST['markers'] ) ? wp_unslash( $_POST['markers'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitizer::map().
			'design'  => isset( $_POST['design'] ) && is_array( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitizer::map().
		);
		// phpcs:enable

		$map = Sanitizer::map( $raw );

		// Fehlende Koordinaten jetzt ermitteln, damit Besucher nie warten müssen.
		$missing = false;
		$this->geocoder->set_limit( 25 );
		foreach ( $map['markers'] as &$marker ) {
			if ( ( '' === $marker['lat'] || '' === $marker['lng'] ) ) {
				$found = '' !== $marker['address'] ? $this->geocoder->lookup( $marker['address'] ) : null;
				if ( null !== $found ) {
					$marker['lat'] = $found['lat'];
					$marker['lng'] = $found['lng'];
				} else {
					$missing = true;
				}
			}
		}
		unset( $marker );

		$id = $this->repository->save_map( $id, $map );
		$this->redirect(
			array(
				'tab'    => 'edit',
				'map'    => $id,
				'notice' => $missing ? 'saved_miss' : 'saved',
			)
		);
	}

	public function handle_delete_map(): void {
		$id = absint( wp_unslash( $_GET['map'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- im guard() geprüft.
		$this->guard( 'b83_osm_delete_map_' . $id );
		$this->redirect( array( 'notice' => $this->repository->delete_map( $id ) ? 'deleted' : 'not_found' ) );
	}

	public function handle_duplicate_map(): void {
		$id = absint( wp_unslash( $_GET['map'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- im guard() geprüft.
		$this->guard( 'b83_osm_duplicate_map_' . $id );
		$new = $this->repository->duplicate_map( $id );
		$this->redirect( $new > 0 ? array( 'notice' => 'duplicated' ) : array( 'notice' => 'not_found' ) );
	}

	public function handle_save_settings(): void {
		$this->guard( 'b83_osm_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() / Sanitizer::settings().
		$this->repository->save_settings(
			array(
				'design'              => isset( $_POST['design'] ) && is_array( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : array(),
				'purge_cache'         => wp_unslash( $_POST['purge_cache'] ?? '0' ),
				'delete_on_uninstall' => wp_unslash( $_POST['delete_on_uninstall'] ?? '0' ),
			)
		);
		// phpcs:enable
		$this->redirect(
			array(
				'tab'    => 'design',
				'notice' => 'settings',
			)
		);
	}

	public function handle_clear_geocache(): void {
		$this->guard( 'b83_osm_clear_geocache' );
		$this->geocoder->clear_cache();
		$this->redirect(
			array(
				'tab'    => 'design',
				'notice' => 'geocache',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	public function ajax_geocode(): void {
		check_ajax_referer( 'b83_osm_ajax' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$address = Sanitizer::textarea( wp_unslash( $_POST['address'] ?? '' ), 300 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitizer::textarea().
		$found   = '' !== $address ? $this->geocoder->lookup( $address ) : null;
		if ( null === $found ) {
			wp_send_json_error();
		}
		wp_send_json_success( $found );
	}

	public function ajax_preview(): void {
		check_ajax_referer( 'b83_osm_ajax' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- über Sanitizer.
		$mode   = sanitize_key( wp_unslash( $_POST['mode'] ?? 'map' ) );
		$design = isset( $_POST['design'] ) && is_array( $_POST['design'] ) ? wp_unslash( $_POST['design'] ) : array();

		if ( 'design' === $mode ) {
			// Standard-Design mit Beispieladressen.
			$map = array(
				'markers' => array(
					Sanitizer::marker(
						array(
							'title'   => __( 'Beispiel GmbH', 'b83-osm-map' ),
							'address' => "Residenzplatz 1\n5020 Salzburg",
							'lat'     => '47.7981',
							'lng'     => '13.0464',
							'text'    => __( '<strong>Öffnungszeiten</strong><br>Mo–Fr 8–17 Uhr', 'b83-osm-map' ),
							'phone'   => '+43 662 000000',
							'website' => 'https://b83.at',
						)
					),
					Sanitizer::marker(
						array(
							'title'   => __( 'Parkplatz', 'b83-osm-map' ),
							'address' => 'Mozartplatz, 5020 Salzburg',
							'lat'     => '47.7985',
							'lng'     => '13.0476',
						)
					),
				),
				'design'  => array(),
			);
			$preview = $this->renderer->preview_with_defaults( $map, Sanitizer::design( $design, false ) );
		} else {
			$map     = Sanitizer::map(
				array(
					'name'    => '',
					'markers' => isset( $_POST['markers'] ) && is_array( $_POST['markers'] ) ? wp_unslash( $_POST['markers'] ) : array(),
					'design'  => $design,
				)
			);
			$preview = $this->renderer->preview( $map );
		}
		// phpcs:enable

		wp_send_json_success( $preview );
	}
}
