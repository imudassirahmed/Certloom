(function (blocks, element, components, editor, serverSideRender, i18n) {
  'use strict';
  var el = element.createElement, __ = i18n.__;
  var SSR = serverSideRender.default || serverSideRender;
  var settings = window.certloomStudioEditor || {enabled: false, recommended: 'essential', presets: {}, numbers: {}, colors: {}};
  blocks.registerBlockType('certloom/verification-studio', {
    title: __('Certloom Pro — Verification Studio', 'certloom'),
    description: __('Five profile-inspired verification designs and a responsive custom layout. Requires Certloom Pro.', 'certloom'),
    icon: 'shield-alt', category: 'widgets',
    supports: {html: false, anchor: true, align: ['wide', 'full'], inserter: settings.enabled === true},
    edit: function (props) {
      var a = props.attributes, set = props.setAttributes;
      var deviceState = element.useState('desktop'), device = deviceState[0];
      var preset = a.preset || settings.recommended;
      var panel = [];
      var blockProps = editor.useBlockProps({className: 'certloom-studio-editor'});
      function changeDevice(key, value) {
        var next = Object.assign({}, a[device] || {}), patch = {};
        if (value === '') { delete next[key]; } else { next[key] = value; }
        patch[device] = next; set(patch);
      }
      function choice(key, label, options) {
        return el(components.SelectControl, {key: key, label: label, value: (a[device] || {})[key] || '', options: [{label: __('Automatic / inherit', 'certloom'), value: ''}].concat(options), onChange: function (value) {changeDevice(key, value);}});
      }
      if (settings.enabled !== true) {
        return el('div', blockProps, el(components.Notice, {status: 'info', isDismissible: false}, __('Verification Studio requires Certloom Pro. This saved block uses standard verification while Pro is unavailable.', 'certloom')), el('div', {inert: '', className: 'certloom-studio-preview'}, el(SSR, {block: 'certloom/verification-studio', attributes: a})));
      }
      panel.push(el(components.PanelBody, {key: 'design', title: __('Verification Studio', 'certloom'), initialOpen: true},
        el(components.SelectControl, {label: __('Design', 'certloom'), value: preset, options: Object.keys(settings.presets).map(function (key) {return {label: settings.presets[key], value: key};}), onChange: function (value) {set({preset: value});}}),
        el('p', {className: 'components-base-control__help'}, __('Certificate position follows the global public certificate layout setting.', 'certloom'))));
      panel.push(el(components.PanelBody, {key: 'content', title: __('Content', 'certloom'), initialOpen: false},
        ['eyebrow', 'heading', 'description', 'placeholder', 'button_text', 'notfound_text'].map(function (key) {
          var Control = key === 'description' || key === 'notfound_text' ? components.TextareaControl : components.TextControl;
          return el(Control, {key: key, label: settings.contentLabels[key], value: typeof a[key] === 'string' ? a[key] : settings.defaults[key], onChange: function (value) {var patch = {}; patch[key] = value; set(patch);}});
        }),
        el(components.SelectControl, {label: __('Heading tag', 'certloom'), value: a.heading_tag, options: ['h2','h3','h4','p'].map(function (tag) {return {label: tag.toUpperCase(), value: tag};}), onChange: function (value) {set({heading_tag: value});}}),
        el(components.SelectControl, {label: __('Content order', 'certloom'), value: a.content_order, options: [
          {label: __('Eyebrow, heading, description', 'certloom'), value: 'eyebrow-heading-description'},
          {label: __('Heading, description, eyebrow', 'certloom'), value: 'heading-description-eyebrow'},
          {label: __('Description, heading, eyebrow', 'certloom'), value: 'description-heading-eyebrow'}
        ], onChange: function (value) {set({content_order: value});}}),
        el(components.ToggleControl, {label: __('Show status guide', 'certloom'), checked: a.show_guide, onChange: function (value) {set({show_guide: value});}})));
      if (preset === 'custom') {
        panel.push(el(components.PanelBody, {key: 'responsive', title: __('Custom layout • responsive', 'certloom'), initialOpen: true},
          el('div', {className: 'certloom-studio-devices', role: 'group', 'aria-label': __('Responsive device', 'certloom')}, ['desktop','tablet','mobile'].map(function (value) {return el(components.Button, {key: value, variant: device === value ? 'primary' : 'secondary', 'aria-pressed': device === value, onClick: function () {deviceState[1](value);}}, settings.devices[value]);})),
          choice('layout', __('Layout', 'certloom'), [{label: __('Split columns', 'certloom'), value: 'split'}, {label: __('Stacked', 'certloom'), value: 'stack'}, {label: __('Compact inline', 'certloom'), value: 'inline'}]),
          choice('align', __('Intro alignment', 'certloom'), [{label: __('Left', 'certloom'), value: 'left'}, {label: __('Center', 'certloom'), value: 'center'}, {label: __('Right', 'certloom'), value: 'right'}]),
          choice('form_layout', __('Input and button', 'certloom'), [{label: __('Same row', 'certloom'), value: 'row'}, {label: __('Stacked', 'certloom'), value: 'stack'}]),
          Object.keys(settings.numbers).map(function (key) {var rule = settings.numbers[key]; return el(components.TextControl, {key: key, type: 'number', label: rule[0], value: (a[device] || {})[key] === undefined ? '' : (a[device] || {})[key], min: rule[2], max: rule[3], step: 1, placeholder: String(rule[1]), help: __('Leave blank to use the automatic size or inherit the larger device.', 'certloom'), onChange: function (value) {changeDevice(key, value === '' ? '' : Number(value));}});})));
        panel.push(el(components.PanelBody, {key: 'colors', title: __('Custom colors and typography', 'certloom'), initialOpen: false},
          el(components.SelectControl, {label: __('Font family', 'certloom'), value: a.font_family || 'system', options: [{label: __('System sans serif', 'certloom'), value: 'system'}, {label: __('Serif', 'certloom'), value: 'serif'}, {label: __('Monospace', 'certloom'), value: 'mono'}], onChange: function(value) {set({font_family: value});}}),
          el(components.SelectControl, {label: __('Heading weight', 'certloom'), value: a.heading_weight || '700', options: ['400','500','600','700'].map(function(value) {return {label:value, value:value};}), onChange: function(value) {set({heading_weight: value});}}),
          el(components.SelectControl, {label: __('Panel shadow', 'certloom'), value: a.shadow || 'soft', options: [{label: __('None', 'certloom'), value:'none'}, {label: __('Soft', 'certloom'), value:'soft'}, {label: __('Elevated', 'certloom'), value:'elevated'}], onChange: function(value) {set({shadow:value});}}),
          Object.keys(settings.colors).map(function (key) {var rule = settings.colors[key]; return el(components.TextControl, {key: key, label: rule[0], type: 'color', value: (a.colors || {})[key] || rule[1], onChange: function (value) {var next = Object.assign({}, a.colors || {}); next[key] = value; set({colors: next});}});})));
      }
      return el(element.Fragment, {}, el(editor.InspectorControls, {}, panel), el('div', blockProps, el('div', {inert: '', className: 'certloom-studio-preview'}, el(SSR, {block: 'certloom/verification-studio', attributes: a, httpMethod: 'POST'}))));
    },
    save: function () {return null;}
  });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender, window.wp.i18n);
