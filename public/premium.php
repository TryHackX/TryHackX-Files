<?php
/**
 * Public plans page (pt 9).
 *
 * Renders whatever the operator configured under Settings → Premium. Nothing here knows how a
 * payment is taken: each plan either links out to the operator's checkout or renders a snippet
 * they pasted in (a provider's button, an embed, a bank-transfer note). Fulfilment happens
 * afterwards, through `?action=premium_activate`.
 */
define('FILEHOST_CSP_STRICT_SCRIPTS', true);
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/includes/Markdown.php';
require_once __DIR__ . '/../src/includes/api/PremiumController.php';
require_once __DIR__ . '/../src/includes/AdRenderer.php';

$appUrl = APP_URL;

// Needed by the shared account modal (header person icon), exactly as index.php, download.php
// and collection.php set it. Without it `auth_scripts.php` renders `currentUser = null` and the
// modal opens on the login form — so a signed-in visitor looked signed out on this page alone.
$currentUser = null;
if (isset($_SESSION['user_id'])) {
	$currentUser = [
		'id' => $_SESSION['user_id'],
		'username' => $_SESSION['user_name'] ?? __('error.user_fallback'),
		'is_admin' => $_SESSION['is_admin'] ?? false,
	];
}

if (!PremiumController::isEnabled()) {
	http_response_code(404);
	$pageTitle = __('premium.title');
	$pageIcon = '<i class="fa-solid fa-circle-question"></i>';
	$pageHeading = __('premium.disabled_heading');
	$pageMessage = __('premium.disabled');
	require __DIR__ . '/../src/includes/error_page.php';
	exit;
}

$settings = PremiumController::settings();
$plans = array_map([PremiumController::class, 'publicView'], PlanRepository::enabled());
$theme = $_COOKIE['theme'] ?? 'dark';
$title = $settings['premium_title'] !== '' ? $settings['premium_title'] : __('premium.title');

// The signed-in visitor's current plan state, so the page can say "you already have this"
// instead of selling them what they are using.
// The group is resolved rather than read raw off the row: an account that has never been moved
// carries `group_id = NULL` and still *is* on the default tier, which is exactly the tier a
// `free` card describes. Reading the column alone made that card look like somebody else's.
$currentGroupId = null;
$currentExpiry = null;
if (isset($_SESSION['user_id'])) {
	$me = Database::getUserById((int) $_SESSION['user_id']);
	$myGroup = Database::getUserGroup((int) $_SESSION['user_id']);
	$currentGroupId = $myGroup ? (int) $myGroup['id'] : (isset($me['group_id']) ? (int) $me['group_id'] : null);
	$currentExpiry = isset($me['group_expires_at']) ? (int) $me['group_expires_at'] : null;
}
$ownedPlanIds = [];
foreach (PlanRepository::enabled() as $p) {
	// A guest card is nobody's plan — it exists to describe having no account at all, so a
	// signed-in visitor never "owns" it however the groups line up.
	if (($p['kind'] ?? 'paid') === 'guest') {
		continue;
	}
	if ($currentGroupId && (int) $p['group_id'] === $currentGroupId) {
		$ownedPlanIds[] = (int) $p['id'];
	}
}

// pt 6: "do they hold any plan at all" decides whether the other cards say "buy" or "switch".
// A free tier is where everyone starts, so holding it is not holding a plan — the paid cards
// must still say "Kup", not "Zmień plan".
$hasAnyPlan = false;
foreach (PlanRepository::enabled() as $p) {
	if (($p['kind'] ?? 'paid') === 'paid' && in_array((int) $p['id'], $ownedPlanIds, true)) {
		$hasAnyPlan = true;
	}
}

/**
 * pt 10: what the built-in checkout has to say when it bounces the buyer back here.
 *
 * `err` is one of PaymentController's codes; `order` is the buyer returning from PayU, which
 * means the payment was *started*, not that it succeeded — the notification decides that, and
 * it may arrive after the redirect. So the page reports what it actually knows.
 */
$noticeText = '';
$noticeKind = '';
$errCode = preg_replace('/[^a-z_]/', '', (string) ($_GET['err'] ?? ''));
if ($errCode !== '' && Lang::has('premium.err_' . $errCode)) {
	$noticeText = __('premium.err_' . $errCode);
	$noticeKind = 'error';
} elseif (!empty($_GET['order'])) {
	$order = PaymentRepository::byExtOrderId((string) $_GET['order']);
	// Only ever act on — or talk about — someone's own order.
	if ($order && isset($_SESSION['user_id']) && (int) $order['user_id'] === (int) $_SESSION['user_id']) {
		// The buyer is standing here having just paid, so ask the provider outright instead of
		// waiting for a notification that may be minutes away — or, on an installation PayU
		// cannot reach at all, never coming. This is the moment the answer matters most.
		// GET rendering is read-only; webhooks and the scheduled worker own reconciliation.
		$status = (string) $order['status'];
		$paid = $status === PaymentRepository::COMPLETED;
		$noticeText = $paid ? __('premium.order_done') : __('premium.order_pending');
		$noticeKind = $paid ? 'success' : 'info';

		// The grant just changed which plan this account holds; re-read so the cards below
		// show "active until …" rather than still offering what was bought a second ago.
		if ($paid) {
			$me = Database::getUserById((int) $_SESSION['user_id']);
			$currentGroupId = isset($me['group_id']) ? (int) $me['group_id'] : null;
			$currentExpiry = isset($me['group_expires_at']) ? (int) $me['group_expires_at'] : null;
			$ownedPlanIds = [];
			foreach (PlanRepository::enabled() as $p) {
				if ($currentGroupId && (int) $p['group_id'] === $currentGroupId) {
					$ownedPlanIds[] = (int) $p['id'];
				}
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="<?= Lang::current() ?>">

<head>
	<?php require_once __DIR__ . '/../src/includes/head_meta.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/csrf_head.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/i18n_head.php'; ?>
	<title><?= APP_NAME ?> — <?= htmlspecialchars($title) ?></title>
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/index.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/background.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/notifications.css?v=<?= APP_VERSION ?>">
	<?php require __DIR__ . '/../src/includes/site_footer_assets.php'; ?>
	<?= AdRenderer::styles() ?>
</head>

<body class="<?= $theme === 'light' ? 'light' : '' ?>">

	<div class="container is-wide">
		<?php require_once __DIR__ . '/../src/includes/header_ui.php'; ?>

		<?= AdRenderer::zone('premium_top') ?>

		<div class="hero">
			<?php /* The default heading is a sentence with an accented opening, like the home
			         page's; an operator who typed their own title gets it printed as written. */ ?>
			<h1>
				<?php if ($settings['premium_title'] !== ''): ?>
					<?= htmlspecialchars($settings['premium_title']) ?>
				<?php else: ?>
					<span><?= _h('premium.hero_lead') ?></span> <?= _h('premium.hero_rest') ?>
				<?php endif; ?>
			</h1>
		</div>

		<?php if ($noticeText !== ''): ?>
			<div class="premium-notice is-<?= $noticeKind ?>">
				<i class="fa-solid <?= $noticeKind === 'error' ? 'fa-circle-exclamation' : ($noticeKind === 'success' ? 'fa-circle-check' : 'fa-circle-info') ?>"></i>
				<span><?= htmlspecialchars($noticeText) ?></span>
			</div>
		<?php endif; ?>

		<?php if ($settings['premium_intro'] !== ''): ?>
			<div class="premium-intro md-body"><?= Markdown::render($settings['premium_intro']) ?></div>
		<?php endif; ?>

		<?php if (!$plans): ?>
			<p class="premium-empty"><?= _h('premium.none') ?></p>
		<?php else: ?>
			<div class="plan-grid">
				<?php foreach ($plans as $plan): ?>
					<?php
					$owned = in_array($plan['id'], $ownedPlanIds, true);
					// A guest card describes the visitor's situation only while they are one.
					$isGuestCard = $plan['kind'] === 'guest';
					$appliesNow = $owned || ($isGuestCard && !isset($_SESSION['user_id']));
					?>
					<div class="plan-card<?= $plan['highlighted'] ? ' is-featured' : '' ?><?= $appliesNow ? ' is-owned' : '' ?><?= $plan['kind'] !== 'paid' ? ' is-' . $plan['kind'] : '' ?>">
						<?php /* Both badges in one row: the operator's own on the left, "your plan" on
						         the right. As two absolutely-positioned boxes they overlapped on any
						         card that had both. */ ?>
						<?php if ($plan['badge'] !== '' || $appliesNow): ?>
							<div class="plan-badges">
								<?php if ($plan['badge'] !== ''): ?>
									<span class="plan-badge-item accent"><?= htmlspecialchars($plan['badge']) ?></span>
								<?php endif; ?>
								<?php if ($appliesNow): ?>
									<span class="plan-badge-item success"><?= _h('premium.your_plan') ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<h3><?= htmlspecialchars($plan['name']) ?></h3>
						<div class="plan-price">
							<?php /* A card with nothing to charge still needs the price line, or the grid
							         goes ragged. It says what the tier costs — nothing — rather than
							         leaving a hole where every other card has a number. */ ?>
							<strong><?= $plan['price'] !== '' ? htmlspecialchars($plan['price']) : ($plan['kind'] !== 'paid' ? _h('premium.price_free') : '') ?></strong>
							<?php if ($plan['period'] !== ''): ?>
								<span><?= htmlspecialchars($plan['period']) ?></span>
							<?php elseif ($isGuestCard): ?>
								<span><?= _h('premium.period_no_account') ?></span>
							<?php endif; ?>
						</div>

						<?php if ($plan['descriptionHtml'] !== ''): ?>
							<div class="plan-desc md-body"><?= $plan['descriptionHtml'] ?></div>
						<?php endif; ?>

						<?php if ($plan['features']): ?>
							<ul class="plan-features">
								<?php foreach ($plan['features'] as $feature): ?>
									<li><i class="fa-solid fa-check"></i> <?= htmlspecialchars($feature) ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php /* The group's real numbers, when the operator asked for them. Read from the
						         group on every request, so a limit changed in Settings is a limit changed
						         here — the feature list above is prose and can drift, this cannot. */ ?>
						<?php if ($plan['limits']): ?>
							<dl class="plan-limits">
								<?php foreach ($plan['limits'] as $limit): ?>
									<div class="plan-limit">
										<dt><i class="fa-solid <?= htmlspecialchars($limit['icon']) ?>"></i> <?= htmlspecialchars($limit['label']) ?></dt>
										<dd><?= htmlspecialchars($limit['value']) ?></dd>
									</div>
								<?php endforeach; ?>
							</dl>
						<?php endif; ?>

						<div class="plan-cta">
							<?php
							/**
							 * pt 6: what this button means depends on what the visitor already has.
							 *
							 * The owned plan is not a dead end with a tick on it — it is the one you
							 * renew, and renewing *extends* rather than restarting (PlanRepository::grant).
							 * The others are a change of tier. Saying "Kup" on all three told a paying
							 * subscriber nothing about either.
							 */
							$ctaLabel = $owned
								? __('premium.extend')
								: ($hasAnyPlan ? __('premium.switch') : ($plan['buttonLabel'] !== '' ? $plan['buttonLabel'] : __('premium.buy')));
							?>
							<?php if ($plan['kind'] !== 'paid'): ?>
								<?php /* Nothing to buy here. What the card offers instead depends on who is
								         reading it: a guest can create the account that lifts these limits,
								         a signed-in reader on this tier is simply told they are on it. */ ?>
								<?php if ($appliesNow): ?>
									<span class="plan-owned">
										<i class="fa-solid fa-circle-check"></i>
										<?= $isGuestCard ? _h('premium.guest_current') : _h('premium.free_current') ?>
									</span>
								<?php elseif (!isset($_SESSION['user_id'])): ?>
									<a class="btn btn-primary" href="<?= $appUrl ?>/?action=register">
										<i class="fa-solid fa-user-plus"></i>
										<?= $plan['buttonLabel'] !== '' ? htmlspecialchars($plan['buttonLabel']) : _h('premium.free_signup') ?>
									</a>
								<?php else: ?>
									<span class="plan-owned plan-owned-muted">
										<i class="fa-solid fa-circle-info"></i>
										<?= $isGuestCard ? _h('premium.guest_note') : _h('premium.free_note') ?>
									</span>
								<?php endif; ?>
							<?php elseif ($owned && $plan['checkoutType'] === 'builtin'): ?>
								<form class="plan-checkout-form" method="post"
									data-plan-id="<?= (int) $plan['id'] ?>"
									action="<?= htmlspecialchars($appUrl . '/api.php?action=checkout&plan=' . (int) $plan['id']) ?>">
									<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
									<input type="hidden" name="_idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
									<button type="submit" class="btn btn-primary plan-checkout-link">
										<i class="fa-solid fa-rotate-right"></i> <?= htmlspecialchars($ctaLabel) ?>
									</button>
								</form>
								<span class="plan-owned">
									<i class="fa-solid fa-circle-check"></i>
									<?= $currentExpiry
										? __('premium.owned_until', ['date' => date('d.m.Y', $currentExpiry)])
										: _h('premium.owned') ?>
								</span>
							<?php elseif ($owned): ?>
								<span class="plan-owned">
									<i class="fa-solid fa-circle-check"></i>
									<?= $currentExpiry
										? __('premium.owned_until', ['date' => date('d.m.Y', $currentExpiry)])
										: _h('premium.owned') ?>
								</span>
							<?php elseif ($plan['checkoutType'] === 'builtin'): ?>
								<?php /* pt 10: the built-in payment checkout. The URL is generated here from the
								         plan's id rather than stored on the row, so it always points at this
								         installation and never carries a stale host. */ ?>
								<form class="plan-checkout-form" method="post"
									data-plan-id="<?= (int) $plan['id'] ?>"
									action="<?= htmlspecialchars($appUrl . '/api.php?action=checkout&plan=' . (int) $plan['id']) ?>">
									<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
									<input type="hidden" name="_idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
									<button type="submit" class="btn btn-primary plan-checkout-link">
										<?= htmlspecialchars($ctaLabel) ?>
									</button>
								</form>
							<?php elseif ($plan['checkoutType'] === 'link' && $plan['checkoutUrl'] !== ''): ?>
								<a class="btn btn-primary" href="<?= htmlspecialchars($plan['checkoutUrl']) ?>" rel="noopener">
									<?= htmlspecialchars($ctaLabel) ?>
								</a>
							<?php elseif ($plan['checkoutType'] === 'html'): ?>
								<?php /* Authored by an admin in the panel, like any other template setting, and
								         rendered as written — that is the whole point of the `html` mode: it is
								         how a provider's own button or embed script gets onto the page. */ ?>
								<?= $plan['checkoutHtml'] ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php /* Promo code (runda 9): checks the code and rewrites the built-in checkout links
		         with `&code=`. Signed-in only — checkout requires an account anyway, and the
		         endpoint refuses anonymous probing of the code space. */ ?>
		<?php if ($plans && isset($_SESSION['user_id'])): ?>
			<div class="promo-box" id="promoBox">
				<label for="promoInput"><i class="fa-solid fa-ticket"></i> <?= _h('premium.promo_label') ?></label>
				<div class="promo-row">
					<input type="text" id="promoInput" maxlength="40" placeholder="<?= _h('premium.promo_ph') ?>" autocomplete="off">
					<button type="button" class="btn" id="promoApply"><?= _h('premium.promo_apply') ?></button>
				</div>
				<small id="promoStatus"></small>
			</div>
		<?php endif; ?>

		<?php if ($settings['premium_footer'] !== ''): ?>
			<div class="premium-footer md-body"><?= Markdown::render($settings['premium_footer']) ?></div>
		<?php endif; ?>

		<?= AdRenderer::zone('premium_bottom') ?>
		<?php require __DIR__ . '/../src/includes/site_footer.php'; ?>
	</div>

	<?php /* pt 1: the header rendered above wires the account button, the theme toggle and the
	         language switcher — all of which live in these two includes. This page was the only
	         one drawing that header without them, so `showAuthModal()` and `toggleTheme()` were
	         simply undefined and both buttons did nothing when pressed. */ ?>
	<?php require_once __DIR__ . '/../src/includes/auth_modal.php'; ?>
	<?php require_once __DIR__ . '/../src/includes/auth_scripts.php'; ?>

	<script src="<?= $appUrl ?>/assets/js/util.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/api.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/ui.js?v=<?= APP_VERSION ?>"></script>
	<script src="<?= $appUrl ?>/assets/js/notifications.js?v=<?= APP_VERSION ?>" defer></script>
	<?php
	$premiumBootstrap = json_encode([
		'valid' => __('premium.promo_ok'),
		'validPlan' => __('premium.promo_ok_plan'),
		'invalid' => __('premium.promo_bad'),
		'connectionError' => __('common.connection_error'),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	if ($premiumBootstrap === false) {
		$premiumBootstrap = '{}';
	}
	?>
	<div id="premiumBootstrap" hidden
		data-messages="<?= htmlspecialchars(
			$premiumBootstrap,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		) ?>"></div>
	<script src="<?= $appUrl ?>/assets/js/premium.js?v=<?= APP_VERSION ?>"></script>
	<?= AdRenderer::scripts() ?>
</body>

</html>
