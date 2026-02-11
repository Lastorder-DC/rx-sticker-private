(function($){
	var stickerLinkPattern = /(^|[?&])mid=sticker(&|$)/;
	var stickerSrlPattern = /(?:^|[?&])sticker_srl=([^&#]+)/;

	function getStickerInfoFromLink($link){
		var href = $link.attr('href') || '';
		if(!href || !stickerLinkPattern.test(href) || !stickerSrlPattern.test(href)){
			return null;
		}

		var stickerSrl = decodeURIComponent(href.replace(stickerSrlPattern, '$1'));
		if(!stickerSrl){
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
			// 전역 함수 blockSticker 호출
			if(typeof window.blockSticker === 'function'){
				window.blockSticker(stickerInfo.stickerSrl);
			} else {
				console.log('blockSticker function is not defined.');
			}
			closeStickerBlockOverlay();
		});

		$moveButton.on('click', function(event){
			event.preventDefault();
			event.stopPropagation();
			window.open(stickerInfo.href, '_blank');
			closeStickerBlockOverlay();
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

// blockSticker 함수 정의 (기존 코드 하단에 있던 내용)
if(typeof window.blockSticker !== 'function'){
	window.blockSticker = function(sticker_srl){
		console.log('blockSticker called:', sticker_srl);
		// 실제 차단 로직(AJAX 등)을 여기에 구현하거나, 
		// 메인 로직에서 이 함수를 덮어씌워야 합니다.
	};
}