( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
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
			heading: { type: 'string', default: 'Credential profile' },
			notFoundText: { type: 'string', default: 'This credential could not be found or is not available.' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps ? useBlockProps( { className: 'wp-block-certloom-credential' } ) : { className: 'wp-block-certloom-credential' };
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Credential', 'certloom' ), initialOpen: true },
						el( TextControl, { label: __( 'Certificate number', 'certloom' ), value: a.number || '', onChange: function ( value ) { set( { number: value } ); } } ),
						el( TextControl, { label: __( 'Heading', 'certloom' ), value: a.heading || '', onChange: function ( value ) { set( { heading: value } ); } } ),
						el( TextareaControl, { label: __( 'Not found message', 'certloom' ), value: a.notFoundText || '', onChange: function ( value ) { set( { notFoundText: value } ); } } )
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
