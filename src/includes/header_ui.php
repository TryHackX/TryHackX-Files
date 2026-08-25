<?php require __DIR__ . '/bg_decoration.php'; ?>
<header>
    <a href="<?= $appUrl ?>/" class="logo">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="17 8 12 3 7 8" />
                <line x1="12" y1="3" x2="12" y2="15" />
            </svg>
        </div>
        <span class="logo-text">
            <?= defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files') ?>
        </span>
    </a>
    <div class="header-actions">
        <?php
        // Language switcher (Faza 4.3). Plain links carrying ?lang= so it works without JS;
        // Lang::init() remembers the choice in a cookie, then we drop the param from the URL.
        // pt 4: first in the row, so the switcher sits at the far left of the actions and the
        // premium link takes the place it used to occupy.
        $__langBase = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $__langQuery = $_GET;
        ?>
        <div class="lang-switch" role="group" aria-label="<?= _h('common.language') ?>">
            <?php /* pt 6: the switcher lists what the admin chose to offer here — not every
                     installed file, which is what it used to show (a language switched off in
                     Settings → Languages still appeared). */ ?>
            <?php foreach (Lang::forSwitcher() as $__code => $__name): ?>
                <?php $__langQuery['lang'] = $__code; ?>
                <a href="<?= htmlspecialchars($__langBase . '?' . http_build_query($__langQuery)) ?>"
                    class="lang-opt<?= Lang::current() === $__code ? ' active' : '' ?>"
                    hreflang="<?= $__code ?>" title="<?= htmlspecialchars($__name) ?>"><?= strtoupper($__code) ?></a>
            <?php endforeach; ?>
        </div>
        <?php
        // pt 9: link to the plans page, when the operator turned premium on and chose to
        // advertise it here. Kept as a plain link so it works without JS, like the switcher.
        $__premiumOn = class_exists('Database')
            && Database::getSetting('premium_enabled', '0') === '1'
            && Database::getSetting('premium_show_header', '1') === '1';
        ?>
        <?php if ($__premiumOn): ?>
            <a href="<?= $appUrl ?>/premium" class="btn btn-premium">
                <i class="fa-solid fa-gem"></i> <?= _h('premium.nav') ?>
            </a>
        <?php endif; ?>
        <?php /* Between premium and the theme toggle: the bell is about this account, so it sits
                 with the account controls rather than out with the site-wide links. Renders
                 nothing at all for a signed-out visitor. */ ?>
        <?php require __DIR__ . '/notification_bell.php'; ?>
        <button class="btn-icon" type="button" data-fh-action="toggle-theme" title="<?= _h('nav.theme') ?>">
            <svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5" />
                <line x1="12" y1="1" x2="12" y2="3" />
                <line x1="12" y1="21" x2="12" y2="23" />
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                <line x1="1" y1="12" x2="3" y2="12" />
                <line x1="21" y1="12" x2="23" y2="12" />
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
            </svg>
            <svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
            </svg>
        </button>
        <button class="btn-icon" id="authBtn" type="button" data-fh-action="open-auth"
            title="<?= isset($_SESSION['user_id']) ? _h('nav.profile') : _h('nav.login') ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </svg>
        </button>
    </div>
</header>
