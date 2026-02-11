(function($){
	var stickerLinkPattern = /(^|[?&])mid=sticker(&|$)/;
	var stickerSrlPattern = /(?:^|[?&])sticker_srl=([^&#]+)/;

	function getStickerInfoFromLink($link){
		var rawHref = $link.attr('href') || '';
		if(!rawHref) return null;

		var href = rawHref.replace(/&amp;/g, '&');
		if(!stickerLinkPattern.test(href) || !stickerSrlPattern.test(href)){
			return null;
		}

		var matches = href.match(stickerSrlPattern);
		var stickerSrl = (matches && matches[1]) ? matches[1] : null;

		if(stickerSrl) {
			stickerSrl = decodeURIComponent(stickerSrl);
		} else {
			return null;
		}

		return {
			href: href,
			stickerSrl: stickerSrl
		};
	}

	function closeStickerBlockOverlay(){
		jQuery('.sticker-block-overlay').remove();
		jQuery('.sticker-block-target').removeClass('sticker-block-target');
	}

	function openStickerBlockOverlay($link, stickerInfo){
		var $img = $link.find('img').first();
		if(!$img.length){
			return;
		}

		closeStickerBlockOverlay();

		$link.addClass('sticker-block-target');

		var $overlay = jQuery('<div class="sticker-block-overlay"></div>');
		var $blockButton = jQuery('<button type="button" class="sticker-overlay-btn sticker-overlay-btn-block" title="차단"><ion-icon name="ban-outline"></ion-icon></button>');
		var $moveButton = jQuery('<button type="button" class="sticker-overlay-btn sticker-overlay-btn-move" title="이동"><ion-icon name="link-outline"></ion-icon></button>');

		$blockButton.on('click', function(event){
			event.preventDefault();
			event.stopPropagation();

			if (stickerInfo.stickerSrl === 'undefined' || !stickerInfo.stickerSrl) {
				var imgSrc = $img[0].src;
				var targetUrl = imgSrc;

				if (imgSrc.indexOf('/files') !== -1) {
					targetUrl = '.' + imgSrc.substring(imgSrc.indexOf('/files'));
				}

				var params = {
					'sticker_src': targetUrl
				};

				exec_json('sticker.procStickerGetStickerSrl', params, function (response) {
					if(response.error) {
						alert(response.message);
						return;
					}

					if(response.sticker_srl) {
						stickerInfo.stickerSrl = response.sticker_srl;
						window.blockSticker(stickerInfo.stickerSrl);
					}
				});

			} else {
				window.blockSticker(stickerInfo.stickerSrl);
			}

			closeStickerBlockOverlay();
		});

		$moveButton.on('click', function(event){
			event.preventDefault();
			event.stopPropagation();

			if (stickerInfo.stickerSrl === 'undefined' || !stickerInfo.stickerSrl) {
				var imgSrc = $img[0].src;
				var targetUrl = imgSrc;

				if (imgSrc.indexOf('/files') !== -1) {
					targetUrl = '.' + imgSrc.substring(imgSrc.indexOf('/files'));
				}

				var params = {
					'sticker_src': targetUrl
				};

				exec_json('sticker.procStickerGetStickerSrl', params, function (response) {
					if(response.error) {
						alert(response.message);
						return;
					}

					if(response.sticker_srl) {
						var newHref = default_url.setQuery('mid', 'sticker').setQuery('sticker_srl', response.sticker_srl);
						window.open(newHref, '_blank');
					}
					
					closeStickerBlockOverlay();
				});

			} else {
				window.open(stickerInfo.href, '_blank');
				closeStickerBlockOverlay();
			}
		});

		$overlay.append($blockButton).append($moveButton);
		$link.append($overlay);
	}

	// 스티커 링크 클릭 이벤트 (오버레이 오픈)
	$(document).on('click', 'a[href*="mid=sticker"][href*="sticker_srl="]', function(e){
		var $link = jQuery(this);
		var stickerInfo = getStickerInfoFromLink($link);
		if(!stickerInfo || !$link.find('img').length){
			return;
		}

		e.preventDefault();
		e.stopPropagation();
		openStickerBlockOverlay($link, stickerInfo);
	});

	// 오버레이 내부 클릭 시 전파 방지
	$(document).on('click', '.sticker-block-overlay', function(e){
		e.stopPropagation();
	});

	// 화면 다른 곳 클릭 시 오버레이 닫기
	$(document).on('click', function(){
		closeStickerBlockOverlay();
	});

})(jQuery);

if(typeof window.blockSticker !== 'function'){
	window.blockSticker = function(sticker_srl){
		console.log('blockSticker called:', sticker_srl);
		// 실제 차단 로직(AJAX 등)을 여기에 구현하거나, 
		// 메인 로직에서 이 함수를 덮어씌워야 합니다.
	};
}