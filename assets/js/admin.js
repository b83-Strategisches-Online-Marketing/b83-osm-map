/**
 * b83 OpenStreetMap – Backend
 * Karten-Editor: Adressen, Icon-Auswahl, Farben, Bilder, Adresssuche, Live-Vorschau.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.b83OsmAdmin || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( 'b83-osm-form' );

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function fireChange( el ) {
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/* ------------------------------------------------------------------
	 * Shortcode kopieren, Löschen bestätigen
	 * ---------------------------------------------------------------- */

	$( document ).on( 'click', '.b83-osm-copy', function () {
		var btn = this;
		var input = btn.parentNode.querySelector( 'input' );
		var done = function () {
			var label = btn.textContent;
			btn.textContent = i18n.copied || 'Kopiert!';
			setTimeout( function () {
				btn.textContent = label;
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done );
		} else {
			input.select();
			document.execCommand( 'copy' );
			done();
		}
	} );
	$( document ).on( 'focus', '.b83-osm-code input', function () {
		this.select();
	} );
	$( document ).on( 'click', '.b83-osm-delete', function ( e ) {
		if ( ! window.confirm( i18n.confirmDelete ) ) {
			e.preventDefault();
		}
	} );

	if ( ! form ) {
		return;
	}

	/* ------------------------------------------------------------------
	 * Farben
	 * ---------------------------------------------------------------- */

	function initColors( root ) {
		$( root ).find( 'input.b83-osm-color' ).each( function () {
			if ( $( this ).closest( 'template' ).length || $( this ).data( 'b83-init' ) ) {
				return;
			}
			$( this ).data( 'b83-init', true );
			var input = this;
			$( input ).wpColorPicker( {
				change: function () {
					setTimeout( function () {
						fireChange( input );
					}, 0 );
				},
				clear: function () {
					setTimeout( function () {
						fireChange( input );
					}, 0 );
				}
			} );
			if ( input.getAttribute( 'data-placeholder' ) ) {
				input.setAttribute( 'placeholder', input.getAttribute( 'data-placeholder' ) );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Icon-Auswahl
	 * ---------------------------------------------------------------- */

	function iconPreview( value, picker ) {
		var icons = cfg.icons || {};
		if ( ! value ) {
			return { html: '<span class="b83-osm-ip-inherit">–</span>', label: picker.getAttribute( 'data-inherit-label' ) || i18n.iconInherit };
		}
		if ( value === 'dot' ) {
			return { html: '<span class="b83-osm-ip-dot"></span>', label: i18n.iconDot || 'Punkt' };
		}
		if ( value === 'none' ) {
			return { html: '<span class="b83-osm-ip-none"></span>', label: i18n.iconNone || 'Kein Icon' };
		}
		if ( icons[ value ] ) {
			return { html: icons[ value ].svg, label: icons[ value ].label };
		}
		return { html: '<span class="b83-osm-ip-fa">fa</span>', label: value };
	}

	function updatePicker( picker ) {
		var value = picker.querySelector( 'input[type=hidden]' ).value;
		var p = iconPreview( value, picker );
		picker.querySelector( '.b83-osm-icon-picker__current' ).innerHTML = p.html;
		picker.querySelector( '.b83-osm-icon-picker__label' ).textContent = p.label;
	}

	function buildPopover( picker ) {
		var icons = cfg.icons || {};
		var pop = document.createElement( 'div' );
		pop.className = 'b83-osm-icon-picker__pop';
		var html = '<input type="search" class="b83-osm-ip-search" placeholder="' + escapeHtml( i18n.iconSearch || '' ) + '">';
		html += '<div class="b83-osm-ip-grid" role="listbox">';
		if ( picker.getAttribute( 'data-inherit' ) === '1' ) {
			html += '<button type="button" data-value="" data-search="standard wie karte" title="' + escapeHtml( picker.getAttribute( 'data-inherit-label' ) ) + '"><span class="b83-osm-ip-inherit">–</span></button>';
		}
		html += '<button type="button" data-value="dot" data-search="punkt dot" title="' + escapeHtml( i18n.iconDot ) + '"><span class="b83-osm-ip-dot"></span></button>';
		html += '<button type="button" data-value="none" data-search="kein leer none" title="' + escapeHtml( i18n.iconNone ) + '"><span class="b83-osm-ip-none"></span></button>';
		Object.keys( icons ).forEach( function ( key ) {
			html += '<button type="button" data-value="' + escapeHtml( key ) + '" data-search="' + escapeHtml( ( key + ' ' + icons[ key ].label ).toLowerCase() ) + '" title="' + escapeHtml( icons[ key ].label ) + '">' + icons[ key ].svg + '</button>';
		} );
		html += '</div>';
		html += '<label class="b83-osm-ip-fa-field">Font Awesome <input type="text" placeholder="fa-solid fa-hotel"></label>';
		pop.innerHTML = html;
		picker.appendChild( pop );

		var hidden = picker.querySelector( 'input[type=hidden]' );
		var faInput = pop.querySelector( '.b83-osm-ip-fa-field input' );
		if ( hidden.value.indexOf( 'fa' ) === 0 && ! icons[ hidden.value ] ) {
			faInput.value = hidden.value;
		}

		pop.querySelector( '.b83-osm-ip-search' ).addEventListener( 'input', function () {
			var q = this.value.toLowerCase().trim();
			pop.querySelectorAll( '.b83-osm-ip-grid button' ).forEach( function ( b ) {
				b.hidden = q !== '' && b.getAttribute( 'data-search' ).indexOf( q ) === -1;
			} );
		} );
		pop.querySelector( '.b83-osm-ip-grid' ).addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( ! b ) {
				return;
			}
			setValue( picker, b.getAttribute( 'data-value' ) );
			close( picker );
		} );
		faInput.addEventListener( 'change', function () {
			var v = this.value.trim().replace( /\s+/g, ' ' );
			if ( /^(fa|fas|far|fab|fa-solid|fa-regular|fa-brands|fa-light|fa-thin|fa-duotone|fa-sharp)( fa-[a-z0-9-]+){1,3}$/.test( v ) ) {
				setValue( picker, v );
			}
		} );
		return pop;
	}

	function setValue( picker, value ) {
		var hidden = picker.querySelector( 'input[type=hidden]' );
		hidden.value = value;
		updatePicker( picker );
		picker.querySelectorAll( '.b83-osm-ip-grid button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-selected', b.getAttribute( 'data-value' ) === value );
		} );
		fireChange( hidden );
	}

	function close( picker ) {
		var pop = picker.querySelector( '.b83-osm-icon-picker__pop' );
		if ( pop ) {
			pop.hidden = true;
		}
		picker.querySelector( '.b83-osm-icon-picker__toggle' ).setAttribute( 'aria-expanded', 'false' );
	}

	function initPickers( root ) {
		$( root ).find( '.b83-osm-icon-picker' ).each( function () {
			if ( $( this ).closest( 'template' ).length || this._b83 ) {
				return;
			}
			this._b83 = true;
			updatePicker( this );
		} );
	}

	$( document ).on( 'click', '.b83-osm-icon-picker__toggle', function () {
		var picker = this.closest( '.b83-osm-icon-picker' );
		var pop = picker.querySelector( '.b83-osm-icon-picker__pop' ) || buildPopover( picker );
		var open = pop.hidden !== false || ! this.getAttribute( 'aria-expanded' ) || this.getAttribute( 'aria-expanded' ) === 'false';
		document.querySelectorAll( '.b83-osm-icon-picker' ).forEach( close );
		if ( open ) {
			pop.hidden = false;
			this.setAttribute( 'aria-expanded', 'true' );
			var value = picker.querySelector( 'input[type=hidden]' ).value;
			pop.querySelectorAll( '.b83-osm-ip-grid button' ).forEach( function ( b ) {
				b.classList.toggle( 'is-selected', b.getAttribute( 'data-value' ) === value );
			} );
			pop.querySelector( '.b83-osm-ip-search' ).focus();
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.b83-osm-icon-picker' ) ) {
			document.querySelectorAll( '.b83-osm-icon-picker' ).forEach( close );
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			document.querySelectorAll( '.b83-osm-icon-picker' ).forEach( close );
		}
	} );

	/* ------------------------------------------------------------------
	 * Bilder aus der Mediathek
	 * ---------------------------------------------------------------- */

	$( document ).on( 'click', '.b83-osm-media__choose', function () {
		var box = this.closest( '.b83-osm-media' );
		var frame = wp.media( {
			title: i18n.chooseImage,
			button: { text: i18n.useImage },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			var hidden = box.querySelector( 'input[type=hidden]' );
			hidden.value = att.id;
			box.querySelector( '.b83-osm-media__preview' ).innerHTML = '<img src="' + escapeHtml( url ) + '" alt="">';
			box.querySelector( '.b83-osm-media__remove' ).hidden = false;
			fireChange( hidden );
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.b83-osm-media__remove', function () {
		var box = this.closest( '.b83-osm-media' );
		var hidden = box.querySelector( 'input[type=hidden]' );
		hidden.value = '';
		box.querySelector( '.b83-osm-media__preview' ).innerHTML = '';
		this.hidden = true;
		fireChange( hidden );
	} );

	/* ------------------------------------------------------------------
	 * Adressen
	 * ---------------------------------------------------------------- */

	var list = document.getElementById( 'b83-osm-markers' );
	var counter = list ? list.children.length + 1000 : 0;

	function updateMarkerHead( marker ) {
		var title = marker.querySelector( '.b83-osm-marker__title-input' ).value.trim();
		var address = marker.querySelector( '.b83-osm-marker__address' ).value.trim().split( '\n' )[ 0 ];
		var lat = marker.querySelector( '.b83-osm-marker__lat' ).value.trim();
		var lng = marker.querySelector( '.b83-osm-marker__lng' ).value.trim();
		marker.querySelector( '.b83-osm-marker__title' ).textContent = title || address || i18n.newAddress;
		marker.querySelector( '.b83-osm-marker__coords' ).classList.toggle( 'has-coords', lat !== '' && lng !== '' );
	}

	if ( list ) {
		document.getElementById( 'b83-osm-add-marker' ).addEventListener( 'click', function () {
			var tpl = document.getElementById( 'b83-osm-marker-template' );
			var html = tpl.innerHTML.replace( /__i__/g, String( counter++ ) );
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = html.trim();
			var marker = wrap.firstChild;
			list.querySelectorAll( '.b83-osm-marker[open]' ).forEach( function ( m ) {
				m.open = false;
			} );
			list.appendChild( marker );
			initColors( marker );
			initPickers( marker );
			marker.querySelector( '.b83-osm-marker__title-input' ).focus();
		} );

		$( list ).on( 'click', '.b83-osm-marker__remove', function ( e ) {
			e.preventDefault();
			if ( window.confirm( i18n.confirmRemove ) ) {
				this.closest( '.b83-osm-marker' ).remove();
				schedulePreview();
			}
		} );

		$( list ).on( 'input', '.b83-osm-marker__title-input, .b83-osm-marker__address, .b83-osm-marker__lat, .b83-osm-marker__lng', function () {
			updateMarkerHead( this.closest( '.b83-osm-marker' ) );
		} );

		// Koordinaten einfügen wie „47.56395, 13.34424“ → auf beide Felder verteilen.
		$( list ).on( 'paste', '.b83-osm-marker__lat', function ( e ) {
			var text = ( e.originalEvent.clipboardData || window.clipboardData ).getData( 'text' );
			var m = text.match( /^\s*(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)\s*$/ );
			if ( m ) {
				e.preventDefault();
				var marker = this.closest( '.b83-osm-marker' );
				this.value = m[ 1 ];
				marker.querySelector( '.b83-osm-marker__lng' ).value = m[ 2 ];
				updateMarkerHead( marker );
				fireChange( this );
			}
		} );

		if ( $.fn.sortable ) {
			$( list ).sortable( {
				handle: '.b83-osm-marker__handle',
				items: '> .b83-osm-marker',
				axis: 'y',
				tolerance: 'pointer',
				update: function () {
					schedulePreview();
				}
			} );
		}

		// Adresssuche.
		$( list ).on( 'click', '.b83-osm-geocode__btn', function () {
			var btn = this;
			var marker = btn.closest( '.b83-osm-marker' );
			var msg = marker.querySelector( '.b83-osm-geocode__msg' );
			var address = marker.querySelector( '.b83-osm-marker__address' ).value.trim();
			msg.className = 'b83-osm-geocode__msg';
			if ( ! address ) {
				msg.textContent = i18n.addressMissing;
				msg.classList.add( 'is-error' );
				return;
			}
			btn.disabled = true;
			msg.textContent = i18n.searching;
			$.post( cfg.ajaxUrl, { action: 'b83_osm_geocode', _ajax_nonce: cfg.nonce, address: address } )
				.done( function ( res ) {
					if ( res && res.success ) {
						marker.querySelector( '.b83-osm-marker__lat' ).value = res.data.lat;
						marker.querySelector( '.b83-osm-marker__lng' ).value = res.data.lng;
						msg.textContent = i18n.found;
						msg.classList.add( 'is-ok' );
						updateMarkerHead( marker );
						schedulePreview();
					} else {
						msg.textContent = i18n.notFound;
						msg.classList.add( 'is-error' );
					}
				} )
				.fail( function () {
					msg.textContent = i18n.notFound;
					msg.classList.add( 'is-error' );
				} )
				.always( function () {
					btn.disabled = false;
				} );
		} );

		// Formatierungs-Leiste.
		$( list ).on( 'click', '.b83-osm-toolbar button', function () {
			var area = this.closest( 'div' ).parentNode.querySelector( '.b83-osm-marker__text' );
			var tag = this.getAttribute( 'data-tag' );
			var start = area.selectionStart;
			var end = area.selectionEnd;
			var sel = area.value.substring( start, end );
			var before = '';
			var after = '';
			if ( tag === 'br' ) {
				before = '<br>\n';
			} else if ( tag === 'a' ) {
				var url = window.prompt( i18n.linkUrl, 'https://' );
				if ( ! url ) {
					return;
				}
				before = '<a href="' + url.replace( /"/g, '' ) + '">';
				after = '</a>';
			} else if ( tag === 'ul' ) {
				var items = ( sel || '' ).split( '\n' ).filter( Boolean );
				sel = '';
				before = '<ul>\n' + ( items.length ? items : [ '' ] ).map( function ( l ) {
					return '<li>' + l + '</li>';
				} ).join( '\n' ) + '\n</ul>';
			} else {
				before = '<' + tag + '>';
				after = '</' + tag + '>';
			}
			area.value = area.value.substring( 0, start ) + before + sel + after + area.value.substring( end );
			area.focus();
			area.selectionStart = start + before.length;
			area.selectionEnd = start + before.length + sel.length;
			fireChange( area );
		} );
	}

	/* ------------------------------------------------------------------
	 * Hinweis zu Nutzungsbedingungen des Kartenstils
	 * ---------------------------------------------------------------- */

	var notes = {
		carto: i18n.noteCarto,
		topo: i18n.noteTopo,
		esri: i18n.noteEsri
	};
	$( form ).find( '.b83-osm-layer-note' ).each( function () {
		var noteEl = this;
		var map = {};
		try {
			map = JSON.parse( noteEl.getAttribute( 'data-notes' ) || '{}' );
		} catch ( e ) {}
		var select = noteEl.parentNode.querySelector( 'select' );
		var update = function () {
			var key = select.value || ( select.options[ 0 ] && select.options[ 0 ].value );
			noteEl.textContent = notes[ map[ key ] ] || '';
		};
		select.addEventListener( 'change', update );
		update();
	} );

	/* ------------------------------------------------------------------
	 * Live-Vorschau
	 * ---------------------------------------------------------------- */

	var previewEl = document.getElementById( 'b83-osm-preview' );
	var previewWrap = document.getElementById( 'b83-osm-preview-wrap' );
	var previewMsg = document.getElementById( 'b83-osm-preview-msg' );
	var timer = null;
	var pending = null;

	function schedulePreview() {
		if ( ! previewEl ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( runPreview, 450 );
	}

	function runPreview() {
		var data = new FormData( form );
		data.set( 'action', 'b83_osm_preview' );
		data.set( '_ajax_nonce', cfg.nonce );
		data.set( 'mode', form.getAttribute( 'data-preview' ) || 'map' );
		data.delete( '_wpnonce' );
		data.delete( '_wp_http_referer' );
		if ( pending ) {
			pending.abort();
		}
		pending = $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			data: data,
			processData: false,
			contentType: false
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			var d = res.data;
			previewWrap.setAttribute( 'style', d.vars );
			previewEl.className = d.classes;
			if ( d.config.markers && d.config.markers.length ) {
				window.b83Osm.render( previewEl, d.config );
				previewMsg.textContent = d.missing > 0 ? ( i18n.missingCoords + ' ' + d.missing ) : '';
			} else {
				window.b83Osm.render( previewEl, d.config );
				previewMsg.textContent = i18n.previewEmpty;
			}
		} );
	}

	form.addEventListener( 'input', function ( e ) {
		if ( e.target.matches( '.b83-osm-ip-search, .b83-osm-ip-fa-field input' ) ) {
			return;
		}
		schedulePreview();
	} );
	form.addEventListener( 'change', schedulePreview );

	initColors( form );
	initPickers( form );
	runPreview();
} )( jQuery );
