<?php

namespace Rhymix\Modules\Sticker\Services;

use DB;
use FileHandler;
use FileModel;
use Rhymix\Framework\Image;
use Rhymix\Framework\Security;
use Rhymix\Framework\Storage;
use RuntimeException;
use stickerModel;

/**
 * Validate and process sticker images and MP4 videos.
 *
 * The service is based on Waterticket/rx-module-sticker and adapted to preserve WebP,
 * support PHP 7.4, and keep generated MP4 poster files in the Rhymix file lifecycle.
 */
class ImageProcessor
{
	public const CODE_IMAGE_TOO_SMALL = 2;
	public const CODE_IMAGE_TOO_LARGE = 3;
	public const CODE_VIDEO_PROCESSING_UNAVAILABLE = 4;

	/**
	 * Check whether a sticker URL points to an MP4 file.
	 *
	 * @param string $url File URL.
	 * @return bool
	 */
	public static function isMp4(string $url): bool
	{
		return substr(strtolower($url), -4) === '.mp4';
	}

	/**
	 * Return the poster URL used for an MP4 sticker.
	 *
	 * @param string $url MP4 URL.
	 * @return string
	 */
	public static function getPosterUrl(string $url): string
	{
		return self::isMp4($url) ? substr($url, 0, -4) . '.webp' : '';
	}

	/**
	 * Check whether the file module's FFmpeg command is executable.
	 *
	 * @return bool
	 */
	public static function isFfmpegAvailable(): bool
	{
		$file_config = FileModel::getFileConfig();
		$ffmpeg_command = (string)($file_config->ffmpeg_command ?? '');

		return function_exists('exec') && $ffmpeg_command !== '' && Storage::isExecutable($ffmpeg_command);
	}

	/**
	 * Check whether both FFmpeg and FFprobe are available for direct MP4 uploads.
	 *
	 * @return bool
	 */
	public static function isVideoProcessingAvailable(): bool
	{
		$file_config = FileModel::getFileConfig();
		$ffmpeg_command = (string)($file_config->ffmpeg_command ?? '');
		$ffprobe_command = (string)($file_config->ffprobe_command ?? '');

		return function_exists('exec') &&
			$ffmpeg_command !== '' && Storage::isExecutable($ffmpeg_command) &&
			$ffprobe_command !== '' && Storage::isExecutable($ffprobe_command);
	}

	/**
	 * Validate an uploaded image or MP4 and normalize its source filename.
	 *
	 * @param string $uploaded_filename Uploaded file path.
	 * @param string $source_filename Original filename.
	 * @param object $module_config Sticker module configuration.
	 * @return object|false|int Validation result or an error code.
	 */
	public static function validate(string $uploaded_filename, string $source_filename, object $module_config)
	{
		if($uploaded_filename === '' || !preg_match('/\.(jpg|jpeg|gif|png|webp|mp4)$/i', $uploaded_filename))
		{
			return false;
		}

		if(preg_match('/\.mp4$/i', $uploaded_filename))
		{
			return self::validateMp4($uploaded_filename, $source_filename, $module_config);
		}

		$source_fileinfo = @getimagesize($uploaded_filename);
		if(!$source_fileinfo || empty($source_fileinfo['mime']))
		{
			return false;
		}

		$allowed_mimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
		if(!in_array($source_fileinfo['mime'], $allowed_mimes, true))
		{
			return false;
		}

		$width = intval($source_fileinfo[0]);
		$height = intval($source_fileinfo[1]);
		$minimum_width = intval($module_config->image_min_width ?? 40);
		$minimum_height = intval($module_config->image_min_height ?? 40);
		if($width < $minimum_width || $height < $minimum_height)
		{
			return self::CODE_IMAGE_TOO_SMALL;
		}
		if($width > 4096 || $height > 2160)
		{
			return self::CODE_IMAGE_TOO_LARGE;
		}

		$extension_map = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
		);
		$source_filename = self::replaceExtension($source_filename, $extension_map[$source_fileinfo['mime']]);
		$maximum_size = max(1, intval($module_config->maxPx ?? 120));
		$ratio = $height > 0 ? $width / $height : 1;

		return (object)array(
			'width' => $width,
			'height' => $height,
			'mime' => $source_fileinfo['mime'],
			'source_filename' => $source_filename,
			'crop_mode' => ($ratio > 1.8 || $ratio < 0.65 || $width >= $maximum_size || $height >= $maximum_size) ? 'crop' : 'ratio',
		);
	}

	/**
	 * Resize an image, re-encode a direct MP4, or optionally convert an animated GIF to MP4.
	 *
	 * The original file is retained until the caller successfully updates its database rows.
	 *
	 * @param string $uploaded_filename Uploaded file path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @param bool $allow_mp4 Whether MP4 conversion may be attempted.
	 * @return object|false|int Processing result or an error code.
	 */
	public static function process(string $uploaded_filename, object $validated, object $module_config, bool $allow_mp4 = true)
	{
		if($validated->mime === 'video/mp4')
		{
			return self::convertMp4($uploaded_filename, $validated, $module_config);
		}

		if($allow_mp4 && $validated->mime === 'image/gif')
		{
			$converted = self::convertGifToMp4($uploaded_filename, $validated, $module_config);
			if($converted !== null)
			{
				return $converted;
			}
		}

		$maximum_size = max(1, intval($module_config->maxPx ?? 120));
		if(($module_config->resizing ?? 'Y') === 'N' || ($validated->width < $maximum_size && $validated->height < $maximum_size))
		{
			return self::unchangedResult($uploaded_filename, $validated);
		}

		if($validated->mime === 'image/gif')
		{
			return self::processGif($uploaded_filename, $validated, $module_config);
		}

		return self::processStaticImage($uploaded_filename, $validated, $module_config);
	}

	/**
	 * Update the Rhymix files table with a processed file.
	 *
	 * @param int $file_srl File serial number.
	 * @param object $result Processing result.
	 * @return object Query result.
	 */
	public static function finalizeFile(int $file_srl, object $result)
	{
		$args = new \stdClass();
		$args->file_srl = $file_srl;
		$args->uploaded_filename = $result->url;
		$args->source_filename = $result->source_filename;
		$args->file_size = intval($result->file_size);
		$args->mime_type = $result->mime_type;
		$args->original_type = $result->original_type;
		$args->direct_download = $result->direct_download;
		$args->thumbnail_filename = $result->poster_filename;

		return executeQuery('sticker.updateFileInfo', $args);
	}

	/**
	 * Remove the superseded source file after all database updates have succeeded.
	 *
	 * @param object $result Processing result.
	 * @return void
	 */
	public static function removeOriginal(object $result): void
	{
		if(!empty($result->changed) && $result->original_url !== $result->url && file_exists($result->original_url))
		{
			FileHandler::removeFile($result->original_url);
		}
	}

	/**
	 * Resolve a stored Rhymix file path for filesystem access by a queue worker.
	 *
	 * @param string $filename Stored file path.
	 * @return string Absolute path where possible.
	 */
	public static function resolveLocalPath(string $filename): string
	{
		if(substr($filename, 0, 2) === './')
		{
			return \RX_BASEDIR . substr($filename, 2);
		}
		if($filename !== '' && !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $filename))
		{
			return \RX_BASEDIR . ltrim($filename, '/');
		}

		return $filename;
	}

	/**
	 * Insert the initial log row before a conversion task is queued.
	 *
	 * @param object $row Sticker file row.
	 * @param int $original_size Original file size, if known.
	 * @return bool
	 */
	public static function createConversionLog(object $row, int $original_size = 0): bool
	{
		$now = date('YmdHis');
		$output = executeQuery('sticker.insertGifConversionLog', (object)array(
			'sticker_file_srl' => intval($row->sticker_file_srl),
			'sticker_srl' => intval($row->sticker_srl),
			'file_srl' => intval($row->file_srl),
			'status' => 'QUEUED',
			'reason' => '',
			'original_url' => (string)$row->url,
			'result_url' => '',
			'original_size' => max(0, $original_size),
			'result_size' => 0,
			'regdate' => $now,
			'last_update' => $now,
		));

		return $output->toBool();
	}

	/**
	 * Update the persistent status of a legacy GIF conversion.
	 *
	 * Null values preserve the corresponding value already stored in the log.
	 * Logging failures never interrupt conversion of the actual sticker file.
	 *
	 * @param int $sticker_file_srl Sticker file serial number.
	 * @param string $status Conversion status.
	 * @param string|null $reason Machine-readable result reason.
	 * @param string|null $result_url Converted file URL.
	 * @param int|null $original_size Original file size.
	 * @param int|null $result_size Converted file size.
	 * @return bool
	 */
	public static function updateConversionLog(
		int $sticker_file_srl,
		string $status,
		?string $reason = null,
		?string $result_url = null,
		?int $original_size = null,
		?int $result_size = null
	): bool
	{
		try
		{
			$current_output = executeQuery('sticker.getGifConversionLog', (object)array(
				'sticker_file_srl' => $sticker_file_srl,
			));
			$current = $current_output->data ?? null;
			if(!$current)
			{
				return false;
			}

			$output = executeQuery('sticker.updateGifConversionLog', (object)array(
				'sticker_file_srl' => $sticker_file_srl,
				'status' => $status,
				'reason' => $reason === null ? (string)$current->reason : $reason,
				'result_url' => $result_url === null ? (string)$current->result_url : $result_url,
				'original_size' => $original_size === null ? intval($current->original_size) : max(0, $original_size),
				'result_size' => $result_size === null ? intval($current->result_size) : max(0, $result_size),
				'last_update' => date('YmdHis'),
			));

			return $output->toBool();
		}
		catch(\Throwable $e)
		{
			\Rhymix\Framework\Debug::addEntry($e);
			return false;
		}
	}

	/**
	 * Queue entry point for GIF to MP4 conversion.
	 *
	 * Duplicate jobs are harmless: a job exits when the sticker or file row no longer points
	 * to the expected GIF path.
	 *
	 * @param object $args Queue arguments.
	 * @return void
	 */
	public static function processAsync(object $args): void
	{
		$required = array('sticker_srl', 'sticker_file_srl', 'file_srl', 'uploaded_filename');
		foreach($required as $key)
		{
			if(empty($args->{$key}))
			{
				throw new RuntimeException('Missing sticker image queue argument: ' . $key);
			}
		}

		$sticker_file_srl = intval($args->sticker_file_srl);
		$expected_url = (string)$args->uploaded_filename;
		$local_path = self::resolveLocalPath($expected_url);
		$original_size = file_exists($local_path) ? intval(filesize($local_path)) : 0;
		self::updateConversionLog($sticker_file_srl, 'PROCESSING', '', null, $original_size);

		$result = null;
		$oDB = null;
		$transaction_started = false;
		try
		{
			$sticker_file_output = executeQuery('sticker.getStickerFileByStickerFileSrl', (object)array(
				'sticker_file_srl' => $sticker_file_srl,
			));
			if(!$sticker_file_output->toBool())
			{
				throw new RuntimeException('Failed to read the sticker file row for MP4 conversion.');
			}
			$sticker_file = $sticker_file_output->data ?? null;
			$file_info = FileModel::getFile(intval($args->file_srl));
			if(!$sticker_file || !$file_info || intval($sticker_file->sticker_srl) !== intval($args->sticker_srl))
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'target_missing', null, $original_size);
				return;
			}
			if(intval($sticker_file->file_srl) !== intval($args->file_srl) || $sticker_file->url !== $expected_url || $file_info->uploaded_filename !== $expected_url)
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'source_changed', null, $original_size);
				return;
			}
			if(self::isMp4($expected_url))
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'already_converted', $expected_url, $original_size, $original_size);
				return;
			}
			if(!file_exists($local_path))
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'source_missing', null, 0);
				return;
			}

			$module_config = stickerModel::getInstance()->getConfig();
			if(($module_config->gif2mp4 ?? 'N') !== 'Y')
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'conversion_disabled', null, $original_size);
				return;
			}

			$validated = self::validate($local_path, (string)$sticker_file->file_name, $module_config);
			if(!is_object($validated))
			{
				self::updateConversionLog($sticker_file_srl, 'FAILED', 'invalid_image', null, $original_size);
				return;
			}
			if($validated->mime !== 'image/gif')
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'not_gif', null, $original_size);
				return;
			}
			if(!Image::isAnimatedGIF($local_path))
			{
				self::updateConversionLog($sticker_file_srl, 'SKIPPED', 'not_animated', null, $original_size);
				return;
			}
			if(!self::isFfmpegAvailable())
			{
				self::updateConversionLog($sticker_file_srl, 'FAILED', 'ffmpeg_unavailable', null, $original_size);
				return;
			}

			$result = self::convertGifToMp4($local_path, $validated, $module_config);
			if($result === null)
			{
				self::updateConversionLog($sticker_file_srl, 'FAILED', 'ffmpeg_failed', null, $original_size);
				return;
			}

			$stored_result = clone $result;
			$stored_result->url = self::toStoredPath($result->url, $expected_url);
			$stored_result->poster_filename = self::toStoredPath($result->poster_filename, $expected_url);
			$stored_result->original_url = $expected_url;

			$oDB = DB::getInstance();
			$oDB->begin();
			$transaction_started = true;
			$file_output = self::finalizeFile(intval($args->file_srl), $stored_result);
			if(!$file_output->toBool())
			{
				throw new RuntimeException('Failed to update the Rhymix file row for sticker MP4 conversion.');
			}

			$sticker_output = executeQuery('sticker.updateStickerFile', (object)array(
				'sticker_srl' => intval($args->sticker_srl),
				'sticker_file_srl' => $sticker_file_srl,
				'member_srl' => intval($sticker_file->member_srl),
				'file_srl' => intval($args->file_srl),
				'file_name' => cut_str(htmlspecialchars($result->source_filename, ENT_QUOTES, 'UTF-8', false), 60),
				'url' => $stored_result->url,
				'regdate' => $sticker_file->regdate,
			));
			if(!$sticker_output->toBool())
			{
				throw new RuntimeException('Failed to update the sticker file row for MP4 conversion.');
			}

			$oDB->commit();
			$transaction_started = false;
			self::removeOriginal($result);
			stickerModel::getInstance()->clearStickerCache(intval($args->sticker_srl));
			self::updateConversionLog(
				$sticker_file_srl,
				'SUCCESS',
				'converted',
				$stored_result->url,
				$original_size,
				intval($stored_result->file_size)
			);
		}
		catch(\Throwable $e)
		{
			if($transaction_started && $oDB)
			{
				$oDB->rollback();
			}
			if($result)
			{
				self::removeGeneratedFiles($result);
			}
			self::updateConversionLog($sticker_file_srl, 'FAILED', 'processing_exception', null, $original_size);
			throw $e;
		}
	}

	/**
	 * Convert an animated GIF to MP4 and create a tracked WebP poster.
	 *
	 * @param string $uploaded_filename GIF path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @return object|null Conversion result, or null when conversion is unavailable.
	 */
	public static function convertGifToMp4(string $uploaded_filename, object $validated, object $module_config): ?object
	{
		if(($module_config->gif2mp4 ?? 'N') !== 'Y' || !Image::isAnimatedGIF($uploaded_filename))
		{
			return null;
		}

		if(!self::isFfmpegAvailable())
		{
			return null;
		}

		return self::transcodeToMp4($uploaded_filename, $validated, $module_config, 'image/gif', true);
	}

	/**
	 * Re-encode a directly uploaded MP4, remove all audio, and generate a poster.
	 *
	 * @param string $uploaded_filename MP4 path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @return object|int Processing result or an error code.
	 */
	private static function convertMp4(string $uploaded_filename, object $validated, object $module_config)
	{
		if(!self::isVideoProcessingAvailable())
		{
			return self::CODE_VIDEO_PROCESSING_UNAVAILABLE;
		}

		return self::transcodeToMp4($uploaded_filename, $validated, $module_config, 'video/mp4', false) ?: self::CODE_VIDEO_PROCESSING_UNAVAILABLE;
	}

	/**
	 * Transcode a GIF or MP4 into an optimized, silent H.264 MP4.
	 *
	 * @param string $uploaded_filename Source path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @param string $original_type Original MIME type.
	 * @param bool $poster_from_image Whether the source can be read directly as an image.
	 * @return object|null Processing result, or null on failure.
	 */
	private static function transcodeToMp4(string $uploaded_filename, object $validated, object $module_config, string $original_type, bool $poster_from_image): ?object
	{
		$file_config = FileModel::getFileConfig();
		$ffmpeg_command = (string)($file_config->ffmpeg_command ?? '');

		$size = max(2, intval($module_config->maxPx ?? 120));
		$size -= $size % 2;
		$directory = substr($uploaded_filename, 0, strrpos($uploaded_filename, '/') + 1);
		$basename = Security::getRandom(32, 'hex');
		$output_name = $directory . $basename . '.mp4';
		$temp_name = $directory . $basename . '.tmp.mp4';
		$poster_name = $directory . $basename . '.webp';

		$filter = sprintf(
			'fps=min(source_fps\,30),scale=%d:%d:force_original_aspect_ratio=increase:flags=lanczos,crop=%d:%d,setsar=1',
			$size,
			$size,
			$size,
			$size
		);
		$command = Security::sanitize($ffmpeg_command, 'command');
		$command .= ' -nostdin -y -i ' . escapeshellarg($uploaded_filename);
		$video_map = isset($validated->video_stream_index) ? '0:' . intval($validated->video_stream_index) : '0:v:0';
		$command .= ' -map ' . $video_map . ' -an -sn -dn -map_metadata -1 -map_chapters -1';
		$command .= ' -movflags +faststart -pix_fmt yuv420p -c:v libx264 -preset medium -crf 26';
		$command .= ' -vf ' . escapeshellarg($filter);
		$command .= ' ' . escapeshellarg($temp_name) . ' 2>&1';
		$command = self::applyFfmpegTimeout($command, $file_config);

		$exec_output = array();
		$return_var = 1;
		@exec($command, $exec_output, $return_var);
		if($return_var !== 0 || !file_exists($temp_name) || filesize($temp_name) < 1)
		{
			if(file_exists($temp_name))
			{
				FileHandler::removeFile($temp_name);
			}
			return null;
		}
		if(!@rename($temp_name, $output_name))
		{
			FileHandler::removeFile($temp_name);
			return null;
		}

		if($poster_from_image)
		{
			$poster_created = FileHandler::createImageFile($uploaded_filename, $poster_name, $size, $size, 'webp', 'fill');
		}
		else
		{
			$poster_created = self::createVideoPoster($output_name, $poster_name, $size, $file_config);
		}
		if(!$poster_created)
		{
			$poster_name = '';
		}

		return (object)array(
			'url' => $output_name,
			'original_url' => $uploaded_filename,
			'poster_filename' => $poster_name,
			'source_filename' => self::replaceExtension($validated->source_filename, 'mp4'),
			'changed' => true,
			'file_size' => filesize($output_name),
			'mime_type' => 'video/mp4',
			'original_type' => $original_type,
			'direct_download' => 'Y',
		);
	}

	/**
	 * Validate the container and primary video stream of a direct MP4 upload.
	 *
	 * @param string $uploaded_filename Uploaded MP4 path.
	 * @param string $source_filename Original filename.
	 * @param object $module_config Sticker module configuration.
	 * @return object|false|int Validation result or an error code.
	 */
	private static function validateMp4(string $uploaded_filename, string $source_filename, object $module_config)
	{
		if(!self::isVideoProcessingAvailable())
		{
			return self::CODE_VIDEO_PROCESSING_UNAVAILABLE;
		}

		$file_config = FileModel::getFileConfig();
		$command = Security::sanitize((string)$file_config->ffprobe_command, 'command');
		$command .= ' -v error -print_format json -show_format -show_streams ' . escapeshellarg($uploaded_filename) . ' 2>&1';
		$command = self::applyFfmpegTimeout($command, $file_config);
		$output = array();
		$return_var = 1;
		@exec($command, $output, $return_var);
		$probe = $return_var === 0 ? json_decode(implode('', $output), true) : null;
		if(!is_array($probe) || empty($probe['streams']) || empty($probe['format']['format_name']))
		{
			return false;
		}
		if(stripos((string)$probe['format']['format_name'], 'mp4') === false)
		{
			return false;
		}

		$video_stream = null;
		foreach($probe['streams'] as $stream)
		{
			if(($stream['codec_type'] ?? '') === 'video' && empty($stream['disposition']['attached_pic']))
			{
				$video_stream = $stream;
				break;
			}
		}
		if(!$video_stream || empty($video_stream['width']) || empty($video_stream['height']))
		{
			return false;
		}

		$width = intval($video_stream['width']);
		$height = intval($video_stream['height']);
		$rotation = intval($video_stream['tags']['rotate'] ?? 0);
		if(isset($video_stream['side_data_list']) && is_array($video_stream['side_data_list']))
		{
			foreach($video_stream['side_data_list'] as $side_data)
			{
				if(isset($side_data['rotation']))
				{
					$rotation = intval($side_data['rotation']);
					break;
				}
			}
		}
		if(in_array(abs($rotation), array(90, 270), true))
		{
			list($width, $height) = array($height, $width);
		}

		$minimum_width = intval($module_config->image_min_width ?? 40);
		$minimum_height = intval($module_config->image_min_height ?? 40);
		if($width < $minimum_width || $height < $minimum_height)
		{
			return self::CODE_IMAGE_TOO_SMALL;
		}
		if($width > 4096 || $height > 2160)
		{
			return self::CODE_IMAGE_TOO_LARGE;
		}

		return (object)array(
			'width' => $width,
			'height' => $height,
			'mime' => 'video/mp4',
			'source_filename' => self::replaceExtension($source_filename, 'mp4'),
			'crop_mode' => 'crop',
			'video_stream_index' => intval($video_stream['index'] ?? 0),
		);
	}

	/**
	 * Create a WebP poster from a transcoded video.
	 *
	 * @param string $video_filename Video path.
	 * @param string $poster_filename Poster path.
	 * @param int $size Poster size.
	 * @param object $file_config File module configuration.
	 * @return bool
	 */
	private static function createVideoPoster(string $video_filename, string $poster_filename, int $size, object $file_config): bool
	{
		$temp_filename = $poster_filename . '.tmp.jpg';
		$command = Security::sanitize((string)$file_config->ffmpeg_command, 'command');
		$command .= ' -nostdin -y -i ' . escapeshellarg($video_filename);
		$command .= ' -map 0:v:0 -frames:v 1 -an -sn -dn -q:v 2 ' . escapeshellarg($temp_filename) . ' 2>&1';
		$command = self::applyFfmpegTimeout($command, $file_config);
		$output = array();
		$return_var = 1;
		@exec($command, $output, $return_var);
		if($return_var !== 0 || !file_exists($temp_filename) || filesize($temp_filename) < 1)
		{
			if(file_exists($temp_filename))
			{
				FileHandler::removeFile($temp_filename);
			}
			return false;
		}

		$result = FileHandler::createImageFile($temp_filename, $poster_filename, $size, $size, 'webp', 'fill');
		FileHandler::removeFile($temp_filename);

		return (bool)$result;
	}

	/**
	 * Apply the timeout configured by Rhymix's file module.
	 *
	 * @param string $command Shell command.
	 * @param object $file_config File module configuration.
	 * @return string
	 */
	private static function applyFfmpegTimeout(string $command, object $file_config): string
	{
		if(!\RX_WINDOWS && !empty($file_config->ffmpeg_timeout) && intval($file_config->ffmpeg_timeout) > 0)
		{
			return 'timeout -k1 ' . intval($file_config->ffmpeg_timeout) . ' ' . $command;
		}

		return $command;
	}

	/**
	 * Resize a GIF using the bundled Imagecraft library.
	 *
	 * @param string $uploaded_filename GIF path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @return object
	 */
	private static function processGif(string $uploaded_filename, object $validated, object $module_config): object
	{
		$maximum_size = max(1, intval($module_config->maxPx ?? 120));
		$larger = max($validated->width, $validated->height);
		$smaller = min($validated->width, $validated->height);
		$ratio = $larger > 0 ? $smaller / $larger : 1;
		if($validated->width <= $maximum_size && $validated->height <= $maximum_size && $ratio > 0.4)
		{
			return self::unchangedResult($uploaded_filename, $validated);
		}

		require_once dirname(__DIR__) . '/sticker.lib.php';
		$directory = substr($uploaded_filename, 0, strrpos($uploaded_filename, '/') + 1);
		$output_name = $directory . Security::getRandom(32, 'hex') . '.gif';
		if(!resizeGIF($uploaded_filename, $output_name, $maximum_size, $maximum_size))
		{
			return self::unchangedResult($uploaded_filename, $validated);
		}

		$keep_resized = filesize($uploaded_filename) > filesize($output_name) || ($module_config->gifResizingIf ?? 'Y') === 'N' || $ratio < 0.4;
		if(!$keep_resized)
		{
			FileHandler::removeFile($output_name);
			return self::unchangedResult($uploaded_filename, $validated);
		}

		return (object)array(
			'url' => $output_name,
			'original_url' => $uploaded_filename,
			'poster_filename' => '',
			'source_filename' => $validated->source_filename,
			'changed' => true,
			'file_size' => filesize($output_name),
			'mime_type' => 'image/gif',
			'original_type' => 'image/gif',
			'direct_download' => 'Y',
		);
	}

	/**
	 * Resize a JPEG, PNG, or WebP file to the legacy JPEG output format.
	 *
	 * @param string $uploaded_filename Image path.
	 * @param object $validated Validation result.
	 * @param object $module_config Sticker module configuration.
	 * @return object|false
	 */
	private static function processStaticImage(string $uploaded_filename, object $validated, object $module_config)
	{
		$maximum_size = max(1, intval($module_config->maxPx ?? 120));
		$directory = substr($uploaded_filename, 0, strrpos($uploaded_filename, '/') + 1);
		$output_name = $directory . Security::getRandom(32, 'hex') . '.jpg';
		if(!FileHandler::createImageFile($uploaded_filename, $output_name, $maximum_size, $maximum_size, 'jpg', $validated->crop_mode))
		{
			return false;
		}

		return (object)array(
			'url' => $output_name,
			'original_url' => $uploaded_filename,
			'poster_filename' => '',
			'source_filename' => self::replaceExtension($validated->source_filename, 'jpg'),
			'changed' => true,
			'file_size' => filesize($output_name),
			'mime_type' => 'image/jpeg',
			'original_type' => $validated->mime,
			'direct_download' => 'Y',
		);
	}

	/**
	 * Build a result for a file that does not need conversion.
	 *
	 * @param string $uploaded_filename Image path.
	 * @param object $validated Validation result.
	 * @return object
	 */
	private static function unchangedResult(string $uploaded_filename, object $validated): object
	{
		return (object)array(
			'url' => $uploaded_filename,
			'original_url' => $uploaded_filename,
			'poster_filename' => '',
			'source_filename' => $validated->source_filename,
			'changed' => false,
			'file_size' => filesize($uploaded_filename),
			'mime_type' => $validated->mime,
			'original_type' => $validated->mime,
			'direct_download' => 'Y',
		);
	}

	/**
	 * Remove outputs created by a failed conversion transaction.
	 *
	 * @param object $result Processing result.
	 * @return void
	 */
	private static function removeGeneratedFiles(object $result): void
	{
		foreach(array($result->url, $result->poster_filename) as $filename)
		{
			if($filename && file_exists($filename))
			{
				FileHandler::removeFile($filename);
			}
		}
	}

	/**
	 * Convert an absolute generated path back to the storage format of its source URL.
	 *
	 * @param string $filename Generated filesystem path.
	 * @param string $reference Stored source URL.
	 * @return string
	 */
	private static function toStoredPath(string $filename, string $reference): string
	{
		if($filename === '')
		{
			return '';
		}
		if(substr($reference, 0, 2) === './' && strpos($filename, \RX_BASEDIR) === 0)
		{
			return './' . str_replace('\\', '/', substr($filename, strlen(\RX_BASEDIR)));
		}

		return $filename;
	}

	/**
	 * Replace or append a filename extension.
	 *
	 * @param string $filename Filename.
	 * @param string $extension Extension without a dot.
	 * @return string
	 */
	private static function replaceExtension(string $filename, string $extension): string
	{
		$position = strrpos($filename, '.');
		$basename = $position === false ? $filename : substr($filename, 0, $position);

		return $basename . '.' . $extension;
	}
}
