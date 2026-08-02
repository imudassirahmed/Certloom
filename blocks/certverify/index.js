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

	blocks.registerBlockType( 'certverify/verification', {
		title: __( 'CertVerify – Certificate Verification', 'certverify' ),
		description: __( 'Add certificate verification without requiring Elementor.', 'certverify' ),
		icon: 'shield-alt',
		category: 'widgets',
		keywords: [ __( 'certificate', 'certverify' ), __( 'verification', 'certverify' ), __( 'credentials', 'certverify' ) ],
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
				eyebrow: __( 'Certificate search', 'certverify' ),
				heading: __( 'Verify a Certificate', 'certverify' ),
				description: __( 'Enter the certificate number exactly as it appears on the face of the certificate. No login required.', 'certverify' ),
				placeholder: __( 'e.g. CERT-9001-2026-00142', 'certverify' ),
				buttonText: __( 'Search', 'certverify' ),
				notFoundText: __( "We couldn't find a certificate with that number. Double check it matches exactly what's printed on the certificate.", 'certverify' ),
				guideEyebrow: __( 'Certificate status guide', 'certverify' ),
				guideHeading: '',
				creditText: __( 'Verification powered by CertVerify.', 'certverify' )
			};
			var textValue = function ( key ) {
				return typeof a[ key ] === 'string' ? a[ key ] : textDefaults[ key ];
			};
			var blockProps = useBlockProps ? useBlockProps( { className: 'ecapla-certverify-block-editor' } ) : { className: 'ecapla-certverify-block-editor' };
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'certverify' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Design', 'certverify' ),
							value: a.design,
							options: [
								{ label: __( 'Professional', 'certverify' ), value: 'professional' },
								{ label: __( 'Meridian', 'certverify' ), value: 'meridian' },
								{ label: __( 'Halo', 'certverify' ), value: 'halo' },
								{ label: __( 'Axis', 'certverify' ), value: 'axis' },
								{ label: __( 'Nova', 'certverify' ), value: 'nova' },
								{ label: __( 'Forge', 'certverify' ), value: 'forge' }
							],
							onChange: function ( value ) { set( { design: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Text alignment', 'certverify' ),
							value: a.textAlign,
							options: [
								{ label: __( 'Left', 'certverify' ), value: 'left' },
								{ label: __( 'Center', 'certverify' ), value: 'center' },
								{ label: __( 'Right', 'certverify' ), value: 'right' }
							],
							onChange: function ( value ) { set( { textAlign: value } ); }
						} ),
						el( SelectControl, {
							label: __( 'Heading tag', 'certverify' ),
							value: a.headingTag,
							options: [ 'h1', 'h2', 'h3', 'h4', 'p', 'div' ].map( function ( tag ) { return { label: tag.toUpperCase(), value: tag }; } ),
							onChange: function ( value ) { set( { headingTag: value } ); }
						} )
					),
					el( PanelBody, { title: __( 'Content', 'certverify' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show subheading', 'certverify' ), checked: a.showEyebrow, onChange: function ( value ) { set( { showEyebrow: value } ); } } ),
						a.showEyebrow ? el( TextControl, { label: __( 'Subheading', 'certverify' ), value: textValue( 'eyebrow' ), onChange: function ( value ) { set( { eyebrow: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show heading', 'certverify' ), checked: a.showHeading, onChange: function ( value ) { set( { showHeading: value } ); } } ),
						a.showHeading ? el( TextControl, { label: __( 'Heading', 'certverify' ), value: textValue( 'heading' ), onChange: function ( value ) { set( { heading: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show description', 'certverify' ), checked: a.showDescription, onChange: function ( value ) { set( { showDescription: value } ); } } ),
						a.showDescription ? el( TextareaControl, { label: __( 'Description', 'certverify' ), value: textValue( 'description' ), onChange: function ( value ) { set( { description: value } ); } } ) : null,
						el( TextControl, { label: __( 'Search placeholder', 'certverify' ), value: textValue( 'placeholder' ), onChange: function ( value ) { set( { placeholder: value } ); } } ),
						el( TextControl, { label: __( 'Button text', 'certverify' ), value: textValue( 'buttonText' ), onChange: function ( value ) { set( { buttonText: value } ); } } ),
						el( TextareaControl, { label: __( 'Not-found message', 'certverify' ), value: textValue( 'notFoundText' ), onChange: function ( value ) { set( { notFoundText: value } ); } } )
					),
					el( PanelBody, { title: __( 'Status guide and credit', 'certverify' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show status guide', 'certverify' ), checked: a.showGuide, onChange: function ( value ) { set( { showGuide: value } ); } } ),
						a.showGuide ? el( TextControl, { label: __( 'Guide subheading', 'certverify' ), value: textValue( 'guideEyebrow' ), onChange: function ( value ) { set( { guideEyebrow: value } ); } } ) : null,
						a.showGuide ? el( TextControl, { label: __( 'Guide heading', 'certverify' ), value: textValue( 'guideHeading' ), onChange: function ( value ) { set( { guideHeading: value } ); } } ) : null,
						el( ToggleControl, { label: __( 'Show credit', 'certverify' ), checked: a.showCredit, onChange: function ( value ) { set( { showCredit: value } ); } } ),
						a.showCredit ? el( TextControl, { label: __( 'Credit text', 'certverify' ), value: textValue( 'creditText' ), onChange: function ( value ) { set( { creditText: value } ); } } ) : null
					)
				),
				el( 'div', blockProps,
					el( Notice, { status: 'info', isDismissible: false, className: 'ecapla-certverify-block-note' }, __( 'This is the native WordPress block. Elementor is not required.', 'certverify' ) ),
					el( ServerSideRender, { block: 'certverify/verification', attributes: a } )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n );
