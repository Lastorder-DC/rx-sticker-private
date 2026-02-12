(function($, global){
	$(document).ready(function(){
		var maxFileSize = global.stickerConfig.maxFileSize;
		var allowMIMEType = global.stickerConfig.allowMIMEType;
		var minPrice = global.stickerConfig.minPrice;
		var maxPrice = global.stickerConfig.maxPrice;

		function resetInputValue($input){
			$input.val('');
		}

		function validateSelectedFile(file){
			if(file.size > (maxFileSize << 10)){
				alert('파일 용량은 ' + maxFileSize + 'KB를 초과할 수 없습니다.');
				return false;
			}

			if($.inArray(file.type, allowMIMEType) === -1){
				alert('지원하지 않는 파일 형식입니다.');
				return false;
			}

			return true;
		}

		$('.et_vars td>input[type=file]').on('change', function(){
			var file = this.files[0];
			if(!file){
				return true;
			}

			if(!validateSelectedFile(file)){
				resetInputValue($(this));
				return false;
			}
		});

		$('form.bd_wrt>.regist>input[type=submit]').on('click', function(){
			var title = $('form.bd_wrt input[name=title]').val();
			var price = parseInt($('form.bd_wrt input[name=price]').val(), 10);
			var content = $('.bd_wrt .get_editor iframe').contents().find('.xe_content').text();

			if(!title){
				return alert('제목 값은 필수입니다.'), false;
			}
			if(!price || !(price <= maxPrice && price >= minPrice)){
				return alert('가격 값이 올바르지 않거나 존재하지 않습니다.'), false;
			}
			if(!content){
				return alert('내용 값은 필수입니다.'), false;
			}

			return true;
		});
	});

})($, this);

function deleteFile(sticker_srl, no){
	var params = {
		mid: 'sticker',
		sticker_srl: sticker_srl,
		no: no
	};

	Rhymix.ajax('sticker.procStickerFileDelete', params, function(){
		var $stickerSection = $(".et_vars.exForm input[name='sticker_file_" + no + "']").parent();
		if($stickerSection.length){
			$stickerSection.find('span.attached_file, button.sticker_delete').remove();
		}
	});
}
