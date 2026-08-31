<?php
/*! Copyright (C) 2016 BGM STORAGE. All rights reserved. */
use Rhymix\Framework\Cache;
use Rhymix\Modules\Sticker\Services\ImageProcessor;
/**
 * @class  stickerModel
 * @author Huhani (mmia268@gmail.com)
 * @brief  Sticker module model class.
 */

class stickerModel extends sticker
{
	function init()
	{
	}

	function getConfig()
	{
		static $config = null;
		if(is_null($config))
		{
			$oModuleModel = moduleModel::getInstance();
			$config = $oModuleModel->getModuleConfig('sticker');
			if(!$config)
			{
				$config = new stdClass;
			}

			unset($config->body);
			unset($config->_filter);
			unset($config->error_return_url);
			unset($config->act);
			unset($config->module);

			$file_extensions = array_filter(array_map('trim', explode(',', (string)($config->file_ext ?? ''))));
			$normalized_extensions = array_map('strtolower', $file_extensions);
			foreach(array('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4') as $extension)
			{
				if(!in_array($extension, $normalized_extensions, true))
				{
					$file_extensions[] = $extension;
					$normalized_extensions[] = $extension;
				}
			}
			$config->file_ext = implode(',', $file_extensions);
		}

		return $config;
	}

	function getCommentStickerList(){

		$logged_info =  Context::get('logged_info');
		$sticker_array = $this->getDefaultSticker();

		$defaultStickerCount = count($sticker_array);
		$page = Context::get('page') ? Context::get('page') : 1;
		$date = date('YmdHis');

		$list_count = Mobile::isMobileCheckByAgent() ? 10 : 10;

		if($logged_info){
			$args = new stdClass();
			$args->page = $page;
			$args->list_count = $page == 1 ? ($list_count-$defaultStickerCount) : $list_count;
			$args->page_count = 2;
			$args->order_type = 'asc';
			$args->member_srl = $logged_info->member_srl;
			$args->date = $date;
			$output2 = executeQueryArray('sticker.getStickerMylist', $args);

			$count = $page > 1 || $defaultStickerCount == 5 ? $defaultStickerCount : 0;

			if($page > 1){
				unset($sticker_array);
				$sticker_array = array();
				$prev_page = new stdClass();
				$prev_page->page = $page-1;
				$prev_page->list_count = $list_count;
				$prev_page->order_type = 'asc';
				$prev_page->member_srl = $logged_info->member_srl;
				$prev_page->date = $date;
				$output = executeQueryArray('sticker.getStickerMylist', $prev_page);
				$prev_data = !empty($output->data) ? $output->data : array();
				$prev_page_count = count($prev_data);

				if($prev_page_count > $list_count-$defaultStickerCount){
					end($prev_data);
					$countMovePos = $defaultStickerCount && $defaultStickerCount - ($list_count - $prev_page_count) > 0 ? $defaultStickerCount - ($list_count - $prev_page_count) : $defaultStickerCount;
					for($i=1; $i<$countMovePos; $i++){
						prev($prev_data);
					}
					for($i=$countMovePos; $i>0; $i--){
						$current = current($prev_data);

						$obj = new stdClass();
						$obj->sticker_srl = $current->sticker_srl;
						$obj->title = $current->title;
						$obj->main_image = $current->main_image;

						if($i !== 1){
							next($prev_data);
						}
						array_push($sticker_array, $obj);
						$count++;
					}
				}
			}


			foreach((array)$output2->data as $sticker){
				if($count >= $list_count){
					break;
				}
				$obj = new stdClass();
				$obj->sticker_srl = $sticker->sticker_srl;
				$obj->title = $sticker->title;
				$obj->main_image = $sticker->main_image;
				array_push($sticker_array, $obj);
				$count++;
			}

			//$this->add("page_navigation", $output2->page_navigation);

		}

		$this->add("sticker", $sticker_array);

	}

	/**
	 * Return every default and purchased sticker pack for an editor picker.
	 *
	 * @return BaseObject|null
	 */
	public function getStickerPickerList()
	{
		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? intval($logged_info->member_srl) : 0;
		$config = $this->getConfig();
		$sticker_array = array();
		$seen = array();

		foreach(explode(',', (string)($config->default_sticker ?? '')) as $sticker_srl)
		{
			$sticker_srl = trim($sticker_srl);
			if(!$sticker_srl || isset($seen[$sticker_srl]))
			{
				continue;
			}

			$pack = $this->_getPickerPack(intval($sticker_srl));
			if($pack)
			{
				$sticker_array[] = $pack;
				$seen[$sticker_srl] = true;
			}
		}

		if($member_srl)
		{
			$args = new stdClass();
			$args->page = 1;
			$args->list_count = 10000;
			$args->page_count = 1;
			$args->order_type = 'asc';
			$args->member_srl = $member_srl;
			$args->date = date('YmdHis');
			$output = executeQueryArray('sticker.getStickerMylist', $args);
			if(!$output->toBool())
			{
				return $output;
			}

			foreach((array)$output->data as $sticker)
			{
				if(isset($seen[$sticker->sticker_srl]))
				{
					continue;
				}

				$pack = $this->_getPickerPack(intval($sticker->sticker_srl));
				if($pack)
				{
					$sticker_array[] = $pack;
					$seen[$sticker->sticker_srl] = true;
				}
			}
		}

		$this->add('sticker', $sticker_array);
	}

	function getStickerElemList(){
		$sticker_srl = Context::get('sticker_srl');
		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? $logged_info->member_srl : 0;

		if(!$sticker_srl){
			return new BaseObject(-1,'invalid_sticker');
		}

		$isDefaultSticker = $this->checkDefaultSticker($sticker_srl);
		if(!$isDefaultSticker){
			if(!$member_srl){
				return new BaseObject(-1,'invalid_sticker');
			}

			$isAccessable = $this->checkBuySticker($member_srl, $sticker_srl);
			if(!$isAccessable){
				return new BaseObject(-1,'invalid_sticker');
			}
		}

		$stickerImageArray = array();

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQueryArray('sticker.getStickerImage', $args);
		if(!$output->toBool() || empty($output->data)){
			return new BaseObject(-1,'invalid_sticker');
		}
		foreach($output->data as $value){
			array_push($stickerImageArray, $this->_getStickerMedia($value));
		}

		$this->add("stickerImage", $stickerImageArray);

	}

	/**
	 * Resolve a bounded set of editor sticker identities to current media data.
	 *
	 * @return BaseObject|null
	 */
	public function resolveStickers()
	{
		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info ? intval($logged_info->member_srl) : 0;
		$requested = json_decode((string)Context::get('stickers'), true);
		if(!is_array($requested))
		{
			return new BaseObject(-1, 'invalid_sticker');
		}

		$resolved = array();
		$seen = array();
		foreach(array_slice($requested, 0, 200) as $item)
		{
			if(!is_array($item))
			{
				continue;
			}

			$sticker_srl = intval($item['sticker_srl'] ?? 0);
			$sticker_file_srl = intval($item['sticker_file_srl'] ?? 0);
			$key = $sticker_srl . '|' . $sticker_file_srl;
			if(!$sticker_srl || !$sticker_file_srl || isset($seen[$key]))
			{
				continue;
			}
			$seen[$key] = true;

			$obj = new stdClass();
			$obj->sticker_srl = $sticker_srl;
			$obj->sticker_file_srl = $sticker_file_srl;
			$obj->valid = false;
			if(!$this->_canUseSticker($member_srl, $sticker_srl, $logged_info))
			{
				$resolved[] = $obj;
				continue;
			}

			$output = executeQuery('sticker.getStickerByStickerFileSrl', (object)array(
				'sticker_file_srl' => $sticker_file_srl,
			));
			if(
				!$output->toBool() ||
				empty($output->data) ||
				intval($output->data->sticker_srl) !== $sticker_srl ||
				($output->data->status ?? 'STOP') === 'STOP'
			)
			{
				$resolved[] = $obj;
				continue;
			}

			$media = $this->_getStickerMedia($output->data);
			$obj->valid = true;
			$obj->title = $output->data->title;
			$obj->name = $media->name;
			$obj->type = $media->type;
			$obj->url = $media->url;
			$obj->poster = $media->poster;
			$resolved[] = $obj;
		}

		$this->add('stickers', $resolved);
	}

	/**
	 * Build a sticker pack summary for the editor picker.
	 *
	 * @param int $sticker_srl Sticker serial number.
	 * @return object|false
	 */
	protected function _getPickerPack(int $sticker_srl)
	{
		$sticker = $this->getSticker($sticker_srl);
		if(!$sticker || $sticker->status === 'STOP')
		{
			return false;
		}

		$output = executeQueryArray('sticker.getStickerMainImage', (object)array(
			'sticker_srl' => $sticker_srl,
			'no' => 0,
		));
		if(!$output->toBool() || empty($output->data[0]))
		{
			return false;
		}

		$media = $this->_getStickerMedia($output->data[0]);
		$obj = new stdClass();
		$obj->sticker_srl = $sticker->sticker_srl;
		$obj->title = $sticker->title;
		$obj->main_image = $media->poster;
		$obj->type = $media->type;
		$obj->url = $media->url;
		$obj->poster = $media->poster;

		return $obj;
	}

	/**
	 * Normalize sticker media data for image and MP4 clients.
	 *
	 * @param object $value Sticker file data.
	 * @return object
	 */
	protected function _getStickerMedia(object $value): object
	{
		$obj = new stdClass();
		$obj->sticker_srl = $value->sticker_srl ?? null;
		$obj->sticker_file_srl = $value->sticker_file_srl;
		$obj->name = htmlspecialchars(pathinfo((string)$value->file_name, PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8', false);
		$obj->type = ImageProcessor::isMp4((string)$value->url) ? 'video' : 'image';
		$obj->url = $value->url;
		$obj->poster = $obj->type === 'video' ? ImageProcessor::getPosterUrl((string)$value->url) : $value->url;

		return $obj;
	}

	/**
	 * Check whether the current member may use a sticker pack.
	 *
	 * @param int $member_srl Member serial number.
	 * @param int $sticker_srl Sticker serial number.
	 * @param object|null $logged_info Logged-in member data.
	 * @return bool
	 */
	protected function _canUseSticker(int $member_srl, int $sticker_srl, ?object $logged_info = null): bool
	{
		if($logged_info && ($logged_info->is_admin ?? 'N') === 'Y')
		{
			return true;
		}

		return $this->checkDefaultSticker($sticker_srl) || ($member_srl && $this->checkBuySticker($member_srl, $sticker_srl));
	}

	function getCommentSticekrCountByDocumentSrl($document_srl = 0, $member_srl = 0){
		$args = new stdClass();
		$args->document_srl = $document_srl;
		$args->member_srl = $member_srl;
		$args->content = "{@sticker:";
		$output = executeQuery('sticker.getCommentStickerByMemberSrl', $args);
		if(!$output->toBool()){
			return false;
		}
		$comments = $output->data;
		if(empty($comments)){
			return 0;
		}
		$typeComment = gettype($comments);
		$count = 0;

		if($typeComment === 'BaseObject'){
			if(preg_match('/{@sticker:[0-9]+\|[0-9]+}/i', $comments->content)){
				$count++;
			}
		} else {
			foreach((array)$comments as $value){
				if(preg_match('/{@sticker:[0-9]+\|[0-9]+}/i', $value->content)){
					$count++;
				}
			}
		}

		return $count;
	}

	function getSticker($sticker_srl){
		$sticker_srl = intval($sticker_srl);
		if($sticker_srl < 1){
			return false;
		}

		$cache_key = sprintf('sticker:item:%d', $sticker_srl);
		$cached_sticker = Cache::get($cache_key);
		if($cached_sticker !== null){
			return $cached_sticker;
		}

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.getSticker', $args);

		$sticker = !empty($output->data) ? $output->data : false;
		Cache::set($cache_key, $sticker);

		return $sticker;
	}

	function clearStickerCache($sticker_srl){
		$sticker_srl = intval($sticker_srl);
		if($sticker_srl < 1){
			return;
		}

		$cache_key = sprintf('sticker:item:%d', $sticker_srl);
		Cache::set($cache_key, null);
	}

	function getDefaultSticker(){
		$config = $this->getConfig();
		$defaultSticker = isset($config->default_sticker) ? $config->default_sticker : '';
		$sticker = explode(',', $defaultSticker);
		$stickerArray = array();
		foreach($sticker as $key=>$value){
			$value = trim($value);
			$oSticker = $this->getSticker($value);

			if($key < 5 && $oSticker && $oSticker->status != "STOP"){
				$obj = new stdClass();
				$obj->sticker_srl = $oSticker->sticker_srl;
				$obj->title = $oSticker->title;
				$obj->main_image = $oSticker->main_image;

				array_push($stickerArray, $obj);
			}
		}

		return $stickerArray;
	}

	function checkDefaultSticker($sticker_srl){
		$config = $this->getConfig();
		$defaultSticker = isset($config->default_sticker) ? $config->default_sticker : '';
		$sticker = explode(',', $defaultSticker);
		foreach($sticker as $value){
			$value = trim($value);
			if($value == $sticker_srl){
				return true;
				break;
			}
		}

		return false;
	}

	function checkBuySticker($member_srl = 0, $sticker_srl = 0){
		$args = new stdClass();
		$args->member_srl = $member_srl;
		$args->sticker_srl = $sticker_srl;
		$args->date = date("YmdHis");
		$output = executeQuery('sticker.getStickerBuyCheck', $args);
		return $output->toBool() && !empty($output->data) && intval($output->data->count) > 0;
	}

}

/* End of file sticker.model.php */
/* Location: ./modules/sticker/sticker.model.php */
