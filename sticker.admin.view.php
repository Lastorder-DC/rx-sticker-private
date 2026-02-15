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

		$this->setTemplatePath($this->module_path.'tpl');
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

		$oFileModel = fileModel::getInstance();
		foreach($output1->data as &$value){
			$oFileInfo = $oFileModel->getFile($value->file_srl);
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

		$oMemberModel = memberModel::getInstance();
		$oStickerModel = stickerModel::getInstance();
		foreach($output->data as &$value){
			$oMember = $oMemberModel->getMemberInfoByMemberSrl($value->member_srl);
			$value->nick_name = $oMember->nick_name;

			$oSticker = $oStickerModel->getSticker($value->sticker_srl);
			$value->title = $oSticker->title;
			$value->main_image = $oSticker->main_image;
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

		$oMemberModel = memberModel::getInstance();
		$oMember = $oMemberModel->getMemberInfoByMemberSrl($output->data->member_srl);
		$output->data->nick_name = $oMember->nick_name;

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

		$oMemberModel = memberModel::getInstance();
		$oStickerModel = stickerModel::getInstance();
		foreach($output->data as &$value){
			$oMember = $oMemberModel->getMemberInfoByMemberSrl($value->member_srl);
			$value->nick_name = $oMember->nick_name;

			$oSticker = $oStickerModel->getSticker($value->sticker_srl);
			$value->title = $oSticker->title;
			$value->main_image = $oSticker->main_image;
		}
		Context::set('list', $output->data);
		Context::set('page_navigation', $output->page_navigation);

		$this->setTemplateFile('log_list');
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

		$oMemberModel = memberModel::getInstance();
		$oMember = $oMemberModel->getMemberInfoByMemberSrl($output->data->member_srl);
		$output->data->nick_name = $oMember->nick_name;

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
