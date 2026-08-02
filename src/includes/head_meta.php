<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php
// Fonts are provided by the OS via a system font stack (see CSS `body { font-family }`) —
// no external font requests, for privacy and performance. Page-specific CSS is linked
// by each page after this include.
//
// FontAwesome is self-hosted (CSS + woff2 under assets/fontawesome) so it satisfies the
// CSP's `style-src 'self'` / `font-src 'self'` without any CDN. Icons replace the emoji
// used throughout the UI.
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/fontawesome/css/all.min.css?v=<?= APP_VERSION ?>">
