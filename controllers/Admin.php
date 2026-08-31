<?php
/*! Copyright (C) 2016 BGM STORAGE. All rights reserved. */

namespace Rhymix\Modules\Sticker\Controllers;

use BaseObject;
use Context;
use ModuleController;
use ModuleModel;
use Rhymix\Framework\Queue;
use Rhymix\Framework\Debug;
use Rhymix\Modules\Sticker\Models\Sticker as StickerModel;
use Rhymix\Modules\Sticker\Services\ImageProcessor;
use stdClass;
use Throwable;

/**
 * Handle sticker administration actions.
 *
 * @author Huhani (mmia268@dnip.co.kr)
 */

class Admin extends Sticker
{
	public function init()
	{
	}

	public function procStickerAdminConfig()
	{

		$oModuleController = ModuleController::getInstance();

		$config = Context::getRequestVars();
		getDestroyXeVars($config);
		unset($config->body);
		unset($config->_filter);
		unset($config->error_return_url);
		unset($config->act);
		unset($config->module);
		unset($config->ruleset);

		// Context discards empty parameters, so explicitly preserve settings that users may clear.
		foreach(array('browser_subtitle', 'quick_tags', 'default_sticker', 'deleted_sticker') as $key){
			if(!isset($config->{$key})){
				$config->{$key} = '';
			}
		}
		$current_config = StickerModel::getInstance()->getConfig();
		$config->notify_message_type = ($config->notify_message_type ?? 'text') === 'none' ? 'none' : 'text';
		$config->gif2mp4 = isset($config->gif2mp4) ? ($config->gif2mp4 === 'Y' ? 'Y' : 'N') : ($current_config->gif2mp4 ?? 'N');
		$config->skin_migrated = $current_config->skin_migrated ?? 'Y';
		$config->list_count = min(100, max(1, (int)($config->list_count ?? 12)));
		$config->doc_max_sticker_count = max(0, (int)($config->doc_max_sticker_count ?? 30));
		if($config->gif2mp4 === 'Y' && !ImageProcessor::isFfmpegAvailable())
		{
			return new BaseObject(-1, 'msg_stkr_cannot_use_ffmpeg');
		}

		if(!empty($config->browser_title)){
			$oModuleModel = ModuleModel::getInstance();
			$sticker_info = $oModuleModel->getModuleInfoByMid('sticker');
			$sticker_info->browser_title = $config->browser_title;
			unset($config->browser_title);
			$oModuleController->updateModule($sticker_info);
		}

		$output = $oModuleController->updateModuleConfig('sticker', $config);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_saved');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminSkin');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Add one bounded batch of existing GIF stickers to the MP4 conversion queue.
	 *
	 * A log row is inserted before each task is queued. This prevents the next batch from
	 * repeatedly selecting missing legacy files, and makes every result traceable.
	 *
	 * @return BaseObject|null
	 */
	public function procStickerAdminMigrateGifToMp4()
	{
		if(Context::getRequestMethod() !== 'POST')
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		if(!config('queue.enabled'))
		{
			return new BaseObject(-1, 'msg_stkr_queue_required');
		}

		$oStickerModel = StickerModel::getInstance();
		$module_config = $oStickerModel->getConfig();
		if(($module_config->gif2mp4 ?? 'N') !== 'Y')
		{
			return new BaseObject(-1, 'msg_stkr_gif2mp4_disabled');
		}

		$batch_size = intval(Context::get('batch_size'));
		$batch_size = min(100, max(1, $batch_size ?: 20));
		$query_args = new stdClass();
		$query_args->ext = '.gif';
		$query_args->list_count = $batch_size;
		$query_args->page_count = 1;
		$query_args->page = 1;
		$query_args->order_type = 'asc';
		$output = executeQueryArray('sticker.getGifStickerFiles', $query_args);
		if(!$output->toBool())
		{
			return $output;
		}

		$queued_count = 0;
		foreach((array)$output->data as $row)
		{
			$local_path = ImageProcessor::resolveLocalPath((string)$row->url);
			$original_size = file_exists($local_path) ? intval(filesize($local_path)) : 0;
			if(!ImageProcessor::createConversionLog($row, $original_size))
			{
				continue;
			}

			try
			{
				$queue_result = Queue::addTask(ImageProcessor::class . '::processAsync', (object)array(
					'sticker_srl' => $row->sticker_srl,
					'sticker_file_srl' => $row->sticker_file_srl,
					'file_srl' => $row->file_srl,
					'uploaded_filename' => $row->url,
				));
				if($queue_result < 1)
				{
					ImageProcessor::updateConversionLog(intval($row->sticker_file_srl), 'FAILED', 'queue_add_failed');
					continue;
				}
				$queued_count++;
			}
			catch(Throwable $e)
			{
				ImageProcessor::updateConversionLog(intval($row->sticker_file_srl), 'FAILED', 'queue_add_failed');
				Debug::addEntry($e);
			}
		}

		if($queued_count > 0)
		{
			$this->setMessage(sprintf(Context::getLang('msg_stkr_gif2mp4_queued'), $queued_count));
		}
		else
		{
			$this->setMessage('msg_stkr_no_gif_to_migrate');
		}

		$returnUrl = Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminConfig');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Requeue one incomplete GIF conversion from the admin log.
	 *
	 * The worker performs the same path and database consistency checks again, so retrying
	 * a task that is still in Redis does not overwrite a newer sticker file.
	 *
	 * @return BaseObject|null
	 */
	public function procStickerAdminRetryGifConversion()
	{
		if(Context::getRequestMethod() !== 'POST')
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		if(!config('queue.enabled'))
		{
			return new BaseObject(-1, 'msg_stkr_queue_required');
		}

		$sticker_file_srl = intval(Context::get('sticker_file_srl'));
		if($sticker_file_srl < 1)
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$log_output = executeQuery('sticker.getGifConversionLog', (object)array(
			'sticker_file_srl' => $sticker_file_srl,
		));
		$log = $log_output->data ?? null;
		$file_output = executeQuery('sticker.getStickerFileByStickerFileSrl', (object)array(
			'sticker_file_srl' => $sticker_file_srl,
		));
		$file = $file_output->data ?? null;
		if(!$log || !$file || !preg_match('/\.gif$/i', (string)$file->url))
		{
			return new BaseObject(-1, 'msg_stkr_gif_retry_unavailable');
		}

		try
		{
			ImageProcessor::updateConversionLog($sticker_file_srl, 'QUEUED', 'requeued', '', null, 0);
			$queue_result = Queue::addTask(ImageProcessor::class . '::processAsync', (object)array(
				'sticker_srl' => $file->sticker_srl,
				'sticker_file_srl' => $file->sticker_file_srl,
				'file_srl' => $file->file_srl,
				'uploaded_filename' => $file->url,
			));
			if($queue_result < 1)
			{
				ImageProcessor::updateConversionLog($sticker_file_srl, 'FAILED', 'queue_add_failed');
				return new BaseObject(-1, 'msg_stkr_gif_queue_failed');
			}
		}
		catch(Throwable $e)
		{
			ImageProcessor::updateConversionLog($sticker_file_srl, 'FAILED', 'queue_add_failed');
			Debug::addEntry($e);
			return new BaseObject(-1, 'msg_stkr_gif_queue_failed');
		}

		$this->setMessage('success_stkr_gif_requeued');
		$returnUrl = Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminGifConversionLog');
		$this->setRedirectUrl($returnUrl);
	}

	public function procStickerAdminDesign(){

		if(Context::getRequestMethod() === 'GET')
		{
			return new BaseObject(-1, 'msg_invalid_request');
		}

		$oModuleController = ModuleController::getInstance();

		$oModuleModel = ModuleModel::getInstance();
		$sticker_info = $oModuleModel->getModuleInfoByMid('sticker');
		if($sticker_info){
			$skin_list = $oModuleModel->getSkins($this->module_path);
			$mskin_list = $oModuleModel->getSkins($this->module_path, 'm.skins');
			$skin = (string)Context::get('skin');
			$mskin = (string)Context::get('mskin');
			$sticker_info->skin = isset($skin_list[$skin]) ? $skin : 'modern';
			$sticker_info->mskin = ($mskin === '/USE_RESPONSIVE/' || isset($mskin_list[$mskin])) ? $mskin : '/USE_RESPONSIVE/';
			$sticker_info->is_skin_fix = 'Y';
			$sticker_info->is_mskin_fix = $sticker_info->mskin === '/USE_RESPONSIVE/' ? 'N' : 'Y';
			$sticker_info->layout_srl = Context::get('layout_srl');
			$sticker_info->mlayout_srl = Context::get('mlayout_srl');

			$oModuleController->updateModule($sticker_info);
		}

		$this->setMessage('success_saved');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminDesign');
		$this->setRedirectUrl($returnUrl);

	}

	public function procStickerAdminUpdate(){

		$sticker_srl = Context::get('sticker_srl');
		$config = Context::getRequestVars();
		getDestroyXeVars($config);
		unset($config->body);
		unset($config->_filter);
		unset($config->error_return_url);
		unset($config->act);
		unset($config->module);
		unset($config->ruleset);

		$oStickerModel = StickerModel::getInstance();
		$oSticker = $oStickerModel->getSticker($sticker_srl);
		if(!$oSticker){
			return new BaseObject(-1,'msg_invalid_sticker');
		}

		$config->start_hour = empty($config->start_hour) ? 0 : intval($config->start_hour);
		$config->start_minute = empty($config->start_minute) ? 0 : intval($config->start_minute);
		$config->start_second = empty($config->start_second) ? 0 : intval($config->start_second);

		$config->end_hour = empty($config->end_hour) ? 0 : intval($config->end_hour);
		$config->end_minute = empty($config->end_minute) ? 0 : intval($config->end_minute);
		$config->end_second = empty($config->end_second) ? 0 : intval($config->end_second);

		$start_date = null;
		if(!empty($config->start_date) &&
			strlen($config->start_date) == 8 &&
			checkdate(substr($config->start_date, 4, 2), substr($config->start_date, -2), substr($config->start_date, 0, 4)) &&
			($config->start_hour >= 0 && $config->start_hour < 24) &&
			($config->start_minute >= 0 && $config->start_minute < 60) &&
			($config->start_second >= 0 && $config->start_second < 60)
		){
			$start_date = $config->start_date . (strlen($config->start_hour) == 1 ? ('0'.$config->start_hour) : $config->start_hour) . (strlen($config->start_minute) == 1 ? ('0'.$config->start_minute) : $config->start_minute) . (strlen($config->start_second) == 1 ? ('0'.$config->start_second) : $config->start_second);
		}


		$end_date = null;
		if(!empty($config->end_date) &&
			strlen($config->end_date) == 8 &&
			checkdate(substr($config->end_date, 4, 2), substr($config->end_date, -2), substr($config->end_date, 0, 4)) &&
			($config->end_hour >= 0 && $config->end_hour < 24) &&
			($config->end_minute >= 0 && $config->end_minute < 60) &&
			($config->end_second >= 0 && $config->end_second < 60)
		){
			$end_date = $config->end_date . (strlen($config->end_hour) == 1 ? ('0'.$config->end_hour) : $config->end_hour) . (strlen($config->end_minute) == 1 ? ('0'.$config->end_minute) : $config->end_minute) . (strlen($config->end_second) == 1 ? ('0'.$config->end_second) : $config->end_second);
		}

		$sequence = getNextSequence();
		$logged_info = Context::get('logged_info');

		$title = empty($config->title) ? $oSticker->title : $config->title;
		$content = empty($config->content) ? $oSticker->content : $config->content;
		$status = array('PUBLIC', 'CHECK', 'PAUSE', 'STOP');

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$args->title = cut_str(htmlspecialchars(strip_tags($title), ENT_QUOTES, 'UTF-8', false), 100);
		$args->tag = cut_str(htmlspecialchars(strip_tags((string)($config->tag ?? '')), ENT_QUOTES, 'UTF-8', false), 250);
		$args->content = removeHackTag($content);

		if(!empty($config->readed_count_e) && $config->readed_count_e === 'Y'){
			$args->readed_count = empty($config->readed_count) ? 0 : intval($config->readed_count);
		}
		if(!empty($config->bought_count_e) && $config->bought_count_e === 'Y'){
			$args->bought_count = empty($config->bought_count) ? 0 : intval($config->bought_count);
		}
		if(!empty($config->used_count_e) && $config->used_count_e === 'Y'){
			$args->used_count = empty($config->used_count) ? 0 : intval($config->used_count);
		}

		$args->start_date = $start_date;
		$args->end_date = $end_date;
		$args->price = empty($config->price) ? 0 : intval($config->price);
		$args->buy_limit = empty($config->buy_limit) ? 0 : intval($config->buy_limit);
		$args->exptime = empty($config->exptime) ? null : intval($config->exptime);

		$args->last_update = date('YmdHis');
		$args->last_updater = $logged_info->nick_name;
		$args->list_order = $sequence * -1;
		$args->status = in_array($config->status ?? '', $status, true) ? $config->status : "PUBLIC";

		$output = executeQuery('sticker.updateStickerAdmin', $args);
		if (!$output->toBool()) {
			return $output;
		}

		$oStickerModel->clearStickerCache($sticker_srl);

		$this->setMessage('success_saved');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminStickerView', 'sticker_srl', $sticker_srl);
		$this->setRedirectUrl($returnUrl);

	}

	public function procStickerAdminDelete(){

		$sticker_srl = Context::get('sticker_srl');
		$oStickerModel = StickerModel::getInstance();
		$oSticker = $oStickerModel->getSticker($sticker_srl);
		if(!$oSticker){
			return new BaseObject(-1,'msg_invalid_sticker');
		}

		$this->_deleteSticker($sticker_srl);
		$this->_deleteStickerFiles($sticker_srl);
		$this->_deleteStickerBuyByStickerSrl($sticker_srl);

		$this->setMessage('success_deleted');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminStickerList');
		$this->setRedirectUrl($returnUrl);

	}

	public function procStickerAdminBuyUpdate(){
		$idx = Context::get('idx');
		$config = Context::getRequestVars();
		getDestroyXeVars($config);

		$args = new stdClass();
		$args->idx = $idx;
		$output = executeQuery('sticker.getStickerBuyByIdx', $args);
		if (!$output->toBool()) {
			return $output;
		}
		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_buy_sticker');
		}

		$expdate_hour = empty($config->expdate_hour) ? 0 : intval($config->expdate_hour);
		$expdate_minute = empty($config->expdate_minute) ? 0 : intval($config->expdate_minute);
		$expdate_second = empty($config->expdate_second) ? 0 : intval($config->expdate_second);

		$expdate = null;
		if(!empty($config->expdate) &&
			strlen($config->expdate) == 8 &&
			checkdate(substr($config->expdate, 4, 2), substr($config->expdate, -2), substr($config->expdate, 0, 4)) &&
			($expdate_hour >= 0 && $expdate_hour < 24) &&
			($expdate_minute >= 0 && $expdate_minute < 60) &&
			($expdate_second >= 0 && $expdate_second < 60)
		){
			$expdate = $config->expdate . (strlen($expdate_hour) == 1 ? ('0'.$expdate_hour) : $expdate_hour) . (strlen($expdate_minute) == 1 ? ('0'.$expdate_minute) : $expdate_minute) . (strlen($expdate_second) == 1 ? ('0'.$expdate_second) : $expdate_second);
		}

		$use_point = empty($config->use_point) ? 0 : intval($config->use_point);
		$args1 = new stdClass();
		$args1->idx = $idx;
		$args1->expdate = $expdate;
		$args1->use_point = $use_point;
		if(!empty($config->used_count_e) && $config->used_count_e === "Y"){
			$args1->used_count = empty($config->used_count) ? 0 : intval($config->used_count);
		}

		$output1 = executeQuery('sticker.updateStickerBuyInfo', $args1);
		if (!$output1->toBool()) {
			return $output1;
		}

		$this->setMessage('success_saved');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminBuyInfo', 'idx', $idx);
		$this->setRedirectUrl($returnUrl);

	}

	public function procStickerAdminBuyDelete(){
		$idx = Context::get('idx');
		$args = new stdClass();
		$args->idx = $idx;

		$output = executeQuery('sticker.getStickerBuyByIdx', $args);
		if (!$output->toBool()) {
			return $output;
		}
		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_buy_sticker');
		}

		executeQuery('sticker.deleteStickerBuyByIdx', $args);

		$this->setMessage('success_deleted');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminBuyList');
		$this->setRedirectUrl($returnUrl);

	}

	public function procStickerAdminLogClear(){
		$select_date = intval(Context::get('select_date'));
		$date = date("YmdHis", mktime(date('H'), date('i'), date('s'), date('m'), date('d') - $select_date, date('Y')));

		$args = new stdClass();
		$args->date = $date;
		executeQuery('sticker.deleteStickerLog', $args);

		$this->setMessage('success_deleted');
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispStickerAdminLogList');
		$this->setRedirectUrl($returnUrl);

	}
}

