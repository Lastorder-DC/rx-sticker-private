<?php
/*! Copyright (C) 2016 BGM STORAGE. All rights reserved. */
/**
 * @class  sticker
 * @author Huhani (mmia268@gmail.com)
 * @brief  Sticker module high class.
 */

class sticker extends ModuleObject
{
	function moduleInstall()
	{
		$oModuleModel = moduleModel::getInstance();
		$oModuleController = moduleController::getInstance();

		$sticker_info = $oModuleModel->getModuleInfoByMid('sticker');
		if(!$sticker_info || !$sticker_info->module_srl) {
			$args = new stdClass();
			$args->mid = 'sticker';
			$args->module = 'sticker';
			$args->browser_title = '스티커';
			$args->site_srl = 0;
			$args->skin = 'modern';
			$args->mskin = '/USE_RESPONSIVE/';
			$args->layout_srl = -1;
			$args->mlayout_srl = -1;
			$oModuleController->insertModule($args);
		}

		$defaults = (object)array(
			'use' => 'Y',
			'before_test' => 'N',
			'add_member_menu' => 'N',
			'browser_subtitle' => '',
			'quick_tags' => '',
			'notify_message_type' => 'text',
			'gif2mp4' => 'N',
			'skin_migrated' => 'Y',
			'list_count' => 12,
			'default_sticker' => '',
			'deleted_sticker' => '<i><p style="color: rgb(125, 125, 125);">존재하지 않는 스티커입니다.</p></i>',
			'buy_limit' => 15,
			'minPoint' => 0,
			'maxPoint' => 600,
			'returnPoint' => 15,
			'upload_charge' => 0,
			'sale_end_date' => 0,
			'use_date' => 0,
			'sale_limit' => 0,
			'limit_modify_buy' => 0,
			'public_modify' => 'Y',
			'check_modify' => 'Y',
			'pause_modify' => 'Y',
			'public_delete' => 'Y',
			'check_delete' => 'Y',
			'pause_delete' => 'Y',
			'limit_delete_buy' => 0,
			'resizing' => 'Y',
			'maxPx' => 120,
			'gifResizingIf' => 'Y',
			'target_width' => 'Y',
			'image_quality' => 100,
			'minUploads' => 5,
			'maxUploads' => 20,
			'image_min_width' => 40,
			'image_min_height' => 40,
			'file_size' => 2048,
			'file_size_all' => 25000,
			'file_ext' => 'jpg,jpeg,png,gif,webp,mp4',
			'cmt_allow_modify' => 'Y',
			'cmt_max_sticker_count' => 0,
			'doc_max_sticker_count' => 30,
		);
		$config = $oModuleModel->getModuleConfig('sticker') ?: new stdClass();
		foreach($defaults as $key => $value)
		{
			if(!isset($config->{$key}))
			{
				$config->{$key} = $value;
			}
		}
		$oModuleController->insertModuleConfig('sticker', $config);

		return new BaseObject();
	}




	function moduleUninstall()
	{
		$oModuleModel = moduleModel::getInstance();
		$oModuleController = moduleController::getInstance();

		//페이지 삭제
		$sticker_info = $oModuleModel->getModuleInfoByMid('sticker');
		if($sticker_info->module_srl) {
			$output = $oModuleController->deleteModule($sticker_info->module_srl);
			if(!$output->toBool()) {
				return $output;
			}
		}

		return new BaseObject();

	}




	function checkUpdate()
	{
		$oModuleModel = moduleModel::getInstance();
		$config = $oModuleModel->getModuleConfig('sticker');
		$required_keys = array('browser_subtitle', 'quick_tags', 'notify_message_type', 'gif2mp4', 'skin_migrated', 'list_count', 'doc_max_sticker_count');
		foreach($required_keys as $key){
			if(!$config || !isset($config->{$key})){
				return true;
			}
		}
		$file_extensions = array_map('strtolower', array_map('trim', explode(',', (string)($config->file_ext ?? ''))));
		if(array_diff(array('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'), $file_extensions))
		{
			return true;
		}
		return false;
	}

	function moduleUpdate()
	{
		$oModuleModel = moduleModel::getInstance();
		$oModuleController = moduleController::getInstance();
		$config = $oModuleModel->getModuleConfig('sticker');
		$config = $config ?: new stdClass();
		$migrate_skin = !isset($config->skin_migrated);
		$config->browser_subtitle = isset($config->browser_subtitle) ? $config->browser_subtitle : '';
		$config->quick_tags = isset($config->quick_tags) ? $config->quick_tags : '';
		$config->notify_message_type = isset($config->notify_message_type) ? $config->notify_message_type : 'text';
		$config->gif2mp4 = isset($config->gif2mp4) ? $config->gif2mp4 : 'N';
		$config->skin_migrated = 'Y';
		$config->list_count = isset($config->list_count) ? min(100, max(1, (int)$config->list_count)) : 12;
		$config->doc_max_sticker_count = isset($config->doc_max_sticker_count) ? max(0, (int)$config->doc_max_sticker_count) : 30;
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
		$oModuleController->insertModuleConfig('sticker', $config);

		$module_info = $migrate_skin ? $oModuleModel->getModuleInfoByMid('sticker') : null;
		if($module_info)
		{
			$module_info->skin = 'modern';
			$module_info->mskin = '/USE_RESPONSIVE/';
			$module_info->is_skin_fix = 'Y';
			$module_info->is_mskin_fix = 'N';
			$oModuleController->updateModule($module_info);
		}

		return new BaseObject();
	}

}

/* End of file sticker.class.php */
/* Location: ./modules/sticker/sticker.class.php */
