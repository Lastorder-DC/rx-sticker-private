(function($, global){

	/* =========================================================
	 * 상태
	 *   - state.items[0] 가 '항상' 대표(메인). 모든 동작에서 이 불변식 유지.
	 *   - state.items[1..N] 은 갤러리 (sticker_file_1 ~ sticker_file_N)
	 * ========================================================= */
	var state = {
		items: [],
		isEdit: false,
		stickerSrl: 0,
		maxFiles: 100,
		minFiles: 1,
		maxFileSize: 0,
		allowedMimeTypes: [],
		minPrice: 0,
		maxPrice: 0
	};

	var dragSrcIdx = null;

	function uid() {
		return 'stk_' + Math.random().toString(36).slice(2, 11);
	}

	// 기존 파일 URL을 fetch하여 File 객체로 변환
	function urlToFile(url, fileName) {
		return fetch(url, { credentials: 'same-origin' })
			.then(function(res){
				if (!res.ok) throw new Error('HTTP ' + res.status + ' - ' + url);
				return res.blob();
			})
			.then(function(blob){
				var name = fileName || 'file';
				var type = blob.type || 'application/octet-stream';
				return new File([blob], name, { type: type });
			});
	}

	// items[0] 이 항상 대표가 되도록 isMain 플래그 정리
	function ensureMainInvariant() {
		if (state.items.length === 0) return;
		state.items.forEach(function(item, i){
			item.isMain = (i === 0);
		});
	}

	/* =========================================================
	 * 렌더링
	 * ========================================================= */
	function render() {
		var grid = document.getElementById('stickerGrid');
		var placeholder = document.getElementById('stickerPlaceholder');
		var countEl = document.getElementById('stickerCurrentCount');

		ensureMainInvariant();

		var oldTiles = grid.querySelectorAll('.sticker_tile');
		for (var k = 0; k < oldTiles.length; k++) {
			oldTiles[k].parentNode.removeChild(oldTiles[k]);
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

			// 클릭 → 대표 지정 (해당 항목을 맨 앞으로 이동)
			tile.addEventListener('click', function(){
				setMain(idx);
			});

			// 드래그 순서 변경
			tile.addEventListener('dragstart', function(e){
				dragSrcIdx = idx;
				tile.classList.add('is-dragging');
				try {
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData('text/plain', String(idx));
				} catch(_) {}
			});
			tile.addEventListener('dragend', function(){
				tile.classList.remove('is-dragging');
				var tiles = grid.querySelectorAll('.sticker_tile');
				for (var i = 0; i < tiles.length; i++) tiles[i].classList.remove('is-drag-over');
				dragSrcIdx = null;
			});
			tile.addEventListener('dragover', function(e){
				if (dragSrcIdx === null) return;
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

		if (countEl) countEl.textContent = state.items.length;

		if (state.items.length >= state.maxFiles) {
			placeholder.classList.add('is-hidden');
		} else {
			placeholder.classList.remove('is-hidden');
		}
	}

	/* =========================================================
	 * 파일 조작
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
				render();
			};
			reader.onerror = function(){ render(); };
			reader.readAsDataURL(file);
		});

		render();
	}

	function removeItem(idx) {
		state.items.splice(idx, 1);
		render();
	}

	// 클릭한 항목을 대표로 → 배열의 맨 앞으로 이동
	function setMain(idx) {
		if (idx <= 0 || idx >= state.items.length) return;
		var moved = state.items.splice(idx, 1)[0];
		state.items.unshift(moved);
		render();
	}

	// 드래그로 순서 변경
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
			e.preventDefault(); e.stopPropagation();
			zone.classList.add('is-drag-active');
		});
		zone.addEventListener('dragover', function(e){
			if (!isExternalFileDrag(e)) return;
			e.preventDefault(); e.stopPropagation();
			try { e.dataTransfer.dropEffect = 'copy'; } catch(_) {}
			zone.classList.add('is-drag-active');
		});
		zone.addEventListener('dragleave', function(e){
			if (e.target === zone || !zone.contains(e.relatedTarget)) {
				zone.classList.remove('is-drag-active');
			}
		});
		zone.addEventListener('drop', function(e){
			if (!isExternalFileDrag(e)) return;
			e.preventDefault(); e.stopPropagation();
			zone.classList.remove('is-drag-active');
			addFiles(e.dataTransfer.files);
		});

		zone.addEventListener('click', function(e){
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
			hidden.value = '';
		});
	}

	/* =========================================================
	 * 폼 제출
	 *   - state.items[0]                  → sticker_main_file
	 *   - state.items[1..N] 의 새 파일/기존 파일 모두 fetch 후
	 *     편집 모드: sticker_file_1, sticker_file_2, ...
	 *     신규 모드: sticker_file[]
	 * ========================================================= */
	function setupFormSubmit() {
		var form = document.querySelector('form.bd_wrt');
		if (!form) return;

		var submitting = false;

		form.addEventListener('submit', function(e){
			if (submitting) return true; // form.submit() 우회 호출은 통과
			e.preventDefault();

			// --- 검증 ---
			var titleEl = form.querySelector('input[name=title]');
			var title = titleEl ? (titleEl.value || '').trim() : '';
			if (!title) { alert('제목 값은 필수입니다.'); return false; }

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
			if (!content) { alert('내용 값은 필수입니다.'); return false; }

			if (state.items.length < Math.max(state.minFiles, 1)) {
				alert('최소 ' + Math.max(state.minFiles, 1) + '장 이상의 스티커 이미지를 업로드해주세요.');
				return false;
			}

			// --- 제출 버튼 lock ---
			var submitBtn = form.querySelector('input[type=submit], button[type=submit]');
			var originalBtnVal = null;
			if (submitBtn) {
				submitBtn.disabled = true;
				if (submitBtn.tagName === 'INPUT') {
					originalBtnVal = submitBtn.value;
					submitBtn.value = '업로드 중...';
				} else {
					originalBtnVal = submitBtn.textContent;
					submitBtn.textContent = '업로드 중...';
				}
			}

			function restoreBtn() {
				if (!submitBtn) return;
				submitBtn.disabled = false;
				if (submitBtn.tagName === 'INPUT') submitBtn.value = originalBtnVal;
				else submitBtn.textContent = originalBtnVal;
			}

			// --- 모든 item을 File 객체로 변환 ---
			var promises = state.items.map(function(item){
				if (item.type === 'new') return Promise.resolve(item.file);
				return urlToFile(item.url, item.fileName);
			});

			Promise.all(promises).then(function(files){
				var box = document.getElementById('dynamicFileInputs');
				box.innerHTML = '';

				try {
					// 대표 → sticker_main_file
					var mainInput = document.createElement('input');
					mainInput.type = 'file';
					mainInput.name = 'sticker_main_file';
					var dtM = new DataTransfer();
					dtM.items.add(files[0]);
					mainInput.files = dtM.files;
					box.appendChild(mainInput);

					// 갤러리 → sticker_file_1, sticker_file_2, ... (편집) / sticker_file[] (신규)
					for (var i = 1; i < files.length; i++) {
						var inp = document.createElement('input');
						inp.type = 'file';
						inp.name = state.isEdit ? ('sticker_file_' + i) : 'sticker_file[]';
						var dt = new DataTransfer();
						dt.items.add(files[i]);
						inp.files = dt.files;
						box.appendChild(inp);
					}
				} catch (err) {
					console.error(err);
					restoreBtn();
					alert('이 브라우저에서는 동적 파일 업로드를 지원하지 않습니다.');
					return;
				}

				submitting = true;
				form.submit();
			}).catch(function(err){
				console.error(err);
				restoreBtn();
				alert('이미지 처리 중 오류가 발생했습니다.\n' + (err && err.message ? err.message : err));
			});

			return false;
		});
	}

	/* =========================================================
	 * 초기화
	 * ========================================================= */
	$(document).ready(function(){
		state.maxFileSize     = global.stickerConfig.maxFileSize;
		state.allowedMimeTypes = global.stickerConfig.allowMIMEType;
		state.minPrice        = global.stickerConfig.minPrice;
		state.maxPrice        = global.stickerConfig.maxPrice;
		state.maxFiles        = global.stickerConfig.maxFiles || 100;
		state.minFiles        = global.stickerConfig.minFiles || 1;
		state.isEdit          = !!global.stickerConfig.isEdit;
		state.stickerSrl      = global.stickerConfig.stickerSrl || 0;

		// 기존 파일 로드: no==0 → 대표(맨 앞), no>=1 → 갤러리(no 오름차순)
		var existing = global.stickerConfig.existingFiles || [];
		var mainFile = null;
		var galleryFiles = [];
		existing.forEach(function(f){
			if (parseInt(f.no, 10) === 0) mainFile = f;
			else galleryFiles.push(f);
		});
		galleryFiles.sort(function(a, b){ return parseInt(a.no, 10) - parseInt(b.no, 10); });

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
				isMain: false
			});
		});

		setupDropZone();
		setupFormSubmit();
		render();
	});

})($, this);


/* =========================================================
 * 기존 AJAX 삭제 (구버전 호환)
 * ========================================================= */
function deleteFile(sticker_srl, no){
	var params = { mid: 'sticker', sticker_srl: sticker_srl, no: no };
	if (typeof Rhymix !== 'undefined' && Rhymix.ajax) {
		Rhymix.ajax('sticker.procStickerFileDelete', params, function(){});
	}
}