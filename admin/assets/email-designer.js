(function () {
	'use strict';
	var root = document.querySelector('[data-certloom-email-designer]');
	if (!root) return;
	var l10n = window.certloomEmailDesignerL10n || {};
	var blocksField = root.querySelector('[data-certloom-email-blocks]');
	var list = root.querySelector('[data-certloom-email-block-list]');
	var frame = root.querySelector('[data-certloom-email-preview]');
	var shell = root.querySelector('[data-certloom-email-preview-shell]');
	var previewLabel = root.querySelector('[data-certloom-email-preview-label]');
	var subjectField = root.querySelector('[data-certloom-email-subject]');
	var preheaderField = root.querySelector('[data-certloom-email-preheader]');
	var blocks = [];
	var lastTextInput = null;
	var dragIndex = null;
	try { blocks = JSON.parse(blocksField.value || '[]'); } catch (e) { blocks = []; }
	if (!Array.isArray(blocks)) blocks = [];

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; });
	}
	function tokenPreview(value) {
		var map = {
			'{{recipient_name}}': l10n.sampleRecipient || '', '{{program_title}}': l10n.sampleProgram || '', '{{issuer_name}}': l10n.sampleIssuer || '', '{{credential_number}}': 'CRT-2026-00421', '{{issue_date}}': '3 Sep 2026', '{{expiry_date}}': '3 Sep 2028', '{{grade}}': l10n.sampleGrade || '', '{{verification_url}}': 'https://example.com/verify/CRT-2026-00421', '{{verification_link}}': '<a href="https://example.com/verify/CRT-2026-00421">' + esc(l10n.verifyThisCredential || '') + '</a>'
		};
		var out = String(value || '');
		Object.keys(map).forEach(function (key) { out = out.split(key).join(map[key]); });
		return out;
	}
	function getTheme() {
		function v(name, fallback) { var el = root.querySelector('[name="theme_' + name + '"]'); return el && el.value ? el.value : fallback; }
		return {
			background: v('background','#f4f7f6'), content_background: v('content_background','#ffffff'), text_color: v('text_color','#24322e'), heading_color: v('heading_color','#173f34'), button_background: v('button_background','#0f6e56'), button_text: v('button_text','#ffffff'), link_color: v('link_color','#0f6e56'), font_family: v('font_family','Arial, Helvetica, sans-serif'), email_width: parseInt(v('email_width','640'),10) || 640, border_radius: parseInt(v('border_radius','12'),10) || 0
		};
	}
	function defaultBlock(type) {
		if (type === 'heading') return {type:'heading', text:l10n.headingPlaceholder || '', level:2};
		if (type === 'text') return {type:'text', html:'<p>' + esc(l10n.textPlaceholder || '') + '</p>'};
		if (type === 'button') return {type:'button', text:l10n.buttonText || '', url:l10n.buttonUrl || '{{verification_url}}', align:'left'};
		if (type === 'image') return {type:'image', url:'', alt:''};
		if (type === 'columns') return {type:'columns', left_html:'<p>' + esc(l10n.leftColumn || '') + '</p>', right_html:'<p>' + esc(l10n.rightColumn || '') + '</p>'};
		if (type === 'spacer') return {type:'spacer', height:24};
		return {type:'divider'};
	}
	function labelFor(type) {
		return {heading:l10n.addHeading,text:l10n.addText,button:l10n.addButton,image:l10n.addImage,divider:l10n.addDivider,spacer:l10n.addSpacer,columns:l10n.addColumns}[type] || l10n.blockType || type;
	}
	function inputHtml(block, i) {
		var body = '';
		if (block.type === 'heading') body = '<label><span>' + esc(l10n.addHeading || '') + '</span><input type="text" data-block-key="text" value="' + esc(block.text || '') + '"></label><label><span>' + esc(l10n.headingLevel || '') + '</span><select data-block-key="level"><option value="1"' + (parseInt(block.level,10)===1?' selected':'') + '>H1</option><option value="2"' + (parseInt(block.level,10)===2?' selected':'') + '>H2</option><option value="3"' + (parseInt(block.level,10)===3?' selected':'') + '>H3</option></select></label>';
		if (block.type === 'text') body = '<label class="is-wide"><span>' + esc(l10n.addText || '') + '</span><textarea rows="4" data-block-key="html">' + esc(block.html || '') + '</textarea></label>';
		if (block.type === 'button') body = '<label><span>' + esc(l10n.addButton || '') + '</span><input type="text" data-block-key="text" value="' + esc(block.text || '') + '"></label><label class="is-wide"><span>' + esc(l10n.urlLabel || '') + '</span><input type="text" data-block-key="url" value="' + esc(block.url || '') + '"></label>';
		if (block.type === 'image') body = '<label class="is-wide"><span>' + esc(l10n.urlLabel || '') + '</span><input type="url" data-block-key="url" value="' + esc(block.url || '') + '"></label><label><span>' + esc(l10n.altText || '') + '</span><input type="text" data-block-key="alt" value="' + esc(block.alt || '') + '"></label><button type="button" class="button" data-email-image>' + esc(l10n.chooseImage || '') + '</button>';
		if (block.type === 'columns') body = '<label><span>' + esc(l10n.leftColumn || '') + '</span><textarea rows="4" data-block-key="left_html">' + esc(block.left_html || '') + '</textarea></label><label><span>' + esc(l10n.rightColumn || '') + '</span><textarea rows="4" data-block-key="right_html">' + esc(block.right_html || '') + '</textarea></label>';
		if (block.type === 'spacer') body = '<label><span>' + esc(l10n.spacingPixels || '') + '</span><input type="number" min="8" max="80" data-block-key="height" value="' + esc(block.height || 24) + '"></label>';
		return '<article class="certloom-email-block-card" draggable="true" data-block-index="' + i + '"><header><span class="dashicons dashicons-move" aria-hidden="true"></span><strong>' + esc(labelFor(block.type)) + '</strong><div><button type="button" class="button-link" data-block-up aria-label="' + esc(l10n.moveUp || '') + '">↑</button><button type="button" class="button-link" data-block-down aria-label="' + esc(l10n.moveDown || '') + '">↓</button><button type="button" class="button-link-delete" data-block-remove>' + esc(l10n.remove || '') + '</button></div></header><div class="certloom-email-block-fields">' + body + '</div></article>';
	}
	function sync() {
		blocksField.value = JSON.stringify(blocks);
		renderPreview();
	}
	function renderBlocks() {
		lastTextInput = null;
		list.innerHTML = blocks.length ? blocks.map(inputHtml).join('') : '<div class="certloom-email-content-empty" role="status">' + esc(l10n.emptyContent || '') + '</div>';
		list.querySelectorAll('[data-block-index]').forEach(function (card) {
			var i = parseInt(card.getAttribute('data-block-index'),10);
			card.addEventListener('dragstart', function () { dragIndex = i; card.classList.add('is-dragging'); });
			card.addEventListener('dragend', function () { dragIndex = null; card.classList.remove('is-dragging'); });
			card.addEventListener('dragover', function (e) { e.preventDefault(); });
			card.addEventListener('drop', function (e) { e.preventDefault(); if (dragIndex === null || dragIndex === i) return; var moved=blocks.splice(dragIndex,1)[0]; blocks.splice(i,0,moved); renderBlocks(); sync(); });
			card.querySelectorAll('[data-block-key]').forEach(function (field) {
				field.addEventListener('focus', function () { lastTextInput = field; });
				field.addEventListener('input', function () { var key=field.getAttribute('data-block-key'); blocks[i][key] = field.type === 'number' ? parseInt(field.value,10) || 0 : field.value; sync(); });
			});
			var up=card.querySelector('[data-block-up]'); if(up) up.addEventListener('click',function(){if(i>0){var x=blocks.splice(i,1)[0];blocks.splice(i-1,0,x);renderBlocks();sync();}});
			var down=card.querySelector('[data-block-down]'); if(down) down.addEventListener('click',function(){if(i<blocks.length-1){var x=blocks.splice(i,1)[0];blocks.splice(i+1,0,x);renderBlocks();sync();}});
			var remove=card.querySelector('[data-block-remove]'); if(remove) remove.addEventListener('click',function(){blocks.splice(i,1);renderBlocks();sync();});
			var imageButton=card.querySelector('[data-email-image]'); if(imageButton) imageButton.addEventListener('click',function(){ if(!window.wp||!window.wp.media)return; var picker=window.wp.media({title:l10n.chooseImage||'',button:{text:l10n.useImage||''},multiple:false,library:{type:'image'}}); picker.on('select',function(){var a=picker.state().get('selection').first().toJSON(); blocks[i].url=a.url||''; blocks[i].alt=a.alt||a.title||''; renderBlocks();sync();}); picker.open(); });
		});
	}
	function renderPreview() {
		if (!frame) return;
		var theme=getTheme(), body='';
		blocks.forEach(function(block){
			if(block.type==='heading'){var l=Math.max(1,Math.min(3,parseInt(block.level,10)||2));var size=l===1?30:(l===2?24:19);body+='<h'+l+' style="margin:0 0 18px;color:'+esc(theme.heading_color)+';font-family:'+esc(theme.font_family)+';font-size:'+size+'px;line-height:1.25">'+esc(tokenPreview(block.text||''))+'</h'+l+'>';}
			else if(block.type==='text'){body+='<div style="margin:0 0 18px;color:'+esc(theme.text_color)+';font-family:'+esc(theme.font_family)+';font-size:16px;line-height:1.65">'+tokenPreview(block.html||'')+'</div>';}
			else if(block.type==='button'){body+='<div style="margin:4px 0 22px;text-align:'+esc(block.align||'left')+'"><a href="'+esc(tokenPreview(block.url||''))+'" style="display:inline-block;background:'+esc(theme.button_background)+';color:'+esc(theme.button_text)+';font-family:'+esc(theme.font_family)+';font-size:16px;font-weight:700;text-decoration:none;padding:13px 20px;border-radius:'+theme.border_radius+'px">'+esc(tokenPreview(block.text||''))+'</a></div>';}
			else if(block.type==='image'&&block.url){body+='<div style="margin:0 0 20px"><img src="'+esc(block.url)+'" alt="'+esc(block.alt||'')+'" style="display:block;max-width:100%;height:auto"></div>';}
			else if(block.type==='divider'){body+='<div style="border-top:1px solid #dce6e1;margin:24px 0"></div>';}
			else if(block.type==='spacer'){body+='<div style="height:'+(parseInt(block.height,10)||24)+'px"></div>';}
			else if(block.type==='columns'){body+='<table role="presentation" width="100%"><tr><td width="50%" valign="top" style="padding-right:10px;color:'+esc(theme.text_color)+';font-family:'+esc(theme.font_family)+'">'+tokenPreview(block.left_html||'')+'</td><td width="50%" valign="top" style="padding-left:10px;color:'+esc(theme.text_color)+';font-family:'+esc(theme.font_family)+'">'+tokenPreview(block.right_html||'')+'</td></tr></table>';}
		});
		var subject=tokenPreview(subjectField ? subjectField.value : ''); var preheader=tokenPreview(preheaderField ? preheaderField.value : '');
		var doc='<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https: http: data:; style-src \'unsafe-inline\';"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'+esc(subject)+'</title></head><body style="margin:0;background:'+esc(theme.background)+'"><div style="display:none;max-height:0;overflow:hidden">'+esc(preheader)+'</div><table role="presentation" width="100%" style="width:100%;background:'+esc(theme.background)+'"><tr><td align="center" style="padding:28px 14px"><table role="presentation" width="100%" style="width:100%;max-width:'+theme.email_width+'px;background:'+esc(theme.content_background)+';border-radius:'+theme.border_radius+'px"><tr><td style="padding:36px">'+body+'</td></tr></table></td></tr></table></body></html>';
		frame.srcdoc=doc;
	}

	root.querySelectorAll('[data-certloom-add-email-block]').forEach(function(button){button.addEventListener('click',function(){blocks.push(defaultBlock(button.getAttribute('data-certloom-add-email-block')));var contentStatus=root.querySelector('[data-certloom-email-content-status]');if(contentStatus){contentStatus.hidden=true;contentStatus.textContent='';}renderBlocks();sync();});});
	root.querySelectorAll('[data-certloom-email-token]').forEach(function(button){button.addEventListener('click',function(){var token='{{'+button.getAttribute('data-certloom-email-token')+'}}';var field=lastTextInput||subjectField;if(!field)return;var start=field.selectionStart==null?field.value.length:field.selectionStart;var end=field.selectionEnd==null?start:field.selectionEnd;field.value=field.value.slice(0,start)+token+field.value.slice(end);field.dispatchEvent(new Event('input',{bubbles:true}));field.focus();});});
	root.querySelectorAll('[data-certloom-email-theme], [data-certloom-email-subject], [data-certloom-email-preheader]').forEach(function(field){field.addEventListener('input',renderPreview);field.addEventListener('change',renderPreview);});
	root.querySelectorAll('[data-certloom-email-preview-mode]').forEach(function(button){button.addEventListener('click',function(){root.querySelectorAll('[data-certloom-email-preview-mode]').forEach(function(b){b.classList.toggle('is-current',b===button);});var mobile=button.getAttribute('data-certloom-email-preview-mode')==='mobile';if(shell)shell.classList.toggle('is-mobile',mobile);if(previewLabel)previewLabel.textContent=mobile?(l10n.mobile||''):(l10n.desktop||'');});});

	var assignment=document.querySelector('[data-certloom-email-assignment]');
	if(assignment){
		var scope=assignment.querySelector('[data-certloom-assignment-scope]'),purpose=assignment.querySelector('[data-certloom-assignment-purpose]'),design=assignment.querySelector('[data-certloom-assignment-design]'),assignmentStatus=assignment.querySelector('[data-certloom-assignment-status]'),assignmentSubmit=assignment.querySelector('[data-certloom-assignment-submit]');
		var updateAssignment=function(){
			var selected=scope?scope.value:'global';
			assignment.querySelectorAll('[data-certloom-assignment-target]').forEach(function(el){var target=el.getAttribute('data-certloom-assignment-target');el.hidden=selected==='batch'?(target!=='program'&&target!=='batch'):target!==selected;});
			var p=purpose?purpose.value:'', first='', available=0;
			Array.prototype.forEach.call(design.options,function(opt){var show=opt.getAttribute('data-purpose')===p;opt.hidden=!show;opt.disabled=!show;if(show){available++;if(!first)first=opt.value;}});
			if(!(design.selectedOptions.length&&!design.selectedOptions[0].disabled)&&first)design.value=first;
			if(assignmentSubmit)assignmentSubmit.disabled=available===0;
			if(assignmentStatus)assignmentStatus.textContent=available===0?(l10n.noCompatibleDesign||''):'';
		};
		if(scope)scope.addEventListener('change',updateAssignment);if(purpose)purpose.addEventListener('change',updateAssignment);updateAssignment();
	}

	var designerForm=root.querySelector('form[data-certloom-human-form]'),contentStatus=root.querySelector('[data-certloom-email-content-status]');
	if(designerForm){designerForm.addEventListener('submit',function(event){if(!blocks.length){event.preventDefault();if(contentStatus){contentStatus.textContent=l10n.contentBlocksRequired||'';contentStatus.hidden=false;}var blockList=root.querySelector('[data-certloom-email-block-list]');if(blockList){blockList.setAttribute('tabindex','-1');blockList.focus();}}});}

	renderBlocks(); sync();
})();
