/**
 * Editor script for the jtc/petition block — hand-written, no build step.
 *
 * The block is dynamic: the canvas preview is rendered server-side by
 * `JTC_Shortcode::render()` (through the block-renderer REST route), so the
 * editor shows — and the front end renders — exactly the same markup as the
 * `[jtc_petition]` shortcode. This file only builds the editor UI:
 *
 *   - a searchable petition picker fed by the CPT's REST endpoint
 *     (`/wp/v2/petitions`, rest_base "petitions"), fetched with wp.apiFetch;
 *   - a "Show title" toggle;
 *   - a `wp.serverSideRender` preview, with a placeholder empty state.
 *
 * @package JoinTheCause
 */

/* global window */
( function ( wp ) {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useEffect         = wp.element.useEffect;
	var useState          = wp.element.useState;
	var __                = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender  = wp.serverSideRender;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var ComboboxControl   = wp.components.ComboboxControl;
	var ToggleControl     = wp.components.ToggleControl;
	var Placeholder       = wp.components.Placeholder;
	var Notice            = wp.components.Notice;
	var Spinner           = wp.components.Spinner;
	var ExternalLink      = wp.components.ExternalLink;
	var apiFetch          = wp.apiFetch;

	// Admin URLs passed from PHP via wp_localize_script (fallbacks keep the
	// block usable even if the data was never printed).
	var editorData     = window.jtcBlockEditor || {};
	var newPetitionUrl = editorData.newPetitionUrl || 'post-new.php?post_type=jtc_petition';

	// Published petitions, fetched once per editor page and cached — every
	// block instance on the page shares the same request.
	var petitionsPromise = null;

	function fetchPetitions() {
		if ( ! petitionsPromise ) {
			petitionsPromise = apiFetch( {
				path: '/wp/v2/petitions?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title'
			} ).then( function ( posts ) {
				return ( posts || [] ).map( function ( post ) {
					return {
						value: String( post.id ),
						label: decodeEntities(
							post.title && post.title.rendered ? post.title.rendered : '#' + post.id
						)
					};
				} );
			} ).catch( function ( error ) {
				petitionsPromise = null; // Allow a retry on the next mount.
				throw error;
			} );
		}
		return petitionsPromise;
	}

	function decodeEntities( html ) {
		var textarea = document.createElement( 'textarea' );
		textarea.innerHTML = html;
		return textarea.value;
	}

	function createPetitionLink() {
		return el(
			'p',
			{ className: 'jtc-block-hint' },
			el( ExternalLink, { href: newPetitionUrl }, __( 'Create a new petition', 'join-the-cause' ) )
		);
	}

	// ── Field: searchable petition picker ──────────────────────────────────

	function PetitionPicker( props ) {
		var loadingState = useState( true );
		var loading      = loadingState[ 0 ];
		var setLoading   = loadingState[ 1 ];

		var optionsState = useState( [] );
		var options      = optionsState[ 0 ];
		var setOptions   = optionsState[ 1 ];

		var errorState = useState( '' );
		var error      = errorState[ 0 ];
		var setError   = errorState[ 1 ];

		useEffect( function () {
			var mounted = true;

			fetchPetitions().then( function ( list ) {
				if ( mounted ) {
					setOptions( list );
					setLoading( false );
				}
			} ).catch( function ( err ) {
				if ( mounted ) {
					setError( err && err.message ? err.message : __( 'Could not load petitions.', 'join-the-cause' ) );
					setLoading( false );
				}
			} );

			return function () {
				mounted = false;
			};
		}, [] );

		if ( loading ) {
			return el(
				'p',
				{ className: 'jtc-block-hint' },
				el( Spinner, null ),
				' ',
				__( 'Loading petitions…', 'join-the-cause' )
			);
		}

		if ( error ) {
			return el(
				Fragment,
				null,
				el( Notice, { status: 'warning', isDismissible: false }, error ),
				createPetitionLink()
			);
		}

		if ( ! options.length ) {
			return el(
				Fragment,
				null,
				el( 'p', { className: 'jtc-block-hint' }, __( 'No published petitions found yet.', 'join-the-cause' ) ),
				createPetitionLink()
			);
		}

		return el( ComboboxControl, {
			label: __( 'Petition', 'join-the-cause' ),
			value: props.value,
			options: options,
			onChange: props.onChange,
			__nextHasNoMarginBottom: true
		} );
	}

	// ── Block registration ─────────────────────────────────────────────────
	// Keep title/description/keywords/supports/attributes in sync with
	// blocks/petition/block.json (there is no build step to share them).

	registerBlockType( 'jtc/petition', {
		apiVersion: 3,
		title: __( 'Petition', 'join-the-cause' ),
		description: __( 'Embed a petition with its signature form, progress bar, and share tools.', 'join-the-cause' ),
		category: 'join-the-cause',
		icon: 'megaphone',
		keywords: [
			__( 'petition', 'join-the-cause' ),
			__( 'sign', 'join-the-cause' ),
			__( 'cause', 'join-the-cause' )
		],
		supports: {
			html: false,
			multiple: true,
			anchor: true
		},
		attributes: {
			petitionId: { type: 'number', default: 0 },
			showTitle: { type: 'boolean', default: false }
		},

		edit: function ( props ) {
			var blockProps = useBlockProps();
			var petitionId = props.attributes.petitionId;

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Petition', 'join-the-cause' ), initialOpen: true },
					el( PetitionPicker, {
						value: petitionId ? String( petitionId ) : null,
						onChange: function ( value ) {
							var id = value ? parseInt( value, 10 ) : 0;
							props.setAttributes( { petitionId: id > 0 ? id : 0 } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show title', 'join-the-cause' ),
						help: __( 'Render the petition title as an H1 — use it when this block sits at the top of the page and the page has no title of its own.', 'join-the-cause' ),
						checked: !! props.attributes.showTitle,
						onChange: function ( value ) {
							props.setAttributes( { showTitle: !! value } );
						},
						__nextHasNoMarginBottom: true
					} )
				)
			);

			var canvas;

			if ( ! petitionId ) {
				canvas = el(
					Placeholder,
					{
						icon: 'megaphone',
						label: __( 'Petition', 'join-the-cause' ),
						instructions: __( 'Select a petition in the block settings to embed it here. Nothing to choose from yet? Create a petition first, then pick it here.', 'join-the-cause' )
					},
					createPetitionLink()
				);
			} else {
				canvas = el( ServerSideRender, {
					block: 'jtc/petition',
					attributes: props.attributes
				} );
			}

			return el( Fragment, null, inspector, el( 'div', blockProps, canvas ) );
		},

		save: function () {
			return null; // Dynamic block — rendered by PHP on the server.
		}
	} );
} )( window.wp );
