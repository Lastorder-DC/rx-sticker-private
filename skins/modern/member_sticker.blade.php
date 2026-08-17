@include('_setting')

<div class="stk">
<div class="stk-section">

	<div class="stk-head">
		<h2 class="stk-head__title">{{ $lang->stkr_my_stickers }}</h2>
		<p class="stk-head__desc">{{ $lang->stkr_my_stickers_description }}</p>
	</div>

	@if(empty($sticker))
		<p class="stk-empty">{{ $lang->stkr_no_owned_stickers }}</p>
	@else
	<table class="stk-mylist">
		<thead>
			<tr>
				<th scope="col">{{ $lang->stkr_rank }}</th>
				<th scope="col">{{ $lang->sticker }}</th>
				<th scope="col">{{ $lang->stkr_purchase_price }}</th>
				<th scope="col">{{ $lang->stkr_purchase_date }}</th>
				<th scope="col">{{ $lang->stkr_expiration_date }}</th>
				<th scope="col">{{ $lang->stkr_order }}</th>
			</tr>
		</thead>
		<tbody>
		@foreach($sticker as $no => $item)
			<tr>
				<td class="stk-mylist__no">{{ $loop->iteration }}</td>
				<td class="stk-mylist__name-cell">
					<a class="stk-mylist__name" href="{{ getUrl('', 'mid', $mid, 'act', '', 'page', '', 'sticker_srl', $item->sticker_srl) }}">
						@if($item->main_image)
							@include('_media', ['url' => $item->main_image, 'class' => 'stk-mylist__thumb', 'lazy' => true])
						@endif
						<span>{{ $item->title }}</span>
					</a>
				</td>
				<td data-label="{{ $lang->stkr_purchase_price }}">{{ number_format($item->use_point) }}P</td>
				<td data-label="{{ $lang->stkr_purchase_date }}">{{ zdate($item->regdate, 'Y.m.d') }}</td>
				<td data-label="{{ $lang->stkr_expiration_date }}">{{ $item->expdate ? zdate($item->expdate, 'Y.m.d') : $lang->stkr_unlimited_period }}</td>
				<td class="stk-mylist__actions">
					@if($page_navigation->total_count != $no)
					<button type="button" class="stk-mylist__ctrl js-stk-move" title="{{ $lang->stkr_move_up }}" data-mid="{{ $mid }}" data-sticker-srl="{{ $item->sticker_srl }}" data-move="up">
						<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 13V3M3.5 7.5 8 3l4.5 4.5"/></svg>
					</button>
					@endif
					@if($no > 1)
					<button type="button" class="stk-mylist__ctrl js-stk-move" title="{{ $lang->stkr_move_down }}" data-mid="{{ $mid }}" data-sticker-srl="{{ $item->sticker_srl }}" data-move="down">
						<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3v10M3.5 8.5 8 13l4.5-4.5"/></svg>
					</button>
					@endif
					<button type="button" class="stk-mylist__ctrl js-stk-mydelete" title="{{ $lang->cmd_trash }}" data-mid="{{ $mid }}" data-sticker-srl="{{ $item->sticker_srl }}" data-title="{{ $item->title }}">
						<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4M6.5 6.5v5M9.5 6.5v5"/></svg>
					</button>
				</td>
			</tr>
		@endforeach
		</tbody>
	</table>
	@endif

	<div class="stk-actions">
		<a class="stk-btn" href="{{ getUrl('', 'mid', $mid) }}">{{ $lang->sticker }}</a>
	</div>

	@if(!empty($page_navigation) && $page_navigation->last_page > 1)
	<nav class="stk-pagination">
		@foreach($page_navigation as $page_no)
			<a class="stk-pagination__item{{ $page_navigation->cur_page == $page_no ? ' is-active' : '' }}" href="{{ getUrl('page', $page_no) }}">{{ $page_no }}</a>
		@endforeach
	</nav>
	@endif

</div>
</div>
