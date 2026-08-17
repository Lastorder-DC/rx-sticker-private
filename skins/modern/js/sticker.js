/**
 * Sticker default skin
 */
(function($) {
	'use strict';

	var $doc = $(document);
	var lang = window.stickerLang || {};
	var ALLOW_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'application/mp4', 'video/x-m4v'];

	function text(key, fallback) {
		return lang[key] || fallback || '';
	}

	function format(message) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(message).replace(/%s/g, function() {
			return args.length ? args.shift() : '';
		});
	}

	function escapeHtml(value) {
		return $('<span>').text(String(value || '')).html()
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function request(action, params, success, failure) {
		Rhymix.ajax(action, params, function(response) {
			if (response && response.error) {
				alert(response.message);
				if (failure) {
					failure(response);
				}
				return;
			}

			if (success) {
				success(response || {});
			}
		});
	}

	function reload() {
		window.location.reload();
	}

	/* Purchase from the detail view. */
	$doc.on('click', '.js-stk-buy', function() {
		var $btn = $(this);
		var price = $btn.data('price');

		if (!confirm(format(text('confirmPurchase', 'Spend %s points to purchase this sticker?'), price))) {
			return false;
		}

		$btn.prop('disabled', true);
		request('sticker.procStickerBuy', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl')
		}, function() {
			alert(text('purchaseComplete', 'Purchase completed.'));
			reload();
		}, function() {
			$btn.prop('disabled', false);
		});

		return false;
	});

	/* Discard an owned sticker from the detail view. */
	$doc.on('click', '.js-stk-discard', function() {
		var $btn = $(this);

		if (!confirm(text('confirmDiscard', 'Discard this owned sticker?'))) {
			return false;
		}

		$btn.prop('disabled', true);
		request('sticker.procStickerBuyDelete', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl')
		}, function() {
			alert(text('deleteComplete', 'Deleted.'));
			reload();
		}, function() {
			$btn.prop('disabled', false);
		});

		return false;
	});

	/* Change the order of owned stickers. */
	$doc.on('click', '.js-stk-move', function() {
		var $btn = $(this);

		request('sticker.procStickerBuyOrderChange', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl'),
			move: $btn.data('move')
		}, reload);

		return false;
	});

	/* Discard a sticker from the owned sticker list. */
	$doc.on('click', '.js-stk-mydelete', function() {
		var $btn = $(this);
		var title = $btn.data('title');

		if (!confirm(format(text('confirmDiscardNamed', 'Discard the owned sticker "%s"?'), title))) {
			return false;
		}

		request('sticker.procStickerBuyDelete', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl')
		}, function() {
			alert(text('deleteComplete', 'Deleted.'));
			reload();
		});

		return false;
	});

	$doc.on('click', '.js-stk-block', function() {
		var $btn = $(this);
		if (!confirm(text('confirmBlock', 'Block this sticker?'))) {
			return false;
		}

		$btn.prop('disabled', true);
		request('sticker.procStickerBlockInsert', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl')
		}, reload, function() {
			$btn.prop('disabled', false);
		});
		return false;
	});

	$doc.on('click', '.js-stk-unblock', function() {
		var $btn = $(this);
		if (!confirm(text('confirmUnblock', 'Unblock this sticker?'))) {
			return false;
		}

		$btn.prop('disabled', true);
		request('sticker.procStickerBlockDelete', {
			mid: $btn.data('mid'),
			sticker_srl: $btn.data('sticker-srl')
		}, reload, function() {
			$btn.prop('disabled', false);
		});
		return false;
	});

	/**
	 * Sticker uploader.
	 *
	 * Replace multiple file fields with one drag-and-drop area. Immediately before
	 * submission, create the file fields expected by the server and populate them
	 * through DataTransfer.
	 */
	function StickerUploader(root) {
		this.root = root;
		this.mode = root.getAttribute('data-mode');
		this.mid = root.getAttribute('data-mid');
		this.stickerSrl = root.getAttribute('data-sticker-srl');
		this.minTotal = parseInt(root.getAttribute('data-min-total'), 10);
		this.maxTotal = parseInt(root.getAttribute('data-max-total'), 10);
		this.maxSlot = parseInt(root.getAttribute('data-max-slot'), 10);
		this.minSlot = parseInt(root.getAttribute('data-min-slot'), 10);
		this.maxSize = parseInt(root.getAttribute('data-max-size'), 10) * 1024;

		this.list = root.querySelector('.stk-uploader__list');
		this.picker = root.querySelector('.js-stk-picker');
		this.inputs = root.querySelector('.js-stk-inputs');
		this.counter = root.querySelector('.js-stk-count');

		this.seq = 0;
		this.replaceTarget = null;
		this.items = this._readExistingItems();

		this._bind();
		this.render();
	}

	/* Import existing server-rendered images into uploader state. */
	StickerUploader.prototype._readExistingItems = function() {
		var self = this;

		return Array.prototype.map.call(this.list.querySelectorAll('.stk-uploader__item'), function(node) {
			var no = parseInt(node.getAttribute('data-no'), 10);

			return {
				id: 'e' + (self.seq++),
				kind: 'existing',
				no: no,
				url: node.querySelector('img, video').getAttribute('src'),
				main: no === 0,
				removable: node.getAttribute('data-removable') === '1',
				file: null
			};
		});
	};

	StickerUploader.prototype._bind = function() {
		var self = this;

		this.picker.addEventListener('change', function() {
			self.add(this.files);
			this.value = '';
		});

		this.root.addEventListener('click', function(e) {
			var action = e.target.closest('[data-action]');
			if (action) {
				e.preventDefault();
				e.stopPropagation();
				self[action.getAttribute('data-action')](action.closest('.stk-uploader__item').getAttribute('data-id'));
				return;
			}
			if (!e.target.closest('.stk-uploader__item')) {
				self.replaceTarget = null;
				self.picker.multiple = true;
				self.picker.click();
			}
		});

		['dragenter', 'dragover'].forEach(function(name) {
			self.root.addEventListener(name, function(e) {
				e.preventDefault();
				self.root.classList.add('is-dragover');
			});
		});

		['dragleave', 'drop'].forEach(function(name) {
			self.root.addEventListener(name, function(e) {
				e.preventDefault();
				if (name === 'dragleave' && self.root.contains(e.relatedTarget)) {
					return;
				}
				self.root.classList.remove('is-dragover');
			});
		});

		this.root.addEventListener('drop', function(e) {
			self.replaceTarget = null;
			self.add(e.dataTransfer.files);
		});
	};

	StickerUploader.prototype.add = function(fileList) {
		var files = Array.prototype.slice.call(fileList || []);
		var self = this;
		var rejected = [];

		files = files.filter(function(file) {
			var genericMp4 = (!file.type || file.type === 'application/octet-stream') && /\.mp4$/i.test(file.name);
			if ($.inArray(file.type, ALLOW_TYPES) === -1 && !genericMp4) {
				rejected.push(file.name + ' : ' + text('unsupportedFormat', 'Unsupported format'));
				return false;
			}
			if (file.size > self.maxSize) {
				rejected.push(file.name + ' : ' + text('fileTooLarge', 'File is too large'));
				return false;
			}
			return true;
		});

		if (rejected.length) {
			alert(text('filesRejected', 'The following files were excluded.') + '\n\n' + rejected.join('\n'));
		}

		/* Replace an existing image. */
		if (this.replaceTarget) {
			if (files.length) {
				var target = this._find(this.replaceTarget);
				target.file = files[0];
				target.url = URL.createObjectURL(files[0]);
			}
			this.replaceTarget = null;
			this.render();
			return;
		}

		var room = this.maxTotal - this.items.length;
		if (files.length > room) {
			alert(format(text('maxUploads', 'You can upload up to %s files.'), this.maxTotal));
			files = files.slice(0, Math.max(room, 0));
		}

		files.forEach(function(file) {
			self.items.push({
				id: 'n' + (self.seq++),
				kind: 'new',
				no: null,
				url: URL.createObjectURL(file),
				main: false,
				removable: true,
				file: file
			});
		});

		/* Use the first image as the main image when none is selected. */
		if (this.items.length && !this.items.some(function(item) { return item.main; })) {
			this.items[0].main = true;
		}

		this.render();
	};

	StickerUploader.prototype._find = function(id) {
		return this.items.filter(function(item) { return item.id === id; })[0];
	};

	StickerUploader.prototype.setMain = function(id) {
		var target = this._find(id);

		this.items.forEach(function(item) { item.main = (item === target); });
		this.render();
	};

	StickerUploader.prototype.replace = function(id) {
		this.replaceTarget = id;
		this.picker.multiple = false;
		this.picker.click();
	};

	StickerUploader.prototype.remove = function(id) {
		var self = this;
		var target = this._find(id);

		if (target.kind === 'new') {
			this._drop(target);
			return;
		}

		if (!target.removable) {
			alert(text('requiredImageNoDelete', 'A required image cannot be removed. Click it to replace it.'));
			return;
		}

		if (!confirm(text('confirmImageDelete', 'Delete this registered image? This cannot be undone.'))) {
			return;
		}

		request('sticker.procStickerFileDelete', {
			mid: this.mid,
			sticker_srl: this.stickerSrl,
			no: target.no
		}, function() {
			self._drop(target);
		});
	};

	StickerUploader.prototype._drop = function(target) {
		if (target.file) {
			URL.revokeObjectURL(target.url);
		}
		this.items.splice(this.items.indexOf(target), 1);
		if (this.items.length && !this.items.some(function(item) { return item.main; })) {
			this.items[0].main = true;
		}
		this.render();
	};

	StickerUploader.prototype.render = function() {
		var self = this;
		var html = '';

		this.items.forEach(function(item) {
			var classes = ['stk-uploader__item'];
			var safeUrl = escapeHtml(item.url);
			if (item.main) {
				classes.push('is-main');
			}
			if (item.kind === 'existing') {
				classes.push('is-existing');
			}

			html += '<li class="' + classes.join(' ') + '" data-id="' + item.id + '">';

			if ((item.file && (item.file.type === 'video/mp4' || item.file.type === 'application/mp4' || item.file.type === 'video/x-m4v')) || /\.mp4$/i.test((item.file && item.file.name) || item.url)) {
				html += '<video src="' + safeUrl + '" poster="' + escapeHtml(item.url.slice(0, -4) + '.webp') + '" autoplay muted loop playsinline></video>';
			}
			else {
				html += '<img src="' + safeUrl + '" alt="" />';
			}

			if (item.kind === 'new' || item.removable) {
				html += '<button type="button" class="stk-uploader__remove" data-action="remove" title="' + escapeHtml(text('delete', 'Delete')) + '">&times;</button>';
			}

			if (item.kind === 'existing') {
				html += '<button type="button" class="stk-uploader__replace" data-action="replace">' + escapeHtml(text('replace', 'Replace')) + '</button>';
			}

			if (item.main) {
				html += '<span class="stk-uploader__badge">' + escapeHtml(text('main', 'Main')) + '</span>';
			}
			else if (self.mode === 'new') {
				/* Main image selection is flexible on create; slot 0 can only be replaced on edit. */
				html += '<button type="button" class="stk-uploader__badge stk-uploader__badge--action" data-action="setMain">' + escapeHtml(text('setMain', 'Set as main')) + '</button>';
			}

			html += '</li>';
		});

		this.list.innerHTML = html;
		this.counter.textContent = this.items.length;
		this.root.classList.toggle('is-filled', this.items.length > 0);

		this._syncInputs();
	};

	/* Copy uploader state into the file fields expected by the server. */
	StickerUploader.prototype._syncInputs = function() {
		var self = this;
		var used = {};

		this.inputs.innerHTML = '';

		this.items.forEach(function(item) {
			if (item.kind === 'existing') {
				used[item.no] = true;
			}
		});

		this.items.forEach(function(item) {
			if (!item.file) {
				return;
			}

			var name;
			if (self.mode === 'new') {
				name = item.main ? 'sticker_main_file' : 'sticker_file[]';
			} else if (item.kind === 'existing') {
				name = item.no === 0 ? 'sticker_main_file' : 'sticker_file_' + item.no;
			} else if (item.main) {
				name = 'sticker_main_file';
			} else {
				name = 'sticker_file_' + self._takeSlot(used);
			}

			self.inputs.appendChild(self._makeInput(name, item.file));
		});
	};

	StickerUploader.prototype._takeSlot = function(used) {
		for (var i = 1; i <= this.maxSlot; i++) {
			if (!used[i]) {
				used[i] = true;
				return i;
			}
		}
		return this.maxSlot;
	};

	StickerUploader.prototype._makeInput = function(name, file) {
		var input = document.createElement('input');
		var transfer = new DataTransfer();

		transfer.items.add(file);
		input.type = 'file';
		input.name = name;
		input.files = transfer.files;

		return input;
	};

	StickerUploader.prototype.validate = function() {
		if (this.mode === 'new') {
			if (this.items.length < this.minTotal) {
				alert(format(text('minUploads', 'Upload at least %s sticker files.'), this.minTotal));
				return false;
			}
			if (!this.items.some(function(item) { return item.main; })) {
				alert(text('selectMain', 'Select a main image.'));
				return false;
			}
		}

		return true;
	};

	/**
	 * Tag input.
	 *
	 * Collect quick and manually entered tags into a comma-separated hidden field.
	 */
	function StickerTagger(root) {
		this.root = root;
		this.max = parseInt(root.getAttribute('data-max'), 10);
		this.maxLength = parseInt(root.getAttribute('data-maxlength'), 10);

		this.list = root.querySelector('.js-stk-taglist');
		this.input = root.querySelector('.js-stk-taginput');
		this.value = root.querySelector('.js-stk-tagvalue');

		this.tags = [];
		this._bind();
		this.add(this.value.value);
	}

	StickerTagger.prototype._bind = function() {
		var self = this;

		this.root.addEventListener('click', function(e) {
			var preset = e.target.closest('.js-stk-tagpreset');
			if (preset) {
				self.toggle(preset.getAttribute('data-tag'));
				return;
			}
			if (e.target.closest('.js-stk-tagadd')) {
				self.flush();
				return;
			}
			var remove = e.target.closest('.js-stk-tagremove');
			if (remove) {
				self.remove(remove.getAttribute('data-tag'));
			}
		});

		/**
		 * Process separators on input instead of keydown. IME composition fires keydown
		 * before committing text, so clearing on keydown can leave the final character behind.
		 */
		this.input.addEventListener('input', function() {
			if (!/[,\s]/.test(this.value)) {
				return;
			}

			var parts = this.value.split(/[,\s]+/);
			var rest = parts.pop();

			this.value = rest;
			self.add(parts.join(','));
		});

		this.input.addEventListener('keydown', function(e) {
			if (e.key !== 'Enter') {
				return;
			}

			// Prevent form submission even while IME composition is active.
			e.preventDefault();
			if (e.isComposing || e.keyCode === 229) {
				return;
			}

			self.flush();
		});
	};

	/**
	 * Commit the remaining input value as a tag.
	 */
	StickerTagger.prototype.flush = function() {
		this.add(this.input.value);
		this.input.value = '';
		this.input.focus();
	};

	StickerTagger.prototype._push = function(tag) {
		tag = String(tag).replace(/^#+/, '').trim();
		if (!tag || $.inArray(tag, this.tags) !== -1) {
			return true;
		}
		if (this.tags.length >= this.max) {
			return false;
		}

		this.tags.push(tag.slice(0, this.maxLength));
		return true;
	};

	StickerTagger.prototype._done = function(has_room) {
		if (!has_room) {
			alert(format(text('maxTags', 'You can add up to %s tags.'), this.max));
		}
		this.render();
	};

	/**
	 * Split a string containing separators into multiple tags.
	 */
	StickerTagger.prototype.add = function(text) {
		var self = this;
		var has_room = true;

		String(text || '').split(/[,\s]+/).forEach(function(tag) {
			has_room = self._push(tag) && has_room;
		});

		this._done(has_room);
	};

	/**
	 * Add an entire value that may contain spaces, such as a quick tag.
	 */
	StickerTagger.prototype.toggle = function(tag) {
		if ($.inArray(tag, this.tags) !== -1) {
			this.remove(tag);
			return;
		}

		this._done(this._push(tag));
	};

	StickerTagger.prototype.remove = function(tag) {
		var index = $.inArray(tag, this.tags);
		if (index !== -1) {
			this.tags.splice(index, 1);
			this.render();
		}
	};

	StickerTagger.prototype.render = function() {
		var html = '';
		var chosen = {};

		this.tags.forEach(function(tag) {
			chosen[tag] = true;
			html += '<li class="stk-tagger__tag">#' + escapeHtml(tag);
			html += '<button type="button" class="js-stk-tagremove" data-tag="' + escapeHtml(tag) + '" aria-label="' + escapeHtml(text('deleteTag', 'Delete tag')) + '">&times;</button></li>';
		});

		this.list.innerHTML = html;
		this.value.value = this.tags.join(', ');

		Array.prototype.forEach.call(this.root.querySelectorAll('.js-stk-tagpreset'), function(preset) {
			preset.classList.toggle('is-chosen', !!chosen[preset.getAttribute('data-tag')]);
		});
	};

	/* Create and edit forms. */
	$(function() {
		var root = document.querySelector('.js-stk-uploader');
		if (root) {
			root.uploader = new StickerUploader(root);
		}

		var tagger = document.querySelector('.js-stk-tagger');
		if (tagger) {
			new StickerTagger(tagger);
		}
	});

	$doc.on('submit', '.js-stk-form', function() {
		var $form = $(this);
		var title = $.trim($form.find('input[name=title]').val() || '');
		var price = parseInt($form.find('input[name=price]').val(), 10);
		var min = parseInt($form.find('input[name=price]').attr('min'), 10);
		var max = parseInt($form.find('input[name=price]').attr('max'), 10);
		var root = this.querySelector('.js-stk-uploader');

		if (!title) {
			alert(text('enterTitle', 'Enter a title.'));
			return false;
		}

		if (isNaN(price) || price < min || price > max) {
			alert(format(text('pointRange', 'Enter sale points between %sP and %sP.'), min, max));
			return false;
		}

		if (root && root.uploader && !root.uploader.validate()) {
			return false;
		}

		return true;
	});

})(jQuery);
