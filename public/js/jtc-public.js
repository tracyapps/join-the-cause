/**
 * Join the Cause — Public JavaScript
 *
 * Multi-instance: every `.jtc-petition` element carries its own runtime
 * configuration in data attributes (data-jtc-nonce, data-petition-id,
 * data-jtc-share-url, data-jtc-ajax-url); shared strings live in
 * jtcData.i18n. This file wires each instance independently, so a page
 * with several petitions (shortcodes or blocks) keeps all forms working.
 *
 * Per instance it handles:
 *   1. Mobile sticky CTA — shows when the sign panel is scrolled out of
 *      view; tapping it scrolls to / reveals the panel.
 *   2. AJAX form submission — client-side validation, then POST, then
 *      success/error handling (count + progressbar updates, recent-signers
 *      refresh, success message or redirect).
 *   3. Share button actions — copy-link, embed shortcode, QR download/print.
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

	var shared = ( typeof jtcData !== 'undefined' ) ? jtcData : {};
	var i18n   = shared.i18n || {};

	function t( key, fallback ) {
		return ( typeof i18n[ key ] === 'string' && i18n[ key ] ) ? i18n[ key ] : fallback;
	}

	var reduceMotion = window.matchMedia
		&& window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	// Wire every petition on the page, independently.
	$( '.jtc-petition' ).each( function () {
		initPetition( $( this ) );
	} );

	function initPetition( $petition ) {
		var pid      = String( $petition.attr( 'data-petition-id' ) || '' );
		if ( ! pid ) return;

		var ajaxUrl  = $petition.attr( 'data-jtc-ajax-url' ) || shared.ajaxUrl || '';
		var nonce    = $petition.attr( 'data-jtc-nonce' ) || '';
		var shareUrl = $petition.attr( 'data-jtc-share-url' ) || window.location.href;

		var $panel      = $petition.find( '.jtc-sign-panel' );
		var $form       = $petition.find( '.jtc-form' );
		var $formWrap   = $petition.find( '.jtc-form-wrap' );
		var $status     = $petition.find( '.jtc-form__status' );
		var $submit     = $form.find( '.jtc-form__submit' );
		var $count      = $petition.find( '.jtc-count__number' );
		var $progress   = $petition.find( '.jtc-progress' );
		var $recentList = $petition.find( '.jtc-recent-signers__list' );
		var $mobileCta  = $( '#jtc-mobile-cta-' + pid );

		if ( ! $mobileCta.length ) {
			$mobileCta = $petition.nextAll( '.jtc-mobile-cta' ).first();
		}

		var $mobileCount = $mobileCta.find( '.jtc-mobile-cta__count' );
		var $mobileBtn   = $mobileCta.find( '.jtc-mobile-cta__button' );

		// Polite announcer for share/copy feedback. The form status region is
		// not reusable: it is assertive (form errors) and is replaced by the
		// success panel after signing.
		var $announcer = $(
			'<p class="jtc-sr-announcer" role="status" aria-live="polite" aria-atomic="true"></p>'
		).appendTo( $petition );

		function announce( message ) {
			if ( ! message ) return;
			// Clear then set (next tick) so a repeated identical message is
			// announced again.
			$announcer.text( '' );
			window.setTimeout( function () {
				$announcer.text( message );
			}, 50 );
		}

		if ( ! $form.length ) return; // Nothing to wire without a form.

		// ── Mobile CTA: show when sign panel exits viewport ───────────────

		function isMobile() {
			return window.innerWidth < 768;
		}

		function updateMobileCta() {
			if ( ! $mobileCta.length ) return;
			if ( ! isMobile() ) {
				$mobileCta.attr( 'aria-hidden', 'true' );
				return;
			}

			var offset = $panel.offset();
			if ( ! offset ) return;

			var panelTop    = offset.top;
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
			var target    = offset - adminBarH - 16;
			var focusFirst = function () {
				$form.find( 'input:not([type=hidden]):first' ).trigger( 'focus' );
			};

			if ( reduceMotion ) {
				window.scrollTo( 0, target );
				focusFirst();
			} else {
				$( 'html, body' ).animate( { scrollTop: target }, 320, focusFirst );
			}
		} );

		// ── AJAX form submission ──────────────────────────────────────────

		$form.on( 'submit', function ( e ) {
			e.preventDefault();

			if ( ! clientValidate() ) return;

			var formData = $form.serialize() +
				'&action=jtc_sign_petition' +
				'&nonce='       + encodeURIComponent( nonce ) +
				'&petition_id=' + encodeURIComponent( pid );

			$submit.prop( 'disabled', true ).text( t( 'signing', 'Signing…' ) );
			$status.removeClass( 'jtc-status--error jtc-status--success' ).text( '' );

			$.post( ajaxUrl, formData )
				.done( function ( response ) {
					if ( response.success ) {
						handleSuccess( response.data );
					} else {
						showError( ( response.data && response.data.message ) || t( 'errorGeneric', 'Something went wrong. Please try again.' ) );
					}
				} )
				.fail( function ( xhr ) {
					showError( getAjaxErrorMessage( xhr ) );
				} );
		} );

		function resetSubmit() {
			$submit.prop( 'disabled', false ).text( t( 'sign', 'Sign the Petition' ) );
		}

		function getAjaxErrorMessage( xhr ) {
			if (
				xhr &&
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				xhr.responseJSON.data.message
			) {
				return xhr.responseJSON.data.message;
			}

			return t( 'errorGeneric', 'Something went wrong. Please try again.' );
		}

		// Client-side validation — mirrors server rules for instant feedback.
		function clientValidate() {
			var valid  = true;
			var $first = null; // first invalid field, for focus

			$form.find( '[required]' ).each( function () {
				var $input = $( this );
				var $error = $( '#' + ( $input.attr( 'aria-describedby' ) || '' ) );
				var type   = $input.attr( 'type' );
				var val    = $input.val().trim();
				var bad;

				if ( 'checkbox' === type ) {
					bad = ! $input.prop( 'checked' );
				} else {
					bad = ! val;
					if ( 'email' === type && ! bad ) {
						bad = ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( val );
					}
				}

				$input.attr( 'aria-invalid', bad ? 'true' : 'false' );

				if ( $error.length ) {
					$error.prop( 'hidden', ! bad );
					if ( bad ) {
						$error.text(
							'email' === type
								? t( 'emailInvalid', 'Please enter a valid email address.' )
								: t( 'fieldRequired', 'This field is required.' )
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

		function updateProgress( count ) {
			if ( ! $progress.length ) return;

			var goal = parseInt( $progress.attr( 'data-jtc-goal' ) || '0', 10 );
			if ( ! ( goal > 0 ) ) return;

			var pct = Math.min( 100, Math.round( ( count / goal ) * 100 ) );
			$progress.attr( 'aria-valuenow', pct );
			// Keep the accessible name in sync with the new value (the server
			// prints the same sentence; %% collapses to % after substitution).
			$progress.attr(
				'aria-label',
				t( 'goalProgress', '%d%% of goal reached' )
					.replace( '%d', String( pct ) )
					.replace( '%%', '%' )
			);
			$progress.find( '.jtc-progress__bar' ).css( 'width', pct + '%' );
		}

		function handleSuccess( data ) {
			// Refresh signature count everywhere.
			if ( data.count !== undefined ) {
				var formatted = parseInt( data.count, 10 ).toLocaleString();
				$count.text( formatted );
				if ( $mobileCount.length ) {
					$mobileCount.text( t( 'signedCount', '— %s signed' ).replace( '%s', formatted ) );
				}
				updateProgress( data.count );
			}

			// Refresh recent-signers list.
			if ( data.recent_signers && $recentList.length ) {
				$recentList.empty();
				$.each( data.recent_signers, function ( i, s ) {
					$recentList.append(
						'<li class="jtc-recent-signers__item">' +
							'<span class="jtc-recent-signers__name">' + escHtml( s.name ) + '</span>' +
							'<time class="jtc-recent-signers__time" datetime="' + escAttr( s.signed_at ) + '">' +
								escHtml( s.signed_at_human || s.signed_at ) +
							'</time>' +
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
			var url = data.share_url || shareUrl;
			var shareHtml = '';

			if ( url ) {
				shareHtml =
					'<div class="jtc-success-share">' +
						'<p>' + escHtml( t( 'shareLabel', 'Share this petition:' ) ) + '</p>' +
						'<a href="' + escAttr( url ) + '" target="_blank" rel="noopener noreferrer">' + escHtml( url ) + '</a>' +
						'<button type="button" class="jtc-success-share__copy" data-share-copy="' + escAttr( url ) + '">' + escHtml( t( 'copyLink', 'Copy link' ) ) + '</button>' +
					'</div>';
			}

			$formWrap.html(
				'<div class="jtc-success" role="status" tabindex="-1">' +
					( data.message || escHtml( t( 'thanksFallback', 'Thank you for signing!' ) ) ) +
					shareHtml +
				'</div>'
			);
			$formWrap.find( '.jtc-success' ).trigger( 'focus' );

			// Hide mobile CTA — no need to sign again.
			$mobileCta.attr( 'aria-hidden', 'true' );
		}

		function showError( msg ) {
			// Re-enable the submit button first (disabling it dropped focus to
			// the body), then move focus to the status region (tabindex="-1")
			// so keyboard users land on the server error instead of restarting
			// from the top of the page.
			resetSubmit();
			$status.addClass( 'jtc-status--error' ).text( msg ).trigger( 'focus' );
		}

		// ── Share buttons ─────────────────────────────────────────────────

		$petition.on( 'click', '.jtc-share__btn, .jtc-share__qr-btn', function ( e ) {
			var action = $( this ).attr( 'data-action' );
			if ( ! action ) return; // external links open natively

			e.preventDefault();

			if ( 'copy' === action ) {
				copyText( shareUrl, $( this ) );
			}

			if ( 'embed' === action ) {
				var code = '[jtc_petition id="' + pid + '"]';
				window.prompt( t( 'embedPrompt', 'Copy this shortcode to embed the petition:' ), code );
			}

			if ( 'print-qr' === action ) {
				printQr( $( this ).attr( 'data-qr-url' ) );
			}
		} );

		$petition.on( 'click', '[data-share-copy]', function () {
			copyText( $( this ).attr( 'data-share-copy' ), $( this ) );
		} );

		function copyText( text, $btn ) {
			if ( ! text ) return;

			var done = function () {
				announce( t( 'copySuccess', 'Link copied to clipboard.' ) );
				if ( $btn && $btn.length ) {
					var original = $btn.text();
					$btn.text( t( 'copied', 'Copied!' ) );
					setTimeout( function () {
						$btn.text( original );
					}, 2000 );
				}
			};

			// Clipboard API missing or denied: hand the user a real selection
			// plus the keyboard-shortcut guidance instead of failing silently.
			var manual = function () {
				selectCopyText( text, $btn );
				announce( t( 'copyManual', 'Text selected. Press Ctrl+C (or Cmd+C) to copy.' ) );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done ).catch( manual );
			} else {
				manual();
			}
		}

		// Manual fallback: show a temporary readonly input holding the URL,
		// select its contents so Ctrl/Cmd+C works, and clean it up on blur.
		function selectCopyText( text, $btn ) {
			var $temp = $( '<input type="text" readonly>' )
				.addClass( 'jtc-copy-temp' )
				.val( text );

			if ( $btn && $btn.length ) {
				$temp.insertAfter( $btn );
			} else {
				$petition.append( $temp );
			}

			var el = $temp.get( 0 );
			if ( el ) {
				el.focus();
				el.select();
			}

			$temp.one( 'blur', function () {
				$temp.remove();
			} );
		}

		// ── QR printing ───────────────────────────────────────────────────

		function printQr( qrUrl ) {
			if ( ! qrUrl ) return;

			var win = window.open( '', 'jtcQrPrint', 'width=640,height=720' );
			if ( ! win ) return;

			var docLang = escAttr( document.documentElement.lang || 'en' );

			win.document.write(
				'<!doctype html><html lang="' + docLang + '"><head><meta charset="utf-8"><title>' + escHtml( t( 'printQrTitle', 'Print QR' ) ) + '</title>' +
				'<style>body{font-family:sans-serif;text-align:center;padding:40px;}img{width:360px;max-width:90vw;height:auto;}a{display:block;margin-top:16px;color:#111;}</style>' +
				'</head><body><img src="' + escAttr( qrUrl ) + '" alt="' + escAttr( t( 'qrAlt', 'QR code' ) ) + '"><a href="' + escAttr( shareUrl ) + '">' + escHtml( shareUrl ) + '</a>' +
				'<scr' + 'ipt>window.onload=function(){window.print();};</scr' + 'ipt></body></html>'
			);
			win.document.close();
		}
	}

	// ── Utility ───────────────────────────────────────────────────────────

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

} );
