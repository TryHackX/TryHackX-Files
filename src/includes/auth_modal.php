<div class="auth-modal" id="authModal">
    <div class="auth-box">
        <button class="auth-close" type="button" data-auth-action="close">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
        </button>

        <div class="auth-message" id="authMessage"></div>

        <div id="authGuest" style="<?= isset($currentUser) ? 'display:none;' : 'display:block;' ?>">
            <h3><?= _h('auth.greeting') ?></h3>
            <p><?= _h('auth.greeting_sub') ?></p>

            <div class="auth-tabs">
                <button class="auth-tab active" type="button" data-auth-tab="login"><?= _h('auth.tab_login') ?></button>
                <button class="auth-tab" type="button" data-auth-tab="register"><?= _h('auth.tab_register') ?></button>
            </div>

            <form class="auth-form active" id="loginForm">
                <div class="form-group">
                    <label for="loginUsername"><?= _h('auth.username') ?></label>
					<input type="text" class="auth-input" id="loginUsername" name="username"
						placeholder="<?= _h('auth.username_ph') ?>" required maxlength="<?= InputLimits::HARD_USERNAME_MAX ?>" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="loginPassword"><?= _h('auth.password') ?></label>
					<input type="password" class="auth-input" id="loginPassword" name="password"
						placeholder="<?= _h('auth.password_ph') ?>" required maxlength="<?= InputLimits::HARD_PASSWORD_MAX ?>" autocomplete="current-password">
                </div>
                <?php if (RememberTokenRepository::enabled()): ?>
                    <?php
                    // Offered durations are capped by the administrator, so a 30-day option on
                    // an installation that allows seven would be a lie. Anything above the cap
                    // simply is not listed.
                    $rememberMax = RememberTokenRepository::maxLifetime();
                    $rememberChoices = [
                        0 => 'auth.remember_session',
                        1800 => 'auth.remember_30m',
                        3600 => 'auth.remember_1h',
                        10800 => 'auth.remember_3h',
                        86400 => 'auth.remember_1d',
                        604800 => 'auth.remember_7d',
                        2592000 => 'auth.remember_30d',
                    ];
                    ?>
                    <div class="form-group">
                        <label for="loginRemember"><?= _h('auth.remember_label') ?></label>
                        <select class="auth-input" id="loginRemember" name="remember">
                            <?php foreach ($rememberChoices as $seconds => $key): ?>
                                <?php if ($seconds === 0 || $seconds <= $rememberMax): ?>
                                    <option value="<?= (int) $seconds ?>"><?= _h($key) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <option value="-1"><?= _h('auth.remember_max') ?></option>
                        </select>
                    </div>
                <?php endif; ?>
                <div style="display:flex; justify-content:flex-end; margin-top:-10px; margin-bottom:15px;">
                    <a href="#" style="font-size:0.85rem; color:var(--text-secondary); text-decoration:none;"
                        data-auth-action="recovery"><?= _h('auth.forgot') ?></a>
                </div>
                <!-- Recaptcha Container -->
                <div id="recaptchaContainer" class="form-group" style="display:none; justify-content:center;">
                    <div id="authCaptchaWidget"></div>
                </div>
                <button type="submit" class="auth-submit" id="loginBtn"><?= _h('auth.login_btn') ?></button>
            </form>

            <!-- Second login factor (Faza 4.4): shown only when the account has 2FA on. -->
            <form class="auth-form" id="twofaForm">
                <h4 style="margin: 0 0 8px; text-align: center;"><?= _h('auth.2fa_title') ?></h4>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 18px; text-align: center;">
                    <?= _h('auth.2fa_sub') ?>
                </p>
                <div class="form-group">
                    <label for="twofaLoginCode"><?= _h('auth.2fa_code') ?></label>
                    <input type="text" class="auth-input" id="twofaLoginCode" name="code" placeholder="123456 / XXXX-XXXX"
                        inputmode="text" autocomplete="one-time-code" maxlength="12" required
                        style="letter-spacing:.12em; text-align:center; font-size:1.2rem;">
                </div>
                <button type="submit" class="auth-submit" id="twofaLoginBtn"><?= _h('auth.2fa_verify') ?></button>
                <div style="display:flex; justify-content:center; margin-top:12px;">
                    <a href="#" style="font-size:0.85rem; color:var(--text-secondary); text-decoration:none;"
                        data-auth-action="cancel-2fa"><?= _h('auth.2fa_back') ?></a>
                </div>
            </form>

            <form class="auth-form" id="registerForm">
                <div class="form-group">
                    <label for="regUsername"><?= _h('auth.username') ?></label>
					<input type="text" class="auth-input" id="regUsername" name="username" placeholder="<?= _h('auth.reg_username_ph') ?>"
						pattern="[A-Za-z0-9_.-]{<?= InputLimits::usernameMin() ?>,<?= InputLimits::usernameMax() ?>}"
						required minlength="<?= InputLimits::usernameMin() ?>" maxlength="<?= InputLimits::usernameMax() ?>" autocomplete="username">
                    <div class="field-status" id="usernameStatus"></div>
                </div>
                <div class="form-group">
                    <label for="regEmail"><?= _h('auth.email') ?></label>
					<input type="email" class="auth-input" id="regEmail" name="email" placeholder="<?= _h('auth.email_ph') ?>"
						required maxlength="<?= InputLimits::emailMax() ?>" autocomplete="email">
                    <div class="field-status" id="emailStatus"></div>
                </div>
                <div class="form-group">
                    <label for="regPassword"><?= _h('auth.password') ?></label>
					<input type="password" class="auth-input" id="regPassword" name="password" placeholder="<?= _h('auth.strong_password_ph') ?>"
						required minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" autocomplete="new-password">
                    <div class="pwd-meter">
                        <div class="pwd-meter-fill" id="pwdBar"></div>
                    </div>
                    <ul class="pwd-reqs">
						<li id="reqLen"><?= _h('pwd.req_len_configured', ['min' => InputLimits::accountPasswordMin()]) ?></li>
                        <li id="reqUpper"><?= _h('pwd.req_upper') ?></li>
                        <li id="reqDigit"><?= _h('pwd.req_digit') ?></li>
                        <li id="reqSpec"><?= _h('pwd.req_special') ?></li>
                    </ul>
                </div>
                <div class="form-group">
					<input type="password" class="auth-input" id="regPassword2" name="password2"
						placeholder="<?= _h('pwd.repeat') ?>" required maxlength="<?= InputLimits::accountPasswordMax() ?>" autocomplete="new-password">
                    <div class="field-status" id="passMatchStatus"></div>
                </div>
                <!-- Recaptcha Container for Register -->
                <div id="recaptchaRegisterContainer" class="form-group" style="display:none; justify-content:center;">
                    <div id="authCaptchaRegisterWidget"></div>
                </div>
                <button type="submit" class="auth-submit" id="registerBtn"><?= _h('auth.register_btn') ?></button>
            </form>

            <form class="auth-form" id="recoveryForm">
                <h4 style="margin: 0 0 16px; text-align: center;"><?= _h('auth.recovery_title') ?></h4>
                <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 20px; text-align: center;">
                    <?= _h('auth.recovery_sub') ?>
                </p>
                <div class="form-group">
                    <label for="recoveryInput"><?= _h('auth.recovery_input') ?></label>
                    <input type="text" class="auth-input" id="recoveryInput" name="input" placeholder="<?= _h('auth.recovery_input') ?>"
						required maxlength="<?= InputLimits::recoveryInputMax() ?>">
                </div>
                <button type="submit" class="auth-submit" id="recoveryBtn"><?= _h('auth.recovery_btn') ?></button>
                <div style="text-align: center; margin-top: 16px;">
                    <a href="#" style="font-size: 0.9rem; color: var(--text-secondary);"
                        data-auth-tab="login"><?= _h('auth.back_to_login') ?></a>
                </div>
            </form>

            <form class="auth-form" id="resetForm">
                <h4 style="margin: 0 0 16px; text-align: center;"><?= _h('auth.reset_title') ?></h4>
                <input type="hidden" name="token" id="resetToken">

                <div class="form-group">
                    <label for="resetPass"><?= _h('auth.new_password') ?></label>
                    <input type="password" class="auth-input" id="resetPass" name="password" placeholder="<?= _h('auth.strong_password_ph') ?>"
						required minlength="<?= InputLimits::accountPasswordMin() ?>" maxlength="<?= InputLimits::accountPasswordMax() ?>" autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label for="resetPass2"><?= _h('pwd.repeat') ?></label>
                    <input type="password" class="auth-input" id="resetPass2" name="password_confirm"
						placeholder="<?= _h('pwd.repeat') ?>" required maxlength="<?= InputLimits::accountPasswordMax() ?>" autocomplete="new-password">
                </div>

                <button type="submit" class="auth-submit" id="resetBtn"><?= _h('auth.reset_btn') ?></button>
            </form>
        </div>

        <div id="authUser" style="<?= isset($currentUser) ? 'display:block;' : 'display:none;' ?>">
            <h3><?= _h('auth.logged_in') ?></h3>

            <div class="auth-user">
                <div class="auth-user-icon" id="userInitial">
                    <?= isset($currentUser)
                        ? htmlspecialchars(
                            strtoupper(substr((string) $currentUser['username'], 0, 1)),
                            ENT_QUOTES,
                            'UTF-8'
                        )
                        : '?' ?>
                </div>
                <div class="auth-user-info">
                    <div class="auth-user-name" id="userName">
                        <?= isset($currentUser) ? htmlspecialchars($currentUser['username']) : _h('auth.role_user') ?>
                    </div>
                    <div class="auth-user-role" id="userRole">
                        <?= isset($currentUser) && $currentUser['is_admin'] ? _h('auth.role_admin') : _h('auth.role_user') ?>
                    </div>
                </div>
            </div>

            <div class="user-stats-grid" id="userStats" style="display:none;">
                <div class="user-stat-card">
                    <span><?= _h('auth.stat_files') ?></span>
                    <strong id="statFiles">0</strong>
                </div>
                <div class="user-stat-card">
                    <span><?= _h('auth.stat_size') ?></span>
                    <strong id="statSize">0 MB</strong>
                </div>
                <div class="user-stat-card">
                    <span><?= _h('auth.stat_downloads') ?></span>
                    <strong id="statDownloads">0</strong>
                </div>
            </div>

            <div class="auth-links">
                <?php if (basename($_SERVER['PHP_SELF']) !== 'index.php'): ?>
                    <a href="<?= $appUrl ?>/" class="auth-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <?= _h('auth.home') ?>
                    </a>
                <?php endif; ?>

                <a href="<?= $appUrl ?>/panel.php?tab=myfiles" class="auth-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z" />
                    </svg>
                    <?= _h('panel.title') ?>
                </a>



                <a href="#" class="auth-link danger" data-auth-action="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                    <?= _h('auth.logout') ?>
                </a>
            </div>
        </div>
    </div>
</div>
