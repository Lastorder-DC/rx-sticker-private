(function($, global){

	/* =========================================================
	 * 상태
	 * ========================================================= */
	var state = {
		items: [],          // {id, type:'new'|'existing', file?, fileNo?, fileName?, dataUrl?, isMain}
		isEdit: false,
		stickerSrl: 0,
		maxFiles: 50,
		minFiles: 1,
		maxFileSize: 0,
		allowedMimeTypes: [],
		minPrice: 0,
		maxPrice: 0
	};

	var dragSrcIdx = null;  // 내부 드래그(순서 변경)용 인덱스

	function uid() {
		return 'stk_' + Math.random().toString(36).slice(2, 11);
	}

	/* =========================================================
	 * 렌더링
	 * ========================================================= */
	function render() {
		var grid = document.getElementById('stickerGrid');
		var placeholder = document.getElementById('stickerPlaceholder');
		var countEl = document.getElementById('stickerCurrentCount');

		// 기존 타일 제거 (placeholder는 유지)
		var oldTiles = grid.querySelectorAll('.sticker_tile');
		for (var k = 0; k < oldTiles.length; k++) {
			oldTiles[k].parentNode.removeChild(oldTiles[k]);
		}

		// 대표가 없으면 첫 번째 항목으로 자동 지정
		if (state.items.length > 0 && !state.items.some(function(i){ return i.isMain; })) {
			state.items[0].isMain = true;
		}

		state.items.forEach(function(item, idx){
			var tile = document.createElement('div');
			tile.className = 'sticker_tile' + (item.isMain ? ' is-main' : '');
			tile.draggable = true;
			tile.dataset.idx = idx;

			var img = document.createElement('img');
			img.src = item.dataUrl || item.url;
			img.alt = item.fileName || '';
			tile.appendChild(img);

			var badge = document.createElement('div');
			badge.className = 'sticker_tile_badge';
			badge.textContent = item.isMain ? '대표' : '대표 지정';
			tile.appendChild(badge);

			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'sticker_tile_delete';
			del.innerHTML = '&times;';
			del.setAttribute('aria-label', '삭제');
			del.addEventListener('click', function(e){
				e.stopPropagation();
				removeItem(idx);
			});
			tile.appendChild(del);

			// 클릭 시 대표 지정
			tile.addEventListener('click', function(){
				setMain(idx);
			});

			// 순서 변경 (드래그)
			tile.addEventListener('dragstart', function(e){
				dragSrcIdx = idx;
				tile.classList.add('is-dragging');
				try {
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData('text/plain', String(idx)); // FF 호환
				} catch(_) {}
			});
			tile.addEventListener('dragend', function(){
				tile.classList.remove('is-dragging');
				var tiles = grid.querySelectorAll('.sticker_tile');
				for (var i = 0; i < tiles.length; i++) tiles[i].classList.remove('is-drag-over');
				dragSrcIdx = null;
			});
			tile.addEventListener('dragover', function(e){
				if (dragSrcIdx === null) return; // 외부 파일 드롭은 zone이 처리
				e.preventDefault();
				try { e.dataTransfer.dropEffect = 'move'; } catch(_) {}
			});
			tile.addEventListener('dragenter', function(){
				if (dragSrcIdx !== null && dragSrcIdx !== idx) {
					tile.classList.add('is-drag-over');
				}
			});
			tile.addEventListener('dragleave', function(){
				tile.classList.remove('is-drag-over');
			});
			tile.addEventListener('drop', function(e){
				if (dragSrcIdx === null) return;
				e.preventDefault();
				e.stopPropagation();
				if (dragSrcIdx !== idx) {
					reorder(dragSrcIdx, idx);
				}
				dragSrcIdx = null;
			});

			grid.insertBefore(tile, placeholder);
		});

		if (countEl) {
			countEl.textContent = state.items.length;
		}

		// placeholder는 최대치 도달 시 숨김
		if (state.items.length >= state.maxFiles) {
			placeholder.classList.add('is-hidden');
		} else {
			placeholder.classList.remove('is-hidden');
		}
	}

	/* =========================================================
	 * 파일 추가/삭제/순서/대표
	 * ========================================================= */
	function addFiles(fileList) {
		if (!fileList || fileList.length === 0) return;

		var files = Array.from(fileList);
		var remain = state.maxFiles - state.items.length;
		if (remain <= 0) {
			alert('최대 ' + state.maxFiles + '장까지 업로드 가능합니다.');
			return;
		}
		if (files.length > remain) {
			alert('남은 업로드 가능 수량(' + remain + '장)을 초과합니다. 앞에서 ' + remain + '장만 추가됩니다.');
			files = files.slice(0, remain);
		}

		var validFiles = [];
		for (var i = 0; i < files.length; i++) {
			var f = files[i];
			if (f.size > (state.maxFileSize << 10)) {
				alert("'" + f.name + "' 파일의 용량이 너무 큽니다. (최대 " + state.maxFileSize + 'KB)');
				continue;
			}
			if (state.allowedMimeTypes.indexOf(f.type) === -1) {
				alert("'" + f.name + "' 파일은 지원하지 않는 형식입니다.\n(허용: " + state.allowedMimeTypes.join(', ') + ')');
				continue;
			}
			validFiles.push(f);
		}

		if (validFiles.length === 0) return;

		var pending = validFiles.length;
		validFiles.forEach(function(file){
			var item = {
				id: uid(),
				type: 'new',
				file: file,
				fileName: file.name,
				dataUrl: '',
				isMain: false
			};
			state.items.push(item);

			var reader = new FileReader();
			reader.onload = function(e){
				item.dataUrl = e.target.result;
				pending--;
				if (pending === 0) render();
			};
			reader.onerror = function(){
				pending--;
				if (pending === 0) render();
			};
			reader.readAsDataURL(file);
		});
	}

	function removeItem(idx) {
		var removed = state.items.splice(idx, 1)[0];
		if (removed && removed.isMain && state.items.length > 0) {
			state.items[0].isMain = true;
		}
		render();
	}

	function setMain(idx) {
		state.items.forEach(function(item, i){
			item.isMain = (i === idx);
		});
		render();
	}

	function reorder(fromIdx, toIdx) {
		if (fromIdx === toIdx) return;
		var moved = state.items.splice(fromIdx, 1)[0];
		state.items.splice(toIdx, 0, moved);
		render();
	}

	/* =========================================================
	 * 드롭존 / 파일 선택
	 * ========================================================= */
	function setupDropZone() {
		var zone = document.getElementById('stickerUploadZone');
		var hidden = document.getElementById('hiddenMultiFileInput');

		function isExternalFileDrag(e) {
			if (!e.dataTransfer || !e.dataTransfer.types) return false;
			var types = e.dataTransfer.types;
			for (var i = 0; i < types.length; i++) {
				if (types[i] === 'Files') return true;
			}
			return false;
		}

		zone.addEventListener('dragenter', function(e){
			if (!isExternalFileDrag(e)) return;
			e.preventDefault();
			e.stopPropagation();
			zone.classList.add('is-drag-active');
		});
		zone.addEventListener('dragover', function(e){
			if (!isExternalFileDrag(e)) return;
			e.preventDefault();
			e.stopPropagation();
			try { e.dataTransfer.dropEffect = 'copy'; } catch(_) {}
			zone.classList.add('is-drag-active');
		});
		zone.addEventListener('dragleave', function(e){
			// zone 바깥으로 나갔을 때만 해제
			if (e.target === zone || !zone.contains(e.relatedTarget)) {
				zone.classList.remove('is-drag-active');
			}
		});
		zone.addEventListener('drop', function(e){
			if (!isExternalFileDrag(e)) return;
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove('is-drag-active');
			addFiles(e.dataTransfer.files);
		});

		// 빈 영역(zone 또는 placeholder) 클릭 시 파일 선택
		zone.addEventListener('click', function(e){
			// 타일/타일 하위 요소 클릭은 제외
			var t = e.target;
			while (t && t !== zone) {
				if (t.classList && t.classList.contains('sticker_tile')) return;
				t = t.parentNode;
			}
			hidden.click();
		});

		hidden.addEventListener('change', function(){
			if (hidden.files && hidden.files.length > 0) {
				addFiles(hidden.files);
			}
			hidden.value = ''; // 같은 파일 다시 선택 가능하도록
		});
	}

	/* =========================================================
	 * 폼 제출
	 * ========================================================= */
	function setupFormSubmit() {
		var form = document.querySelector('form.bd_wrt');
		if (!form) return;

		form.addEventListener('submit', function(e){
			// 제목
			var titleEl = form.querySelector('input[name=title]');
			var title = titleEl ? (titleEl.value || '').trim() : '';
			if (!title) {
				e.preventDefault();
				alert('제목 값은 필수입니다.');
				return false;
			}

			// 본문 (에디터 iframe)
			var content = '';
			var iframe = form.querySelector('.get_editor iframe');
			if (iframe) {
				try {
					var inner = iframe.contentDocument || iframe.contentWindow.document;
					var xc = inner.querySelector('.xe_content');
					content = xc ? (xc.textContent || '').trim() : '';
				} catch(_) {}
			}
			if (!content) {
				var cIn = form.querySelector('input[name=content]');
				if (cIn) content = (cIn.value || '').trim();
			}
			if (!content) {
				e.preventDefault();
				alert('내용 값은 필수입니다.');
				return false;
			}

			// 최소 업로드 수
			if (state.items.length < state.minFiles) {
				e.preventDefault();
				alert('최소 ' + state.minFiles + '장 이상의 스티커 이미지를 업로드해주세요.');
				return false;
			}

			// 대표 지정 여부
			var mainIdx = -1;
			for (var i = 0; i < state.items.length; i++) {
				if (state.items[i].isMain) { mainIdx = i; break; }
			}
			if (mainIdx === -1) {
				e.preventDefault();
				alert('대표 이미지를 지정해주세요.');
				return false;
			}

			// 동적 input 컨테이너 초기화
			var box = document.getElementById('dynamicFileInputs');
			box.innerHTML = '';

			var mainItem = state.items[mainIdx];

			/* ---- sticker_main_file ----
			 *  - 대표 항목이 '새 파일'이면 그대로 업로드
			 *  - 대표 항목이 '기존 파일'이면 서버에 어느 기존 파일을 대표로 쓸지 hint 전달
			 */
			if (mainItem.type === 'new') {
				var mainInput = document.createElement('input');
				mainInput.type = 'file';
				mainInput.name = 'sticker_main_file';
				try {
					var dtM = new DataTransfer();
					dtM.items.add(mainItem.file);
					mainInput.files = dtM.files;
				} catch (err) {
					e.preventDefault();
					alert('이 브라우저에서는 동적 파일 업로드를 지원하지 않습니다.');
					return false;
				}
				box.appendChild(mainInput);
			} else {
				// 기존 파일을 대표로 지정한 경우, 서버에서 처리할 수 있도록 hint 전달
				var h = document.createElement('input');
				h.type = 'hidden';
				h.name = 'sticker_main_existing_no';
				h.value = String(mainItem.fileNo);
				box.appendChild(h);
			}

			/* ---- 갤러리 파일들 ----
			 *  편집 모드(isEdit): sticker_file_1 ~ sticker_file_N (위치별)
			 *  신규 모드:        sticker_file[] (배열)
			 *
			 *  편집 모드에서 기존 파일이 순서 변경된 경우를 서버가 알 수 있도록
			 *  sticker_file_layout[N] 힌트도 함께 전송 (값: 'existing:기존no' 또는 'new')
			 */
			state.items.forEach(function(item, idx){
				var pos = idx + 1;

				if (item.type === 'new') {
					var inp = document.createElement('input');
					inp.type = 'file';
					inp.name = state.isEdit ? ('sticker_file_' + pos) : 'sticker_file[]';
					try {
						var dt = new DataTransfer();
						dt.items.add(item.file);
						inp.files = dt.files;
					} catch (err) {
						return; // 무시
					}
					box.appendChild(inp);

					if (state.isEdit) {
						var hn = document.createElement('input');
						hn.type = 'hidden';
						hn.name = 'sticker_file_layout[' + pos + ']';
						hn.value = 'new';
						box.appendChild(hn);
					}
				} else {
					// existing
					if (state.isEdit) {
						var he = document.createElement('input');
						he.type = 'hidden';
						he.name = 'sticker_file_layout[' + pos + ']';
						he.value = 'existing:' + item.fileNo;
						box.appendChild(he);
					}
				}
			});

			return true;
		});
	}

	/* =========================================================
	 * 초기화
	 * ========================================================= */
	$(document).ready(function(){
		state.maxFileSize = global.stickerConfig.maxFileSize;
		state.allowedMimeTypes = global.stickerConfig.allowMIMEType;
		state.minPrice = global.stickerConfig.minPrice;
		state.maxPrice = global.stickerConfig.maxPrice;
		state.maxFiles = global.stickerConfig.maxFiles || 50;
		state.minFiles = global.stickerConfig.minFiles || 1;
		state.isEdit = !!global.stickerConfig.isEdit;
		state.stickerSrl = global.stickerConfig.stickerSrl || 0;

		// 기존 파일을 state.items에 로드
		// no==0 은 대표 이미지, no>=1 은 갤러리 이미지
		var existing = global.stickerConfig.existingFiles || [];
		var mainFile = null;
		var galleryFiles = [];
		existing.forEach(function(f){
			if (parseInt(f.no, 10) === 0) {
				mainFile = f;
			} else {
				galleryFiles.push(f);
			}
		});
		// 갤러리 파일은 no 오름차순 정렬
		galleryFiles.sort(function(a, b){ return parseInt(a.no, 10) - parseInt(b.no, 10); });

		// state.items 구성: 대표 파일을 맨 앞에, 그 뒤로 갤러리
		if (mainFile) {
			state.items.push({
				id: uid(),
				type: 'existing',
				fileNo: 0,
				fileName: mainFile.fileName,
				url: mainFile.url,
				isMain: true
			});
		}
		galleryFiles.forEach(function(f){
			state.items.push({
				id: uid(),
				type: 'existing',
				fileNo: parseInt(f.no, 10),
				fileName: f.fileName,
				url: f.url,
				isMain: !mainFile && state.items.length === 0 // 대표 파일 없으면 첫 갤러리를 대표로
			});
		});

		setupDropZone();
		setupFormSubmit();
		render();
	});

})($, this);


/* =========================================================
 * 기존 AJAX 삭제 (구버전 호환 - 외부에서 호출될 수 있음)
 * ========================================================= */
function deleteFile(sticker_srl, no){
	var params = {
		mid: 'sticker',
		sticker_srl: sticker_srl,
		no : no
	};
	if (typeof Rhymix !== 'undefined' && Rhymix.ajax) {
		Rhymix.ajax('sticker.procStickerFileDelete', params, function(){});
	}
}