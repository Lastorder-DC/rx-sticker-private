@include('_setting')

<div class="stk">
<div class="stk-section">

	<div class="stk-head">
		<h2 class="stk-head__title">{{ $lang->stkr_block_list }}</h2>
		<p class="stk-head__desc">{{ $lang->stkr_block_list_description }}</p>
	</div>

	@if(empty($sticker))
		<p class="stk-empty">{{ $lang->stkr_no_blocked_stickers }}</p>
	@else
	<table class="stk-mylist">
		<thead>
			<tr>
				<th scope="col">{{ $lang->stkr_number }}</th>
				<th scope="col">{{ $lang->sticker_title }}</th>
				<th scope="col">{{ $lang->sticker_config }}</th>
			</tr>
		</thead>
		<tbody>
		@foreach($sticker as $item)
			<tr>
				<td class="stk-mylist__no">{{ $loop->iteration }}</td>
				<td class="stk-mylist__name-cell">
					<a class="stk-mylist__name" href="{{ getUrl('', 'mid', $mid, 'act', '', 'sticker_srl', $item->sticker_srl) }}">
						@if($item->main_image)
							@include('_media', ['url' => $item->main_image, 'class' => 'stk-mylist__thumb', 'alt' => $item->title, 'lazy' => true])
						@endif
						<span>{{ $item->title }}</span>
					</a>
				</td>
				<td class="stk-mylist__actions" data-label="{{ $lang->sticker_config }}">
					<button type="button" class="stk-btn js-stk-unblock" data-mid="{{ $mid }}" data-sticker-srl="{{ $item->sticker_srl }}">{{ $lang->stkr_unblock }}</button>
				</td>
			</tr>
		@endforeach
		</tbody>
	</table>
	@endif

	<div class="stk-actions">
		<a class="stk-btn" href="{{ getUrl('', 'mid', $mid) }}">{{ $lang->sticker }}</a>
		<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'act', 'dispStickerMylist') }}">{{ $lang->cmd_sticker_mypage }}</a>
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
