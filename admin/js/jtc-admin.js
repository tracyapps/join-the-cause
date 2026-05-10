/**
 * Join the Cause — Admin JavaScript
 *
 * Handles:
 * - Settings tab: colour-mode radio toggle (preset/custom/none)
 * - Settings tab: WP colour picker init
 * - Settings tab: email method toggle (wp_mail/smtp/api rows)
 * - Petition edit: form field builder (add, remove, drag-to-reorder)
 * - Petition edit: JSON serialisation of field table into hidden input
 */

/* global jQuery, jtcAdmin, wp */

jQuery( function ( $ ) {
	'use strict';

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
		$( '.jtc-color-picker' ).wpColorPicker();
	}

	// ── Email method toggle (Settings > Email) ────────────────────────────

	function updateEmailMethod() {
		var method = $( 'input[name="jtc_email_method"]:checked' ).val();
		$( '.jtc-smtp-row' ).toggle( method === 'smtp' );
		$( '.jtc-api-row'  ).toggle( method === 'api' );
	}

	$( document ).on( 'change', '.jtc-email-method-radio', updateEmailMethod );
	updateEmailMethod();

	// ── Form Field Builder (Petition edit screen) ─────────────────────────

	var $body   = $( '#jtc-fields-body' );
	var $hidden = $( '#jtc_form_fields_data' );

	if ( ! $body.length ) return; // not on petition edit screen

	// Generate a simple UUID-ish ID for new fields.
	function uid() {
		return 'f' + Math.random().toString( 36 ).substr( 2, 9 );
	}

	// Serialise the table rows back into JSON and write to the hidden input.
	function syncFieldData() {
		var fields = [];

		$body.find( 'tr.jtc-field-row' ).each( function () {
			var $row = $( this );
			fields.push( {
				id          : $row.data( 'id' ) || uid(),
				label       : $row.find( '.jtc-field-label' ).val().trim(),
				type        : $row.find( '.jtc-field-type' ).val(),
				placeholder : $row.find( '.jtc-field-placeholder' ).val().trim(),
				required    : $row.find( '.jtc-field-required' ).is( ':checked' ),
			} );
		} );

		$hidden.val( JSON.stringify( fields ) );
	}

	// Sync on any input change inside the table.
	$body.on( 'change input', 'input, select', syncFieldData );

	// Add new field row.
	$( '#jtc-add-field' ).on( 'click', function () {
		var newId  = uid();
		var label  = jtcAdmin.i18n.newField;
		var $row   = $( '<tr class="jtc-field-row"></tr>' ).attr( 'data-id', newId );

		$row.html(
			'<td class="jtc-drag-handle" aria-hidden="true" title="Drag to reorder">⠿</td>' +
			'<td><input type="text" class="jtc-field-label widefat" value="' + label + '" aria-label="Field label"></td>' +
			'<td><select class="jtc-field-type" aria-label="Field type">' +
				'<option value="text">text</option>' +
				'<option value="email">email</option>' +
				'<option value="textarea">textarea</option>' +
				'<option value="checkbox">checkbox</option>' +
				'<option value="select">select</option>' +
			'</select></td>' +
			'<td><input type="text" class="jtc-field-placeholder widefat" value="" aria-label="Placeholder text"></td>' +
			'<td style="text-align:center;"><input type="checkbox" class="jtc-field-required" aria-label="Required field"></td>' +
			'<td><button type="button" class="button-link jtc-remove-field" aria-label="Remove this field">✕</button></td>'
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

	// jQuery UI Sortable drag-to-reorder.
	if ( $.fn.sortable ) {
		$body.sortable( {
			handle   : '.jtc-drag-handle',
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

	// Initial sync (populate the hidden field from existing rendered rows).
	syncFieldData();
} );
