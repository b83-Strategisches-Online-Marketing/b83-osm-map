<?php
/**
 * Deinstallation. Karten und Einstellungen werden nur entfernt, wenn das in
 * den Einstellungen ausdrücklich aktiviert wurde.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$b83_osm_settings = get_option( 'b83_osm_settings', array() );

if ( is_array( $b83_osm_settings ) && ! empty( $b83_osm_settings['delete_on_uninstall'] ) && '0' !== $b83_osm_settings['delete_on_uninstall'] ) {
	foreach ( array(
		'b83_osm_settings',
		'b83_osm_maps',
		'b83_osm_next_id',
		'b83_osm_db_version',
		'b83_osm_geocache',
	) as $b83_osm_option ) {
		delete_option( $b83_osm_option );
	}
	delete_transient( 'b83_osm_pages_scan' );
	delete_transient( 'b83_osm_map_release' );
}
