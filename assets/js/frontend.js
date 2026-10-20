/**
 * b83 OpenStreetMap – Frontend
 *
 * Baut alle Karten mit [data-b83-osm] auf. Die Konfiguration (inkl. fertig
 * escaptem Markup für Pins und Info-Fenster) kommt vom Server.
 *
 * Öffentliche Schnittstelle:
 *   window.b83Osm.init( root )        Karten in root (Standard: document) aufbauen
 *   window.b83Osm.load( el )          Karte mit „Erst nach Klick laden“ sofort laden
 *   window.b83Osm.render( el, cfg )   Karte mit neuer Konfiguration neu zeichnen
 * Ereignis: document „b83-osm:ready“ (detail: { el, map, markers })
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'b83_osm_consent';
	var i18n = window.b83OsmI18n || {};
	var touch = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function rememberedConsent() {
		try {
			return window.localStorage.getItem( STORAGE_KEY ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function storeConsent() {
		try {
			window.localStorage.setItem( STORAGE_KEY, '1' );
		} catch ( e ) {}
	}

	function parseConfig( el ) {
		try {
			return JSON.parse( el.getAttribute( 'data-b83-osm' ) || '{}' );
		} catch ( e ) {
			return null;
		}
	}

	function tileLayer( layer ) {
		var options = {
			maxZoom: layer.maxZoom || 19,
			attribution: layer.attribution || ''
		};
		if ( layer.subdomains ) {
			options.subdomains = layer.subdomains;
		}
		return L.tileLayer( layer.url, options );
	}

	function makeIcon( pin ) {
		return L.divIcon( {
			className: 'b83-osm__marker',
			html: pin.html,
			iconSize: pin.size,
			iconAnchor: pin.anchor,
			popupAnchor: pin.popup
		} );
	}

	/* Vollbild-Schaltfläche (Fullscreen-API, ohne Zusatzbibliothek) */
	var FullscreenControl = null;
	function fullscreenControl( el ) {
		if ( ! FullscreenControl ) {
			FullscreenControl = L.Control.extend( {
				options: { position: 'topright' },
				onAdd: function () {
					var box = L.DomUtil.create( 'div', 'leaflet-bar leaflet-control' );
					var btn = L.DomUtil.create( 'a', 'b83-osm__fs', box );
					btn.href = '#';
					btn.setAttribute( 'role', 'button' );
					var target = this.options.target;
					var update = function () {
						var active = document.fullscreenElement === target;
						btn.title = active ? t( 'exitFullscreen', 'Vollbild beenden' ) : t( 'fullscreen', 'Vollbild' );
						btn.setAttribute( 'aria-label', btn.title );
						btn.innerHTML = active
							? '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M6 1v5H1M10 1v5h5M6 15v-5H1M10 15v-5h5"/></svg>'
							: '<svg viewBox="0 0 16 16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M1 6V1h5M15 6V1h-5M1 10v5h5M15 10v5h-5"/></svg>';
					};
					update();
					document.addEventListener( 'fullscreenchange', update );
					L.DomEvent.disableClickPropagation( box );
					L.DomEvent.on( btn, 'click', function ( e ) {
						L.DomEvent.preventDefault( e );
						if ( document.fullscreenElement ) {
							document.exitFullscreen();
						} else if ( target.requestFullscreen ) {
							target.requestFullscreen();
						}
					} );
					return box;
				}
			} );
		}
		return new FullscreenControl( { target: el } );
	}

	function destroy( el ) {
		if ( el._b83Osm ) {
			if ( el._b83Osm.observer ) {
				el._b83Osm.observer.disconnect();
			}
			el._b83Osm.map.remove();
			el._b83Osm = null;
		}
		var hint = el.querySelector( '.b83-osm__hint' );
		if ( hint ) {
			hint.parentNode.removeChild( hint );
		}
	}

	function build( el, cfg ) {
		destroy( el );
		if ( ! cfg || ! cfg.layer || ! cfg.markers || ! cfg.markers.length ) {
			return;
		}

		var interactive = cfg.scroll === 'always';
		var map = L.map( el, {
			zoomControl: false,
			scrollWheelZoom: interactive,
			dragging: interactive || ! touch,
			tap: false,
			keyboard: true,
			attributionControl: true
		} );
		map.attributionControl.setPrefix( false );

		if ( cfg.zoomButtons ) {
			L.control.zoom( {
				position: 'topleft',
				zoomInTitle: t( 'zoomIn', 'Vergrößern' ),
				zoomOutTitle: t( 'zoomOut', 'Verkleinern' )
			} ).addTo( map );
		}

		var base = tileLayer( cfg.layer ).addTo( map );
		if ( cfg.layers && cfg.layers.length > 1 ) {
			var choices = {};
			cfg.layers.forEach( function ( layer ) {
				choices[ layer.label ] = layer.key === cfg.layer.key ? base : tileLayer( layer );
			} );
			L.control.layers( choices, null, { position: 'topright', collapsed: true } ).addTo( map );
		}
		if ( cfg.fullscreen && el.requestFullscreen ) {
			fullscreenControl( el ).addTo( map );
		}

		var markers = [];
		var bounds = [];
		cfg.markers.forEach( function ( m ) {
			var marker = L.marker( [ m.lat, m.lng ], {
				icon: makeIcon( m.pin ),
				title: m.title || '',
				alt: m.title || '',
				riseOnHover: true,
				keyboard: true
			} ).addTo( map );
			if ( m.popup ) {
				marker.bindPopup( m.popup, { autoPan: true, autoPanPadding: [ 24, 24 ], maxWidth: 280, closeButton: true } );
			}
			markers.push( marker );
			bounds.push( [ m.lat, m.lng ] );
		} );

		var zoom = cfg.zoom || 15;
		var place = function () {
			if ( bounds.length > 1 ) {
				map.fitBounds( bounds, { padding: [ 50, 50 ], maxZoom: zoom } );
			} else {
				map.setView( bounds[ 0 ], zoom );
			}
		};
		place();

		if ( cfg.popupOpen === 'first' && markers[ 0 ] && markers[ 0 ].getPopup() ) {
			// Erst öffnen, wenn die Karte steht – sonst verschiebt autoPan den Ausschnitt.
			setTimeout( function () {
				if ( el._b83Osm && el._b83Osm.map === map ) {
					markers[ 0 ].openPopup();
				}
			}, 250 );
		}

		if ( cfg.scroll === 'never' ) {
			map.dragging.disable();
			map.touchZoom.disable();
			map.doubleClickZoom.disable();
			map.boxZoom.disable();
		} else if ( cfg.scroll === 'click' ) {
			// Mausrad bzw. Wischen erst nach Klick, damit die Seite beim Scrollen nicht hängen bleibt.
			var hint = document.createElement( 'div' );
			hint.className = 'b83-osm__hint';
			hint.textContent = touch ? t( 'hintTouch', 'Zum Bedienen auf die Karte tippen' ) : t( 'hintClick', 'Zum Zoomen in die Karte klicken' );
			el.appendChild( hint );
			var activate = function () {
				map.scrollWheelZoom.enable();
				map.dragging.enable();
				hint.style.opacity = '0';
			};
			map.once( 'click focus', activate );
			map.on( 'popupopen', function () {
				if ( ! touch ) {
					activate();
				}
			} );
		}

		// Größe neu berechnen, falls die Karte in einem Tab, Akkordeon oder Popup steckt.
		var observer = null;
		if ( window.ResizeObserver ) {
			var last = el.clientWidth + 'x' + el.clientHeight;
			observer = new ResizeObserver( function () {
				var now = el.clientWidth + 'x' + el.clientHeight;
				if ( now !== last && el.clientWidth > 0 ) {
					var wasHidden = last.indexOf( '0x' ) === 0;
					last = now;
					map.invalidateSize();
					if ( wasHidden ) {
						place();
					}
				}
			} );
			observer.observe( el );
		}

		el._b83Osm = { map: map, markers: markers, observer: observer };
		el.classList.add( 'is-loaded' );
		el.setAttribute( 'data-ready', '1' );
		bindList( el, map, markers );

		var event;
		try {
			event = new CustomEvent( 'b83-osm:ready', { detail: { el: el, map: map, markers: markers } } );
		} catch ( e ) {
			event = document.createEvent( 'CustomEvent' );
			event.initCustomEvent( 'b83-osm:ready', false, false, { el: el, map: map, markers: markers } );
		}
		document.dispatchEvent( event );
	}

	function bindList( el, map, markers ) {
		var list = document.querySelector( '.b83-osm-list[data-b83-osm-for="' + el.id + '"]' );
		if ( ! list || list._b83Bound ) {
			return;
		}
		list._b83Bound = true;
		list.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.b83-osm-list__item' );
			if ( ! btn || ! el._b83Osm ) {
				return;
			}
			var marker = el._b83Osm.markers[ parseInt( btn.getAttribute( 'data-index' ), 10 ) ];
			if ( ! marker ) {
				return;
			}
			list.querySelectorAll( '.is-active' ).forEach( function ( b ) {
				b.classList.remove( 'is-active' );
			} );
			btn.classList.add( 'is-active' );
			var rect = el.getBoundingClientRect();
			if ( rect.top < 0 || rect.bottom > window.innerHeight ) {
				el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			}
			var target = Math.max( el._b83Osm.map.getZoom(), 15 );
			el._b83Osm.map.flyTo( marker.getLatLng(), target, { duration: 0.6 } );
			el._b83Osm.map.once( 'moveend', function () {
				marker.openPopup();
			} );
		} );
	}

	function load( el ) {
		if ( el._b83Osm || ! window.L ) {
			return;
		}
		build( el, parseConfig( el ) );
	}

	function setupConsent( el ) {
		var box = el.querySelector( '.b83-osm__consent' );
		if ( ! box ) {
			return false;
		}
		if ( rememberedConsent() ) {
			return false;
		}
		if ( box._b83Bound ) {
			return true;
		}
		box._b83Bound = true;
		var btn = box.querySelector( '.b83-osm__consent-btn' );
		var remember = box.querySelector( '.b83-osm__consent-remember input' );
		L.DomEvent.disableClickPropagation( box );
		btn.addEventListener( 'click', function () {
			if ( remember && remember.checked ) {
				storeConsent();
				// Alle wartenden Karten auf der Seite laden.
				document.querySelectorAll( '[data-b83-osm]' ).forEach( function ( other ) {
					if ( other !== el ) {
						load( other );
					}
				} );
			}
			load( el );
		} );
		return true;
	}

	var lazy = null;
	function init( root ) {
		if ( ! window.L ) {
			return;
		}
		root = root || document;
		var els = root.querySelectorAll ? root.querySelectorAll( '[data-b83-osm]' ) : [];
		if ( root.matches && root.matches( '[data-b83-osm]' ) ) {
			els = [ root ];
		}
		Array.prototype.forEach.call( els, function ( el ) {
			if ( el._b83Osm || el.getAttribute( 'data-ready' ) ) {
				return;
			}
			if ( setupConsent( el ) ) {
				return;
			}
			// Kacheln erst laden, wenn die Karte in die Nähe des sichtbaren Bereichs kommt.
			if ( 'IntersectionObserver' in window ) {
				if ( ! lazy ) {
					lazy = new IntersectionObserver( function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								lazy.unobserve( entry.target );
								load( entry.target );
							}
						} );
					}, { rootMargin: '300px 0px' } );
				}
				lazy.observe( el );
			} else {
				load( el );
			}
		} );
	}

	function render( el, cfg ) {
		if ( ! window.L || ! el ) {
			return;
		}
		el.setAttribute( 'data-b83-osm', JSON.stringify( cfg ) );
		build( el, cfg );
	}

	window.b83Osm = { init: init, load: load, render: render };

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}

	// Elementor: Karten in nachgeladenen Widgets (Editor-Vorschau, Popups) aufbauen.
	// Elementor löst seine Ereignisse über jQuery aus.
	var elementorHooked = false;
	function hookElementor() {
		if ( elementorHooked || ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		elementorHooked = true;
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/global', function ( $scope ) {
			init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
		} );
	}
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
		window.jQuery( document ).on( 'elementor/popup/show', function () {
			init();
		} );
	}
	hookElementor();
} )();
