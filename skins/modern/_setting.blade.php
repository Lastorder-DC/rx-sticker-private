@load('./css/sticker.css')
@load('./js/sticker.js')
@php
	$sticker_js_lang = [
		'confirmPurchase' => $lang->stkr_confirm_purchase,
		'purchaseComplete' => $lang->stkr_purchase_complete,
		'confirmDiscard' => $lang->stkr_confirm_discard,
		'confirmDiscardNamed' => $lang->stkr_confirm_discard_named,
		'deleteComplete' => $lang->stkr_delete_complete,
		'confirmBlock' => $lang->stkr_confirm_block_sticker,
		'confirmUnblock' => $lang->stkr_confirm_unblock_sticker,
		'unsupportedFormat' => $lang->stkr_unsupported_format,
		'fileTooLarge' => $lang->stkr_file_too_large,
		'filesRejected' => $lang->stkr_files_rejected,
		'maxUploads' => $lang->stkr_max_uploads,
		'requiredImageNoDelete' => $lang->stkr_required_image_no_delete,
		'confirmImageDelete' => $lang->stkr_confirm_image_delete,
		'minUploads' => $lang->stkr_min_uploads,
		'selectMain' => $lang->stkr_select_main,
		'maxTags' => $lang->stkr_max_tags,
		'deleteTag' => $lang->stkr_delete_tag,
		'enterTitle' => $lang->stkr_enter_title,
		'pointRange' => $lang->stkr_point_range,
		'delete' => $lang->cmd_delete,
		'replace' => $lang->stkr_replace,
		'main' => $lang->stkr_main,
		'setMain' => $lang->stkr_set_main,
	];
	$sticker_js_lang_json = json_encode(
		$sticker_js_lang,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
	);
@endphp
<script>window.stickerLang = {!! $sticker_js_lang_json !!};</script>
