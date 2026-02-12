(function($){
	var stickerLinkPattern = /(^|[?&])mid=sticker(&|$)/;
	var stickerSrlPattern = /(?:^|[?&])sticker_srl=([^&#]+)/;
	var stickerLinkSelector = 'a[href*="mid=sticker"][href*="sticker_srl="]';

	function getStickerInfoFromLink($link){
		var rawHref = $link.attr('href') || '';
		if(!rawHref){
			return null;
		}

		var href = rawHref.replace(/&amp;/g, '&');
		if(!stickerLinkPattern.test(href) || !stickerSrlPattern.test(href)){
			return null;
		}

		var matches = href.match(stickerSrlPattern);
		var stickerSrl = (matches && matches[1]) ? decodeURIComponent(matches[1]) : '';
		if(!stickerSrl){
			return null;
		}

		return {
			href: href,
			stickerSrl: stickerSrl
		};
	}

	function closeStickerBlockOverlay(){
		$('.sticker-block-overlay').remove();
		$('.sticker-block-target').removeClass('sticker-block-target');
	}

	function openStickerBlockOverlay($link, stickerInfo){
		var $img = $link.find('img').first();
		if(!$img.length){
			return;
		}

		closeStickerBlockOverlay();
		$link.addClass('sticker-block-target');

		var $overlay = $('<div class="sticker-block-overlay"></div>');
		var $blockButton = $('<button type="button" class="sticker-overlay-btn sticker-overlay-btn-block" title="차단"><ion-icon name="ban-outline"></ion-icon></button>');
		var $moveButton = $('<button type="button" class="sticker-overlay-btn sticker-overlay-btn-move" title="이동"><ion-icon name="link-outline"></ion-icon></button>');

		$blockButton.on('click', function(event){
			event.preventDefault();
			event.stopPropagation();
			blockSticker(stickerInfo.stickerSrl);
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

	function getCurrentStickerSrl(){
		var className = $('.xe_content[class*=sticker_]').attr('class') || '';
		return className.replace(/.*sticker_([0-9]+).*/, '$1');
	}

	function isNotLoggedIn($button){
		return $button.parent().hasClass('not_logged_in');
	}

	function requestBuySticker(stickerSrl){
		Rhymix.ajax('sticker.procStickerBuy', { mid: 'sticker', sticker_srl: stickerSrl }, function(){
			alert('구매하였습니다');
			location.reload();
		});
	}

	function requestDeleteSticker(stickerSrl, onSuccess){
		Rhymix.ajax('sticker.procStickerBuyDelete', { mid: 'sticker', sticker_srl: stickerSrl }, function(){
			alert('삭제하였습니다.');
			onSuccess();
		});
	}

	$(document).on('click', '.sticker_buy>span:not(.block_btn)', function(){
		var $button = $(this);
		var stickerSrl = getCurrentStickerSrl();

		if(isNotLoggedIn($button)){
			return alert('로그인 후 이용해주세요.'), false;
		}

		var buttonClass = $button.attr('class') || '';
		var btn = buttonClass.replace(/([a-z]+_btn).*/, '$1');

		if(btn === 'buy_btn'){
			var price = buttonClass.replace(/.*price_([0-9]+).*/, '$1');
			var text = (price == 0) ? '스티커를 추가하시겠습니까?' : '포인트 ' + price + '을 사용하여 스티커를 구매하시겠습니까?';
			if(confirm(text)){
				requestBuySticker(stickerSrl);
			}
		} else if(btn === 'throw_btn') {
			if(confirm('이 스티커를 삭제하시겠습니까?')){
				requestDeleteSticker(stickerSrl, function(){
					location.reload();
				});
			}
		} else {
			alert('구매가능한 상품이 아닙니다.');
		}

		return false;
	});

	$(document).on('click', '.stk_lnk.sticker_delete', function(){
		var $button = $(this);
		var stickerSrl = $button.attr('data-src');
		var title = $button.parent().parent().find('span.sticker_title').text();

		if(confirm('보유중인 ' + title + '을(를) 삭제하시겠습니까?')){
			requestDeleteSticker(stickerSrl, function(){
				getMyPage();
			});
		}

		return false;
	});

	$(document).on('click', '.stk_lnk.sticker_up, .stk_lnk.sticker_down', function(){
		var $button = $(this);
		var stickerSrl = $button.attr('data-src');
		var movePos = $button.hasClass('sticker_up') ? 'up' : 'down';

		moveStickerPos(stickerSrl, movePos);
		return false;
	});

	$(document).on('click', stickerLinkSelector, function(e){
		var $link = $(this);
		var stickerInfo = getStickerInfoFromLink($link);
		if(!stickerInfo || !$link.find('img').length){
			return;
		}

		e.preventDefault();
		e.stopPropagation();
		openStickerBlockOverlay($link, stickerInfo);
	});

	$(document).on('click', '.sticker-block-overlay', function(e){
		e.stopPropagation();
	});

	$(document).on('click', function(){
		closeStickerBlockOverlay();
	});

})($);

function completeDeleteSticker(ret_obj){
	var error = ret_obj.error;
	var message = ret_obj.message;
	var url = location.hostname.setQuery('mid', 'sticker');
	location.href = url;
}

function completeSearch(ret_obj, response_tags, params, fo_obj){
	fo_obj.submit();
}

function deleteSticker(sticker_srl){
	var msg = confirm('이 스티커를 삭제하시겠습니까?');

	msg && Rhymix.ajax('sticker.procStickerBuyDelete', { mid: 'sticker', sticker_srl: sticker_srl }, function(){
		alert('삭제하였습니다.');
		location.reload();
	});
}

function moveStickerPos(sticker_srl, pos){
	if(!sticker_srl || !pos || !(pos == 'up' || pos == 'down')){
		return alert('올바르지 않은 접근입니다.'), false;
	}

	var params = [];
	params.sticker_srl = sticker_srl;
	params.move = pos;
	Rhymix.ajax('sticker.procStickerBuyOrderChange', params, function(){
		getMyPage();
	});
}

function getMyPage(page){
	var href;
	if(page){
		href = '//' + window.location.hostname.setQuery('mid', 'sticker').setQuery('act', 'dispStickerMylist');
		href.setQuery('page', page);
	} else {
		href = location.href;
	}

	$.ajax({
		type: 'GET',
		dataType: 'html',
		url: href,
		success: function(response){
			var $response = $(response);
			$('.table thead').html($response.find('.table thead').html());
			$('.table tbody').html($response.find('.table tbody').html());
			$('.pagination').html($response.find('.pagination').html());
		},
		error: function(e){
			alert(e.responseText);
		}
	});
}

if(typeof window.blockSticker !== 'function'){
	window.blockSticker = function(sticker_srl){
		console.log('blockSticker called:', sticker_srl);
	};
}
