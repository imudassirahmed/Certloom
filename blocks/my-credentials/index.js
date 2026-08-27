( function ( blocks, element, components, blockEditor, serverSideRender, i18n ) {
	'use strict';
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var Notice = components.Notice;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( 'certloom/my-credentials', {
		title: __( 'Certloom – My Credentials', 'certloom' ),
		description: __( 'Display credentials belonging to the signed-in Certloom recipient.', 'certloom' ),
		icon: 'portfolio',
		category: 'widgets',
		keywords: [ __( 'credentials', 'certloom' ), __( 'certificates', 'certloom' ), __( 'recipient portal', 'certloom' ) ],
		attributes: {
			layout: { type: 'string', default: 'cards' },
			perPage: { type: 'number', default: 12 },
			showFilters: { type: 'boolean', default: true },
			heading: { type: 'string', default: __( 'My Credentials', 'certloom' ) }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps ? useBlockProps( { className: 'wp-block-certloom-my-credentials' } ) : { className: 'wp-block-certloom-my-credentials' };
			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'My Credentials', 'certloom' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout', 'certloom' ), value: a.layout || 'cards',
							options: [
								{ label: __( 'Cards', 'certloom' ), value: 'cards' },
								{ label: __( 'Grid', 'certloom' ), value: 'grid' },
								{ label: __( 'Table', 'certloom' ), value: 'table' }
							],
							onChange: function ( value ) { set( { layout: value } ); }
						} ),
						el( RangeControl, { label: __( 'Credentials per page', 'certloom' ), value: a.perPage || 12, min: 1, max: 24, onChange: function ( value ) { set( { perPage: value } ); } } ),
						el( ToggleControl, { label: __( 'Show status and sort filters', 'certloom' ), checked: a.showFilters !== false, onChange: function ( value ) { set( { showFilters: value } ); } } ),
						el( TextControl, { label: __( 'Heading', 'certloom' ), value: a.heading || '', onChange: function ( value ) { set( { heading: value } ); } } )
					)
				),
				el( 'div', blockProps,
					el( Notice, { status: 'info', isDismissible: false, className: 'certloom-my-credentials-block-note' }, __( 'This block is recipient-specific. The editor preview uses the current signed-in account and remains fail-closed until normalized credential authority is available.', 'certloom' ) ),
					el( ServerSideRender, { block: 'certloom/my-credentials', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n );
