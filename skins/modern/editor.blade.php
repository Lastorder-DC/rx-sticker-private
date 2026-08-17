@include('_setting')

@php
	$is_edit = (bool)$sticker;
	$sticker_data = is_object($sticker) ? $sticker : new stdClass();
	$sticker_data->content = $sticker_data->content ?? '';
	$sticker_data->title = $sticker_data->title ?? '';
	$sticker_data->sticker_srl = $sticker_data->sticker_srl ?? '';
	$sticker_data->tag = $sticker_data->tag ?? '';

	// Map existing files by slot number. Slot 0 is the main image.
	$file_map = array();
	foreach($sticker_file as $file)
	{
		$file_map[$file->no] = $file;
	}
	ksort($file_map);

	// The server counts sticker_file[] separately, so include the main image in total limits.
	$min_total = $config->minUploads + 1;
	$max_total = $config->maxUploads + 1;

	$is_manager = (!empty($logged_info) && $logged_info->is_admin === 'Y') || $grant->manager;

	$quick_tags = array();
	foreach(explode(',', isset($config->quick_tags) ? $config->quick_tags : '') as $quick_tag)
	{
		$quick_tag = trim($quick_tag);
		if($quick_tag !== '' && !in_array($quick_tag, $quick_tags))
		{
			$quick_tags[] = $quick_tag;
		}
	}
@endphp

<div class="stk">
<div class="stk-section">

	<div class="stk-head">
		<h2 class="stk-head__title">{{ $is_edit ? $lang->stkr_edit_sticker : $lang->stkr_add_sticker }}</h2>
		<p class="stk-head__desc">{{ $is_edit ? $lang->stkr_edit_sticker_description : $lang->stkr_add_sticker_description }}</p>
	</div>

	@if(!empty($XE_VALIDATOR_MESSAGE) && empty($XE_VALIDATOR_ID))
		<p class="stk-msg stk-msg--error">{{ $XE_VALIDATOR_MESSAGE }}</p>
	@endif

	<form class="stk-write js-stk-form" method="post" action="{{ getUrl('', 'mid', $mid) }}" enctype="multipart/form-data">
		@csrf
		<input type="hidden" name="mid" value="{{ $mid }}" />
		<input type="hidden" name="act" value="procStickerInsert" />
		<input type="hidden" name="error_return_url" value="{{ getRequestUriByServerEnviroment() }}" />
		<input type="hidden" name="content" value="{{ $sticker_data->content }}" />
		<input type="hidden" name="price" value="0" />
		@if($is_edit)
			<input type="hidden" name="sticker_srl" value="{{ $sticker_data->sticker_srl }}" />
		@endif

		<input type="text" class="stk-write__title" name="title" value="{{ $sticker_data->title }}" placeholder="{{ $lang->stkr_title_placeholder }}" aria-label="{{ $lang->stkr_title_placeholder }}" />

		<h3 class="stk-write__label">{{ $lang->stkr_upload }}</h3>
		<div class="stk-uploader js-stk-uploader"
			data-mode="{{ $is_edit ? 'edit' : 'new' }}"
			data-mid="{{ $mid }}"
			data-sticker-srl="{{ $sticker_data->sticker_srl }}"
			data-min-total="{{ $min_total }}"
			data-max-total="{{ $max_total }}"
			data-max-slot="{{ $config->maxUploads }}"
			data-min-slot="{{ $config->minUploads }}"
			data-max-size="{{ $config->file_size }}">

			<ul class="stk-uploader__list">
				@foreach($file_map as $no => $file)
				<li class="stk-uploader__item{{ $no === 0 ? ' is-main' : '' }}"
					data-kind="existing"
					data-no="{{ $no }}"
					data-removable="{{ $no > $config->minUploads ? '1' : '0' }}">
					@include('_media', ['url' => $file->url, 'alt' => $file->file_name])
				</li>
				@endforeach
			</ul>

			<div class="stk-uploader__guide">
				<svg class="stk-uploader__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7.5 8.5 12 4l4.5 4.5M4 15v3.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V15"/></svg>
				<p class="stk-uploader__title">{{ $lang->stkr_upload_guide }}</p>
				<p class="stk-uploader__hint">{{ sprintf($lang->stkr_upload_hint, number_format($config->file_size), $min_total, $max_total) }}</p>
				<p class="stk-uploader__count">{!! sprintf($lang->stkr_upload_count, '<b class="js-stk-count">0</b>', $max_total) !!}</p>
			</div>

			<input type="file" class="stk-uploader__picker js-stk-picker" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4" multiple />
			<div class="stk-uploader__inputs js-stk-inputs" hidden></div>
		</div>

		<h3 class="stk-write__label">{{ $lang->stkr_description }}</h3>
		<div class="stk-editor">
			{!! $editor !!}
		</div>

		<h3 class="stk-write__label">{{ $lang->stkr_tag }}</h3>
		<div class="stk-tagger js-stk-tagger" data-max="10" data-maxlength="20">
			<p class="stk-tagger__hint">
				{{ $lang->stkr_tag_guide }}
			</p>

			@if($quick_tags)
			<div class="stk-tagger__block">
				<span class="stk-tagger__label">{{ $lang->stkr_quick_register }}</span>
				<div class="stk-tagger__quick">
					@foreach($quick_tags as $quick_tag)
					<button type="button" class="stk-tagger__preset js-stk-tagpreset" data-tag="{{ $quick_tag }}" title="{{ $lang->stkr_quick_tag_toggle }}">
						<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 5.4v5.2M5.4 8h5.2"/></svg>
						#{{ $quick_tag }}
					</button>
					@endforeach
				</div>
			</div>
			@elseif($is_manager)
			<div class="stk-tagger__block">
				<span class="stk-tagger__label">{{ $lang->stkr_quick_register }}</span>
				<p class="stk-tagger__empty">
					{{ $lang->stkr_no_quick_tags }}
					<a href="{{ getUrl('', 'module', 'admin', 'act', 'dispStickerAdminConfig') }}" target="_blank" rel="noopener">{{ $lang->stkr_add_quick_tag_template }}</a>
				</p>
			</div>
			@endif

			<div class="stk-tagger__block">
				<span class="stk-tagger__label">{{ $lang->stkr_direct_input }}</span>
				<div class="stk-tagger__field">
					<input type="text" class="stk-input js-stk-taginput" placeholder="{{ $lang->stkr_enter_tag }}" aria-label="{{ $lang->stkr_direct_input }}" autocomplete="off" />
					<button type="button" class="stk-btn js-stk-tagadd">{{ $lang->stkr_add }}</button>
				</div>
			</div>

			<ul class="stk-tagger__list js-stk-taglist"></ul>
			<input type="hidden" name="tags" class="js-stk-tagvalue" value="{{ $sticker_data->tag }}" />
		</div>

		<div class="stk-write__foot">
			<a class="stk-btn" href="{{ $is_edit ? getUrl('', 'mid', $mid, 'act', '', 'sticker_srl', $sticker_data->sticker_srl) : getUrl('', 'mid', $mid) }}">{{ $lang->stkr_back }}</a>
			<button type="submit" class="stk-btn stk-btn--primary">{{ $is_edit ? $lang->cmd_modify : $lang->stkr_register }}</button>
		</div>
	</form>

</div>
</div>
