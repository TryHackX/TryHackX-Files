<?php
/**
 * Shared file-type icons (FontAwesome) + human labels — PHP side.
 *
 * Mirrors public/assets/js/file-icons.js for server-rendered pages (download,
 * collection). Maps by extension, then by MIME major type, then a generic file
 * icon; unknown types keep their MIME string as the label.
 */

/** extension => [FontAwesome class, human label] */
function fileIconMap(): array
{
	static $map = null;
	if ($map !== null) {
		return $map;
	}
	return $map = [
		'jpg' => ['fa-file-image', 'JPEG'], 'jpeg' => ['fa-file-image', 'JPEG'], 'png' => ['fa-file-image', 'PNG'],
		'gif' => ['fa-file-image', 'GIF'], 'webp' => ['fa-file-image', 'WebP'], 'bmp' => ['fa-file-image', 'BMP'],
		'svg' => ['fa-file-image', 'SVG'], 'avif' => ['fa-file-image', 'AVIF'], 'heic' => ['fa-file-image', 'HEIC'],
		'tiff' => ['fa-file-image', 'TIFF'], 'ico' => ['fa-file-image', 'ICO'],
		'mp4' => ['fa-file-video', 'MP4'], 'webm' => ['fa-file-video', 'WebM'], 'mkv' => ['fa-file-video', 'MKV'],
		'avi' => ['fa-file-video', 'AVI'], 'mov' => ['fa-file-video', 'MOV'], 'wmv' => ['fa-file-video', 'WMV'],
		'flv' => ['fa-file-video', 'FLV'], 'm4v' => ['fa-file-video', 'M4V'], 'mpg' => ['fa-file-video', 'MPEG'],
		'mpeg' => ['fa-file-video', 'MPEG'],
		'mp3' => ['fa-file-audio', 'MP3'], 'wav' => ['fa-file-audio', 'WAV'], 'flac' => ['fa-file-audio', 'FLAC'],
		'ogg' => ['fa-file-audio', 'OGG'], 'm4a' => ['fa-file-audio', 'M4A'], 'aac' => ['fa-file-audio', 'AAC'],
		'opus' => ['fa-file-audio', 'Opus'], 'wma' => ['fa-file-audio', 'WMA'],
		'pdf' => ['fa-file-pdf', 'PDF'],
		'doc' => ['fa-file-word', 'Word'], 'docx' => ['fa-file-word', 'Word'], 'odt' => ['fa-file-word', 'ODT'],
		'rtf' => ['fa-file-word', 'RTF'],
		'xls' => ['fa-file-excel', 'Excel'], 'xlsx' => ['fa-file-excel', 'Excel'], 'ods' => ['fa-file-excel', 'ODS'],
		'csv' => ['fa-file-csv', 'CSV'], 'tsv' => ['fa-file-csv', 'TSV'],
		'ppt' => ['fa-file-powerpoint', 'PowerPoint'], 'pptx' => ['fa-file-powerpoint', 'PowerPoint'],
		'odp' => ['fa-file-powerpoint', 'ODP'],
		'txt' => ['fa-file-lines', 'Text'], 'md' => ['fa-file-lines', 'Markdown'], 'log' => ['fa-file-lines', 'Log'],
		'nfo' => ['fa-file-lines', 'NFO'],
		'zip' => ['fa-file-zipper', 'ZIP'], 'rar' => ['fa-file-zipper', 'RAR'], '7z' => ['fa-file-zipper', '7z'],
		'tar' => ['fa-file-zipper', 'TAR'], 'gz' => ['fa-file-zipper', 'GZip'], 'bz2' => ['fa-file-zipper', 'BZip2'],
		'xz' => ['fa-file-zipper', 'XZ'], 'zst' => ['fa-file-zipper', 'Zstd'], 'tgz' => ['fa-file-zipper', 'TAR.GZ'],
		'torrent' => ['fa-magnet', 'Torrent'],
		'js' => ['fa-file-code', 'JavaScript'], 'ts' => ['fa-file-code', 'TypeScript'], 'jsx' => ['fa-file-code', 'JSX'],
		'tsx' => ['fa-file-code', 'TSX'], 'php' => ['fa-file-code', 'PHP'], 'py' => ['fa-file-code', 'Python'],
		'rb' => ['fa-file-code', 'Ruby'], 'java' => ['fa-file-code', 'Java'], 'c' => ['fa-file-code', 'C'],
		'cpp' => ['fa-file-code', 'C++'], 'cc' => ['fa-file-code', 'C++'], 'h' => ['fa-file-code', 'C header'],
		'cs' => ['fa-file-code', 'C#'], 'go' => ['fa-file-code', 'Go'], 'rs' => ['fa-file-code', 'Rust'],
		'swift' => ['fa-file-code', 'Swift'], 'kt' => ['fa-file-code', 'Kotlin'], 'html' => ['fa-file-code', 'HTML'],
		'htm' => ['fa-file-code', 'HTML'], 'css' => ['fa-file-code', 'CSS'], 'scss' => ['fa-file-code', 'SCSS'],
		'json' => ['fa-file-code', 'JSON'], 'xml' => ['fa-file-code', 'XML'], 'yml' => ['fa-file-code', 'YAML'],
		'yaml' => ['fa-file-code', 'YAML'], 'sh' => ['fa-file-code', 'Shell'], 'sql' => ['fa-file-code', 'SQL'],
		'ini' => ['fa-file-code', 'INI'], 'toml' => ['fa-file-code', 'TOML'],
		'exe' => ['fa-box', 'Executable'], 'msi' => ['fa-box', 'Installer'], 'apk' => ['fa-box', 'Android app'],
		'deb' => ['fa-box', 'Debian package'], 'rpm' => ['fa-box', 'RPM package'], 'dmg' => ['fa-box', 'macOS image'],
		'appimage' => ['fa-box', 'AppImage'],
		'iso' => ['fa-compact-disc', 'ISO'], 'img' => ['fa-compact-disc', 'Disk image'], 'bin' => ['fa-compact-disc', 'Binary'],
		'ttf' => ['fa-font', 'Font'], 'otf' => ['fa-font', 'Font'], 'woff' => ['fa-font', 'Font'], 'woff2' => ['fa-font', 'Font'],
		'epub' => ['fa-book', 'ePub'], 'mobi' => ['fa-book', 'Mobi'], 'azw3' => ['fa-book', 'Kindle'],
	];
}

/** Resolve [icon class, label] for a filename + optional MIME. */
function fileTypeInfo(string $name, string $mime = ''): array
{
	$map = fileIconMap();
	$ext = '';
	if (($dot = strrpos($name, '.')) !== false) {
		$ext = strtolower(substr($name, $dot + 1));
	}
	if (isset($map[$ext])) {
		return ['icon' => $map[$ext][0], 'label' => $map[$ext][1]];
	}
	if ($mime !== '') {
		$major = explode('/', $mime)[0];
		$byMajor = ['image' => 'fa-file-image', 'video' => 'fa-file-video', 'audio' => 'fa-file-audio', 'text' => 'fa-file-lines'];
		if (isset($byMajor[$major])) {
			return ['icon' => $byMajor[$major], 'label' => $mime];
		}
		return ['icon' => 'fa-file', 'label' => $mime]; // unknown ext → show the MIME
	}
	return ['icon' => 'fa-file', 'label' => $ext !== '' ? strtoupper($ext) : 'File'];
}

/** FontAwesome <i> for a file, with the human type as tooltip. */
function fileIconHtml(string $name, string $mime = ''): string
{
	$info = fileTypeInfo($name, $mime);
	return '<i class="fa-solid ' . $info['icon'] . '" title="' . htmlspecialchars($info['label'], ENT_QUOTES) . '" aria-hidden="true"></i>';
}

/** Human-readable type label (known short name, or the MIME string). */
function fileTypeLabel(string $name, string $mime = ''): string
{
	return fileTypeInfo($name, $mime)['label'];
}

/**
 * A type label that fits in a small tile, plus the full one for the tooltip.
 *
 * Office formats have genuinely enormous MIME types —
 * `application/vnd.openxmlformats-officedocument.wordprocessingml.document` is 65 characters,
 * which no metadata card is going to hold. Widening the card to suit the worst case would make
 * every other card worse, so the display collapses instead:
 *
 *   - a known extension already has a short name in the icon map ("Word", "PNG") — use it;
 *   - an unknown but short MIME is informative as it stands — leave it alone;
 *   - an unknown long one falls back to the extension in caps, or failing that to the major
 *     type with an ellipsis, so it still says what *kind* of thing this is.
 *
 * The full MIME is always returned as well and belongs in `title`: nothing is hidden, it just
 * stops setting the width of the page.
 *
 * @return array{short: string, full: string}
 */
function fileTypeShort(string $name, string $mime = '', int $maxLen = 28): array
{
	$full = $mime !== '' ? $mime : fileTypeLabel($name, $mime);
	$label = fileTypeLabel($name, $mime);

	if ($label !== $mime && $label !== '') {
		return ['short' => $label, 'full' => $full];   // a real name, e.g. "Word"
	}
	if (mb_strlen($label) <= $maxLen) {
		return ['short' => $label, 'full' => $full];   // short enough to print as it is
	}

	$ext = ($dot = strrpos($name, '.')) !== false ? strtoupper(substr($name, $dot + 1)) : '';
	if ($ext !== '' && mb_strlen($ext) <= 6) {
		return ['short' => $ext, 'full' => $full];
	}
	$major = explode('/', $label)[0];
	return ['short' => $major . '/…', 'full' => $full];
}
