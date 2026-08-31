<?php

namespace Rhymix\Modules\Sticker\Services;

use Imagecraft\ImageBuilder;

/**
 * Resize animated GIF files with the bundled Imagecraft library.
 */
final class GifResizer
{
	/**
	 * Resize an animated GIF using a fill-and-crop operation.
	 *
	 * @param string $filename Source GIF path.
	 * @param string $output_name Destination GIF path.
	 * @param int $width Destination width.
	 * @param int $height Destination height.
	 * @return bool True on success.
	 */
	public static function resize(string $filename, string $output_name, int $width, int $height): bool
	{
		require_once dirname(__DIR__) . '/lib/imagecraft/autoload.php';

		$builder = new ImageBuilder(array('engine' => 'php_gd'));
		$context = $builder->about();
		if(!$context->isEngineSupported())
		{
			return false;
		}

		$layer = $builder->addBackgroundLayer();
		$layer->filename(ImageProcessor::resolveLocalPath($filename));
		$layer->resize($width, $height, 'fill_crop');

		$image = $builder->save();
		if(!$image->isValid())
		{
			return false;
		}

		return file_put_contents(
			ImageProcessor::resolveLocalPath($output_name),
			$image->getContents()
		) !== false;
	}
}
