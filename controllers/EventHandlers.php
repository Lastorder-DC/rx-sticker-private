<?php

namespace Rhymix\Modules\Sticker\Controllers;

use Context;
use stickerModel;

/**
 * Handle events that are not tied to a public module action.
 *
 * The notification integration is adapted from Waterticket/rx-module-sticker.
 */
class EventHandlers extends Base
{
	/**
	 * Replace sticker tokens with a readable summary before ncenterlite stores a notification.
	 *
	 * @param object $obj Notification data passed by ncenterlite.
	 * @return void
	 */
	public function beforeInsertNotify($obj): void
	{
		if(!is_object($obj) || empty($obj->target_summary))
		{
			return;
		}

		$config = stickerModel::getInstance()->getConfig();
		if(($config->use ?? 'N') !== 'Y' || ($config->notify_message_type ?? 'text') === 'none')
		{
			return;
		}

		if(!preg_match('/{@sticker:[0-9]+\|[0-9]+}/i', $obj->target_summary))
		{
			return;
		}

		Context::loadLang(\RX_BASEDIR . 'modules/sticker/lang');
		$label = Context::getLang('stkr_notification_sticker');
		if(!$label)
		{
			$label = 'Sticker';
		}

		$obj->target_summary = preg_replace('/{@sticker:[0-9]+\|[0-9]+}/i', '[' . $label . ']', $obj->target_summary);
	}
}
