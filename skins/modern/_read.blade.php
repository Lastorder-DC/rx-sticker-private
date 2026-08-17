@php
	$is_manager = ($logged_info && $logged_info->is_admin === 'Y') || $grant->manager;
	$is_owner = ($logged_info && $logged_info->member_srl == $sticker->member_srl) || $is_manager;
	$can_view_ip = $logged_info && (int)$logged_info->member_srl === 4;
	$publish_confirm_json = json_encode(
		(string)$lang->stkr_confirm_publish,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
	);
	$in_sale_period = (!$sticker->start_date || $sticker->start_date <= $date) && (!$sticker->end_date || $sticker->end_date > $date);
	$is_sold_out = $sticker->buy_limit > 0 && $sticker->bought_count >= $sticker->buy_limit;

	$exptime_year = $sticker->exptime >= 8760 ? (int)($sticker->exptime / 8760) : 0;
	$exptime_day = $sticker->exptime >= 24 ? (int)(($sticker->exptime - $exptime_year * 8760) / 24) : 0;
	$exptime_hour = (int)($sticker->exptime - $exptime_year * 8760 - $exptime_day * 24);
@endphp

<div class="stk-section stk-view">

	<h1 class="stk-view__title">{{ $sticker->title }}</h1>

	<div class="stk-view__meta">
		<span class="stk-view__author">{{ $sticker->nick_name }}</span>
		<span>{{ zdate($sticker->regdate, 'Y.m.d H:i') }}</span>
		<span>{{ $lang->stkr_read_count }} <b>{{ number_format($sticker->readed_count) }}</b></span>
		<span>{{ $lang->stkr_sale_count }} <b>{{ number_format($sticker->bought_count) }}</b></span>
		@if($can_view_ip)
			<span>IP {{ $sticker->ipaddress }}</span>
		@endif
	</div>

	@if($is_owner)
	<div class="stk-actions">
		<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'sticker_srl', $sticker->sticker_srl, 'act', 'dispStickerWrite') }}">{{ $lang->cmd_modify }}</a>
		<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'sticker_srl', $sticker->sticker_srl, 'act', 'dispStickerDelete') }}">{{ $lang->cmd_delete }}</a>
	</div>
	@endif

	@if($is_manager && $sticker->status === 'CHECK')
	<form action="./" method="post" class="stk-actions" onsubmit='return confirm({!! $publish_confirm_json !!});'>
		<input type="hidden" name="module" value="sticker" />
		<input type="hidden" name="mid" value="{{ $mid }}" />
		<input type="hidden" name="act" value="procStickerPublish" />
		<input type="hidden" name="sticker_srl" value="{{ $sticker->sticker_srl }}" />
		<input type="hidden" name="success_return_url" value="{{ getUrl('', 'mid', $mid, 'sticker_srl', $sticker->sticker_srl) }}" />
		<button type="submit" class="stk-btn stk-btn--primary">{{ $lang->stkr_publish_now }}</button>
	</form>
	@endif

	<div class="stk-view__content xe_content">
		<!--BeforeDocument({{ $sticker->sticker_srl }},{{ $sticker->member_srl }})-->
		{!! $sticker->content !!}
		<!--AfterDocument({{ $sticker->sticker_srl }},{{ $sticker->member_srl }})-->
	</div>

	@if($sticker->start_date || $sticker->end_date || $sticker->exptime || $sticker->buy_limit)
	<div class="stk-facts">
		@if($sticker->start_date)
		<div class="stk-facts__item">
			<span class="stk-facts__label">{{ $lang->stkr_sale_start_date }}</span>
			<span class="stk-facts__value">{{ zdate($sticker->start_date, 'Y.m.d H:i') }}</span>
		</div>
		@endif
		@if($sticker->end_date)
		<div class="stk-facts__item">
			<span class="stk-facts__label">{{ $lang->stkr_sale_end_date_label }}</span>
			<span class="stk-facts__value">{{ zdate($sticker->end_date, 'Y.m.d H:i') }}</span>
		</div>
		@endif
		@if($sticker->exptime)
		<div class="stk-facts__item">
			<span class="stk-facts__label">{{ $lang->stkr_use_period }}</span>
			<span class="stk-facts__value">{{ $lang->stkr_from_purchase_time }} @if($exptime_year){{ $exptime_year }}{{ $lang->stkr_year_unit }} @endif@if($exptime_day){{ $exptime_day }}{{ $lang->stkr_day_unit }} @endif@if($exptime_hour){{ $exptime_hour }}{{ $lang->stkr_hour_unit }}@endif</span>
		</div>
		@endif
		@if($sticker->buy_limit)
		<div class="stk-facts__item">
			<span class="stk-facts__label">{{ $lang->stkr_remaining_quantity }}</span>
			<span class="stk-facts__value">{{ max($sticker->buy_limit - $sticker->bought_count, 0) }}</span>
		</div>
		@endif
	</div>
	@endif

	@if(!empty($sticker_file))
	<ul class="stk-samples">
		@foreach($sticker_file as $file)
		@php
			$file_label = ($dot = strrpos($file->file_name, '.')) === false ? $file->file_name : substr($file->file_name, 0, $dot);
		@endphp
		<li class="stk-samples__item">
			@include('_media', ['url' => $file->url, 'alt' => $file_label, 'title' => $file_label, 'lazy' => true])
		</li>
		@endforeach
	</ul>
	@endif

	@if($sticker->tag)
	<ul class="stk-tags">
		@foreach(explode(',', $sticker->tag) as $tag)
			@if(trim($tag) !== '')
			<li><a class="stk-tags__item" href="{{ getUrl('', 'mid', $mid, 'search_target', 'tag', 'search_keyword', trim($tag)) }}">{{ trim($tag) }}</a></li>
			@endif
		@endforeach
	</ul>
	@endif

	<div class="stk-buy">
		@if($is_bougth)
			<button type="button" class="stk-btn stk-btn--lg js-stk-discard" data-mid="{{ $mid }}" data-sticker-srl="{{ $sticker->sticker_srl }}">{{ $lang->stkr_owned_discard }}</button>
		@elseif(!$logged_info)
			<a class="stk-btn stk-btn--primary stk-btn--lg" href="{{ getUrl('', 'mid', $mid, 'act', 'dispMemberLoginForm') }}">{{ $lang->stkr_login_to_purchase }}</a>
		@elseif($sticker->status === 'CHECK')
			<span class="stk-btn stk-btn--lg stk-btn--muted">{{ $lang->stkr_msg_under_review }}</span>
		@elseif($sticker->status !== 'PUBLIC')
			<span class="stk-btn stk-btn--lg stk-btn--muted">{{ $lang->stkr_msg_sale_paused }}</span>
		@elseif(!$in_sale_period)
			<span class="stk-btn stk-btn--lg stk-btn--muted">{{ $lang->stkr_msg_not_purchase_period }}</span>
		@elseif($is_sold_out)
			<span class="stk-btn stk-btn--lg stk-btn--muted">{{ $lang->stkr_msg_out_of_stock }}</span>
		@else
			<button type="button" class="stk-btn stk-btn--primary stk-btn--lg js-stk-buy" data-mid="{{ $mid }}" data-sticker-srl="{{ $sticker->sticker_srl }}" data-price="{{ $sticker->price }}">{{ $lang->stkr_buy_now }} ({{ number_format($sticker->price) }}P)</button>
		@endif
	</div>

	@if($logged_info)
	<div class="stk-actions">
		@if($is_blocked)
			<button type="button" class="stk-btn js-stk-unblock" data-mid="{{ $mid }}" data-sticker-srl="{{ $sticker->sticker_srl }}">{{ $lang->stkr_unblock }}</button>
		@else
			<button type="button" class="stk-btn js-stk-block" data-mid="{{ $mid }}" data-sticker-srl="{{ $sticker->sticker_srl }}">{{ $lang->stkr_block }}</button>
		@endif
	</div>
	@endif

</div>
