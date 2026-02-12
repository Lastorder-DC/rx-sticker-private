(function($, global){

	/**
	 * 선택된 파일들을 검사하고 할당하는 메인 함수
	 * @param {Object} global - 글로벌 객체 (window)
	 * @param {FileList} selectedFiles - 사용자가 선택한 파일 목록
	 */
	function handleFileSelection(global, selectedFiles) {
		const maxFiles = 100;
		const maxFileSize = global.stickerConfig.maxFileSize;
		const allowedMimeTypes = global.stickerConfig.allowMIMEType;
		const visibleInputs = document.querySelectorAll('input[name="sticker_file[]"]');
		const fileCountDisplay = document.getElementById('fileCount');

		if (fileCountDisplay) {
			fileCountDisplay.textContent = selectedFiles.length;
		}

		if (selectedFiles.length === 0) {
			visibleInputs.forEach(input => {
				input.value = '';
			});
			return;
		}

		if (selectedFiles.length > maxFiles) {
			alert(`${maxFiles}개를 초과하는 파일은 선택할 수 없습니다. (현재 ${selectedFiles.length}개 선택됨)`);
			return;
		}

		for (let i = 0; i < selectedFiles.length; i++) {
			const file = selectedFiles[i];

			if (file.size > (maxFileSize << 10)) {
				alert(`'${file.name}' 파일의 용량이 너무 큽니다. (최대 ${maxFileSize}KB)`);
				return;
			}

			if (!allowedMimeTypes.includes(file.type)) {
				alert(`'${file.name}' 파일은 지원하지 않는 형식입니다.\n(허용 형식: ${allowedMimeTypes.join(', ')})`);
				return;
			}
		}
		
		visibleInputs.forEach(input => {
			input.value = ''; // value를 비워 파일 이름 표시를 지움
		});

		for (let i = 0; i < selectedFiles.length; i++) {
			if(visibleInputs[i]) {
				const dataTransfer = new DataTransfer();
				dataTransfer.items.add(selectedFiles[i]);
				visibleInputs[i].files = dataTransfer.files;
			}
		}
	}
	
	$(document).ready(function(){
		const maxFileSize = global.stickerConfig.maxFileSize;
		const allowMIMEType = global.stickerConfig.allowMIMEType;
		const minPrice = global.stickerConfig.minPrice;
		const maxPrice = global.stickerConfig.maxPrice;
		const selectBtn = $('#selectFilesBtn');
		const clearBtn = $('#clearFilesBtn');
		const hiddenInput = $('#hiddenMultiFileInput');

		selectBtn.on('click', function(e) {
			e.preventDefault();
			hiddenInput.value = '';
			hiddenInput.click();
		});

		clearBtn.on('click', function(e) {
			e.preventDefault();
			hiddenInput.value = '';
			handleFileSelection(global, []);
		});

		hiddenInput.change(function(event) {
			handleFileSelection(global, event.target.files);
		});

		$('.et_vars td>input[type=file]').bind('change', function() {
			var $this = this.files[0];
			if($this.size > (maxFileSize << 10)){
				alert('파일 용량은 '+maxFileSize+'KB를 초과할 수 없습니다.');
				$(this).val('');
				return false;
			}
			if($.inArray($this.type, allowMIMEType) == -1){
				alert('지원하지 않는 파일 형식입니다.');
				$(this).val('');
				return false;
			}
		});

		$('form.bd_wrt>.regist>input[type=submit]').on('click', function() {
			var title = $('form.bd_wrt input[name=title]').val();
			var price = parseInt($('form.bd_wrt input[name=price]').val());
			var content = $('.bd_wrt .get_editor iframe').contents().find('.xe_content').text();

			if(!title){
				return alert('제목 값은 필수입니다.'), false;
			}
			if(!(price <= maxPrice && price >= minPrice)){
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
		no : no
	};

	Rhymix.ajax('sticker.procStickerFileDelete', params, function(){
		//location.reload();
		var stk_sect = $(".et_vars.exForm input[name='sticker_file_"+no+"']").parent();
		if(stk_sect.length){
			stk_sect.find('span.attached_file, button.sticker_delete').remove();
		}
	});
}
