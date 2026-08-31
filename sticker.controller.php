<?php
/*! Copyright (C) 2016 BGM STORAGE. All rights reserved. */
use Rhymix\Framework\Cache;
use Rhymix\Framework\Security;
use Rhymix\Modules\Sticker\Services\ImageProcessor;

/**
 * @class  stickerController
 * @author Huhani (mmia268@gmail.com)
 * @brief  Sticker module controller class.
 */
class stickerController extends sticker
{
	/** @var array<int, array<int, array<int, int>>> Stickers awaiting post-save accounting. */
	protected $pendingDocumentStickers = array();

	function init(){
		//직접적으로 sticker모듈이 로딩되었을 때만 적용됨.
		$oStickerModel = stickerModel::getInstance();

		$this->module_config = $oStickerModel->getConfig();
		$this->module_config->start_time = date('YmdHis');
	}

	function triggerDeleteMember(&$obj){
		$member_srl = $obj->member_srl;
		if(!$member_srl){
			return new BaseObject();
		}
		executeQuery('sticker.deleteStickerBuyAllByMemberSrl', $obj);

		return new BaseObject();
	}

	function triggerBeforeModuleInit(&$obj){
		if(!Context::get('is_logged')){
			return new BaseObject();
		}

		$oStickerModel = stickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();

		if($module_config->add_member_menu === "Y"){
			$oMemberController = memberController::getInstance();
			$oMemberController->addMemberMenu('dispStickerMylist', 'cmd_sticker_mypage');
			$oMemberController->addMemberMenu('dispStickerMyBlock', 'stkr_block_list');
		}

		return new BaseObject();
	}

	function triggerMemberMenu(&$obj){
		$member_srl = Context::get('target_srl');
		$mid = Context::get('cur_mid');

		if(!$member_srl || !$mid) {
			return new BaseObject();
		}

		$logged_info = Context::get('logged_info');

		$oModuleModel = moduleModel::getInstance();
		$columnList = array('module');
		$cur_module_info = $oModuleModel->getModuleInfoByMid($mid, 0, $columnList);

		if(!$cur_module_info || $cur_module_info->module != 'sticker'){
			return new BaseObject();
		}

		if($logged_info && $member_srl == $logged_info->member_srl){
			$member_info = $logged_info;
		} else {
			$oMemberModel = memberModel::getInstance();
			$member_info = $oMemberModel->getMemberInfoByMemberSrl($member_srl);
		}

		if(!$member_info || !$member_info->user_id){
			return new BaseObject();
		}

		$url = getUrl('', 'mid', 'sticker', 'search_target', 'nick_name', 'search_keyword', $member_info->nick_name);
		$oMemberController = memberController::getInstance();
		$oMemberController->addMemberPopupMenu($url, 'cmd_view_own_sticker', '');

		return new BaseObject();
	}

	/**
	 * Validate and normalize stickers before inserting a document.
	 *
	 * @param object $obj Document arguments.
	 * @return BaseObject
	 */
	public function triggerBeforeInsertDocument(&$obj)
	{
		return $this->_processDocumentStickers($obj, false);
	}

	/**
	 * Validate and normalize stickers before updating a document.
	 *
	 * @param object $obj Document arguments.
	 * @return BaseObject
	 */
	public function triggerBeforeUpdateDocument(&$obj)
	{
		return $this->_processDocumentStickers($obj, true);
	}

	/**
	 * Account for stickers after a document has been inserted.
	 *
	 * @param object $obj Document arguments.
	 * @return BaseObject
	 */
	public function triggerAfterInsertDocument(&$obj)
	{
		return $this->_accountDocumentStickers($obj);
	}

	/**
	 * Account for newly added stickers after a document has been updated.
	 *
	 * @param object $obj Document arguments.
	 * @return BaseObject
	 */
	public function triggerAfterUpdateDocument(&$obj)
	{
		return $this->_accountDocumentStickers($obj);
	}

	/**
	 * Replace editor sticker placeholders with canonical, validated image tags.
	 *
	 * @param object $obj Document arguments.
	 * @param bool $is_update Whether an existing document is being updated.
	 * @return BaseObject
	 */
	protected function _processDocumentStickers(object $obj, bool $is_update)
	{
		$oStickerModel = stickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();
		if(($module_config->use ?? 'N') !== 'Y')
		{
			return new BaseObject();
		}

		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? intval($logged_info->member_srl) : 0;
		$stickers = array();
		$content = $this->_replaceUndefinedStickerSrlInContent((string)($obj->content ?? ''));
		$content = preg_replace('/{@sticker:[0-9]+\|[0-9]+}/i', '', $content);
		$content = preg_replace_callback(
			'/<img\b[^>]*\bdata-rx-sticker(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?[^>]*>/i',
			function($match) use (&$stickers, $member_srl, $logged_info)
			{
				if(!preg_match('/\bdata-rx-sticker\s*=\s*(["\'])([0-9]+)\|([0-9]+)\1/i', $match[0], $identity))
				{
					return '';
				}

				$sticker_srl = intval($identity[2]);
				$sticker_file_srl = intval($identity[3]);
				$is_admin = $logged_info && ($logged_info->is_admin ?? 'N') === 'Y';
				if((!$is_admin && !$this->_checkFakeSticker($sticker_srl, $sticker_file_srl, $member_srl)) || !$this->_checkUsableSticker($sticker_srl))
				{
					return '';
				}

				$output = $this->_getStickerComment($sticker_file_srl);
				if(!$output->toBool() || empty($output->data) || intval($output->data->sticker_srl) !== $sticker_srl)
				{
					return '';
				}

				$stickers[] = array($sticker_srl, $sticker_file_srl);
				return $this->_documentStickerTag($output->data, $match[0]);
			},
			$content
		);

		$limit = max(0, intval($module_config->doc_max_sticker_count ?? 30));
		if($limit && count($stickers) > $limit)
		{
			return new BaseObject(-1, 'msg_exceed_document_sticker_count');
		}

		$obj->content = $content;
		$pending = $stickers;
		if($is_update && !empty($obj->document_srl))
		{
			$document = DocumentModel::getDocument($obj->document_srl);
			$old_stickers = $this->_extractDocumentStickerIds($document ? (string)$document->get('content') : '');
			foreach($old_stickers as $identity)
			{
				$key = array_search($identity, $pending);
				if($key !== false)
				{
					unset($pending[$key]);
				}
			}
		}

		$this->pendingDocumentStickers[spl_object_id($obj)] = array_values($pending);
		return new BaseObject();
	}

	/**
	 * Increase usage counters and write logs after a document save succeeds.
	 *
	 * @param object $obj Document arguments.
	 * @return BaseObject
	 */
	protected function _accountDocumentStickers(object $obj)
	{
		$key = spl_object_id($obj);
		$stickers = $this->pendingDocumentStickers[$key] ?? array();
		unset($this->pendingDocumentStickers[$key]);

		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? intval($logged_info->member_srl) : 0;
		foreach($stickers as $identity)
		{
			$this->_increaseStickerUsedCount($identity[0], $identity[1], $member_srl);
			$log = new stdClass();
			$log->sticker_srl = $identity[0];
			$log->sticker_file_srl = $identity[1];
			$log->member_srl = $member_srl;
			$log->document_srl = intval($obj->document_srl ?? 0);
			$log->type = 'insertDocumentSticker';
			$this->insertStickerLog($log);
		}

		return new BaseObject();
	}

	/**
	 * Extract canonical sticker identities from stored document content.
	 *
	 * @param string $content Document content.
	 * @return array<int, array<int, int>>
	 */
	protected function _extractDocumentStickerIds(string $content): array
	{
		$stickers = array();
		preg_match_all('/\bdata-rx-sticker\s*=\s*(["\'])([0-9]+)\|([0-9]+)\1/i', $content, $matches, PREG_SET_ORDER);
		foreach($matches as $match)
		{
			$stickers[] = array(intval($match[2]), intval($match[3]));
		}

		return $stickers;
	}

	/**
	 * Build the canonical image tag stored in document content.
	 *
	 * @param object $data Sticker data.
	 * @param string $source_tag Submitted image tag.
	 * @return string
	 */
	protected function _documentStickerTag(object $data, string $source_tag): string
	{
		$width = 100;
		$height = 100;
		if(preg_match('/\bwidth\s*=\s*(["\']?)([0-9]+)\1/i', $source_tag, $match))
		{
			$width = min(100, max(24, intval($match[2])));
		}
		if(preg_match('/\bheight\s*=\s*(["\']?)([0-9]+)\1/i', $source_tag, $match))
		{
			$height = min(100, max(24, intval($match[2])));
		}
		if(preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $source_tag, $match))
		{
			if(preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+)px/i', $match[2], $size))
			{
				$width = min(100, max(24, intval($size[1])));
			}
			if(preg_match('/(?:^|;)\s*height\s*:\s*([0-9]+)px/i', $match[2], $size))
			{
				$height = min(100, max(24, intval($size[1])));
			}
		}

		$is_video = ImageProcessor::isMp4((string)$data->url);
		$src = $is_video ? ImageProcessor::getPosterUrl((string)$data->url) : (string)$data->url;
		return sprintf(
			'<img src="%s" alt="%s" width="%d" height="%d" style="width:%dpx;height:%dpx" data-rx-sticker="%d|%d" data-rx-sticker-type="%s">',
			htmlspecialchars($src, ENT_QUOTES, 'UTF-8', false),
			htmlspecialchars((string)$data->title, ENT_QUOTES, 'UTF-8', false),
			$width,
			$height,
			$width,
			$height,
			intval($data->sticker_srl),
			intval($data->sticker_file_srl),
			$is_video ? 'video' : 'image'
		);
	}

	function triggerBeforeInsertComment(&$obj){

		$oStickerModel = stickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();

		if($module_config->use != "Y"){
			return new BaseObject();
		}

		$logged_info = Context::get('logged_info');

		$member_srl = $logged_info ? $logged_info->member_srl : 0;
		$obj->content = $this->_replaceUndefinedStickerSrlInContent($obj->content);
		$content = html_entity_decode($obj->content);
		preg_match('/{@sticker:([0-9]+)\|([0-9]+)}/i', $content, $match);
		if(!empty($match)){
			$checkFake = $this->_checkFakeSticker($match[1], $match[2], $member_srl);
			if(!$checkFake){
				return new BaseObject(-1,'invalid sticker');
			}

			$isUsable = $this->_checkUsableSticker($match[1]);
			if(!$isUsable){
				return new BaseObject(-1,'disalbe sticker');
			}

			if($module_config->cmt_max_sticker_count != 0){
				$writeStickeCount = $oStickerModel->getCommentSticekrCountByDocumentSrl($obj->document_srl, $member_srl);
				if($writeStickeCount >= intval($module_config->cmt_max_sticker_count)){
					return new BaseObject(-1,'msg_exceed_wrote_sticker_count');
				}
			}


			$obj->content = "{@sticker:".$match[1]."|".$match[2]."}";


			$this->_increaseStickerUsedCount($match[1], $match[2], $member_srl);
		} else {
			$isHiddenSticker = $this->_checkStickerInContent($content);
			if($isHiddenSticker){
				return new BaseObject(-1,'invalid sticker');
			}
		}

	}

	function triggerBeforeUpdateComment(&$obj){

		$oStickerModel = stickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();

		if($module_config->use != "Y"){
			return new BaseObject();
		}

		$logged_info = Context::get('logged_info');

		if($module_config->cmt_allow_modify === "N" && (!$logged_info || ($logged_info && !$logged_info->is_admin) )){
			$oCommentModel = commentModel::getInstance();
			$oComment = $oCommentModel->getComment($obj->comment_srl);

			if($oComment && $oComment->isExists()){
				if(preg_match('/{@sticker:[0-9]+\|[0-9]+}/i', $oComment->content)){
					return new BaseObject(-1,'msg_invalid_update_comment');
				}
			}
		}

		$member_srl = $logged_info ? $logged_info->member_srl : 0;
		$obj->content = $this->_replaceUndefinedStickerSrlInContent($obj->content);
		$content = html_entity_decode($obj->content);
		preg_match('/{@sticker:([0-9]+)\|([0-9]+)}/i', $content, $match);
		if(!empty($match)){
			$checkFake = $this->_checkFakeSticker($match[1], $match[2], $member_srl);
			if(!$checkFake){
				return new BaseObject(-1,'invalid sticker');
			}
			if($module_config->cmt_max_sticker_count != 0){
				$writeStickeCount = $oStickerModel->getCommentSticekrCountByDocumentSrl($obj->document_srl, $member_srl);
				if($writeStickeCount > intval($module_config->cmt_max_sticker_count)){
					return new BaseObject(-1,'msg_exceed_wrote_sticker_count');
				}
			}

			$obj->content = "{@sticker:".$match[1]."|".$match[2]."}";
		} else {
			$isHiddenSticker = $this->_checkStickerInContent($content);
			if($isHiddenSticker){
				return new BaseObject(-1,'invalid sticker');
			}
		}

	}


	function triggerBeforeDisplay(&$obj){
		$is_content_object = is_object($obj) && isset($obj->content);
		$content = $is_content_object ? $obj->content : $obj;

		$content = $this->_replaceUndefinedStickerSrlInContent($content);

		if(Context::get('document_srl')){
			$temp_output = preg_replace_callback('/<!--BeforeComment\(([0-9]+),([0-9]+)\)-->.*?{@sticker:([0-9]+)\|([0-9]+)}.*?<!--AfterComment\([0-9]+,[0-9]+\)-->/s', array($this, 'stickerCommentCallback'), $content);
			if($temp_output){
				$content = $temp_output;
			}
		}

		$content = preg_replace_callback(
			'/<a\b[^>]*>\s*<img\b[^>]*\bdata-rx-sticker(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?[^>]*>\s*<\/a>|<img\b[^>]*\bdata-rx-sticker(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?[^>]*>/i',
			array($this, 'stickerDocumentCallback'),
			$content
		);
		$content = $this->_replaceBlockedStickerImgInContent($content);

		if($is_content_object){
			$obj->content = $content;
		} else {
			$obj = $content;
		}

		return new BaseObject();
	}

	function _replaceBlockedStickerImgInContent($content){
		if(!$content){
			return $content;
		}

		$logged_info = Context::get('logged_info');
		if(!$logged_info || !$logged_info->member_srl){
			return $content;
		}

		return preg_replace_callback('/<a\b[^>]*href=("|\')\/\?mid=sticker(?:&amp;|&)sticker_srl=([0-9]+)\1[^>]*>.*?<img\b[^>]*>.*?<\/a>/is', array($this, '_replaceBlockedStickerImgAnchorCallback'), $content);
	}

	function _replaceBlockedStickerImgAnchorCallback($matches){
		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? $logged_info->member_srl : 0;
		$sticker_srl = intval($matches[2]);

		if(!$this->_isBlockedSticker($member_srl, $sticker_srl)){
			return $matches[0];
		}

		$blocked_img_src = './modules/sticker/skins/default/blocked.png';
		return preg_replace('/(<img\b[^>]*\bsrc=)(["\']).*?\2/i', '$1$2'.$blocked_img_src.'$2', $matches[0], 1);
	}

	function stickerCommentCallback($matches){
		$output = $this->_getStickerComment($matches[4]);
		$part = "";
		if(!empty($output->data)){
			$data = $output->data;
			$file_name = (string)$data->file_name;
			$extension_pos = strrpos($file_name, ".");
			$file_name = $extension_pos === false ? $file_name : substr($file_name, 0, $extension_pos);

			$url = htmlspecialchars((string)$data->url, ENT_QUOTES, 'UTF-8', false);
			$title = htmlspecialchars((string)$data->title, ENT_QUOTES, 'UTF-8', false);
			$file_name = htmlspecialchars($file_name, ENT_QUOTES, 'UTF-8', false);
			$sticker_url = htmlspecialchars(getNotEncodedUrl('', 'mid', 'sticker', 'sticker_srl', $data->sticker_srl), ENT_QUOTES, 'UTF-8', false);
			if(empty($_COOKIE['txtmode'])){
				$logged_info = Context::get('logged_info');
				$member_srl = $logged_info ? $logged_info->member_srl : 0;
				if($this->_isBlockedSticker($member_srl, $data->sticker_srl)){
					$blocked_url = htmlspecialchars('./modules/sticker/skins/default/blocked.png', ENT_QUOTES, 'UTF-8', false);
					$media = '<img src="'.$blocked_url.'" style="width:120px;height:120px;border-radius:3px;" alt="'.$file_name.'">';
				} else if(ImageProcessor::isMp4($data->url)){
					$poster = htmlspecialchars(ImageProcessor::getPosterUrl($data->url), ENT_QUOTES, 'UTF-8', false);
					$media = '<video src="'.$url.'" poster="'.$poster.'" autoplay muted loop playsinline preload="metadata" style="width:120px;height:120px;object-fit:cover;border-radius:3px;"></video>';
				} else {
					$media = '<img src="'.$url.'" style="width:120px;height:120px;border-radius:3px;" alt="'.$file_name.'">';
				}
				$part = '<!--BeforeComment('.$matches[1].','.$matches[2].')--><div class="comment_'.$matches[1].'_'.$matches[2].' xe_content"><a href="'.$sticker_url.'" title="'.$title.'">'.$media.'</a></div><!--AfterComment('.$matches[1].','.$matches[2].')-->';
			} else {
				$data_saver_label = htmlspecialchars((string)Context::getLang('stkr_data_saver_active'), ENT_QUOTES, 'UTF-8', false);
				$part = '<!--BeforeComment('.$matches[1].','.$matches[2].')--><div class="txtmode comment_'.$matches[1].'_'.$matches[2].' xe_content"><p style="margin:1em;">'.$data_saver_label.'<br><a href="'.$sticker_url.'" target="_blank" rel="noopener" style="color:#777;">('.$title.')</a></p></div><!--AfterComment('.$matches[1].','.$matches[2].')-->';
			}

		} else {
			$delete_msg = $this->_getStickerDeleteMsg();
			$part = '<!--BeforeComment('.$matches[1].','.$matches[2].')--><div class="comment_'.$matches[1].'_'.$matches[2].' xe_content">'.$delete_msg.'</div><!--AfterComment('.$matches[1].','.$matches[2].')-->';
		}
		return $part;
	}

	function procStickerCommentInsert(){

	}

	function procStickerBuy(){
		$sticker_srl = Context::get('sticker_srl');
		$logged_info = Context::get('logged_info');

		if(!$logged_info || !$sticker_srl){
			return new BaseObject(-1,'msg_invalid_access');
		}
		$member_srl = $logged_info->member_srl;

		if(!$this->grant->buy){
			return new BaseObject(-1,'msg_access_denied');
		}

		$oStickerModel = stickerModel::getInstance();
		$sticker = $oStickerModel->getSticker($sticker_srl);
		if(!$sticker){
			return new BaseObject(-1,'msg_invalid_sticker');
		}

		$start_date = (int)$sticker->start_date;
		$end_date = (int)$sticker->end_date;
		$date = (int)$this->module_config->start_time;
		$bought_count = $sticker->bought_count;
		$buy_limit = $sticker->buy_limit;
		$status = $sticker->status;
		if($buy_limit > 0 && $bought_count >= $buy_limit){
			return new BaseObject(-1,'msg_sold_out_sticker');
		}
		if(($start_date && $date < $start_date) ||
			($end_date && $date > $end_date)
		){
			return new BaseObject(-1,'msg_not_sale_date');
		}
		if($status != "PUBLIC"){
			return new BaseObject(-1,'msg_not_sale_sticker');
		}

		$checkBuySticker = $oStickerModel->checkBuySticker($member_srl, $sticker_srl);
		if($checkBuySticker){
			return new BaseObject(-1,'msg_already_bought_sticker');
		}

		$isDefaultSticker = $this->_checkDefaultSticker($sticker_srl);
		if($isDefaultSticker){
			return new BaseObject(-1,'msg_default_sticker');
		}

		$buyCount = $this->_getStickerBuyCount($member_srl);
		if($this->module_config->buy_limit != 0 && $buyCount >= $this->module_config->buy_limit){
			return new BaseObject(-1,'팬비닛콘 구매 제한(50개)을 초과했습니다');
		}

		if(!$this->grant->free){
			$oPointModel = pointModel::getInstance();
			$point = intval($oPointModel->getPoint($member_srl));

			if($sticker->price > $point){
				return new BaseObject(-1,'msg_not_enough_point');
			}

			$this->_setBuyMemberPoint($sticker->member_srl, $logged_info->member_srl, $sticker->price);
		}

		$date = $this->module_config->start_time;
		$sequence = getNextSequence();
		$expdate = $sticker->exptime ? date("YmdHis", mktime(date('H') + $sticker->exptime, date('i'), date('s'), date('m'), date('d'), date('Y'))) : null;

		$args = new stdClass();
		$args->idx = $sequence;
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->use_point = $sticker->price;
		$args->expdate = $expdate;
		$args->ipaddress = \RX_CLIENT_IP;
		$args->list_order = $sequence * -1;
		$args->regdate = $date;
		$output = executeQuery('sticker.insertBuyStickerInfo', $args);
		if (!$output->toBool())	{
			return new BaseObject(-1,'msg_fail_buy_sticker');
		}

		$checkBuyHistoryToday = $this->_checkBuyStickerToday($member_srl, $sticker_srl);
		if($sticker->member_srl != $member_srl && $checkBuyHistoryToday === 0){
			$this->_increaseStickerBuyCount($sticker_srl);
		}

		$args->type = "buySticker";
		$this->insertStickerLog($args);

		$this->setMessage('success_buy_sticker');

	}

	function procStickerBuyOrderChange()
	{
		$sticker_srl = Context::get('sticker_srl');
		$move = Context::get('move');
		$logged_info = Context::get('logged_info');

		if(!$logged_info){
			return new BaseObject(-1, 'msg_invalid_access');
		}

		if(!$sticker_srl || !in_array($move, array('up', 'down'), true)){
			return new BaseObject(-1, 'msg_invalid_access');
		}

		$member_srl = $logged_info->member_srl;
		$date = isset($this->module_config->start_time) ? $this->module_config->start_time : null;

		$currentArgs = new stdClass();
		$currentArgs->member_srl = $member_srl;
		$currentArgs->sticker_srl = $sticker_srl;
		$currentArgs->date = $date;

		$output = executeQuery('sticker.getStickerBuy', $currentArgs);
		if(!$output->toBool() || empty($output->data)){
			return new BaseObject(-1, 'msg_invalid_sticker');
		}

		if(is_array($output->data)){
			return new BaseObject(-1, 'msg_multiple_useable_same_sticker');
		}

		$current_list_order = $output->data->list_order;

		$targetArgs = new stdClass();
		$targetArgs->member_srl = $member_srl;
		$targetArgs->list_count = 1;
		$targetArgs->page = 1;
		$targetArgs->date = $date;

		if($move == 'up'){
			$targetArgs->order_up = $current_list_order;
			$targetArgs->order_type = 'desc';
		}else{
			$targetArgs->order_down = $current_list_order;
			$targetArgs->order_type = 'asc';
		}

		$targetOutput = executeQuery('sticker.getStickerOrder', $targetArgs);
		if(!$targetOutput->toBool() || empty($targetOutput->data)){
			return new BaseObject(-1, 'msg_invalid_access');
		}

		$targetStickerObj = is_array($targetOutput->data) ? current($targetOutput->data) : $targetOutput->data;

		$target_sticker_srl = $targetStickerObj->sticker_srl;
		$target_list_order = $targetStickerObj->list_order;

		if(!isset($target_sticker_srl) || !isset($target_list_order)){
			return new BaseObject(-1, 'msg_exception_process');
		}

		$updateMyArgs = new stdClass();
		$updateMyArgs->member_srl = $member_srl;
		$updateMyArgs->sticker_srl = $sticker_srl;
		$updateMyArgs->list_order = $current_list_order;
		$updateMyArgs->swap_list_order = $target_list_order;
		$output = executeQuery('sticker.updateStickerBuyOrder', $updateMyArgs);
		if(!$output->toBool()){
			return $output;
		}

		$updateTargetArgs = new stdClass();
		$updateTargetArgs->member_srl = $member_srl;
		$updateTargetArgs->sticker_srl = $target_sticker_srl;
		$updateTargetArgs->list_order = $target_list_order;
		$updateTargetArgs->swap_list_order = $current_list_order;
		$output = executeQuery('sticker.updateStickerBuyOrder', $updateTargetArgs);
		if(!$output->toBool()){
			return $output;
		}

		$this->setMessage('success_moved');
	}

	function procStickerFileDelete(){

		$sticker_srl = Context::get('sticker_srl');
		$no = Context::get('no');

		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		if(!$sticker_srl || !$no){
			return new BaseObject(-1,'msg_unknown_image');
		} else if($no > $this->module_config->maxUploads || $no <= $this->module_config->minUploads){
			return new BaseObject(-1,'msg_invalid_image');
		}

		$output = $this->_getStickerFile($sticker_srl, $no);
		if(empty($output)){
			return new BaseObject(-1,'msg_unknown_image');
		}

		if(!($output->member_srl == $logged_info->member_srl || $this->grant->manager || $logged_info->is_admin === "Y")){
			return new BaseObject(-1,'msg_invalid_access');
		}

		if(!($logged_info->is_admin == 'Y' || $this->grant->manager)){

			$args1 = new stdClass();
			$args1->sticker_srl = $sticker_srl;
			$output1 = executeQuery('sticker.getSticker', $args1);
			if (!$output1->toBool()){

				return $output1;

			}
			if(empty($output1->data)){
				return new BaseObject(-1,'msg_invalid_image');
			}

			$sticker_status = $output1->data->status;
			if($sticker_status == "PUBLIC"){
				if($this->module_config->public_modify != "Y"){
					return new BaseObject(-1, 'msg_modify_denied');
				}
			} else if($sticker_status == "CHECK"){
				if($this->module_config->check_modify != "Y"){
					return new BaseObject(-1, 'msg_modify_denied');
				}
			} else if($sticker_status == "PAUSE"){
				if($this->module_config->pause_modify != "Y"){
					return new BaseObject(-1, 'msg_modify_denied');
				}
			} else if($sticker_status == "STOP"){
				return new BaseObject(-1, 'msg_modify_denied');
			} else {
				return new BaseObject(-1, 'invalid_status_sticker');
			}

			if($this->module_config->limit_modify_buy && $output1->data->bought_count >= $this->module_config->limit_modify_buy){
				return new BaseObject(-1, 'msg_modify_denied');
			}
		}

		$this->_deleteStickerFile($sticker_srl, $output->file_srl);

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->uploaded_count = $this->_getStickerFileCount($sticker_srl);
		$output = executeQuery("sticker.updateStickerUploadedCount", $args);

		$this->setMessage('success_deleted');
	}


	function procStickerDelete(){
		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		$sticker_srl = Context::get('sticker_srl');
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.getSticker', $args);
		if (!$output->toBool()){
			return $output;
		}

		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_sticker');
		}
		if(!($logged_info->member_srl == $output->data->member_srl || $this->grant->manager || $logged_info->is_admin === "Y")){
			return new BaseObject(-1,'msg_invalid_access');
		}

		if(!($logged_info->is_admin == 'Y' || $this->grant->manager)){
			$sticker_status = $output->data->status;
			if($sticker_status == "PUBLIC"){
				if($this->module_config->public_delete != "Y"){
					return new BaseObject(-1, 'msg_delete_denied');
				}
			} else if($sticker_status == "CHECK"){
				if($this->module_config->check_delete != "Y"){
					return new BaseObject(-1, 'msg_delete_denied');
				}
			} else if($sticker_status == "PAUSE"){
				if($this->module_config->pause_delete != "Y"){
					return new BaseObject(-1, 'msg_delete_denied');
				}
			} else if($sticker_status == "STOP"){
				return new BaseObject(-1, 'msg_delete_denied');
			} else {
				return new BaseObject(-1, 'invalid_status_sticker');
			}

			if($this->module_config->limit_delete_buy && $output->data->bought_count >= $this->module_config->limit_delete_buy){
				return new BaseObject(-1, 'msg_delete_denied');
			}
		}


		$args->type = "deleteSticker";
		$this->insertStickerLog($args);

		$this->_deleteSticker($sticker_srl);
		$this->_deleteStickerFiles($sticker_srl);
		$this->_deleteStickerBuyByStickerSrl($sticker_srl);

		$this->setMessage('success_deleted');
	}


	function procStickerBuyDelete(){

		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		$sticker_srl = Context::get('sticker_srl');

		$oStickerModel = stickerModel::getInstance();
		$is_bougth = $oStickerModel->checkBuySticker($logged_info->member_srl, $sticker_srl);
		if(!$is_bougth){
			return new BaseObject(-1,'sticker was not exist');
		}
		$args = new stdClass();
		$args->member_srl = $logged_info->member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->type = "deleteBuySticker";
		$this->insertStickerLog($args);
		$this->_deleteStickerBuyByMemberSrl($logged_info->member_srl, $sticker_srl);
		ModuleHandler::triggerCall('sticker.deleteStickerMember', 'after', $args);

		$this->setMessage('success_deleted');

	}

	function procStickerGetStickerSrl() {
		$sticker_src = Context::get('sticker_src');

		$args = new stdClass();
		$args->sticker_src = $sticker_src;
		$output = executeQuery('sticker.getStickerSrlByStickerFile', $args);
		if(!$output->toBool()){
			return $output;
		}

		if(empty($output->data)){
			return new BaseObject(-1,'invalid sticker srl');
		}

		$sticker_srl = $output->data->sticker_srl;
		$this->add('sticker_srl', $sticker_srl);
	}



	function _replaceUndefinedStickerSrlInContent($content){
		if(!$content){
			return $content;
		}

		return preg_replace_callback('/<a\b[^>]*href=("|\')(\/?\?mid=sticker(?:&amp;|&)sticker_srl=undefined)\1[^>]*>.*?<\/a>/is', array($this, '_replaceUndefinedStickerSrlAnchorCallback'), $content);
	}

	function _replaceUndefinedStickerSrlAnchorCallback($matches){
		if(!preg_match('/<img\b[^>]*src=("|\')([^"\']+)\1/i', $matches[0], $img_match)){
			return $matches[0];
		}

		$sticker_src = $this->_extractStickerSrcFromImgTag($img_match[2]);
		if(!$sticker_src){
			return $matches[0];
		}

		$sticker_srl = $this->_getStickerSrlByStickerSrc($sticker_src);
		if(!$sticker_srl){
			return $matches[0];
		}

		return preg_replace('/sticker_srl=undefined/i', 'sticker_srl='.$sticker_srl, $matches[0], 1);
	}

	function _extractStickerSrcFromImgTag($img_src){
		if(!$img_src){
			return '';
		}

		$img_src = preg_replace('/[#?].*$/', '', $img_src);
		$files_pos = strpos($img_src, '/files');
		if($files_pos === false){
			return '';
		}

		return '.'.substr($img_src, $files_pos);
	}

	function _getStickerSrlByStickerSrc($sticker_src){
		if(!$sticker_src){
			return 0;
		}

		$args = new stdClass();
		$args->sticker_src = $sticker_src;
		$output = executeQuery('sticker.getStickerSrlByStickerFile', $args);
		if(!$output->toBool() || empty($output->data) || empty($output->data->sticker_srl)){
			return 0;
		}

		return $output->data->sticker_srl;
	}
	function procStickerBlockInsert(){
		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		$sticker_srl = Context::get('sticker_srl');
		if(!$sticker_srl){
			return new BaseObject(-1,'invalid_sticker');
		}

		if($this->_isBlockedSticker($logged_info->member_srl, $sticker_srl)){
			return new BaseObject(-1,'already_blocked_sticker');
		}

		$output = $this->_insertStickerBlock($logged_info->member_srl, $sticker_srl);
		if(!$output->toBool()){
			return $output;
		}

		$this->setMessage('success_saved');
	}

	function procStickerBlockDelete(){
		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		$sticker_srl = Context::get('sticker_srl');
		if(!$sticker_srl){
			return new BaseObject(-1,'invalid_sticker');
		}

		$output = $this->_deleteStickerBlock($logged_info->member_srl, $sticker_srl);
		if(!$output->toBool()){
			return $output;
		}

		$this->setMessage('success_deleted');
	}

	/**
	 * Publish a sticker that is currently awaiting review.
	 *
	 * @return BaseObject|null
	 */
	public function procStickerPublish()
	{
		if(Context::getRequestMethod() !== 'POST')
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$logged_info = Context::get('logged_info');
		if(!$logged_info || (($logged_info->is_admin ?? 'N') !== 'Y' && !$this->grant->manager))
		{
			return new BaseObject(-1, 'msg_not_permitted');
		}

		$sticker_srl = (int)Context::get('sticker_srl');
		$oStickerModel = stickerModel::getInstance();
		$oSticker = $sticker_srl > 0 ? $oStickerModel->getSticker($sticker_srl) : null;
		if(!$oSticker)
		{
			return new BaseObject(-1, 'msg_invalid_sticker');
		}
		if($oSticker->status !== 'CHECK')
		{
			return new BaseObject(-1, 'msg_stkr_only_review_can_publish');
		}

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->current_status = 'CHECK';
		$args->status = 'PUBLIC';
		$args->last_update = date('YmdHis');
		$args->last_updater = $logged_info->nick_name;
		$args->list_order = getNextSequence() * -1;
		$output = executeQuery('sticker.updateStickerStatus', $args);
		if(!$output->toBool())
		{
			return $output;
		}
		if(DB::getInstance()->getAffectedRows() !== 1)
		{
			return new BaseObject(-1, 'msg_stkr_only_review_can_publish');
		}

		$oStickerModel->clearStickerCache($sticker_srl);
		$log_args = new stdClass();
		$log_args->sticker_srl = $sticker_srl;
		$log_args->type = 'updateStickerAdmin';
		$this->insertStickerLog($log_args);

		$this->setMessage('success_stkr_published');
		$return_url = Context::get('success_return_url') ?: getNotEncodedUrl('', 'mid', 'sticker', 'sticker_srl', $sticker_srl);
		$this->setRedirectUrl($return_url);
	}


	function procStickerInsert(){
		if( !(extension_loaded('gd') && function_exists('gd_info')) ){
			return new BaseObject(-1,'GD_library_is_not_installed');
		}

		$oModuleModel = moduleModel::getInstance();
		$module_info = $oModuleModel->getModuleInfoByMid("sticker");

		$obj = Context::getRequestVars();

		$title = Context::get('title');
		$content = Context::get('content');

		$sticker_srl = Context::get('sticker_srl');
		$logged_info = Context::get('logged_info');

		if(!$logged_info){
			return new BaseObject(-1,'msg_invalid_access');
		}

		if($sticker_srl){

			$args = new stdClass();
			$args->sticker_srl = $sticker_srl;
			$output = executeQueryArray('sticker.getSticker', $args);

			if (!$output->toBool()) {
				return $output;
			}

			if(!empty($output->data)){

				if(!($logged_info->member_srl == $output->data[0]->member_srl || $this->grant->manager || $logged_info->is_admin === "Y")){
					return new BaseObject(-1,'msg_invalid_access');
				}

				if(!$this->grant->upload){
					return new BaseObject(-1,'msg_access_denied');
				}

				if(!($logged_info->is_admin == 'Y' || $this->grant->manager)){
					$sticker_status = $output->data[0]->status;
					if($sticker_status == "PUBLIC"){
						if($this->module_config->public_modify != "Y"){
							return new BaseObject(-1, 'msg_modify_denied');
						}
					} else if($sticker_status == "CHECK"){
						if($this->module_config->check_modify != "Y"){
							return new BaseObject(-1, 'msg_modify_denied');
						}
					} else if($sticker_status == "PAUSE"){
						if($this->module_config->pause_modify != "Y"){
							return new BaseObject(-1, 'msg_modify_denied');
						}
					} else if($sticker_status == "STOP"){
						return new BaseObject(-1, 'msg_modify_denied');
					} else {
						return new BaseObject(-1, 'invalid_status_sticker');
					}

					if($this->module_config->limit_modify_buy && $output->data[0]->bought_count >= $this->module_config->limit_modify_buy){
						return new BaseObject(-1, 'msg_modify_denied');
					}
				}

				return $this->_updateSticker($obj, $output->data[0]);
			}
		}

		if(!$this->grant->upload){
			return new BaseObject(-1,'msg_access_denied');
		}

		// 제목 유무 체크
		if(empty($obj->title)){
			return new BaseObject(-1,'unknown title');
		}

		//빈 내용인지 체크
		if(empty($obj->content)){
			return new BaseObject(-1,'unknown content');
		}

		//포인트 체크
		if($this->module_config->minPoint==$this->module_config->maxPoint){
			$obj->price = $this->module_config->minPoint;
		} else {

			if(!isset($obj->price) || $obj->price > $this->module_config->maxPoint || $obj->price < $this->module_config->minPoint){
				return new BaseObject(-1,'point error');
			}

		}

		//파일 체크
		//파일이 존재하지 않을 시
		if(empty($obj->sticker_main_file) || empty($obj->sticker_file)){
			return new BaseObject(-1,'file is not exist');
		} else { //존재 할 때 파일 갯수와 용량, 확장자 체크

			$sticker_count = count($obj->sticker_file);
			$sticker_accu_size = 0;
			$file_size = $this->module_config->file_size << 10;
			$file_size_all = $this->module_config->file_size_all << 10;

			if($sticker_count < $this->module_config->minUploads){
				return new BaseObject(-1,'file_is_not_enough');
			}

			if($sticker_count > $this->module_config->maxUploads){
				return new BaseObject(-1,'file_count_is_over_limit');
			}

			if($obj->sticker_main_file['error'] != 0){
				return new BaseObject(-1,'file transfer error');
			}

			if($obj->sticker_main_file['size'] > $file_size){
				return new BaseObject(-1,'exceed file size');
			}

			if(!$this->_isAllowedStickerUploadFile($obj->sticker_main_file)){
				return new BaseObject(-1,'unknown file extension');
			}

			foreach($obj->sticker_file as $value){

				if($value['error'] != 0){
					return new BaseObject(-1,'file transfer error');
				}

				$sticker_accu_size += $value['size'];
				if($value['size'] > $file_size){
					return new BaseObject(-1,'exceed file size');
				}
				if($sticker_accu_size > $file_size_all){
					return new BaseObject(-1,'exceed files size');
				}

				if(!$this->_isAllowedStickerUploadFile($value)){
					return new BaseObject(-1,'unknown file extension');
				}
			}

		}

		$date = $this->module_config->start_time;
		$sequence = getNextSequence();

		$module_srl = $module_info->module_srl;
		$sticker_srl = $sequence;
		$file_count = 0;

		//sticker_main_file
		$oFileController = fileController::getInstance();
		$output = $oFileController->insertFile($obj->sticker_main_file, $module_srl, $sticker_srl, 0, true);
		if (!$output->toBool()) {
			return $output;
		} else {
			$convert = $this->_insertImage($sticker_srl, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'), $file_count);

			//정상적인 이미지 파일이 아닐 시
			if($convert === false){
				$this->_deleteStickerFiles($sticker_srl);
				return new BaseObject(-1,'unknown file extension');
			} else if($convert === 2){
				$this->_deleteStickerFiles($sticker_srl);
				return new BaseObject(-1,'image size is too small');
			} else if($convert === 3){
				$this->_deleteStickerFiles($sticker_srl);
				return new BaseObject(-1,'image resolution is too big');
			} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
				$this->_deleteStickerFiles($sticker_srl);
				return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
			}
		}

		//sticker_file
		foreach($obj->sticker_file as $value){
			$output = $oFileController->insertFile($value, $module_srl, $sticker_srl, 0, true);
			if (!$output->toBool()) {
				return $output;
			} else {
				$convert = $this->_insertImage($sticker_srl, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'), $file_count);

				//정상적인 이미지 파일이 아닐 시
				if($convert === false){
					$this->_deleteStickerFiles($sticker_srl);
					return new BaseObject(-1,'unknown file extension');
				} else if($convert === 2){
					$this->_deleteStickerFiles($sticker_srl);
					return new BaseObject(-1,'image size is too small');
				} else if($convert === 3){
					$this->_deleteStickerFiles($sticker_srl);
					return new BaseObject(-1,'image resolution is too big');
				} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
					$this->_deleteStickerFiles($sticker_srl);
					return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
				}
			}

		}

		$end_date = null;
		if($this->module_config->sale_end_date){
			$end_date = date("YmdHis", mktime(date('H'), date('i'), date('s'), date('m'), date('d')+$this->module_config->sale_end_date, date('Y')));
		}
		$exptime = $this->module_config->use_date && $this->module_config->use_date > 0 ? $this->module_config->use_date : null;
		$buy_limit = $this->module_config->sale_limit && $this->module_config->sale_limit > 0 ? $this->module_config->sale_limit : null;

		//insert sticker document
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->member_srl = $logged_info->member_srl;
		$args->nick_name = $logged_info->nick_name;
		$args->category_srl = 0;
		$args->title = cut_str(trim(htmlspecialchars(strip_tags($obj->title), ENT_QUOTES, 'UTF-8', false)), 100);
		$args->tag = cut_str(htmlspecialchars(strip_tags($obj->tags), ENT_QUOTES, 'UTF-8', false), 150);
		$args->content = removeHackTag($obj->content);
		$args->uploaded_count = $file_count;
		$args->end_date = $end_date;
		$args->price = $obj->price;
		$args->buy_limit = $buy_limit;
		$args->exptime = $exptime;
		$args->ipaddress = \RX_CLIENT_IP;
		$args->last_update = $date;
		$args->last_updater = $logged_info->nick_name;
		$args->list_order = $sequence * -1;
		$args->regdate = $date;
		$args->status = $this->module_config->before_test == "N" ? "PUBLIC" : "CHECK";

		$output = executeQuery('sticker.insertSticker', $args);
		if (!$output->toBool())	{
			return $output;
		}
		$this->_updateFileStatus($sticker_srl); //sicker_srl;

		if($this->module_config->upload_charge > 0 && !$this->grant->manager){
			$oPointController = pointController::getInstance();
			$oPointController->setPoint($logged_info->member_srl, $this->module_config->upload_charge, 'minus');
		}

		$args->type = "insertSticker";
		unset($args->content);
		$this->insertStickerLog($args);

		$this->setMessage('success_saved');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'mid', 'sticker', 'sticker_srl', $sticker_srl);

		$this->setRedirectUrl($returnUrl);
	}
	

	function _updateSticker($obj, $sticker){

		// 제목 유무 체크
		if(empty($obj->title)){
			return new BaseObject(-1,'unknown title');
		}

		//빈 내용인지 체크
		if(empty($obj->content)){
			return new BaseObject(-1,'unknown content');
		}

		//포인트 체크
		if($this->module_config->minPoint==$this->module_config->maxPoint){
			$obj->price = $this->module_config->minPoint;
		} else {
			if(!isset($obj->price) || $obj->price > $this->module_config->maxPoint || $obj->price < $this->module_config->minPoint){
				return new BaseObject(-1,'point error');
			}
		}

		$oModuleModel = moduleModel::getInstance();
		$module_info = $oModuleModel->getModuleInfoByMid("sticker");

		$sticker_srl = $sticker->sticker_srl;

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->order_type = 'asc';
		$output = executeQueryArray('sticker.getFiles', $args);

		$file_size_accm = 0;
		foreach($output->data as $value){
			$file_size_accm += $value->file_size;
		}

		$sticker_file = $output->data;
		$file_size = $this->module_config->file_size << 10;
		$file_size_all = $this->module_config->file_size_all << 10;

		$module_srl = $module_info->module_srl;
		$date = $this->module_config->start_time;
		$sequence = getNextSequence();

		$oFileController = fileController::getInstance();

		if(!empty($obj->sticker_main_file)){

			$main_image_info = null;
			foreach($sticker_file as $value){
				if($value->no == '0'){
					$main_image_info = $value;
					break;
				}
			}

			if($main_image_info == null){
				$args = new stdClass();
				$args->sticker_srl = $sticker->sticker_srl;
				$args->no = 0;
				$output = executeQuery('sticker.getStickerByNo', $args);
				if (!$output->toBool()) {
					return $output;
				}
				
				$main_image_info = $output->data;
				$main_image_info->file_size = 0;
			}

			if($obj->sticker_main_file['error'] != 0){
				return new BaseObject(-1,'file transfer error');
			}

			if($obj->sticker_main_file['size'] > $file_size){
				return new BaseObject(-1,'exceed file size');
			}

			if($file_size_accm > $file_size_all - $main_image_info->file_size){
				return new BaseObject(-1,'exceed files size');
			}

			$file_size_accm = $file_size_accm - $main_image_info->file_size + $obj->sticker_main_file['size'];

			if(!$this->_isAllowedStickerUploadFile($obj->sticker_main_file)){
				return new BaseObject(-1,'unknown file extension');
			}

			//update sticker

			$output = $oFileController->insertFile($obj->sticker_main_file, $module_srl, $sticker_srl, 0, true);
			if (!$output->toBool()) {
				return $output;
			} else {
				$convert = $this->_updateImage($main_image_info, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'));
				if($convert === true){
					$this->_updateFileStatus($sticker_srl);
				} else if($convert == 2){
					$this->_deleteFile($output->get('file_srl'));
					return new BaseObject(-1,'image size is too small');
				} else if($convert === false){
					$this->_deleteFile($output->get('file_srl'));
					return new BaseObject(-1,'unknown file extension');
				} else if($convert === 3){
					$this->_deleteFile($output->get('file_srl'));
					return new BaseObject(-1,'image resolution is too big');
				} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
					$this->_deleteFile($output->get('file_srl'));
					return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
				}
			}

		}

		for($i=1; $i<=$this->module_config->maxUploads; $i++){
			$stk = $obj->{"sticker_file_".$i};
			if($stk){

				if($stk['error'] != 0){
					return new BaseObject(-1,'file transfer error');
				}

				if(!$this->_isAllowedStickerUploadFile($stk)){
					return new BaseObject(-1,'unknown file extension');
				}

				$image_info = null;
				// 이미지가 이미 존재하는지 체크
				foreach($sticker_file as $value){
					if($value->no == $i){
						$image_info = $value;
						break;
					}
				}

				//이미 존재한다면 업데이트
				if($image_info){
					if($stk['size'] > $file_size){
						return new BaseObject(-1,'exceed file size');
					}

					$file_size_accm = $file_size_accm - $image_info->file_size + $stk['size'];
					if($file_size_accm > $file_size_all){
						return new BaseObject(-1,'exceed files size');
					}

					$output = $oFileController->insertFile($stk, $module_srl, $sticker_srl, 0, true);

					if (!$output->toBool()) {
						return $output;
					} else {
						$convert = $this->_updateImage($image_info, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'));
						if($convert === true){
							$this->_updateFileStatus($sticker_srl);
						} else if($convert == 2){
							$this->_deleteFile($output->get('file_srl'));
							return new BaseObject(-1,'image size is too small');
						} else if($convert === false){
							$this->_deleteFile($output->get('file_srl'));
							return new BaseObject(-1,'unknown file extension');
						} else if($convert === 3){
							$this->_deleteFile($output->get('file_srl'));
							return new BaseObject(-1,'image resolution is too big');
						} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
							$this->_deleteFile($output->get('file_srl'));
							return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
						}
					}


				//존재하지 않을때
				} else {
					//file모듈에만 없을 수 있으므로 sticker_files테이블 검색
					
					$args = new stdClass();
					$args->sticker_srl = $sticker->sticker_srl;
					$args->no = $i;
					$output = executeQuery('sticker.getStickerByNo', $args);
					if (!$output->toBool()) {
						return $output;
					}
					$image_info = $output->data;

					if($stk['size'] > $file_size){
						return new BaseObject(-1,'exceed file size');
					}

					$file_size_accm += $stk['size'];
					if($file_size_accm > $file_size_all){
						return new BaseObject(-1,'exceed files size');
					}

					//데이터가 존재한다!
					if(!empty($image_info)){
						$image_info->file_size = 0;

						$output = $oFileController->insertFile($stk, $module_srl, $sticker_srl, 0, true);
						if (!$output->toBool()) {
							return $output;
						} else {
							$convert = $this->_updateImage($image_info, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'));
							if($convert === true){
								$this->_updateFileStatus($sticker_srl);
							} else if($convert == 2){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'image size is too small');
							} else if($convert === false){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'unknown file extension');
							} else if($convert == 3){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'image resolution is too big');
							} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
							}
						}

					} else { // 없는 데이터. 업데이트가 아닌 새로 업로드

						$output = $oFileController->insertFile($stk, $module_srl, $sticker_srl, 0, true);
						if (!$output->toBool()) {
							return $output;
						} else {
							$convert = $this->_insertImage($sticker_srl, $output->get('file_srl'), $output->get('uploaded_filename'), $output->get('source_filename'), $i, true);

							//정상적인 이미지 파일이 아닐 시
							if($convert === true){
								$this->_updateFileStatus($sticker_srl);
							} else if($convert === false){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'unknown file extension');
							} else if($convert === 2){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'image size is too small');
							} else if($convert === 3){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1,'image resolution is too big');
							} else if($convert === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE){
								$this->_deleteFile($output->get('file_srl'));
								return new BaseObject(-1, 'msg_stkr_video_processing_unavailable');
							}
						}

					}


				} // if($image_info)

			} // END FOR
		}

		if(!empty($obj->sticker_file_order))
		{
			$output = $this->_updateStickerFileOrder($sticker_srl, $obj->sticker_file_order);
			if(!$output->toBool())
			{
				return $output;
			}
		}

		$file_count = $this->_getStickerFileCount($sticker_srl);

		$tag = $this->_checkCorrectTag(cut_str(htmlspecialchars(strip_tags($obj->tags), ENT_QUOTES, 'UTF-8', false), 150));

		//insert sticker document
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->category_srl = 0;
		$args->title = cut_str(htmlspecialchars(strip_tags($obj->title), ENT_QUOTES, 'UTF-8', false), 100);
		$args->tag = $tag;
		$args->content = removeHackTag($obj->content);
		$args->uploaded_count = $file_count;
		$args->price = $obj->price;
		$args->last_update = $date;
		$args->last_updater = $logged_info->nick_name;
		$args->list_order = $sequence * -1;
		$args->status = $sticker->status;

		$output = executeQuery('sticker.updateSticker', $args);
		if (!$output->toBool())	{
			return $output;
		}

		Cache::set(sprintf('sticker:item:%d', $sticker_srl), null);

		$args->type = "updateSticker";
		unset($args->content);
		$this->insertStickerLog($args);

		$this->setMessage('success_saved');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'mid', 'sticker', 'sticker_srl', $sticker_srl);
		$this->setRedirectUrl($returnUrl);

	}

	//convert and insert
	/**
	 * Perform an early browser MIME check before the authoritative server-side validation.
	 *
	 * Some browsers report MP4 files as application/octet-stream, so the extension is used
	 * only for that fallback. ImageProcessor validates the actual container and stream later.
	 *
	 * @param mixed $file PHP upload array.
	 * @return bool
	 */
	private function _isAllowedStickerUploadFile($file): bool
	{
		if(!is_array($file))
		{
			return false;
		}

		$mime_type = strtolower((string)($file['type'] ?? ''));
		$allowed_mime_types = array('image/jpeg', 'image/gif', 'image/png', 'image/webp', 'video/mp4', 'application/mp4', 'video/x-m4v');
		if(in_array($mime_type, $allowed_mime_types, true))
		{
			return true;
		}

		return ($mime_type === '' || $mime_type === 'application/octet-stream') && preg_match('/\.mp4$/i', (string)($file['name'] ?? '')) === 1;
	}

	/**
	 * Apply the file order submitted by the modern uploader.
	 *
	 * Existing files are identified by their primary key. Files uploaded with the same
	 * request are identified by the temporary free slot selected by the uploader.
	 * Every current sticker file must occur exactly once in the submitted order.
	 *
	 * @param int $sticker_srl Sticker serial number.
	 * @param string $serialized_order JSON-encoded file identifiers.
	 * @return BaseObject
	 */
	private function _updateStickerFileOrder($sticker_srl, $serialized_order)
	{
		$order = json_decode((string)$serialized_order, true);
		if(!is_array($order))
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$args = new stdClass();
		$args->sticker_srl = intval($sticker_srl);
		$output = executeQueryArray('sticker.getStickerImage', $args);
		if(!$output->toBool())
		{
			return $output;
		}

		$files = is_array($output->data) ? $output->data : array();
		if(count($order) !== count($files))
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$files_by_srl = array();
		$files_by_no = array();
		foreach($files as $file)
		{
			$files_by_srl[intval($file->sticker_file_srl)] = $file;
			$files_by_no[intval($file->no)] = $file;
		}

		$ordered_files = array();
		$used_file_srls = array();
		foreach($order as $identifier)
		{
			$file = null;
			if(is_array($identifier) && isset($identifier['sticker_file_srl']))
			{
				$file_srl = intval($identifier['sticker_file_srl']);
				$file = $files_by_srl[$file_srl] ?? null;
			}
			elseif(is_array($identifier) && isset($identifier['upload_no']))
			{
				$upload_no = intval($identifier['upload_no']);
				$file = $files_by_no[$upload_no] ?? null;
			}

			if(!$file || isset($used_file_srls[$file->sticker_file_srl]))
			{
				return new BaseObject(-1, 'msg_invalid_request');
			}

			$used_file_srls[$file->sticker_file_srl] = true;
			$ordered_files[] = $file;
		}

		foreach($ordered_files as $no => $file)
		{
			$args = new stdClass();
			$args->sticker_srl = intval($sticker_srl);
			$args->sticker_file_srl = intval($file->sticker_file_srl);
			$args->no = $no;
			$output = executeQuery('sticker.updateStickerFileOrder', $args);
			if(!$output->toBool())
			{
				return $output;
			}
		}

		return new BaseObject();
	}

	/**
	 * Render a stored document sticker using its current media and status.
	 *
	 * @param array<int, string> $matches Regular expression matches.
	 * @return string
	 */
	public function stickerDocumentCallback(array $matches): string
	{
		if(!preg_match('/\bdata-rx-sticker\s*=\s*(["\'])([0-9]+)\|([0-9]+)\1/i', $matches[0], $identity))
		{
			return $this->_getStickerDeleteMsg();
		}

		$output = $this->_getStickerComment(intval($identity[3]));
		if(
			!$output->toBool() ||
			empty($output->data) ||
			intval($output->data->sticker_srl) !== intval($identity[2]) ||
			($output->data->status ?? 'STOP') === 'STOP'
		)
		{
			return $this->_getStickerDeleteMsg();
		}

		$width = preg_match('/\bwidth\s*=\s*(["\']?)([0-9]+)\1/i', $matches[0], $size) ? min(100, max(24, intval($size[2]))) : 100;
		$height = preg_match('/\bheight\s*=\s*(["\']?)([0-9]+)\1/i', $matches[0], $size) ? min(100, max(24, intval($size[2]))) : 100;
		$is_video = ImageProcessor::isMp4((string)$output->data->url);
		$title = htmlspecialchars((string)$output->data->title, ENT_QUOTES, 'UTF-8', false);
		$sticker_url = htmlspecialchars(
			getNotEncodedUrl('', 'mid', 'sticker', 'sticker_srl', $output->data->sticker_srl),
			ENT_QUOTES,
			'UTF-8',
			false
		);

		if($is_video)
		{
			$media = sprintf(
				'<video src="%s" poster="%s" width="%d" height="%d" autoplay muted loop playsinline preload="metadata" style="width:100%%;height:100%%;display:block" data-rx-sticker="%d|%d" data-rx-sticker-type="video"></video>',
				htmlspecialchars((string)$output->data->url, ENT_QUOTES, 'UTF-8', false),
				htmlspecialchars(ImageProcessor::getPosterUrl((string)$output->data->url), ENT_QUOTES, 'UTF-8', false),
				$width,
				$height,
				intval($output->data->sticker_srl),
				intval($output->data->sticker_file_srl)
			);
		}
		else
		{
			$media = sprintf(
				'<img src="%s" alt="%s" width="%d" height="%d" style="width:100%%;height:100%%;display:block" data-rx-sticker="%d|%d" data-rx-sticker-type="image">',
				htmlspecialchars((string)$output->data->url, ENT_QUOTES, 'UTF-8', false),
				$title,
				$width,
				$height,
				intval($output->data->sticker_srl),
				intval($output->data->sticker_file_srl)
			);
		}

		return sprintf(
			'<a href="%s" title="%s" style="display:inline-block;width:%dpx;height:%dpx;vertical-align:middle;line-height:0">%s</a>',
			$sticker_url,
			$title,
			$width,
			$height,
			$media
		);
	}

	/**
	 * Validate, process, and register a newly uploaded sticker image.
	 *
	 * @param int $sticker_srl Sticker serial number.
	 * @param int $file_srl Rhymix file serial number.
	 * @param string $uploaded_filename Uploaded file path.
	 * @param string $source_filename Original filename.
	 * @param int $file_count Current file number.
	 * @param bool $is_update Whether the requested slot number must be preserved.
	 * @return bool|int
	 */
	function _insertImage($sticker_srl, $file_srl, $uploaded_filename, $source_filename, &$file_count, $is_update = false)
	{
		$validated = ImageProcessor::validate((string)$uploaded_filename, (string)$source_filename, $this->module_config);
		if($validated === false || $validated === ImageProcessor::CODE_IMAGE_TOO_SMALL || $validated === ImageProcessor::CODE_IMAGE_TOO_LARGE || $validated === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE)
		{
			return $validated;
		}

		$result = ImageProcessor::process((string)$uploaded_filename, $validated, $this->module_config);
		if($result === false || $result === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE)
		{
			return $result;
		}

		$file_output = ImageProcessor::finalizeFile(intval($file_srl), $result);
		if(!$file_output->toBool())
		{
			return false;
		}

		$no = $is_update ? $file_count : $file_count++;
		$sticker_output = $this->_insertSickerFile($sticker_srl, $file_srl, $result->source_filename, $result->url, $no);
		if(!$sticker_output || !$sticker_output->toBool())
		{
			return false;
		}

		ImageProcessor::removeOriginal($result);
		return true;
	}

	/**
	 * Validate, process, and register a replacement sticker image.
	 *
	 * The existing file is deleted only after the new file and sticker rows have both been
	 * updated successfully.
	 *
	 * @param object $origin_obj Existing sticker file row.
	 * @param int $file_srl New Rhymix file serial number.
	 * @param string $uploaded_filename Uploaded file path.
	 * @param string $source_filename Original filename.
	 * @return bool|int
	 */
	function _updateImage($origin_obj, $file_srl, $uploaded_filename, $source_filename)
	{
		if(!$origin_obj || !$file_srl)
		{
			return false;
		}

		$validated = ImageProcessor::validate((string)$uploaded_filename, (string)$source_filename, $this->module_config);
		if($validated === false || $validated === ImageProcessor::CODE_IMAGE_TOO_SMALL || $validated === ImageProcessor::CODE_IMAGE_TOO_LARGE || $validated === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE)
		{
			return $validated;
		}

		$result = ImageProcessor::process((string)$uploaded_filename, $validated, $this->module_config);
		if($result === false || $result === ImageProcessor::CODE_VIDEO_PROCESSING_UNAVAILABLE)
		{
			return $result;
		}

		$file_output = ImageProcessor::finalizeFile(intval($file_srl), $result);
		if(!$file_output->toBool())
		{
			return false;
		}

		$sticker_output = $this->_updateStickerFileInfo($origin_obj->sticker_file_srl, $file_srl, $result->source_filename, $result->url, $origin_obj->no);
		if(!$sticker_output || !$sticker_output->toBool())
		{
			return false;
		}

		$this->_deleteFile($origin_obj->file_srl);
		ImageProcessor::removeOriginal($result);
		return true;
	}

	function _updateStickerFileInfo($sticker_file_srl, $file_srl, $file_name, $url, $no){
		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return false;
		}

		$args = new stdClass();
		$args->sticker_file_srl = $sticker_file_srl;
		$args->member_srl = $logged_info->member_srl;
		$args->file_srl = $file_srl;
		$args->file_name = cut_str(htmlspecialchars($file_name, ENT_QUOTES, 'UTF-8', false), 60);
		$args->url = $url;
		$args->regdate = $this->module_config->start_time;
		$output = executeQuery('sticker.updateStickerFile', $args);

		return $output;

	}

	function updateReadedCount(&$oSticker){
		if(isCrawler()) return false;
		$sticker_srl = $oSticker->sticker_srl;
		$member_srl = $oSticker->member_srl;
		$logged_info = Context::get('logged_info');
		if(!empty($_SESSION['readed_sticker'][$sticker_srl])){
			return false;
		}

		if($oSticker->ipaddress == \RX_CLIENT_IP){
			$_SESSION['readed_sticker'][$sticker_srl] = true;
			return false;
		}

		if($logged_info && $logged_info->member_srl == $member_srl){
			$_SESSION['readed_sticker'][$sticker_srl] = true;
			return false;
		}

		$args = new stdClass;
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.updateReadedCount', $args);

		$_SESSION['readed_sticker'][$sticker_srl] = true;
		$oSticker->readed_count += 1;
		
		return true;

	}

	function _insertSickerFile($sticker_srl, $file_srl, $file_name, $url, $no){
		$logged_info = Context::get('logged_info');
		if(!$logged_info){
			return false;
		}

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->sticker_file_srl = $file_srl;
		$args->member_srl = $logged_info->member_srl;
		$args->file_srl = $file_srl;
		$args->file_name = cut_str(htmlspecialchars($file_name, ENT_QUOTES, 'UTF-8', false), 60);
		$args->no = $no; //00 main, 01~ sub
		$args->url = $url;
		$args->regdate = $this->module_config->start_time;
		$output = executeQuery('sticker.insertStickerFile', $args);

		return $output;
	}

	function insertStickerLog($obj, $sequence = false){
		$logged_info = Context::get('logged_info');
		$idx = $sequence ? $sequence : getNextSequence();
		$sticker_srl = isset($obj->sticker_srl) && $obj->sticker_srl ? $obj->sticker_srl : 0;
		$sticker_file_srl = isset($obj->sticker_file_srl) && $obj->sticker_file_srl ? $obj->sticker_file_srl : null;
		//$member_srl = $obj->member_srl ? $obj->member_srl : $logged_info ? $logged_info->member_srl : 0; //php 8 error
		if(isset($obj->member_srl) && $obj->member_srl)
		{
			$member_srl = $obj->member_srl;
		}
		elseif($logged_info)
		{
			$member_srl = $logged_info->member_srl;
		}
		else
		{
			$member_srl = 0;
		}

		$type = isset($obj->type) && $obj->type ? $obj->type : null;
		$comment_srl = isset($obj->comment_srl) && $obj->comment_srl ? $obj->comment_srl : null;
		$document_srl = isset($obj->document_srl) && $obj->document_srl ? $obj->document_srl : null;
		$content = isset($obj->content) && $obj->content ? $obj->content : null;
		//$point = $obj->point ? $obj->point : $obj->use_point ? $obj->use_point : $obj->price ? $obj->price : null; //php 8 error
		if(isset($obj->point) && $obj->point)
		{
			$point = $obj->point;
		}
		elseif(isset($obj->use_point) && $obj->use_point)
		{
			$point = $obj->use_point;
		}
		elseif(isset($obj->price) && $obj->price)
		{
			$point = $obj->price;
		}
		else
		{
			$point = null;
		}

		$ipaddress = isset($obj->ipaddress) && $obj->ipaddress ? $obj->ipaddress : \RX_CLIENT_IP;
		$regdate = isset($obj->regdate) && $obj->regdate ? $obj->regdate : date("YmdHis");

		if(!$type){
			return false;
		}

		$args = new stdClass();
		$args->idx = $idx;
		$args->sticker_srl = $sticker_srl;
		$args->sticker_file_srl = $sticker_file_srl;
		$args->member_srl = $member_srl;
		$args->type = $type;
		$args->comment_srl = $comment_srl;
		$args->document_srl = $document_srl;
		$args->content = $content;	// Hack 태그가 심겨져있을 수 있으니 스킨단에서 철저히 확인 후 사용 할 것.
		$args->point = $point;
		$args->ipaddress = $ipaddress;
		$args->regdate = $regdate;
		$output = executeQuery('sticker.insertStickerLog', $args);
		if(!$output->toBool()){
			return $output;
		}

		return true;
	}

	function _setBuyMemberPoint($sticker_member_srl, $member_srl, $price=0){
		$oPointController = pointController::getInstance();
		$return_percent = $this->module_config->returnPoint;
		//판매자 포인트 설정
		if($return_percent > 0 && $return_percent <= 100){
			$oPointController->setPoint($sticker_member_srl, $price * $return_percent / 100 , 'add');
		}

		//구매자 포인트 설정
		$oPointController->setPoint($member_srl, $price , 'minus');

		return true;
	}

	function _increaseStickerBuyCount($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.updateBoughtCount', $args);
		return !$output->toBool() ? FALSE : TRUE;
	}

	function _increaseStickerUsedCount($sticker_srl, $sticker_file_srl, $member_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.updateStickerUsedCount', $args);

		$args1 = new stdClass();
		$args1->sticker_file_srl = $sticker_file_srl;
		$output = executeQuery('sticker.updateStickerFileUsedCount', $args1);

		if($member_srl != 0){
			$args->member_srl = $member_srl;
			$args->date = date("YmdHis");
			$output = executeQuery('sticker.updateStickerBuyUsedCount', $args);
		}
	}


	function _checkFakeSticker($sticker_srl, $sticker_file_srl, $member_srl){
		$oStickerModel = stickerModel::getInstance();

		$isDefaultSticker = $this->_checkDefaultSticker($sticker_srl);
		if(!$isDefaultSticker){
			$checkBuySticker = $oStickerModel->checkBuySticker($member_srl, $sticker_srl);
			if(!$checkBuySticker){
				return false;
			}
		}

		$args = new stdClass();
		$args->member_srl = $member_srl;
		$args->sticker_file_srl = $sticker_file_srl;
		$output = executeQuery('sticker.getStickerFileByStickerFileSrl', $args);
		return (!$output->toBool() || empty($output->data)) ? FALSE : TRUE;
	}

	function _checkUsableSticker($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.getSticker', $args);
		if (!$output->toBool() || empty($output->data))	{
			return false;
		}
		$sticker_status = $output->data->status;

		return $sticker_status != "STOP" ? TRUE : FALSE;
	}

	function _checkDefaultSticker($sticker_srl){
		$oStickerModel = stickerModel::getInstance();

		$module_config = $oStickerModel->getConfig();
		$default_sticker = explode(',', $module_config->default_sticker);

		foreach($default_sticker as &$value){
			$value = trim($value);
		}

		if(in_array($sticker_srl, $default_sticker)){
			return true;
		}

		return false;
	}

	function _insertStickerBlock($member_srl, $sticker_srl){
		$args = new stdClass();
		$args->srl = getNextSequence();
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->regdate = date('YmdHis');

		$output = executeQuery('sticker.insertStickerBlock', $args);
		if(!$output->toBool()){
			return $output;
		}

		if(!isset($_SESSION['sticker_block_list'])){
			$_SESSION['sticker_block_list'] = array();
		}

		$_SESSION['sticker_block_list'][$member_srl][$sticker_srl] = true;

		return $output;
	}

	function _deleteStickerBlock($member_srl, $sticker_srl){
		$args = new stdClass();
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;

		$output = executeQuery('sticker.deleteStickerBlock', $args);
		if(!$output->toBool()){
			return $output;
		}

		if(isset($_SESSION['sticker_block_list'][$member_srl][$sticker_srl])){
			unset($_SESSION['sticker_block_list'][$member_srl][$sticker_srl]);
		}

		return $output;
	}

	function _isBlockedSticker($member_srl, $sticker_srl){
		if(!$member_srl || !$sticker_srl){
			return false;
		}

		if(!isset($_SESSION['sticker_block_list'])){
			$_SESSION['sticker_block_list'] = array();
		}

		if(!isset($_SESSION['sticker_block_list'][$member_srl])){
			$_SESSION['sticker_block_list'][$member_srl] = array();
			$args = new stdClass();
			$args->member_srl = $member_srl;
			$output = executeQueryArray('sticker.getStickerBlockList', $args);
			if($output->toBool() && !empty($output->data)){
				foreach($output->data as $item){
					$_SESSION['sticker_block_list'][$member_srl][$item->sticker_srl] = true;
				}
			}
		}

		return isset($_SESSION['sticker_block_list'][$member_srl][$sticker_srl]);
	}

	function _checkCorrectTag($tag){
		$tag = preg_replace(array('/^,?\s+?/', '/(,?\s*?,+|,+)/', '/\s+/'), array('', ',', ' '), $tag);
		$tag = explode(',', $tag);
		$iTagCount = count($tag);
		$new_tag = array();
		for($i = 0; $i<$iTagCount; $i++){
			$is_null = true;
			$new_tag_count = count($new_tag);
		    	for($j = 0; $j<$new_tag_count; $j++){
					$trim_tag = trim($tag[$i]);
					if($tag[$i] && (!$trim_tag || $trim_tag == $new_tag[$j])){
						$is_null = false;
						break;
					}
				}
			if($is_null){
				array_push($new_tag, trim($tag[$i]));
			}
		}

		$sTag = "";
		$new_tag_count = count($new_tag);
		foreach($new_tag as $key=>$value){
			if(!!trim($value)){
				if($sTag){
					$sTag .= ", ";
				}
				$sTag .= $value;
			}
		}

		return $sTag;
	}

	function _checkBuyStickerToday($member_srl = 0, $sticker_srl = 0){
		$args = new stdClass();
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->type = "buySticker,buyStickerAdmin";
		$args->date = date("Ymd");
		$output = executeQuery('sticker.getStickerBuyCheckByDate', $args);
		if(!$output->toBool()){
			return false;
		}

		return $output->data->count;
	}

	function _checkStickerInContent($content){
		$content = trim(strip_tags($content));
		if(preg_match('/{@sticker:[0-9]+\|[0-9]+}/i', $content)){
			return true;
		}
		return false;
	}

	function _getStickerFile($sticker_srl, $no){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->no = $no;
		$output = executeQuery('sticker.getStickerFileByNo', $args);
		return $output->data;
	}

	function _getStickerFileCount($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.getStickerFileCheck', $args);
		return $output->data->count;
	}

	function _getStickerBuyCount($member_srl){
		$args = new stdClass();
		$args->member_srl = $member_srl;
		$output = executeQuery('sticker.getStickerBuyCount', $args);
		return $output->data->count;
	}

	function _getStickerComment($sticker_file_srl){
		$args = new stdClass();
		$args->sticker_file_srl = $sticker_file_srl;
		$output = executeQuery('sticker.getStickerByStickerFileSrl', $args);

		return $output;
	}

	function _getStickerDeleteMsg(){
		$oStickerModel = stickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();
		return $module_config->deleted_sticker;
	}

	function _deleteSticker($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.deleteSticker', $args);
		ModuleHandler::triggerCall('sticker.deleteSticker', 'after', $args);
		
		Cache::set(sprintf('sticker:item:%d', intval($sticker_srl)), null);
	}

	function _deleteStickerFile($sticker_srl, $file_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->file_srl = $file_srl;
		$output = executeQuery('sticker.deleteStickerFileByFileSrl', $args);

		$this->_deleteFile($file_srl);
	}

	function _deleteStickerFiles($sticker_srl){ // file_parent_srl
		$oFileController = fileController::getInstance();
		$output = $oFileController->deleteFiles($sticker_srl);

		$this->_deleteStickerFilesDB($sticker_srl);
	}

	function _deleteFile($file_srl){
		$oFileController = fileController::getInstance();
		$output = $oFileController->deleteFile($file_srl);
	}

	function _deleteTemporaryFile($sticker_main, $sticker_file){

	}

	function _updateFileStatus($sticker_srl){
		$args = new stdClass();
		$args->upload_target_srl = $sticker_srl;
		$args->isvalid = 'Y';
		$output = executeQuery('sticker.updateStickerFileValid', $args);
	}

	function _deleteStickerFilesDB($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		executeQuery('sticker.deleteStickerFilesByStickerSrl', $args);
	}

	function _deleteStickerBuyByStickerSrl($sticker_srl){
		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		executeQuery('sticker.deleteStickerBuyByStickerSrl', $args);
	}

	function _deleteStickerBuyByMemberSrl($member_srl, $sticker_srl){
		$args = new stdClass();
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->date = date("YmdHis");
		$output = executeQuery('sticker.deleteStickerBuyByMemberSrl', $args);

		return $output;
	}

}

/* End of file sticker.controller.php */
/* Location: ./modules/sticker/sticker.controller.php */
