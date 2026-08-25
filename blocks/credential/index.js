( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var SelectControl = components.SelectControl;
	var Notice = components.Notice;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;
	var __ = i18n.__;
	blocks.registerBlockType( 'certloom/credential', {
		title: __( 'Certloom – Credential Profile', 'certloom' ),
		description: __( 'Display a known Certloom credential without a search form.', 'certloom' ),
		icon: 'id-alt',
		category: 'widgets',
		attributes: {
			number: { type: 'string', default: '' },
			heading: { type: 'string' },
			headingTag: { type: 'string', default: 'h2' },
			notFoundText: { type: 'string' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var textDefaults = {
				heading: __( 'Credential profile', 'certloom' ),
				notFoundText: __( 'This credential could not be found or is not available.', 'certloom' )
			};
			var textValue = function ( key ) {
				return typeof a[ key ] === 'string' ? a[ key ] : textDefaults[ key ];
			};
			var blockProps = useBlockProps ? useBlockProps( { className: 'wp-block-certloom-credential' } ) : { className: 'wp-block-certloom-credential' };
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Credential', 'certloom' ), initialOpen: true },
						el( TextControl, { label: __( 'Certificate number', 'certloom' ), value: a.number || '', onChange: function ( value ) { set( { number: value } ); } } ),
						el( TextControl, { label: __( 'Heading', 'certloom' ), value: textValue( 'heading' ), onChange: function ( value ) { set( { heading: value } ); } } ),
						el( SelectControl, { label: __( 'Heading level', 'certloom' ), value: a.headingTag || 'h2', options: [ { label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' }, { label: 'H4', value: 'h4' }, { label: 'H5', value: 'h5' }, { label: 'H6', value: 'h6' } ], onChange: function ( value ) { set( { headingTag: value } ); } } ),
						el( TextareaControl, { label: __( 'Not found message', 'certloom' ), value: textValue( 'notFoundText' ), onChange: function ( value ) { set( { notFoundText: value } ); } } )
					)
				),
				el( 'div', blockProps,
					a.number ? el( ServerSideRender, { block: 'certloom/credential', attributes: a } ) : el( Notice, { status: 'info', isDismissible: false }, __( 'Enter a certificate number in the block sidebar to preview the credential profile.', 'certloom' ) )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n );
