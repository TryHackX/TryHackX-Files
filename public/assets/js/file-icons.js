/**
 * Shared file-type icons (FontAwesome) + human labels.
 *
 * One source of truth for the whole front-end: panel.js, index.js and any inline
 * page script call window.fileIcon()/fileTypeLabel(). Maps by extension first, then
 * falls back to the MIME major type, then to a generic file icon. Unknown types keep
 * their MIME string as the visible label (per product requirement).
 */
(function () {
	'use strict';

	// extension -> [FontAwesome class, human label]
	var EXT = {
		// images
		jpg: ['fa-file-image', 'JPEG'], jpeg: ['fa-file-image', 'JPEG'], png: ['fa-file-image', 'PNG'],
		gif: ['fa-file-image', 'GIF'], webp: ['fa-file-image', 'WebP'], bmp: ['fa-file-image', 'BMP'],
		svg: ['fa-file-image', 'SVG'], avif: ['fa-file-image', 'AVIF'], heic: ['fa-file-image', 'HEIC'],
		tiff: ['fa-file-image', 'TIFF'], ico: ['fa-file-image', 'ICO'],
		// video
		mp4: ['fa-file-video', 'MP4'], webm: ['fa-file-video', 'WebM'], mkv: ['fa-file-video', 'MKV'],
		avi: ['fa-file-video', 'AVI'], mov: ['fa-file-video', 'MOV'], wmv: ['fa-file-video', 'WMV'],
		flv: ['fa-file-video', 'FLV'], m4v: ['fa-file-video', 'M4V'], mpg: ['fa-file-video', 'MPEG'],
		mpeg: ['fa-file-video', 'MPEG'],
		// audio
		mp3: ['fa-file-audio', 'MP3'], wav: ['fa-file-audio', 'WAV'], flac: ['fa-file-audio', 'FLAC'],
		ogg: ['fa-file-audio', 'OGG'], m4a: ['fa-file-audio', 'M4A'], aac: ['fa-file-audio', 'AAC'],
		opus: ['fa-file-audio', 'Opus'], wma: ['fa-file-audio', 'WMA'],
		// documents
		pdf: ['fa-file-pdf', 'PDF'],
		doc: ['fa-file-word', 'Word'], docx: ['fa-file-word', 'Word'], odt: ['fa-file-word', 'ODT'],
		rtf: ['fa-file-word', 'RTF'],
		xls: ['fa-file-excel', 'Excel'], xlsx: ['fa-file-excel', 'Excel'], ods: ['fa-file-excel', 'ODS'],
		csv: ['fa-file-csv', 'CSV'], tsv: ['fa-file-csv', 'TSV'],
		ppt: ['fa-file-powerpoint', 'PowerPoint'], pptx: ['fa-file-powerpoint', 'PowerPoint'],
		odp: ['fa-file-powerpoint', 'ODP'],
		txt: ['fa-file-lines', 'Text'], md: ['fa-file-lines', 'Markdown'], log: ['fa-file-lines', 'Log'],
		nfo: ['fa-file-lines', 'NFO'],
		// archives
		zip: ['fa-file-zipper', 'ZIP'], rar: ['fa-file-zipper', 'RAR'], '7z': ['fa-file-zipper', '7z'],
		tar: ['fa-file-zipper', 'TAR'], gz: ['fa-file-zipper', 'GZip'], bz2: ['fa-file-zipper', 'BZip2'],
		xz: ['fa-file-zipper', 'XZ'], zst: ['fa-file-zipper', 'Zstd'], tgz: ['fa-file-zipper', 'TAR.GZ'],
		// torrents / p2p
		torrent: ['fa-magnet', 'Torrent'],
		// code
		js: ['fa-file-code', 'JavaScript'], ts: ['fa-file-code', 'TypeScript'], jsx: ['fa-file-code', 'JSX'],
		tsx: ['fa-file-code', 'TSX'], php: ['fa-file-code', 'PHP'], py: ['fa-file-code', 'Python'],
		rb: ['fa-file-code', 'Ruby'], java: ['fa-file-code', 'Java'], c: ['fa-file-code', 'C'],
		cpp: ['fa-file-code', 'C++'], cc: ['fa-file-code', 'C++'], h: ['fa-file-code', 'C header'],
		cs: ['fa-file-code', 'C#'], go: ['fa-file-code', 'Go'], rs: ['fa-file-code', 'Rust'],
		swift: ['fa-file-code', 'Swift'], kt: ['fa-file-code', 'Kotlin'], html: ['fa-file-code', 'HTML'],
		htm: ['fa-file-code', 'HTML'], css: ['fa-file-code', 'CSS'], scss: ['fa-file-code', 'SCSS'],
		json: ['fa-file-code', 'JSON'], xml: ['fa-file-code', 'XML'], yml: ['fa-file-code', 'YAML'],
		yaml: ['fa-file-code', 'YAML'], sh: ['fa-file-code', 'Shell'], sql: ['fa-file-code', 'SQL'],
		ini: ['fa-file-code', 'INI'], toml: ['fa-file-code', 'TOML'],
		// executables / packages
		exe: ['fa-box', 'Executable'], msi: ['fa-box', 'Installer'], apk: ['fa-box', 'Android app'],
		deb: ['fa-box', 'Debian package'], rpm: ['fa-box', 'RPM package'], dmg: ['fa-box', 'macOS image'],
		appimage: ['fa-box', 'AppImage'],
		// disk images
		iso: ['fa-compact-disc', 'ISO'], img: ['fa-compact-disc', 'Disk image'], bin: ['fa-compact-disc', 'Binary'],
		// fonts
		ttf: ['fa-font', 'Font'], otf: ['fa-font', 'Font'], woff: ['fa-font', 'Font'], woff2: ['fa-font', 'Font'],
		// ebooks
		epub: ['fa-book', 'ePub'], mobi: ['fa-book', 'Mobi'], azw3: ['fa-book', 'Kindle']
	};

	// MIME major type -> FontAwesome class (fallback when the extension is unknown)
	var MIME_MAJOR = {
		image: 'fa-file-image', video: 'fa-file-video', audio: 'fa-file-audio', text: 'fa-file-lines'
	};

	function ext(name) {
		if (!name) return '';
		var i = name.lastIndexOf('.');
		return i >= 0 ? name.slice(i + 1).toLowerCase() : '';
	}

	/** Resolve {icon, label} for a filename + optional MIME. */
	function resolve(name, mime) {
		var e = ext(name);
		if (EXT[e]) return { icon: EXT[e][0], label: EXT[e][1] };
		if (mime) {
			var major = String(mime).split('/')[0];
			if (MIME_MAJOR[major]) return { icon: MIME_MAJOR[major], label: mime };
			return { icon: 'fa-file', label: mime };          // unknown ext → show the MIME
		}
		return { icon: 'fa-file', label: e ? e.toUpperCase() : 'File' };
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	/** HTML for an <i> FontAwesome icon, with the human type as its tooltip. */
	window.fileIcon = function (name, mime) {
		var r = resolve(name, mime);
		return '<i class="fa-solid ' + r.icon + '" title="' + esc(r.label) + '" aria-hidden="true"></i>';
	};

	/** DOM equivalent for renderers that intentionally avoid HTML-string sinks. */
	window.fileIconElement = function (name, mime) {
		var r = resolve(name, mime);
		var icon = document.createElement('i');
		icon.className = 'fa-solid ' + r.icon;
		icon.title = r.label;
		icon.setAttribute('aria-hidden', 'true');
		return icon;
	};

	/** Just the human-readable type label (known short name, or the MIME string). */
	window.fileTypeLabel = function (name, mime) {
		return resolve(name, mime).label;
	};
})();
