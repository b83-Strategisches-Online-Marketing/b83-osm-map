<?php
/**
 * Einstiegspunkt: verdrahtet alle Komponenten.
 *
 * @package B83\OsmMap
 */

declare(strict_types=1);

namespace B83\OsmMap;

use B83\OsmMap\Admin\AdminPage;
use B83\OsmMap\Frontend\Assets;
use B83\OsmMap\Frontend\Renderer;
use B83\OsmMap\Frontend\Shortcodes;
use B83\OsmMap\Integrations\CachePurge;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Repository $repository;
	private Geocoder $geocoder;
	private Renderer $renderer;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->repository = new Repository();
		$this->geocoder   = new Geocoder();
		$this->renderer   = new Renderer( $this->repository, $this->geocoder );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Migration::class, 'maybe_run' ) );

		( new Assets() )->register();
		( new CachePurge( $this->repository ) )->register();
		( new Updater() )->register();
		( new Shortcodes( $this->renderer ) )->register();

		if ( is_admin() ) {
			( new AdminPage( $this->repository, $this->renderer, $this->geocoder ) )->register();
		}

		/**
		 * Nach dem Laden aller Komponenten. Erweiterungen können sich hier einhängen.
		 *
		 * @param Plugin $plugin Instanz.
		 */
		do_action( 'b83_osm_loaded', $this );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'b83-osm-map', false, dirname( plugin_basename( B83_OSM_FILE ) ) . '/languages' );
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function renderer(): Renderer {
		return $this->renderer;
	}

	public function geocoder(): Geocoder {
		return $this->geocoder;
	}
}
