@include('_setting')

<div class="stk">
<div class="stk-section">

	<div class="stk-confirm">
		<h2 class="stk-confirm__title">{{ $lang->stkr_confirm_delete_sticker }}</h2>
		<p class="stk-confirm__target">{{ $sticker->title }} · {{ $sticker->nick_name }}</p>

		<form method="post" action="{{ getUrl('', 'mid', $mid) }}">
			@csrf
			<input type="hidden" name="mid" value="{{ $mid }}" />
			<input type="hidden" name="module" value="sticker" />
			<input type="hidden" name="act" value="procStickerDelete" />
			<input type="hidden" name="error_return_url" value="{{ getRequestUriByServerEnviroment() }}" />
			<input type="hidden" name="sticker_srl" value="{{ $sticker->sticker_srl }}" />
			<input type="hidden" name="success_return_url" value="{{ getNotEncodedUrl('', 'mid', $mid, 'sticker_srl', '', 'act', '') }}" />

			<div class="stk-confirm__buttons">
				<button type="submit" class="stk-btn stk-btn--danger">{{ $lang->cmd_delete }}</button>
				<a class="stk-btn" href="{{ getUrl('', 'mid', $mid, 'sticker_srl', $sticker->sticker_srl) }}">{{ $lang->cmd_cancel }}</a>
			</div>
		</form>
	</div>

</div>
</div>
