@php
	$is_review_list = ($search_target === 'status' && $search_keyword === 'CHECK');
	$is_manager = ($logged_info && $logged_info->is_admin === 'Y') || $grant->manager;
	$skin_shop_name = trim((string)($module_info->shop_name ?? ''));
	$skin_notice = trim((string)($module_info->notice ?? ''));
	$sample_width = (int)($module_info->sb_width ?? 160);
	$sample_height = (int)($module_info->sb_height ?? 160);
	$title_cut = (int)($module_info->cut ?? 25);
	$sample_width = ($sample_width >= 40 && $sample_width <= 1000) ? $sample_width : 160;
	$sample_height = ($sample_height >= 40 && $sample_height <= 1000) ? $sample_height : 160;
	$title_cut = ($title_cut >= 1 && $title_cut <= 200) ? $title_cut : 25;
	$show_seller = ($module_info->seller ?? 'Y') !== 'N';
	$display_title = $skin_shop_name !== '' ? $skin_shop_name : $sticker_title;
@endphp

<div class="stk-section">

	<div class="stk-head">
		<h2 class="stk-head__title">@if($is_review_list){{ $lang->stkr_reviewing_stickers }}@else{{ $display_title }}@endif</h2>
		<div class="stk-head__desc">
			@if($is_review_list)
				{{ $lang->stkr_reviewing_description }}
			@elseif($skin_notice !== '')
				<div class="stk-head__notice">{!! $skin_notice !!}</div>
			@else
				{{ $sticker_subtitle }}
			@endif
		</div>
	</div>

	<div class="stk-tabs">
		<a class="stk-tabs__item{{ $sort !== 'popular' ? ' is-active' : '' }}" href="{{ getUrl('sticker_srl', '', 'page', '', 'sort', '') }}">{{ $lang->stkr_sort_latest }}</a>
		<a class="stk-tabs__item{{ $sort === 'popular' ? ' is-active' : '' }}" href="{{ getUrl('sticker_srl', '', 'page', '', 'sort', 'popular') }}">{{ $lang->stkr_sort_popular }}</a>
	</div>

	@if(empty($list))
		<p class="stk-empty">{{ $lang->stkr_no_registered_stickers }}</p>
	@else
		<ul class="stk-grid" style="--stk-card-width: {{ $sample_width }}px; --stk-card-height: {{ $sample_height }}px; --stk-card-aspect: {{ $sample_width }} / {{ $sample_height }};">
		@foreach($list as $item)
			<li class="stk-card">
				<a class="stk-card__thumb" href="{{ getUrl('', 'mid', $mid, 'sticker_srl', $item->sticker_srl) }}">
					@if($item->main_image)
						@include('_media', ['url' => $item->main_image, 'alt' => $item->title, 'lazy' => true])
					@endif
					@if($item->status === 'CHECK')
						<span class="stk-card__badge">{{ $lang->stkr_status_check }}</span>
					@elseif($item->status === 'PAUSE' || $item->status === 'STOP')
						<span class="stk-card__badge">{{ $lang->stkr_status_pause }}</span>
					@endif
				</a>
				<a class="stk-card__title" href="{{ getUrl('', 'mid', $mid, 'sticker_srl', $item->sticker_srl) }}">{{ cut_str($item->title, $title_cut) }}</a>
				@if($show_seller)<span class="stk-card__author">{{ $item->nick_name }}</span>@endif
			</li>
		@endforeach
		</ul>
	@endif

	<form class="stk-minisearch" method="get" action="{{ getUrl('', 'mid', $mid) }}">
		<input type="hidden" name="mid" value="{{ $mid }}" />
		<select name="search_target" class="stk-minisearch__select" aria-label="{{ $lang->stkr_search_target }}">
			<option value="title" @selected($search_target === 'title')>{{ $lang->sticker_title }}</option>
			<option value="content" @selected($search_target === 'content')>{{ $lang->stkr_content }}</option>
			<option value="nick_name" @selected($search_target === 'nick_name')>{{ $lang->stkr_nickname }}</option>
			<option value="tag" @selected($search_target === 'tag')>{{ $lang->stkr_tag }}</option>
		</select>
		<input type="text" name="search_keyword" class="stk-minisearch__input" placeholder="{{ $lang->stkr_search_stickers }}" aria-label="{{ $lang->stkr_search_keyword }}" value="{{ $is_review_list ? '' : $search_keyword }}" />
		<button type="submit" class="stk-minisearch__btn" aria-label="{{ $lang->stkr_search }}">
			<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14" stroke-linecap="round"/></svg>
		</button>
	</form>

	<div class="stk-actions stk-actions--split">
		<div>
			@if($is_review_list)
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid) }}">
					<svg class="stk-btn__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
					{{ $lang->stkr_all_list }}
				</a>
			@else
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'search_target', 'status', 'search_keyword', 'CHECK') }}">{{ $lang->stkr_review_list }}</a>
			@endif
		</div>
		<div>
			@if($is_manager)
				<a class="stk-btn" href="{{ getUrl('', 'module', 'admin', 'act', 'dispStickerAdminConfig') }}" target="_blank" rel="noopener">
					<svg class="stk-btn__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="8" cy="8" r="2.3"/><path d="M8 1.6v1.8M8 12.6v1.8M1.6 8h1.8M12.6 8h1.8M3.5 3.5l1.3 1.3M11.2 11.2l1.3 1.3M12.5 3.5l-1.3 1.3M4.8 11.2l-1.3 1.3" stroke-linecap="round"/></svg>
					{{ $lang->stkr_module_settings }}
				</a>
			@endif
			@if($grant->upload)
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'act', 'dispStickerWrite') }}">
					<svg class="stk-btn__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>
					{{ $lang->stkr_create_sticker }}
				</a>
			@endif
			@if($logged_info)
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'act', 'dispStickerMylist') }}">
					<svg class="stk-btn__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4.5" width="9" height="9" rx="1.6"/><path d="M5 4.5V3.6a1.6 1.6 0 0 1 1.6-1.6h6.2A1.2 1.2 0 0 1 14 3.2v6.2A1.6 1.6 0 0 1 12.4 11h-.9" stroke-linecap="round"/></svg>
					{{ $lang->cmd_sticker_mypage }}
				</a>
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'act', 'dispStickerMyBlock') }}">{{ $lang->stkr_block_list }}</a>
			@endif
		</div>
	</div>

	@if(!empty($page_navigation) && $page_navigation->last_page > 1)
	<nav class="stk-pagination">
		@foreach($page_navigation as $page_no)
			<a class="stk-pagination__item{{ $page_navigation->cur_page == $page_no ? ' is-active' : '' }}" href="{{ getUrl('sticker_srl', '', 'page', $page_no) }}">{{ $page_no }}</a>
		@endforeach
		@if($page_navigation->cur_page < $page_navigation->last_page)
			<a class="stk-pagination__item" href="{{ getUrl('sticker_srl', '', 'page', $page_navigation->last_page) }}">{{ $lang->last_page }}</a>
		@endif
	</nav>
	@endif

</div>
