/**
 * Join the Cause — Admin JavaScript
 *
 * Handles:
 * - Settings: colour-mode toggle, WP colour picker, email method toggle
 * - Settings: live preview panel (CSS-vars only, no server round-trip)
 * - Settings: Short.io "Test connection" (AJAX)
 * - Settings: copy-debug-info button
 * - Petition edit: form field builder (add/remove/drag/keyboard reorder,
 *   select options, JSON serialisation into the hidden input)
 * - Petition edit: QR print popup
 * - Newsletter: send confirmation with recipient count
 *
 * All user-facing strings come from jtcAdmin.i18n.
 */

/* global jQuery, jtcAdmin, wp */

jQuery( function ( $ ) {
	'use strict';

	var i18n = ( typeof jtcAdmin !== 'undefined' && jtcAdmin.i18n ) ? jtcAdmin.i18n : {};

	function t( key, fallback ) {
		return ( typeof i18n[ key ] === 'string' && i18n[ key ] ) ? i18n[ key ] : ( fallback || '' );
	}

	function speak( message ) {
		if ( message && window.wp && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'assertive' );
		}
	}

	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var context = this;
			var args    = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( context, args );
			}, wait || 80 );
		};
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	function escAttr( str ) {
		return escHtml( str ).replace( /`/g, '&#096;' );
	}

	// ── Colour mode toggle (Settings > Appearance) ────────────────────────

	function updateColorMode() {
		var mode = $( 'input[name="jtc_color_mode"]:checked' ).val();
		$( '.jtc-show-when-preset' ).toggle( mode === 'preset' );
		$( '.jtc-show-when-custom' ).toggle( mode === 'custom' );
	}

	$( document ).on( 'change', 'input[name="jtc_color_mode"]', updateColorMode );
	updateColorMode(); // initialise on page load

	// ── WP Colour Picker ─────────────────────────────────────────────────

	if ( $.fn.wpColorPicker ) {
		$( '.jtc-color-picker' ).wpColorPicker( {
			change: function () { updatePreview(); },
			clear: function () { updatePreview(); },
		} );
	}

	// ── Email method toggle (Settings > Email) ────────────────────────────

	function updateEmailMethod() {
		var method = $( 'input[name="jtc_email_method"]:checked' ).val();
		var provider = $( '#jtc_api_provider' ).val();

		$( '.jtc-smtp-row' ).toggle( method === 'smtp' );
		$( '.jtc-api-row'  ).toggle( method === 'api' );
		$( '.jtc-mailgun-api-row' ).toggle( method === 'api' && provider === 'mailgun' );
	}

	$( document ).on( 'change', '.jtc-email-method-radio', updateEmailMethod );
	$( document ).on( 'change', '#jtc_api_provider', updateEmailMethod );
	updateEmailMethod();

	// ── Live preview (Settings > Appearance) ──────────────────────────────
	// Pure client-side: the sample element gets its --jtc-* variables set
	// inline from the current form values. No server round-trip.

	var styleMaps = ( typeof jtcAdmin !== 'undefined' && jtcAdmin.styleMaps ) ? jtcAdmin.styleMaps : {};
	var presets   = ( typeof jtcAdmin !== 'undefined' && jtcAdmin.presets ) ? jtcAdmin.presets : {};

	var COLOR_VAR_MAP = {
		primary:       '--jtc-primary',
		primary_dark:  '--jtc-primary-dark',
		primary_light: '--jtc-primary-light',
		hero_from:     '--jtc-hero-from',
		hero_to:       '--jtc-hero-to',
		page_bg:       '--jtc-page-bg',
		surface:       '--jtc-surface',
		surface_alt:   '--jtc-surface-alt',
		text:          '--jtc-text',
		text_strong:   '--jtc-text-strong',
		text_muted:    '--jtc-text-muted',
		border:        '--jtc-border',
		input_bg:      '--jtc-input-bg',
		button_text:   '--jtc-button-text',
	};

	var PREVIEW_VARS = Object.keys( COLOR_VAR_MAP ).map( function ( k ) { return COLOR_VAR_MAP[ k ]; } ).concat( [
		'--jtc-primary-rgb',
		'--jtc-radius', '--jtc-radius-lg',
		'--jtc-shadow-sm', '--jtc-shadow-md', '--jtc-shadow-lg',
		'--jtc-button-bg', '--jtc-button-fg', '--jtc-button-border',
		'--jtc-button-hover-bg', '--jtc-button-hover-fg',
		'--jtc-font-base', '--jtc-font-scale',
		'--jtc-hero-text',
	] );

	function hexToRgbString( hex ) {
		hex = String( hex || '' ).replace( '#', '' );
		if ( 3 === hex.length ) {
			hex = hex.split( '' ).map( function ( c ) { return c + c; } ).join( '' );
		}
		if ( 6 !== hex.length ) return '';
		var r = parseInt( hex.slice( 0, 2 ), 16 );
		var g = parseInt( hex.slice( 2, 4 ), 16 );
		var b = parseInt( hex.slice( 4, 6 ), 16 );
		return r + ', ' + g + ', ' + b;
	}

	function luminance( hex ) {
		hex = String( hex || '' ).replace( '#', '' );
		if ( 3 === hex.length ) {
			hex = hex.split( '' ).map( function ( c ) { return c + c; } ).join( '' );
		}
		if ( 6 !== hex.length ) return 0.5;
		var channels = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( hex.slice( i, i + 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
	}

	function deriveNeutrals( surface ) {
		if ( luminance( surface ) > 0.4 ) {
			return { text: '#1c261b', text_strong: '#111a10', text_muted: '#5d6f58', input_bg: '#ffffff' };
		}
		return { text: '#edf5e9', text_strong: '#ffffff', text_muted: '#b7c8ae', input_bg: '#111a10' };
	}

	function contrastText( background ) {
		return luminance( background ) > 0.35 ? '#111a10' : '#ffffff';
	}

	function updatePreview() {
		var $sample = $( '#jtc-preview-sample' );
		if ( ! $sample.length ) return;

		var el = $sample[0];
		PREVIEW_VARS.forEach( function ( v ) { el.style.removeProperty( v ); } );

		var mode = $( 'input[name="jtc_color_mode"]:checked' ).val() || 'preset';
		var vars = {};

		if ( 'preset' === mode ) {
			var preset = presets[ $( 'input[name="jtc_preset_theme"]:checked' ).val() ];
			if ( preset ) {
				Object.keys( COLOR_VAR_MAP ).forEach( function ( key ) {
					if ( preset[ key ] ) {
						vars[ COLOR_VAR_MAP[ key ] ] = preset[ key ];
					}
				} );
				if ( preset.primary ) {
					vars['--jtc-primary-rgb'] = hexToRgbString( preset.primary );
				}
			}
		} else if ( 'custom' === mode ) {
			var surface  = $( '#jtc_custom_surface' ).val() || '#ffffff';
			var primary  = $( '#jtc_custom_primary' ).val() || '#2d6a2d';
			var neutrals = deriveNeutrals( surface );

			vars['--jtc-primary']       = primary;
			vars['--jtc-primary-dark']  = $( '#jtc_custom_secondary' ).val() || '#1a3d1a';
			vars['--jtc-primary-light'] = $( '#jtc_custom_accent' ).val() || '#f0faf0';
			vars['--jtc-hero-from']     = $( '#jtc_custom_hero_from' ).val() || '#245e2b';
			vars['--jtc-hero-to']       = $( '#jtc_custom_hero_to' ).val() || '#4f8d33';
			vars['--jtc-page-bg']       = $( '#jtc_custom_page_bg' ).val() || '#f6f8f4';
			vars['--jtc-surface']       = surface;
			vars['--jtc-surface-alt']   = $( '#jtc_custom_surface_alt' ).val() || '#f3f7f0';
			vars['--jtc-border']        = $( '#jtc_custom_border' ).val() || '#d8e2d2';
			vars['--jtc-text']          = neutrals.text;
			vars['--jtc-text-strong']   = neutrals.text_strong;
			vars['--jtc-text-muted']    = neutrals.text_muted;
			vars['--jtc-input-bg']      = neutrals.input_bg;
			vars['--jtc-button-text']   = contrastText( primary );
			vars['--jtc-primary-rgb']   = hexToRgbString( primary );
		}

		// Layout & Style vars apply in every colour mode.
		var radiusMap = ( styleMaps.radius || {} )[ $( '#jtc_style_radius' ).val() ];
		if ( radiusMap ) {
			vars['--jtc-radius']    = radiusMap[0];
			vars['--jtc-radius-lg'] = radiusMap[1];
		}

		var shadowMap = ( styleMaps.shadow || {} )[ $( '#jtc_style_shadow' ).val() ];
		if ( shadowMap ) {
			vars['--jtc-shadow-sm'] = shadowMap[0];
			vars['--jtc-shadow-md'] = shadowMap[1];
			vars['--jtc-shadow-lg'] = shadowMap[2];
		}

		if ( 'outline' === ( $( '#jtc_style_button' ).val() || 'solid' ) ) {
			vars['--jtc-button-bg']       = 'transparent';
			vars['--jtc-button-fg']       = 'var(--jtc-primary)';
			vars['--jtc-button-border']   = 'var(--jtc-primary)';
			vars['--jtc-button-hover-bg'] = 'var(--jtc-primary)';
			vars['--jtc-button-hover-fg'] = 'var(--jtc-button-text, #ffffff)';
		} else {
			vars['--jtc-button-bg']       = 'var(--jtc-primary)';
			vars['--jtc-button-fg']       = 'var(--jtc-button-text, #ffffff)';
			vars['--jtc-button-border']   = 'transparent';
			vars['--jtc-button-hover-bg'] = 'var(--jtc-primary-dark)';
			vars['--jtc-button-hover-fg'] = 'var(--jtc-button-text, #ffffff)';
		}

		var fontSource = $( '#jtc_style_font_source' ).val() || 'inherit';
		if ( 'custom' === fontSource ) {
			vars['--jtc-font-base'] = $( '#jtc_style_font_custom' ).val() || 'inherit';
		} else if ( styleMaps.font_source && styleMaps.font_source[ fontSource ] ) {
			vars['--jtc-font-base'] = styleMaps.font_source[ fontSource ];
		} else {
			vars['--jtc-font-base'] = 'inherit';
		}
		$( '.jtc-font-custom-row' ).toggle( 'custom' === fontSource );

		var scale = parseInt( $( '#jtc_style_font_scale' ).val(), 10 );
		if ( ! isNaN( scale ) ) {
			vars['--jtc-font-scale'] = String( scale / 100 );
		}

		var heroText = $( '#jtc_style_hero_text' ).val();
		if ( heroText ) {
			vars['--jtc-hero-text'] = heroText;
		}

		$sample.attr( 'data-jtc-hero', $( '#jtc_style_hero_style' ).val() || 'gradient' );

		Object.keys( vars ).forEach( function ( key ) {
			el.style.setProperty( key, vars[ key ] );
		} );
	}

	var updatePreviewDebounced = debounce( updatePreview, 60 );

	// Any change inside the settings form re-renders the preview (cheap no-op
	// on tabs without a preview element).
	$( document ).on( 'change input', '.jtc-settings-form select, .jtc-settings-form input[type="text"], .jtc-settings-form input[type="password"], .jtc-settings-form input[type="number"], .jtc-settings-form input[type="radio"], .jtc-settings-form input[type="checkbox"]', function () {
		updateColorMode();
		updatePreviewDebounced();
	} );

	if ( $( '#jtc-preview-sample' ).length ) {
		updatePreview();
	}

	// ── Short.io: test connection ─────────────────────────────────────────

	$( document ).on( 'click', '.jtc-shortio-test', function ( e ) {
		e.preventDefault();

		var $btn = $( this );
		var $out = $( '#jtc-shortio-test-result' );

		$btn.prop( 'disabled', true );
		$out.removeClass( 'is-error is-success' ).text( t( 'testingConnection', 'Testing connection…' ) );

		$.post(
			jtcAdmin.ajaxUrl,
			{
				action: 'jtc_shortio_test',
				nonce: jtcAdmin.nonce,
			}
		)
			.done( function ( response ) {
				var message = ( response && response.data && response.data.message ) || '';
				if ( response && response.success ) {
					$out.addClass( 'is-success' ).text( message );
				} else {
					$out.addClass( 'is-error' ).text( message || t( 'errorGeneric' ) );
				}
				speak( message );
			} )
			.fail( function () {
				$out.addClass( 'is-error' ).text( t( 'errorGeneric' ) );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	// ── Help: copy debug info ─────────────────────────────────────────────

	$( document ).on( 'click', '.jtc-copy-debug', function () {
		var $btn = $( this );
		var text = $( '#' + $btn.attr( 'data-target' ) ).text();

		if ( ! text || ! navigator.clipboard || ! navigator.clipboard.writeText ) return;

		navigator.clipboard.writeText( text ).then( function () {
			var message = t( 'debugCopied', 'Copied!' );
			var original = $btn.text();
			$btn.text( message );
			speak( message );
			setTimeout( function () { $btn.text( original ); }, 2000 );
		} );
	} );

	// ── Newsletter: confirm with recipient count ──────────────────────────

	$( document ).on( 'click', '.jtc-send-btn', function ( e ) {
		var count    = parseInt( $( '#jtc-nl-recipients' ).attr( 'data-count' ) || '0', 10 );
		var template = count > 0 ? t( 'confirmSendCount' ) : t( 'confirmSend' );
		var message  = template ? template.replace( '%d', String( count ) ) : '';

		if ( message && ! window.confirm( message ) ) {
			e.preventDefault();
		}
	} );

	$( document ).on( 'change', '#jtc-nl-petition', function () {
		var $option = $( this ).find( 'option:selected' );
		var count   = parseInt( $option.attr( 'data-recipients' ) || '0', 10 );
		var label;

		if ( 1 === count ) {
			label = t( 'recipientCountOne', '1 recipient' );
		} else {
			label = t( 'recipientCount', '%d recipients' ).replace( '%d', String( count ) );
		}

		$( '#jtc-nl-recipients' ).attr( 'data-count', count ).text( label );
	} );

	// ── QR print popup ────────────────────────────────────────────────────

	$( document ).on( 'click', '.jtc-print-qr', function () {
		var qrUrl = $( this ).attr( 'data-qr-url' );
		if ( ! qrUrl ) return;

		var win = window.open( '', 'jtcQrPrint', 'width=640,height=720' );
		if ( ! win ) return;

		var docLang = escAttr( document.documentElement.lang || 'en' );

		win.document.write(
			'<!doctype html><html lang="' + docLang + '"><head><meta charset="utf-8"><title>' + escHtml( t( 'printQrTitle', 'Print QR' ) ) + '</title>' +
			'<style>body{font-family:sans-serif;text-align:center;padding:40px;}img{width:360px;max-width:90vw;height:auto;}</style>' +
			'</head><body><img src="' + escAttr( qrUrl ) + '" alt="' + escAttr( t( 'qrAlt', 'QR code' ) ) + '">' +
			'<scr' + 'ipt>window.onload=function(){window.print();};</scr' + 'ipt></body></html>'
		);
		win.document.close();
	} );

	// ── Form Field Builder (Petition edit screen) ─────────────────────────

	var $body   = $( '#jtc-fields-body' );
	var $hidden = $( '#jtc_form_fields_data' );

	if ( ! $body.length ) return; // not on petition edit screen

	// Generate a simple UUID-ish ID for new fields.
	function uid() {
		return 'f' + Math.random().toString( 36 ).slice( 2, 11 );
	}

	// Serialise the table rows back into JSON and write to the hidden input.
	function syncFieldData() {
		var fields = [];

		$body.find( 'tr.jtc-field-row' ).each( function () {
			var $row       = $( this );
			var type       = $row.find( '.jtc-field-type' ).val();
			var optionsRaw = $row.find( '.jtc-field-options' ).val() || '';
			var options    = [];

			if ( 'select' === type ) {
				options = optionsRaw.split( /[\n,]+/ ).map( function ( s ) {
					return s.trim();
				} ).filter( function ( s ) {
					return s.length > 0;
				} );
			}

			fields.push( {
				id          : $row.attr( 'data-id' ) || uid(),
				label       : $row.find( '.jtc-field-label' ).val().trim(),
				type        : type,
				placeholder : $row.find( '.jtc-field-placeholder' ).val().trim(),
				required    : $row.find( '.jtc-field-required' ).is( ':checked' ),
				options     : options,
			} );
		} );

		$hidden.val( JSON.stringify( fields ) );
	}

	// Sync on any input change inside the table.
	$body.on( 'change input', 'input, select, textarea', syncFieldData );

	// Show/hide the options cell for select fields.
	$body.on( 'change', '.jtc-field-type', function () {
		$( this ).closest( 'tr.jtc-field-row' )
			.find( '.jtc-field-options-cell' )
			.toggle( 'select' === $( this ).val() );
		syncFieldData();
	} );

	// Add new field row.
	$( '#jtc-add-field' ).on( 'click', function () {
		var newId = uid();
		var $row  = $( '<tr class="jtc-field-row"></tr>' ).attr( 'data-id', newId );

		$row.html(
			'<td class="jtc-drag-handle" aria-hidden="true" title="' + escAttr( t( 'dragToReorder', 'Drag to reorder' ) ) + '">⠿</td>' +
			'<td><input type="text" class="jtc-field-label widefat" value="' + escAttr( t( 'newField', 'New Field' ) ) + '" aria-label="' + escAttr( t( 'fieldLabel', 'Field label' ) ) + '"></td>' +
			'<td><select class="jtc-field-type" aria-label="' + escAttr( t( 'fieldType', 'Field type' ) ) + '">' +
				'<option value="text">text</option>' +
				'<option value="email">email</option>' +
				'<option value="textarea">textarea</option>' +
				'<option value="checkbox">checkbox</option>' +
				'<option value="select">select</option>' +
			'</select></td>' +
			'<td><input type="text" class="jtc-field-placeholder widefat" value="" aria-label="' + escAttr( t( 'placeholderText', 'Placeholder text' ) ) + '"></td>' +
			'<td class="jtc-field-options-cell" style="display:none;"><textarea class="jtc-field-options" rows="2" aria-label="' + escAttr( t( 'optionsLabel', 'Select field options' ) ) + '" placeholder="' + escAttr( t( 'optionsPlaceholder', 'One option per line' ) ) + '"></textarea></td>' +
			'<td style="text-align:center;"><input type="checkbox" class="jtc-field-required" aria-label="' + escAttr( t( 'requiredField', 'Required field' ) ) + '"></td>' +
			'<td class="jtc-field-actions">' +
				'<button type="button" class="button-link jtc-move-field jtc-move-field--up" aria-label="' + escAttr( t( 'moveFieldUp', 'Move field up' ) ) + '">↑</button> ' +
				'<button type="button" class="button-link jtc-move-field jtc-move-field--down" aria-label="' + escAttr( t( 'moveFieldDown', 'Move field down' ) ) + '">↓</button> ' +
				'<button type="button" class="button-link jtc-remove-field" aria-label="' + escAttr( t( 'removeField', 'Remove this field' ) ) + '">✕</button>' +
			'</td>'
		);

		$body.append( $row );
		$row.find( '.jtc-field-label' ).trigger( 'focus' );
		syncFieldData();
	} );

	// Remove a field row.
	$body.on( 'click', '.jtc-remove-field', function () {
		$( this ).closest( 'tr' ).remove();
		syncFieldData();
	} );

	// Keyboard-accessible reorder (up/down) in addition to drag.
	$body.on( 'click', '.jtc-move-field', function () {
		var $btn = $( this );
		var $row = $btn.closest( 'tr.jtc-field-row' );
		var up   = $btn.hasClass( 'jtc-move-field--up' );
		var $sib = up
			? $row.prevAll( 'tr.jtc-field-row' ).first()
			: $row.nextAll( 'tr.jtc-field-row' ).first();

		if ( ! $sib.length ) return; // Already at the edge — nothing moved.

		if ( up ) {
			$sib.before( $row );
		} else {
			$sib.after( $row );
		}

		syncFieldData();

		// Keep focus on the mover button (it travels with the row) and
		// announce the move, mirroring the Meet With Me reorder pattern.
		$btn.trigger( 'focus' );
		speak( t( up ? 'moveFieldUp' : 'moveFieldDown', up ? 'Move field up' : 'Move field down' ) );
	} );

	// jQuery UI Sortable drag-to-reorder.
	if ( $.fn.sortable ) {
		$body.sortable( {
			handle   : '.jtc-drag-handle',
			items    : 'tr.jtc-field-row',
			axis     : 'y',
			update   : syncFieldData,
			cursor   : 'grabbing',
			helper   : function ( e, $row ) {
				// Keep column widths during drag.
				$row.children().each( function () {
					$( this ).width( $( this ).width() );
				} );
				return $row;
			},
		} );
	}

	// Initial: toggle options cells + populate the hidden field.
	$body.find( 'tr.jtc-field-row' ).each( function () {
		var $row = $( this );
		$row.find( '.jtc-field-options-cell' ).toggle( 'select' === $row.find( '.jtc-field-type' ).val() );
	} );

	syncFieldData();
} );
