( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var Notice = components.Notice;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( 'certloom/verification', {
		title: __( 'Certloom – Certificate Verification', 'certloom' ),
		description: __( 'Add certificate verification without requiring Elementor.', 'certloom' ),
		icon: 'shield-alt',
		category: 'widgets',
		keywords: [ __( 'certificate', 'certloom' ), __( 'verification', 'certloom' ), __( 'credentials', 'certloom' ) ],
		attributes: {
			design: { type: 'string', default: 'professional' },
			showGuide: { type: 'boolean', default: true },
			showEyebrow: { type: 'boolean', default: true },
			showHeading: { type: 'boolean', default: true },
			showDescription: { type: 'boolean', default: true },
			showCredit: { type: 'boolean', default: false },
			headingTag: { type: 'string', default: 'h2' },
			textAlign: { type: 'string', default: 'left' },
			eyebrow: { type: 'string' },
			heading: { type: 'string' },
			description: { type: 'string' },
			placeholder: { type: 'string' },
			buttonText: { type: 'string' },
			notFoundText: { type: 'string' },
			guideEyebrow: { type: 'string' },
			guideHeading: { type: 'string' },
			creditText: { type: 'string' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var textDefaults = {
				eyebrow: __( 'Certificate search', 'certloom' ),
				heading: __( 'Verify a Certificate', 'certloom' ),
				description: __( 'Enter the certificate number exactly as it appears on the face of the certificate. No login required.', 'certloom' ),
				placeholder: __( 'e.g. CERT-9001-2026-00142', 'certloom' ),
				buttonText: __( 'Search', 'certloom' ),
				notFoundText: __( "We couldn't find a certificate with that number. Double check it matches exactly what's printed on the certificate.", 'certloom' ),
				guideEyebrow: __( 'Certificate status guide', 'certloom' ),
				guideHeading: '',
				creditText: __( 'Verification powered by Certloom.', 'certloom' )
			};
			var textValue = function ( key ) {
				return typeof a[ key ] === 'string' ? a[ key ] : textDefaults[ key ];
			};
			var blockProps = useBlockProps ? useBlockProps( { className: 'ecapla-certverify-block-editor' } ) : { className: 'ecapla-certverify-block-editor' };
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'certloom' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Design', 'certloom' ),
							value: a.design,
							options: [
								{ label: __( 'Professional', 'certloom' ), value: 'professional' },
								{ label: __( 'Meridian', 'certloom' ), value: 'meridian' },
								{ label: __( 'Halo', 'certloom' ), value: 'halo' },
								{ label: __( 'Axis', 'certloom' ), value: 'axis' },
								{ label: __( 'Nova', 'certloom' ), value: 'nova' },
								{ label: __( 'Forge', 'certloom' ), value: 'forge' }
							],
							onChange: function ( value ) { set( { design: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Text alignment', 'certloom' ),
							value: a.textAlign,
							options: [
								{ label: __( 'Left', 'certloom' ), value: 'left' },
								{ label: __( 'Center', 'certloom' ), value: 'center' },
								{ label: __( 'Right', 'certloom' ), value: 'right' }
							],
							onChange: function ( value ) { set( { textAlign: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Heading tag', 'certloom' ),
							value: a.headingTag,
							options: [ 'h1', 'h2', 'h3', 'h4', 'p', 'div' ].map( function ( tag ) { return { label: tag.toUpperCase(), value: tag }; } ),
							onChange: function ( value ) { set( { headingTag: value } ); }
						} )
					),
					el( PanelBody, { title: __( 'Content', 'certloom' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show subheading', 'certloom' ), checked: a.showEyebrow, onChange: function ( value ) { set( { showEyebrow: value } ); } } ),
						a.showEyebrow ? el( TextControl, { label: __( 'Subheading', 'certloom' ), value: textValue( 'eyebrow' ), onChange: function ( value ) { set( { eyebrow: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show heading', 'certloom' ), checked: a.showHeading, onChange: function ( value ) { set( { showHeading: value } ); } } ),
						a.showHeading ? el( TextControl, { label: __( 'Heading', 'certloom' ), value: textValue( 'heading' ), onChange: function ( value ) { set( { heading: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show description', 'certloom' ), checked: a.showDescription, onChange: function ( value ) { set( { showDescription: value } ); } } ),
						a.showDescription ? el( TextareaControl, { label: __( 'Description', 'certloom' ), value: textValue( 'description' ), onChange: function ( value ) { set( { description: value } ); } } ) : null,
						el( TextControl, { label: __( 'Search placeholder', 'certloom' ), value: textValue( 'placeholder' ), onChange: function ( value ) { set( { placeholder: value } ); } } ),
						el( TextControl, { label: __( 'Button text', 'certloom' ), value: textValue( 'buttonText' ), onChange: function ( value ) { set( { buttonText: value } ); } } ),
						el( TextareaControl, { label: __( 'Not-found message', 'certloom' ), value: textValue( 'notFoundText' ), onChange: function ( value ) { set( { notFoundText: value } ); } } )
					),
					el( PanelBody, { title: __( 'Status guide and credit', 'certloom' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show status guide', 'certloom' ), checked: a.showGuide, onChange: function ( value ) { set( { showGuide: value } ); } } ),
						a.showGuide ? el( TextControl, { label: __( 'Guide subheading', 'certloom' ), value: textValue( 'guideEyebrow' ), onChange: function ( value ) { set( { guideEyebrow: value } ); } } ) : null,
						a.showGuide ? el( TextControl, { label: __( 'Guide heading', 'certloom' ), value: textValue( 'guideHeading' ), onChange: function ( value ) { set( { guideHeading: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show credit', 'certloom' ), checked: a.showCredit, onChange: function ( value ) { set( { showCredit: value } ); } } ),
						a.showCredit ? el( TextControl, { label: __( 'Credit text', 'certloom' ), value: textValue( 'creditText' ), onChange: function ( value ) { set( { creditText: value } ); } } ) : null
					)
				),
				el( 'div', blockProps,
					el( Notice, { status: 'info', isDismissible: false, className: 'ecapla-certverify-block-note' }, __( 'This is the native WordPress block. Elementor is not required.', 'certloom' ) ),
					el( ServerSideRender, { block: 'certloom/verification', attributes: a } )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n );
