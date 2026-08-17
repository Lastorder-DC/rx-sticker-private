@php
	$is_video = substr(strtolower($url), -4) === '.mp4';
	// ImageProcessor stores the poster next to the MP4 with only the extension changed.
	// WebP preserves transparent sticker backgrounds while the video is loading.
	$poster = $is_video ? substr($url, 0, -4) . '.webp' : null;
@endphp
@if($is_video)
	<video class="{{ $class ?? '' }}" src="{{ $url }}" poster="{{ $poster }}" autoplay muted loop playsinline preload="metadata"@if(!empty($lazy)) loading="lazy"@endif@if(!empty($title)) title="{{ $title }}"@endif></video>
@else
	<img class="{{ $class ?? '' }}" src="{{ $url }}" alt="{{ $alt ?? '' }}"@if(!empty($title)) title="{{ $title }}"@endif@if(!empty($lazy)) loading="lazy"@endif />
@endif
