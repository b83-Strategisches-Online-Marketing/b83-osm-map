<?php
/**
 * Leert nach dem Speichern einer Karte oder der Standards den Seiten-Cache
 * der Seiten, auf denen eine Karte eingebunden ist (WP Rocket, LiteSpeed,
 * W3 Total Cache, WP Super Cache, SiteGround).
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap\Integrations;

use B83\OsmMap\Repository;

defined( 'ABSPATH' ) || exit;

final class CachePurge {

	private const NEEDLE    = '[b83_osm';
	private const TRANSIENT = 'b83_osm_pages_scan';

	public function __construct( private Repository $repository ) {}

	public function register(): void {
		add_action( 'b83_osm_map_saved', array( $this, 'purge' ) );
		add_action( 'b83_osm_map_deleted', array( $this, 'purge' ) );
		add_action( 'b83_osm_settings_saved', array( $this, 'purge' ) );
		add_action(
			'save_post',
			static function (): void {
				delete_transient( self::TRANSIENT );
			}
		);
	}

	/**
	 * Seiten mit Karten (Inhalt und Elementor-Daten), 12 Stunden zwischengespeichert.
	 *
	 * @return int[]
	 */
	public function affected_pages(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			$ids = array_map( 'intval', $cached );
		} else {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( self::NEEDLE ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ergebnis wird per Transient gecacht.
			$from_content = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 500", $like ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$from_meta = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 500", $like ) );
			$ids       = array_values( array_unique( array_merge( array_map( 'intval', (array) $from_content ), array_map( 'intval', (array) $from_meta ) ) ) );
			set_transient( self::TRANSIENT, $ids, 12 * HOUR_IN_SECONDS );
		}

		/**
		 * Filtert die IDs der Seiten, deren Cache nach dem Speichern geleert wird.
		 *
		 * @param int[] $ids Beitrags-/Seiten-IDs.
		 */
		return array_map( 'intval', (array) apply_filters( 'b83_osm_purge_post_ids', $ids ) );
	}

	public function purge(): void {
		if ( '1' !== $this->repository->get_settings()['purge_cache'] ) {
			return;
		}
		$ids = $this->affected_pages();
		foreach ( $ids as $id ) {
			$this->purge_post( $id );
		}

		/**
		 * Nach dem Leeren des Caches – für weitere Cache-Lösungen.
		 *
		 * @param int[] $ids Beitrags-/Seiten-IDs.
		 */
		do_action( 'b83_osm_purge_cache', $ids );
	}

	private function purge_post( int $id ): void {
		clean_post_cache( $id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id ); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $id ); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $id ); // WP Super Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( (string) get_permalink( $id ) ); // SiteGround.
		}
		do_action( 'litespeed_purge_post', $id ); // LiteSpeed Cache.
	}

	/** Name des erkannten Cache-Plugins für die Anzeige im Backend ('' wenn keins). */
	public static function detected_plugin(): string {
		return match ( true ) {
			function_exists( 'rocket_clean_post' )         => 'WP Rocket',
			defined( 'LSCWP_V' )                           => 'LiteSpeed Cache',
			function_exists( 'w3tc_flush_post' )           => 'W3 Total Cache',
			function_exists( 'wp_cache_post_change' )      => 'WP Super Cache',
			function_exists( 'sg_cachepress_purge_cache' ) => 'SiteGround Speed Optimizer',
			default                                        => '',
		};
	}
}
