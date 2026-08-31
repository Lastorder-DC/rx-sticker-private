<?php
/**
 * @class stickerAdminView
 * @author Huhani (mmia268@gmail.com)
 * @brief Sticker 모듈의 admin.view class
 **/

class stickerAdminView extends sticker
{
	public function init(){

		$oModuleModel = moduleModel::getInstance();
		$this->module_info = $oModuleModel->getModuleInfoByMid("sticker");

		$search_target = (string)Context::get('search_target');
		$search_keyword = (string)Context::get('search_keyword');
		if($search_target === 'ipaddress' && !$this->canViewIp())
		{
			$search_target = '';
			$search_keyword = '';
		}

		Context::set('search_target', $search_target);
		Context::set('search_keyword', $search_keyword);
		Context::set('page', Context::get('page') ? intval(Context::get('page')) : 1);
		Context::set('sort_index', (string)Context::get('sort_index'));
		Context::set('order_type', (string)Context::get('order_type'));

		$this->setTemplatePath($this->module_path.'tpl');
	}

	/**
	 * Check whether the current member may view stored IP addresses.
	 *
	 * @return bool
	 */
	private function canViewIp(): bool
	{
		$logged_info = Context::get('logged_info');
		return $logged_info && (int)$logged_info->member_srl === 4;
	}

	/**
	 * Remove IP addresses before data is exposed through Context.
	 *
	 * @param object|array|null $data
	 * @return void
	 */
	private function redactIpAddresses($data): void
	{
		if($this->canViewIp())
		{
			return;
		}

		foreach(is_array($data) ? $data : array($data) as $item)
		{
			if(is_object($item))
			{
				unset($item->ipaddress);
			}
		}
	}


	function dispStickerAdminStickerList(){
		$search_target = Context::get('search_target');
		$search_keyword = Context::get('search_keyword');

		$args = new stdClass();
		$columnList = array('title', 'tag', 'nick_name', 'ipaddress', 'member_srl', 'regdate', 'exptime', 'status');
		$search_target = Context::get('search_target');
		if($search_target && in_array($search_target, $columnList)) {
			if($search_target == "status" && $search_keyword == "READY"){
				$args->ready = date("YmdHis");
			} else if($search_target == "status" && $search_keyword == "EXPIRED"){
				$args->expdate = date("YmdHis");
			} else {
				$args->{"s_".Context::get('search_target')} = Context::get('search_keyword') ? Context::get('search_keyword') : null;
			}
		}
		$args->sort_index = Context::get('sort_index') ? Context::get('sort_index') : 'regdate';
		$args->order_type = Context::get('order_type') ? Context::get('order_type') : 'desc';
		$args->list_count = 20;
		$args->page = Context::get('page') ? Context::get('page') : 1;
		$output = executeQueryArray('sticker.getStickerAdminList', $args);
		$this->redactIpAddresses($output->data);

		Context::set('list', $output->data);
		Context::set('page_navigation', $output->page_navigation);

		$this->setTemplateFile('sticker_list');
	}

	function dispStickerAdminStickerView(){

		$logged_info =  Context::get('logged_info');
		$sticker_srl = Context::get('sticker_srl');

		$args = new stdClass();
		$args->sticker_srl = $sticker_srl;
		$output = executeQuery('sticker.getSticker', $args);
		$output1 = executeQueryArray('sticker.getStickerImage', $args);

		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_sticker');
		}

		$output->data->sticker_editor = htmlspecialchars($output->data->content, ENT_COMPAT | ENT_HTML401, 'UTF-8', false);
		$this->redactIpAddresses($output->data);

		$oFileModel = fileModel::getInstance();
		foreach((array)$output1->data as &$value){
			$oFileInfo = $oFileModel->getFile($value->file_srl);
			if(is_object($oFileInfo))
			{
				$oFileInfo = clone $oFileInfo;
			}
			$this->redactIpAddresses($oFileInfo);
			$value->file_info = $oFileInfo;
		}

		$oEditorModel = editorModel::getInstance();
		$option = new stdClass();
		$option->primary_key_name = 'sticker_srl';
		$option->content_key_name = 'content';
		$option->allow_fileupload = FALSE;
		$option->enable_autosave = FALSE;
		$option->enable_default_component = TRUE;
		$option->enable_component = FALSE;
		$option->resizable = TRUE;
		$option->disable_html = FALSE;
		$option->skin = 'ckeditor';
		$option->height = 200;
		$editor = $oEditorModel->getEditor($logged_info->member_srl, $option);

		Context::set('editor', $editor);
		Context::set('oSticker', $output->data);
		Context::set('oStickerImage', $output1->data);

		$this->setTemplateFile('sticker_view');
	}

	function dispStickerAdminBuyList(){
		$search_target = Context::get('search_target');
		$search_keyword = Context::get('search_keyword');

		$args = new stdClass();
		$columnList = array('sticker_srl', 'member_srl', 'option', 'use_point' , 'ipaddress', 'expdate', 'regdate', 'status');
		if($search_target && in_array($search_target, $columnList)) {
			if($search_target == 'status'){
				$args->{Context::get('search_keyword') != 'ACTIVE' ? 'inactive' : 'active'} = date("YmdHis");
			} else {
				$args->{"s_".Context::get('search_target')} = Context::get('search_keyword') ? Context::get('search_keyword') : null;
			}
		}
		$args->sort_index = Context::get('sort_index') ? Context::get('sort_index') : 'regdate';
		$args->order_type = Context::get('order_type') ? Context::get('order_type') : 'desc';
		$args->list_count = 20;
		$args->page = Context::get('page') ? Context::get('page') : 1;
		$output = executeQueryArray('sticker.getStickerBuyList'.($search_target == 'status' && $search_keyword == 'ACTIVE' ? "ByActive" : ""), $args);
		$this->redactIpAddresses($output->data);

		$oMemberModel = memberModel::getInstance();
		$oStickerModel = stickerModel::getInstance();
		foreach((array)$output->data as &$value){
			$oMember = $oMemberModel->getMemberInfoByMemberSrl($value->member_srl);
			$value->nick_name = $oMember ? $oMember->nick_name : '';

			$oSticker = $oStickerModel->getSticker($value->sticker_srl);
			$value->title = $oSticker ? $oSticker->title : '';
			$value->main_image = $oSticker ? $oSticker->main_image : '';
		}
		Context::set('date', date("YmdHis"));
		Context::set('list', $output->data);
		Context::set('page_navigation', $output->page_navigation);

		$this->setTemplateFile('buy_list');
	}

	function dispStickerAdminBuyInfo(){
		$idx = Context::get('idx');

		$args = new stdClass();
		$args->idx = $idx;
		$output = executeQuery('sticker.getStickerBuyByIdx', $args);
		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_data');
		}

		$oStickerModel = stickerModel::getInstance();
		$oSticker = $oStickerModel->getSticker($output->data->sticker_srl);
		if(!$oSticker){
			return new BaseObject(-1,'msg_invalid_sticker');
		}
		$oSticker = clone $oSticker;
		$this->redactIpAddresses($oSticker);

		$oMemberModel = memberModel::getInstance();
		$oMember = $oMemberModel->getMemberInfoByMemberSrl($output->data->member_srl);
		$output->data->nick_name = $oMember ? $oMember->nick_name : '';
		$this->redactIpAddresses($output->data);

		Context::set('date', date('YmdHis'));
		Context::set('oBuyInfo', $output->data);
		Context::set('oSticker', $oSticker);

		$this->setTemplateFile('buy_view');
	}

	function dispStickerAdminLogList(){
		$search_target = Context::get('search_target');
		$search_keyword = Context::get('search_keyword');

		$args = new stdClass();
		$columnList = array('sticker_srl', 'member_srl', 'type', 'ipaddress', 'regdate');
		if($search_target && in_array($search_target, $columnList)) {
			$args->{"s_".Context::get('search_target')} = Context::get('search_keyword') ? Context::get('search_keyword') : null;
		}
		$args->sort_index = Context::get('sort_index') ? Context::get('sort_index') : 'regdate';
		$args->order_type = Context::get('order_type') ? Context::get('order_type') : 'desc';
		$args->list_count = 20;
		$args->page = Context::get('page') ? Context::get('page') : 1;
		$output = executeQueryArray('sticker.getStickerLogs', $args);
		$this->redactIpAddresses($output->data);

		$oMemberModel = memberModel::getInstance();
		$oStickerModel = stickerModel::getInstance();
		foreach((array)$output->data as &$value){
			$oMember = $oMemberModel->getMemberInfoByMemberSrl($value->member_srl);
			$value->nick_name = $oMember ? $oMember->nick_name : '';

			$oSticker = $oStickerModel->getSticker($value->sticker_srl);
			$value->title = $oSticker ? $oSticker->title : '';
			$value->main_image = $oSticker ? $oSticker->main_image : '';
		}
		Context::set('list', $output->data);
		Context::set('page_navigation', $output->page_navigation);

		$this->setTemplateFile('log_list');
	}

	/**
	 * Display the latest conversion state of each legacy GIF sticker file.
	 *
	 * @return BaseObject|null
	 */
	public function dispStickerAdminGifConversionLog()
	{
		$allowed_statuses = array('QUEUED', 'PROCESSING', 'SUCCESS', 'SKIPPED', 'FAILED');
		$status = strtoupper((string)Context::get('conversion_status'));
		if(!in_array($status, $allowed_statuses, true))
		{
			$status = '';
		}
		$sticker_srl = max(0, intval(Context::get('sticker_srl')));

		$args = new stdClass();
		$args->status = $status ?: null;
		$args->sticker_srl = $sticker_srl ?: null;
		$args->sort_index = 'last_update';
		$args->order_type = 'desc';
		$args->list_count = 30;
		$args->page_count = 10;
		$args->page = max(1, intval(Context::get('page')) ?: 1);
		$output = executeQueryArray('sticker.getGifConversionLogs', $args);
		if(!$output->toBool())
		{
			return $output;
		}

		foreach((array)$output->data as $item)
		{
			$status_key = 'stkr_gif_status_' . strtolower((string)$item->status);
			$reason_key = 'stkr_gif_reason_' . strtolower((string)$item->reason);
			$item->status_text = Context::getLang($status_key) ?: $item->status;
			$item->reason_text = $item->reason ? (Context::getLang($reason_key) ?: $item->reason) : '';
		}

		$counts = array();
		foreach($allowed_statuses as $count_status)
		{
			$count_output = executeQuery('sticker.getGifConversionLogCount', (object)array('status' => $count_status));
			$counts[strtolower($count_status)] = intval($count_output->data->count ?? 0);
		}
		$count_output = executeQuery('sticker.getGifConversionLogCount');
		$counts['total'] = intval($count_output->data->count ?? 0);
		$gif_output = executeQuery('sticker.getGifStickerCount', (object)array('ext' => '.gif'));
		$pending_output = executeQuery('sticker.getPendingGifStickerCount', (object)array('ext' => '.gif'));

		Context::set('conversion_statuses', $allowed_statuses);
		Context::set('conversion_status', $status);
		Context::set('conversion_sticker_srl', $sticker_srl);
		Context::set('conversion_counts', $counts);
		Context::set('remaining_gif_count', intval($gif_output->data->count ?? 0));
		Context::set('pending_gif_count', intval($pending_output->data->count ?? 0));
		Context::set('is_ffmpeg', Rhymix\Modules\Sticker\Services\ImageProcessor::isFfmpegAvailable());
		Context::set('is_queue_enabled', (bool)config('queue.enabled'));
		Context::set('queue_driver', (string)config('queue.driver'));
		Context::set('list', $output->data);
		Context::set('page_navigation', $output->page_navigation);
		Context::set('page', $args->page);

		$this->setTemplateFile('gif_conversion_log');
	}

	function dispStickerAdminLogInfo(){
		$idx = Context::get('idx');

		$args = new stdClass();
		$args->idx = $idx;
		$output = executeQuery('sticker.getStickerLogInfo', $args);
		if(empty($output->data)){
			return new BaseObject(-1,'msg_invalid_data');
		}
		$oStickerModel = stickerModel::getInstance();
		$oSticker = $oStickerModel->getSticker($output->data->sticker_srl);
		if($oSticker)
		{
			$oSticker = clone $oSticker;
		}
		$this->redactIpAddresses($oSticker);

		$oMemberModel = memberModel::getInstance();
		$oMember = $oMemberModel->getMemberInfoByMemberSrl($output->data->member_srl);
		$output->data->nick_name = $oMember ? $oMember->nick_name : '';
		$this->redactIpAddresses($output->data);

		Context::set('oLog', $output->data);
		Context::set('oSticker', $oSticker);

		$this->setTemplateFile('log_view');
	}

	function dispStickerAdminConfig(){
		if(!$this->module_config){
			$oStickerModel = stickerModel::getInstance();
			$config = $oStickerModel->getConfig();
			$this->module_config = $config;
		}
		
		if($this->module_info && $this->module_info->module == "sticker"){
			$module_info = $this->module_info;
		} else {
			$oModuleModel = moduleModel::getInstance();
			$module_info = $oModuleModel->getModuleInfoByMid('sticker');
		}
		$this->module_config->browser_subtitle = $this->module_config->browser_subtitle ?? '';
		$this->module_config->quick_tags = $this->module_config->quick_tags ?? '';
		$this->module_config->notify_message_type = $this->module_config->notify_message_type ?? 'text';
		$this->module_config->gif2mp4 = $this->module_config->gif2mp4 ?? 'N';
		$this->module_config->list_count = $this->module_config->list_count ?? 12;
		$this->module_config->doc_max_sticker_count = $this->module_config->doc_max_sticker_count ?? 30;
		Context::set('is_ffmpeg', Rhymix\Modules\Sticker\Services\ImageProcessor::isFfmpegAvailable());
		Context::set('is_queue_enabled', (bool)config('queue.enabled'));

		Context::set('module_info', $module_info);
		Context::set('config', $this->module_config);
		$this->setTemplateFile('config');
	}

	function dispStickerAdminCategoryInfo(){
		$oDocumentModel = documentModel::getInstance();
		Context::set('category_content', $oDocumentModel->getCategoryHTML($this->module_info->module_srl));

		$this->setTemplateFile('category_list');
	}

	function dispStickerAdminGrantInfo(){
		$oModuleAdminModel = moduleAdminModel::getInstance();

		$oModuleModel = moduleModel::getInstance();
		$this->mid_info = $oModuleModel->getModuleInfoByMid("sticker");

		$admin_member = $oModuleModel->getAdminId($this->mid_info->module_srl);
		$grant_content = $oModuleAdminModel->getModuleGrantHTML($this->mid_info->module_srl, $this->xml_info->grant);
		Context::set('grant_content', $grant_content);

		$this->setTemplateFile('grant');
	}

	function dispStickerAdminDesign(){
		Context::set('module_info', $this->module_info);

		$oLayoutModel = layoutModel::getInstance();
		$layout_list = $oLayoutModel->getLayoutList();
		$mlayout_list = $oLayoutModel->getLayoutList(0, 'M');

		Context::set('layout_list', $layout_list);
		Context::set('mlayout_list', $mlayout_list);

		$oModuleModel = moduleModel::getInstance();
		$skin_list = $oModuleModel->getSkins($this->module_path);
		Context::set('skin_list', $skin_list);

		$mskin_list = $oModuleModel->getSkins($this->module_path, 'm.skins');
		Context::set('mskin_list', $mskin_list);

		$this->setTemplateFile('design');

	}

	function dispStickerAdminSkinInfo() {

		$oModuleModel = moduleModel::getInstance();
		$mid_info = $oModuleModel->getModuleInfoByMid("sticker");

		$oModuleAdminModel = moduleAdminModel::getInstance();
		$skin_content = $oModuleAdminModel->getModuleSkinHTML($mid_info->module_srl);
		Context::set('skin_content', $skin_content);

		$this->setTemplateFile('skin_info');
	}

	function dispStickerAdminMobileSkinInfo() {

		$oModuleModel = moduleModel::getInstance();
		$mid_info = $oModuleModel->getModuleInfoByMid("sticker");

		$oModuleAdminModel = moduleAdminModel::getInstance();
		$skin_content = $oModuleAdminModel->getModuleMobileSkinHTML($mid_info->module_srl);
		Context::set('skin_content', $skin_content);

		$this->setTemplateFile('skin_info');
	}

}

/* End of file : sticker.admin.view.php */
/* Location : ./modules/sticker/sticker.admin.view.php */
