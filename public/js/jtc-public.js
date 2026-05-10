/**
 * Join the Cause — Public JavaScript
 *
 * What this file does:
 *   1. Mobile sticky CTA — shows a fixed bottom bar when the sign panel is
 *      scrolled out of view; tapping it scrolls to / reveals the panel.
 *   2. AJAX form submission — client-side validation, then POST, then
 *      success/error handling (count update, recent-signers update,
 *      success message or redirect).
 *   3. Share button actions — copy-link and embed shortcode.
 *
 * What this file intentionally does NOT do:
 *   Sticky panel positioning. The .jtc-sign-panel-col wrapper stretches to
 *   the full grid-row height (same as the content column) and
 *   `position: sticky` in CSS handles it cleanly, without the jump artefact
 *   that manual absolute-position calculations produce.
 */

/* global jQuery, jtcData */

jQuery( function ( $ ) {
	'use strict';

	if ( typeof jtcData === 'undefined' ) return;

	var PID          = jtcData.petitionId;
	var $petition    = $( '#jtc-petition-'     + PID );
	var $panel       = $( '#jtc-sign-panel-'   + PID );
	var $form        = $( '#jtc-form-'         + PID );
	var $formWrap    = $( '#jtc-form-wrap-'    + PID );
	var $status      = $( '#jtc-status-'       + PID );
	var $submit      = $( '#jtc-submit-'       + PID );
	var $count       = $( '#jtc-count-'        + PID );
	var $recentList  = $( '#jtc-recent-list-'  + PID );
	var $mobileCta   = $( '#jtc-mobile-cta-'   + PID );
	var $mobileCount = $( '#jtc-mobile-count-' + PID );
	var $mobileBtn   = $mobileCta.find( '.jtc-mobile-cta__button' );

	if ( ! $petition.length ) return;

	// ── Mobile CTA: show when sign panel exits viewport ───────────────────

	function isMobile() {
		return window.innerWidth < 768;
	}

	function updateMobileCta() {
		if ( ! isMobile() ) {
			$mobileCta.attr( 'aria-hidden', 'true' );
			return;
		}

		var panelTop    = $panel.offset() ? $panel.offset().top : 0;
		var panelBottom = panelTop + $panel.outerHeight( true );
		var scrollTop   = $( window ).scrollTop();
		var viewBottom  = scrollTop + window.innerHeight;

		// Panel is "visible" when any part of it is in the viewport.
		var panelVisible = panelBottom > scrollTop && panelTop < viewBottom;

		$mobileCta.attr( 'aria-hidden', panelVisible ? 'true' : 'false' );
	}

	$( window ).on( 'scroll.jtc resize.jtc', updateMobileCta );
	updateMobileCta();

	// Tap the CTA → scroll to the panel and focus the first input.
	$mobileBtn.on( 'click', function () {
		var offset    = $panel.offset() ? $panel.offset().top : 0;
		var adminBarH = $( '#wpadminbar' ).outerHeight( true ) || 0;

		$( 'html, body' ).animate(
			{ scrollTop: offset - adminBarH - 16 },
			320,
			function () {
				$form.find( 'input:not([type=hidden]):first' ).trigger( 'focus' );
			}
		);
	} );

	// ── AJAX form submission ──────────────────────────────────────────────

	$form.on( 'submit', function ( e ) {
		e.preventDefault();

		if ( ! clientValidate() ) return;

		var formData = $form.serialize() +
			'&action=jtc_sign_petition' +
			'&nonce='       + encodeURIComponent( jtcData.nonce ) +
			'&petition_id=' + PID;

		$submit.prop( 'disabled', true ).text( jtcData.i18n.signing );
		$status.removeClass( 'jtc-status--error jtc-status--success' ).text( '' );

		$.post( jtcData.ajaxUrl, formData )
			.done( function ( response ) {
				if ( response.success ) {
					handleSuccess( response.data );
				} else {
					showError( response.data.message || jtcData.i18n.errorGeneric );
					$submit.prop( 'disabled', false ).text( jtcData.i18n.sign );
				}
			} )
			.fail( function () {
				showError( jtcData.i18n.errorGeneric );
				$submit.prop( 'disabled', false ).text( jtcData.i18n.sign );
			} );
	} );

	// Client-side validation — mirrors server rules for instant feedback.
	function clientValidate() {
		var valid  = true;
		var $first = null; // first invalid field, for focus

		$form.find( '[required]' ).each( function () {
			var $input = $( this );
			var $error = $( '#' + ( $input.attr( 'aria-describedby' ) || '' ) );
			var val    = $input.val().trim();
			var bad    = ! val;

			if ( 'email' === $input.attr( 'type' ) && ! bad ) {
				bad = ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( val );
			}

			$input.attr( 'aria-invalid', bad ? 'true' : 'false' );

			if ( $error.length ) {
				$error.prop( 'hidden', ! bad );
				if ( bad ) {
					$error.text(
						'email' === $input.attr( 'type' )
							? 'Please enter a valid email address.'
							: 'This field is required.'
					);
				}
			}

			if ( bad && valid ) {
				valid  = false;
				$first = $input;
			}
		} );

		if ( $first ) $first.trigger( 'focus' );
		return valid;
	}

	function handleSuccess( data ) {
		// Refresh signature count everywhere.
		if ( data.count !== undefined ) {
			var formatted = parseInt( data.count, 10 ).toLocaleString();
			$count.text( formatted );
			if ( $mobileCount.length ) {
				$mobileCount.text( '— ' + formatted + ' signed' );
			}
		}

		// Refresh recent-signers list.
		if ( data.recent_signers && $recentList.length ) {
			$recentList.empty();
			$.each( data.recent_signers, function ( i, s ) {
				$recentList.append(
					'<li class="jtc-recent-signers__item">' +
						'<span class="jtc-recent-signers__name">' + escHtml( s.name )      + '</span>' +
						'<span class="jtc-recent-signers__time">' + escHtml( s.signed_at ) + '</span>' +
					'</li>'
				);
			} );
		}

		// After-sign action.
		if ( 'redirect' === data.action && data.redirect_url ) {
			window.location.href = data.redirect_url;
			return;
		}

		// Replace form with success message.
		$formWrap.html(
			'<div class="jtc-success" role="status" tabindex="-1">' +
				( data.message || 'Thank you for signing!' ) +
			'</div>'
		);
		$formWrap.find( '.jtc-success' ).trigger( 'focus' );

		// Hide mobile CTA — no need to sign again.
		$mobileCta.attr( 'aria-hidden', 'true' );
	}

	function showError( msg ) {
		$status.addClass( 'jtc-status--error' ).text( msg );
	}

	// ── Share buttons ─────────────────────────────────────────────────────

	$petition.on( 'click', '.jtc-share__btn', function ( e ) {
		var action = $( this ).data( 'action' );
		if ( ! action ) return; // external links open natively

		e.preventDefault();

		if ( 'copy' === action ) {
			navigator.clipboard.writeText( window.location.href ).then( function () {
				var $btn = $petition.find( '.jtc-share__btn--copy' );
				var orig = $btn.text();
				$btn.text( '✓ Copied!' );
				setTimeout( function () { $btn.text( orig ); }, 2000 );
			} );
		}

		if ( 'embed' === action ) {
			var code = '[jtc_petition id="' + PID + '"]';
			window.prompt( 'Copy this shortcode to embed the petition:', code );
		}
	} );

	// ── Utility ───────────────────────────────────────────────────────────

	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

} );
