<?php
/**
 * PremiumController (Faza 7 · pt 9) — paid access plans.
 *
 * Three audiences:
 *   - visitors read the enabled plans (`premium_plans`);
 *   - admins manage them (`admin_plans*`) and can grant or revoke one by hand;
 *   - a payment provider — or any script the operator writes — confirms a purchase through
 *     `premium_activate`, authenticated by a shared secret rather than a session.
 *
 * The activation endpoint is the whole reason this is provider-agnostic: TryHackX Files never sees a
 * payment. Whatever the operator sells with (Stripe, PayPal, Gumroad, a bank transfer they
 * reconcile by hand) calls this one URL with the plan and the buyer, and the group is granted.
 */
final class PremiumController
{
	/** Settings that make up the premium feature, with their defaults. */
	public const SETTINGS = [
		'premium_enabled' => '0',
		'premium_title' => '',
		'premium_intro' => '',
		'premium_footer' => '',
		'premium_show_header' => '1',
		'premium_show_home' => '1',
		'premium_show_panel' => '1',
		// Receipts (runda 10): an optional, printable document per completed payment. The
		// seller block is freeform text — whatever the operator wants printed (name,
		// address, tax id); the app never needs to understand it.
		'invoice_enabled' => '0',
		'invoice_seller' => '',
		'invoice_prefix' => 'FH',
		'invoice_footer' => '',
	];

	public const INVOICE_SETTINGS = [
		'invoice_enabled' => '0',
		'invoice_seller' => '',
		'invoice_prefix' => 'FH',
		'invoice_footer' => '',
	];

	/** Current premium settings, defaults filled in. */
	public static function settings(): array
	{
		$out = [];
		foreach (self::SETTINGS as $key => $default) {
			$out[$key] = (string) Database::getSetting($key, $default);
		}
		return $out;
	}

	/** One shared document configuration used by both Premium and advertising purchases. */
	public static function invoiceSettings(): array
	{
		$out = [];
		foreach (self::INVOICE_SETTINGS as $key => $default) {
			$out[$key] = (string) Database::getSetting($key, $default);
		}
		return $out;
	}

	/** Validate and persist only the shared receipt/invoice fields present in an input. */
	public static function saveInvoiceSettings(array $input): void
	{
		foreach (self::INVOICE_SETTINGS as $key => $default) {
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = (string) $input[$key];
			if ($key === 'invoice_enabled') {
				$value = in_array($value, ['1', 'true'], true) || $input[$key] === true ? '1' : '0';
			} elseif ($key === 'invoice_prefix') {
				$value = preg_replace('/[^A-Za-z0-9_-]/', '', trim($value)) ?: 'FH';
				$value = substr($value, 0, 12);
			} else {
				$value = mb_substr(trim($value), 0, 4000);
			}
			Database::setSetting($key, $value);
		}
	}

	public static function isEnabled(): bool
	{
		return Database::getSetting('premium_enabled', '0') === '1';
	}

	/**
	 * A plan as the public page needs it: descriptions already rendered, and nothing about the
	 * plan's internals (which group it maps to, how long the grant lasts) leaking out.
	 */
	public static function publicView(array $p): array
	{
		$format = $p['description_format'] ?? 'markdown';
		$desc = (string) ($p['description'] ?? '');

		// A showcase card in automatic mode writes itself from the group it is bound to, in the
		// reader's language. The stored fields stay untouched underneath, so turning the switch
		// off hands back whatever the operator had typed before.
		if (!empty($p['auto_content'])) {
			$auto = PlanRepository::autoContent($p);
			$p = array_merge($p, [
				'name' => $auto['name'],
				'price' => $auto['price'],
				'period' => $auto['period'],
				'features' => implode("\n", $auto['features']),
			]);
			$desc = $auto['description'];
			$format = 'markdown';
		}

		return [
			'id' => (int) $p['id'],
			'name' => (string) $p['name'],
			// `free` / `guest` cards are not products (see PlanRepository::KINDS); the page uses
			// this to decide what the card says instead of a price and a buy button.
			'kind' => in_array($p['kind'] ?? 'paid', PlanRepository::KINDS, true) ? (string) $p['kind'] : 'paid',
			'groupId' => isset($p['group_id']) ? (int) $p['group_id'] : 0,
			'auto' => !empty($p['auto_content']),
			// The limits the server will actually enforce for this tier, read from the group
			// rather than retyped into the feature list — a card that lies about its quota is
			// worse than one that says nothing.
			'limits' => !empty($p['show_limits'])
				? self::groupLimits(
					(int) ($p['group_id'] ?? 0),
					array_key_exists('limit_fields', $p) ? (string) $p['limit_fields'] : null
				)
				: [],
			'price' => (string) $p['price'],
			'period' => (string) $p['period'],
			'badge' => (string) $p['badge'],
			// `html` is passed through as authored — the same trust every other admin-written
			// setting gets. `markdown` is escaped and rendered (see Markdown).
			'descriptionHtml' => $format === 'html' ? $desc : Markdown::render($desc),
			'features' => array_values(array_filter(array_map('trim', explode("\n", (string) ($p['features'] ?? ''))))),
			'checkoutType' => (string) $p['checkout_type'],
			'checkoutUrl' => (string) $p['checkout_url'],
			'checkoutHtml' => (string) ($p['checkout_html'] ?? ''),
			'buttonLabel' => (string) $p['button_label'],
			'highlighted' => !empty($p['highlighted']),
		];
	}

	/**
	 * A group's limits as a list of label/value pairs the pricing card can print.
	 *
	 * Everything here is read live from the group, so the numbers on the page are the numbers
	 * the uploader will hit. A zero means "not capped by this group": for storage and file size
	 * that is genuinely unlimited, for retention it means the installation default applies —
	 * which is a different sentence, hence GroupRepository::retentionDays rather than the raw
	 * column.
	 *
	 * @return list<array{label: string, value: string, icon: string}>
	 */
	public static function groupLimits(int $groupId, ?string $selected = null): array
	{
		$group = $groupId ? GroupRepository::getById($groupId) : null;
		if (!$group) {
			return [];
		}

		$mb = static function (int $value): string {
			if ($value <= 0) {
				return __('premium.limit_unlimited');
			}
			$bytes = $value * 1048576;
			foreach ([1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB'] as $unit => $label) {
				if ($bytes >= $unit) {
					$scaled = $bytes / $unit;
					$hasFraction = $bytes % $unit !== 0;
					$formatted = number_format($scaled, $hasFraction ? 1 : 0, '.', '');
					return ($hasFraction ? rtrim(rtrim($formatted, '0'), '.') : $formatted) . ' ' . $label;
				}
			}
			return $bytes . ' B';
		};
		$count = static fn(int $v): string => $v <= 0 ? __('premium.limit_unlimited') : (string) $v;

		$bytes = static function (int $value): string {
			if ($value <= 0) {
				return __('premium.limit_unlimited');
			}
			foreach ([1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB'] as $unit => $label) {
				if ($value >= $unit) {
					return round($value / $unit, $value % $unit ? 1 : 0) . ' ' . $label;
				}
			}
			return $value . ' B';
		};
		$items = [
			'quota' => ['icon' => 'fa-database', 'label' => __('premium.limit_quota'), 'value' => $mb((int) $group['storage_quota_mb'])],
			'file' => ['icon' => 'fa-file-arrow-up', 'label' => __('premium.limit_file'), 'value' => $mb((int) $group['max_file_size_mb'])],
			'files' => ['icon' => 'fa-layer-group', 'label' => __('premium.limit_files'), 'value' => $count((int) $group['max_files_per_session'])],
			'concurrent' => ['icon' => 'fa-download', 'label' => __('premium.limit_concurrent'), 'value' => $count((int) $group['concurrent_downloads'])],
		];

		// Retention only earns a line when something actually expires — "files kept forever" is
		// worth saying, "kept for 0 days" is not.
		$days = GroupRepository::retentionDays($group);
		$items['retention'] = [
			'icon' => 'fa-clock',
			'label' => __('premium.limit_retention'),
			'value' => $days > 0 ? __('premium.limit_days', ['n' => $days]) : __('premium.limit_forever'),
		];
		$period = (string) ($group['transfer_quota_period'] ?? 'week');
		$items['transfer'] = [
			'icon' => 'fa-gauge-high',
			'label' => __('premium.limit_transfer'),
			'value' => $bytes((int) ($group['transfer_quota_bytes'] ?? 0))
				. ((int) ($group['transfer_quota_bytes'] ?? 0) > 0
					? ' / ' . __('premium.transfer_period_' . $period)
					: ''),
		];
		$fields = $selected === null
			? array_keys($items)
			: array_filter(array_map('trim', explode(',', $selected)));
		return array_values(array_intersect_key($items, array_flip($fields)));
	}

	/** The enabled plans, for the public page and the home-page teaser. */
	public static function handlePremiumPlans()
	{
		header('Content-Type: application/json');
		if (!self::isEnabled()) {
			echo json_encode(['success' => true, 'enabled' => false, 'plans' => []]);
			return;
		}
		$settings = self::settings();
		echo json_encode([
			'success' => true,
			'enabled' => true,
			'title' => $settings['premium_title'],
			'introHtml' => Markdown::render($settings['premium_intro']),
			'plans' => array_map([self::class, 'publicView'], PlanRepository::enabled()),
		]);
	}

	/* ---------------- Admin ---------------- */

	public static function handleAdminPlans()
	{
		if (!self::requireAdmin()) {
			return;
		}
		echo json_encode([
			'success' => true,
			'plans' => PlanRepository::all(),
			'groups' => array_map(
				fn($g) => ['id' => (int) $g['id'], 'name' => $g['name']],
				array_values(array_filter(
					Database::getGroups(),
					fn($g) => ($g['slug'] ?? null) !== 'moderator'
				))
			),
			'settings' => self::settings(),
			// Shown once so the operator can paste it into their provider's webhook config.
			// It is a bearer secret for granting paid access, so it never leaves this endpoint.
			'activateUrl' => APP_URL . '/api.php?action=premium_activate',
			'hasToken' => trim((string) Database::getSecretSetting('premium_api_token', '')) !== '',
		]);
	}

	public static function handleAdminPlanSave()
	{
		if (!self::requireAdmin()) {
			return;
		}
		try {
			$input = readBoundedJsonBody(65536);
		} catch (Throwable $e) {
			http_response_code($e instanceof LengthException ? 413 : 400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$id = (int) ($input['id'] ?? 0);
		$res = PlanRepository::save($id ?: null, $input);
		if (!empty($res['success'])) {
			Database::logAudit($id ? 'plan_updated' : 'plan_created', (string) ($input['name'] ?? ''));
		}
		echo json_encode($res);
	}

	public static function handleAdminPlanDelete()
	{
		if (!self::requireAdmin()) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		try {
			$input = readBoundedJsonBody(65536);
		} catch (Throwable $e) {
			http_response_code($e instanceof LengthException ? 413 : 400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$id = (int) ($input['id'] ?? 0);
		$plan = $id ? PlanRepository::get($id) : null;
		if ($plan && !empty($plan['is_system'])) {
			echo json_encode(['success' => false, 'error' => __('api.plan_is_system')]);
			return;
		}
		if (!$id || !PlanRepository::delete($id)) {
			echo json_encode(['success' => false, 'error' => __('api.plan_not_found')]);
			return;
		}
		Database::logAudit('plan_deleted', '#' . $id);
		echo json_encode(['success' => true]);
	}

	/** Save the premium settings block (enabled, copy, where it is advertised). */
	public static function handleAdminPremiumSettings()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		self::saveInvoiceSettings($input);
		foreach (self::SETTINGS as $key => $default) {
			if (array_key_exists($key, self::INVOICE_SETTINGS)) {
				continue;
			}
			if (!array_key_exists($key, $input)) {
				continue;
			}
			$value = $input[$key];
			Database::setSetting($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
		}
		Database::logAudit('premium_settings_saved', '');
		echo json_encode(['success' => true]);
	}

	/**
	 * Issue (or re-issue) the shared secret the activation endpoint accepts.
	 *
	 * Returned exactly once, here: it is stored encrypted and never read back to the panel, so
	 * regenerating is the only way to recover from a lost one — and that invalidates the old
	 * secret, which is the correct outcome if it went missing.
	 */
	public static function handleAdminPremiumToken()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$token = bin2hex(random_bytes(24));
		Database::setSecretSetting('premium_api_token', $token);
		Database::logAudit('premium_token_regenerated', '');
		echo json_encode(['success' => true, 'token' => $token]);
	}

	/**
	 * The payment-plugin catalogue with whatever is already configured (pkt 5).
	 *
	 * Presets, not integrations — see PaymentPlugins. Secrets never come back out; the panel is
	 * told only whether one is set.
	 */
	public static function handleAdminPaymentPlugins()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$out = [];
		foreach (PaymentPlugins::all() as $id => $def) {
			$out[] = [
				'id' => $id,
				'name' => $def['name'],
				'icon' => $def['icon'],
				'iconStyle' => $def['icon_style'] ?? 'fa-solid',
				'methods' => $def['methods'],
				'type' => $def['type'],
				'serverSide' => $def['server_side'],
				'builtIn' => !empty($def['built_in']),
				'active' => !empty($def['built_in']) && PaymentPlugins::builtinProvider() === $id,
				'docs' => $def['docs'],
				'fields' => $def['fields'],
				'values' => PaymentPlugins::values($id),
				'notes' => __($def['notes']),
			];
		}
		echo json_encode(['success' => true, 'plugins' => $out]);
	}

	/** Save a plugin's credentials, and hand back the checkout it would put on a plan. */
	public static function handleAdminPaymentPluginSave()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (string) ($input['id'] ?? '');
		if (!PaymentPlugins::save($id, is_array($input['values'] ?? null) ? $input['values'] : [])) {
			echo json_encode(['success' => false, 'error' => __('api.plugin_unknown')]);
			return;
		}
		Database::logAudit('payment_plugin_saved', $id);
		echo json_encode(['success' => true, 'checkout' => PaymentPlugins::checkoutFor($id)]);
	}

	/** Verify saved native-provider credentials without returning any secret or provider body. */
	public static function handleAdminPaymentPluginTest()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = strtolower(trim((string) ($input['id'] ?? '')));
		if ($id !== 'przelewy24') {
			echo json_encode(['success' => false, 'error' => __('panel.plug.test_unsupported')]);
			return;
		}
		if (!P24::isConfigured()) {
			echo json_encode(['success' => false, 'error' => __('panel.plug.test_unconfigured')]);
			return;
		}
		$success = P24::testAccess();
		Database::logAudit('payment_plugin_tested', $id . ': ' . ($success ? 'success' : 'failed'));
		echo json_encode([
			'success' => $success,
			'message' => $success ? __('panel.plug.test_ok') : __('panel.plug.test_failed'),
			'error' => $success ? null : __('panel.plug.test_failed'),
		]);
	}

	/**
	 * Grant or revoke a plan by hand, from the admin panel.
	 *
	 * pt 2/3/5: both actions are now recorded in the plan ledger and both can notify the
	 * account by e-mail. Giving someone a plan and quietly taking it away again were leaving no
	 * trace anywhere the person concerned could see — which for something that changes what
	 * their account may do is not good enough.
	 */
	public static function handleAdminPlanGrant()
	{
		if (!self::requirePermission('premium.grants')) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$userId = (int) ($input['user_id'] ?? 0);
		$user = $userId ? Database::getUserById($userId) : null;
		if (!$user) {
			echo json_encode(['success' => false, 'error' => __('api.user_not_found')]);
			return;
		}
		$actorId = (int) $_SESSION['user_id'];
		$notify = !empty($input['notify']);

		if (!empty($input['revoke'])) {
			// Which plan is being taken away, so the ledger entry names it.
			$group = Database::getUserGroup($userId);
			$planId = 0;
			foreach (PlanRepository::all() as $p) {
				if ($group && (int) $p['group_id'] === (int) $group['id']) {
					$planId = (int) $p['id'];
					break;
				}
			}
			$ok = PlanRepository::revoke($userId);
			if ($ok) {
				PaymentRepository::recordAdminAction(PaymentRepository::KIND_ADMIN_REVOKE, $planId, $userId, $actorId);
				if ($notify) {
					self::notifyPlanChange($user, null, 0, true);
				}
			}
			echo json_encode(['success' => $ok]);
			return;
		}

		$plan = PlanRepository::get((int) ($input['plan_id'] ?? 0));
		if (!$plan) {
			echo json_encode(['success' => false, 'error' => __('api.plan_not_found')]);
			return;
		}
		// The guest card describes people who have no account; handing it to one would put a
		// registered user in the group meant for anonymous visitors. Dropping someone to the
		// free tier is what "revoke" already does.
		if (($plan['kind'] ?? 'paid') === 'guest') {
			echo json_encode(['success' => false, 'error' => __('api.plan_not_grantable')]);
			return;
		}

		// An empty duration field means "as the plan says"; an explicit 0 means "no expiry".
		$override = null;
		if (array_key_exists('duration_days', $input) && $input['duration_days'] !== '' && $input['duration_days'] !== null) {
			$override = (int) $input['duration_days'];
		}

		$result = PlanRepository::grant($userId, $plan, $override);
		if (!empty($result['success'])) {
			PaymentRepository::recordAdminAction(
				PaymentRepository::KIND_ADMIN_GRANT,
				(int) $plan['id'],
				$userId,
				$actorId,
				(string) ($plan['currency'] ?? 'PLN')
			);
			if ($notify) {
				self::notifyPlanChange($user, $plan, (int) ($result['expires_at'] ?? 0), false);
			}
		}
		echo json_encode($result);
	}

	/**
	 * Tell the account what just happened to its plan (pt 3).
	 *
	 * Best-effort by design: the grant has already been made and must not be undone because a
	 * mail server was unreachable. The outcome goes to the audit log so a silent failure is
	 * still a recorded one.
	 */
	private static function notifyPlanChange(array $user, ?array $plan, int $expiresAt, bool $revoked): void
	{
		$planName = $plan ? (string) $plan['name'] : '';

		// The bell first, and it carries the e-mail with it: `plan.granted` / `plan.revoked` are
		// mailable types, so the account's own notification preferences decide whether a message
		// arrives and by which route. Before this, "notify" meant "e-mail, if SMTP works".
		Notifications::send((int) $user['id'], $revoked ? 'plan.revoked' : 'plan.granted', [
			'subject' => $planName,
			'data' => [
				'until' => $expiresAt > 0 ? date('d.m.Y', $expiresAt) : __('mail.plan_no_expiry'),
			],
			'link' => APP_URL . '/panel.php?tab=premium',
		]);

		$email = trim((string) ($user['email'] ?? ''));
		Database::logAudit(
			'plan_notified',
			($revoked ? 'revoked' : 'granted') . ' → ' . ($email !== '' ? $email : '#' . $user['id']),
			(int) $user['id']
		);
	}

	/* ---------------- Premium tab (pt 6) ---------------- */

	/** Sales figures + the daily series behind the chart. Admin only. */
	public static function handlePremiumOverview()
	{
		if (!self::requirePermission('premium.metrics')) {
			return;
		}
		$days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
		echo json_encode([
			'success' => true,
			'days' => $days,
			'stats' => PaymentRepository::stats($days),
			'series' => PaymentRepository::series($days),
			// So the overview can say "3 plans, 1 disabled" without a second round trip.
			'plans' => array_map(fn($p) => [
				'id' => (int) $p['id'],
				'name' => (string) $p['name'],
				'enabled' => !empty($p['enabled']),
				'amountMinor' => (int) ($p['amount_minor'] ?? 0),
				'currency' => (string) ($p['currency'] ?? 'PLN'),
				'durationDays' => (int) $p['duration_days'],
			], PlanRepository::all()),
		]);
	}

	/** The purchases list. Admin only. */
	public static function handlePremiumPayments()
	{
		if (!self::requirePermission('premium.payments')) {
			return;
		}
		$res = PaymentRepository::browse([
			'page' => (int) ($_GET['page'] ?? 1),
			'per_page' => (int) ($_GET['per_page'] ?? 20),
			'status' => (string) ($_GET['status'] ?? ''),
			'search' => (string) ($_GET['search'] ?? ''),
		]);
		echo json_encode([
			'success' => true,
			// Whether the rows may carry a "receipt" link (runda 10).
			'invoicesEnabled' => Database::getSetting('invoice_enabled', '0') === '1',
		] + $res);
	}

	/**
	 * Refund a completed PayU plan purchase from the payments list (runda 9). The money goes
	 * back through the provider, and when the buyer still holds THIS purchase's plan it is
	 * revoked in the same motion — a refunded purchase must not keep its goods. The refund is
	 * attempted first: a provider failure leaves everything as it was.
	 */
	public static function handleAdminPremiumRefund()
	{
		if (!self::requirePermission('premium.refunds')) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$ext = trim((string) ($input['ext_order_id'] ?? ''));
		$payment = $ext !== '' ? PaymentRepository::byExtOrderId($ext) : null;
		if (!$payment || $payment['status'] !== PaymentRepository::COMPLETED
			|| ($payment['kind'] ?? '') !== PaymentRepository::KIND_PURCHASE
			|| !in_array((string) $payment['provider'], ['payu', 'przelewy24'], true)
			|| empty($payment['provider_order_id'])) {
			echo json_encode(['success' => false, 'error' => __('panel.prem.refund_not_refundable')]);
			return;
		}
		$isP24 = (string) $payment['provider'] === 'przelewy24';
		$refundToken = PaymentRepository::claimRefund($ext, $isP24 ? 604800 : 900);
		if ($refundToken === null) {
			http_response_code(409);
			echo json_encode(['success' => false, 'error' => __('panel.prem.refund_not_refundable')]);
			return;
		}
		$plan = PlanRepository::get((int) $payment['plan_id']);
		$snapshot = json_decode((string) ($payment['product_snapshot'] ?? ''), true);
		$product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
		$planName = (string) ($product['name'] ?? $plan['name'] ?? ('#' . $payment['plan_id']));
		$expectedGroupId = (int) ($product['group_id'] ?? $plan['group_id'] ?? 0);
		$description = mb_substr(__('panel.prem.refund_desc', ['name' => $planName]), 0, 255);
		if ($isP24) {
			$requestId = bin2hex(random_bytes(16));
			$refundsUuid = bin2hex(random_bytes(16));
			if (!PaymentRepository::setRefundContext($ext, $refundToken, [
				'requestId' => $requestId,
				'refundsUuid' => $refundsUuid,
				'actorId' => (int) $_SESSION['user_id'],
			])) {
				PaymentRepository::releaseRefund($ext, $refundToken);
				http_response_code(503);
				echo json_encode(['success' => false, 'error' => __('api.db_error')]);
				return;
			}
			$res = P24::refund(
				(int) $payment['provider_order_id'],
				$ext,
				(int) $payment['amount_minor'],
				$description,
				APP_URL . '/api.php?action=p24_refund_notify',
				$requestId,
				$refundsUuid
			);
		} else {
			$res = PayU::refund((string) $payment['provider_order_id'], $description);
		}
		if (empty($res['success'])) {
			Database::logAudit('plan_refund_failed', $ext . ' — ' . ($res['error'] ?? '?'));
			PaymentRepository::releaseRefund($ext, $refundToken);
			echo json_encode(['success' => false, 'error' => __('panel.ads.refund_failed', ['err' => $res['error'] ?? '?'])]);
			return;
		}
		if ($isP24) {
			PaymentRepository::recordProviderEvent(
				'p24-refund-request',
				hash('sha256', (string) ($res['refundsUuid'] ?? '')),
				$ext,
				'accepted'
			);
			Database::logAudit('plan_refund_pending', $ext);
			echo json_encode(['success' => true, 'pending' => true, 'revoked' => false]);
			return;
		}
		$finalized = PaymentRepository::finalizeRefund(
			$ext,
			$refundToken,
			$expectedGroupId
		);
		if (empty($finalized['success'])) {
			http_response_code(503);
			Database::logAudit('plan_refund_finalize_failed', $ext);
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}
		$refundId = trim((string) ($res['refundId'] ?? ''));
		if ($refundId !== '') {
			PaymentRepository::recordProviderEvent(
				'payu-refund',
				hash('sha256', $refundId),
				$ext,
				(string) ($res['status'] ?? 'accepted')
			);
		}
		$userId = (int) $finalized['user_id'];
		$revoked = !empty($finalized['revoked']);
		if ($revoked) {
			PaymentRepository::recordAdminAction(
				PaymentRepository::KIND_ADMIN_REVOKE,
				(int) $payment['plan_id'],
				$userId,
				(int) $_SESSION['user_id']
			);
			Notifications::send($userId, 'plan.revoked', [
				'subject' => $planName,
				'data' => ['until' => __('mail.plan_no_expiry')],
				'link' => APP_URL . '/panel.php?tab=premium',
			]);
		}
		Database::logAudit(
			'plan_refunded',
			$ext . ' ' . $payment['amount_minor'] . ' ' . $payment['currency'] . ($revoked ? ' + revoke' : ''),
			$userId ?: null
		);
		Notifications::send($userId, 'payment.refunded', [
			'subject' => $planName,
			'data' => ['amount' => number_format(((int) $payment['amount_minor']) / 100, 2, ',', ' ') . ' ' . $payment['currency']],
			'link' => APP_URL . '/panel.php?tab=premium',
		]);
		echo json_encode(['success' => true, 'revoked' => $revoked]);
	}

	/**
	 * A printable receipt for one completed payment (runda 10). Browser navigation, not
	 * JSON: it renders a self-contained HTML document styled for print (Ctrl+P → PDF).
	 *
	 * Off by default (`invoice_enabled`); the seller block and footer are the operator's
	 * freeform text, so the document adapts to whatever legal form the operator needs —
	 * the app numbers it ({prefix}/{year}/{payment id}: unique and monotonic) and prints
	 * the ledger's own facts. Owner or admin only; a refunded payment prints with a
	 * clearly-marked refund note rather than pretending it never happened.
	 */
	public static function handleInvoice()
	{
		if (Database::getSetting('invoice_enabled', '0') !== '1') {
			http_response_code(404);
			exit;
		}
		if (empty($_SESSION['user_id'])) {
			http_response_code(403);
			exit(__('api.not_logged_in'));
		}
		$payment = PaymentRepository::byExtOrderId((string) ($_GET['order'] ?? ''));
		$isAdmin = !empty($_SESSION['is_admin']) || Permissions::has('premium.payments');
		if (!$payment
			|| (!$isAdmin && (int) $payment['user_id'] !== (int) $_SESSION['user_id'])
			|| !in_array($payment['status'], [PaymentRepository::COMPLETED, PaymentRepository::REFUNDED], true)) {
			http_response_code(404);
			exit(__('api.payment_not_found'));
		}

		$snapshot = json_decode((string) ($payment['product_snapshot'] ?? ''), true);
		$snapshot = is_array($snapshot) ? $snapshot : [];
		$productSnapshot = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
		$invoiceSnapshot = is_array($snapshot['invoice'] ?? null) ? $snapshot['invoice'] : [];

		// What was bought: prefer the immutable checkout snapshot. The catalog lookup remains
		// only for receipts created before snapshots were introduced.
		$item = '';
		if (!empty($productSnapshot['name'])) {
			$item = (string) $productSnapshot['name'];
		} elseif (!empty($payment['plan_id'])) {
			$plan = PlanRepository::get((int) $payment['plan_id']);
			$item = (string) ($plan['name'] ?? '');
		} elseif (!empty($payment['package_id'])) {
			$package = AdRepository::packageGet((int) $payment['package_id']);
			$item = (string) ($package['name'] ?? '');
		}
		if ($item === '') {
			$item = (string) $payment['ext_order_id'];
		}

		$buyer = is_array($invoiceSnapshot['buyer'] ?? null)
			? $invoiceSnapshot['buyer']
			: (Database::getUserById((int) $payment['user_id']) ?: []);
		$paidAt = (int) ($payment['granted_at'] ?: $payment['updated_at'] ?: $payment['created_at']);
		$number = array_key_exists('prefix', $invoiceSnapshot)
			? trim((string) $invoiceSnapshot['prefix'])
			: trim((string) Database::getSetting('invoice_prefix', 'FH'));
		$number = $number ?: 'FH';
		$number .= '/' . date('Y', $paidAt) . '/' . (int) $payment['id'];
		$seller = array_key_exists('seller', $invoiceSnapshot)
			? (string) $invoiceSnapshot['seller']
			: (string) Database::getSetting('invoice_seller', '');
		$footer = array_key_exists('footer', $invoiceSnapshot)
			? (string) $invoiceSnapshot['footer']
			: (string) Database::getSetting('invoice_footer', '');
		$amount = number_format(((int) $payment['amount_minor']) / 100, 2, ',', ' ') . ' ' . $payment['currency'];
		$refunded = $payment['status'] === PaymentRepository::REFUNDED;

		$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Robots-Tag: noindex');
		header("Content-Security-Policy: default-src 'none'; script-src 'self'; script-src-attr 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
		echo '<!DOCTYPE html><html lang="' . Lang::current() . '"><head><meta charset="utf-8">'
			. '<title>' . $h(__('invoice.title')) . ' ' . $h($number) . '</title><style>'
			. 'body{font:14px/1.5 system-ui,sans-serif;color:#111;max-width:720px;margin:40px auto;padding:0 20px;}'
			. 'h1{font-size:1.3rem;margin:0 0 2px;} .muted{color:#666;} .grid{display:flex;gap:40px;margin:26px 0;}'
			. '.grid>div{flex:1;} .grid h2{font-size:0.8rem;text-transform:uppercase;letter-spacing:0.06em;color:#666;margin:0 0 6px;}'
			. 'table{width:100%;border-collapse:collapse;margin:10px 0 4px;} th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #ddd;}'
			. 'th{font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:#666;} td.num,th.num{text-align:right;}'
			. '.total{text-align:right;font-size:1.1rem;font-weight:700;margin:12px 0 4px;}'
			. '.refund{border:2px solid #c00;color:#c00;display:inline-block;padding:4px 12px;border-radius:6px;font-weight:700;margin:12px 0;}'
			. '.foot{margin-top:30px;color:#444;white-space:pre-line;font-size:0.85rem;}'
			. '.actions{margin:26px 0;} .actions button{padding:10px 22px;font:inherit;cursor:pointer;}'
			. '@media print{.actions{display:none;} body{margin:0;}}'
			. '</style></head><body>'
			. '<h1>' . $h(__('invoice.title')) . ' ' . $h($number) . '</h1>'
			. '<div class="muted">' . $h(__('invoice.issued', ['date' => date('d.m.Y', $paidAt)])) . '</div>'
			. ($refunded ? '<div class="refund">' . $h(__('invoice.refunded')) . '</div>' : '')
			. '<div class="grid"><div><h2>' . $h(__('invoice.seller')) . '</h2>'
			. nl2br($h($seller)) . '</div>'
			. '<div><h2>' . $h(__('invoice.buyer')) . '</h2>'
			. $h((string) ($buyer['username'] ?? ('#' . $payment['user_id'])))
			. '<br><span class="muted">' . $h((string) ($buyer['email'] ?? '')) . '</span></div></div>'
			. '<table><thead><tr><th>' . $h(__('invoice.item')) . '</th><th>' . $h(__('invoice.order_id')) . '</th>'
			. '<th class="num">' . $h(__('invoice.amount')) . '</th></tr></thead><tbody>'
			. '<tr><td>' . $h($item) . '</td><td><code>' . $h((string) $payment['ext_order_id']) . '</code></td>'
			. '<td class="num">' . $h($amount) . '</td></tr></tbody></table>'
			. '<div class="total">' . $h(__('invoice.total', ['amount' => $amount])) . '</div>'
			. '<div class="muted">' . $h(__('invoice.paid_via', ['provider' => strtoupper((string) $payment['provider'])])) . '</div>'
			. '<div class="foot">' . nl2br($h($footer)) . '</div>'
			. '<div class="actions"><button type="button" id="invoicePrint">' . $h(__('invoice.print')) . '</button></div>'
			. '<script src="' . $h(APP_URL) . '/assets/js/invoice.js?v=' . $h(APP_VERSION) . '" defer></script>'
			. '</body></html>';
		exit;
	}

	/* ---------------- Promo codes (runda 9) ---------------- */

	/**
	 * Is this code good, and for how much? Signed-in only — the checkout itself requires an
	 * account, and anonymous probing of the code space would be free enumeration.
	 */
	public static function handlePromoCheck()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$row = PromoCodeRepository::validate((string) ($_GET['code'] ?? ''));
		if (!$row) {
			echo json_encode(['success' => true, 'valid' => false]);
			return;
		}
		$planId = ($row['scope'] ?? 'all') === 'plan' ? (int) ($row['plan_id'] ?? 0) : 0;
		$plan = $planId > 0 ? PlanRepository::get($planId) : null;
		if ($planId > 0 && (!$plan || empty($plan['enabled']) || ($plan['kind'] ?? 'paid') !== 'paid')) {
			// A scoped code must not look usable when its target was deleted, disabled or
			// converted into a non-purchasable showcase plan.
			echo json_encode(['success' => true, 'valid' => false]);
			return;
		}
		echo json_encode([
			'success' => true,
			'valid' => true,
			'code' => (string) $row['code'],
			'percentOff' => (int) $row['percent_off'],
			'scope' => (string) ($row['scope'] ?? 'all'),
			'planId' => $planId,
			'planName' => (string) ($plan['name'] ?? ''),
		]);
	}

	public static function handleAdminPromoCodes()
	{
		if (!self::requireAdmin()) {
			return;
		}
		echo json_encode(['success' => true, 'codes' => array_map(fn($c) => [
			'id' => (int) $c['id'],
			'code' => (string) $c['code'],
			'scope' => (string) ($c['scope'] ?? 'all'),
			'planId' => isset($c['plan_id']) ? (int) $c['plan_id'] : 0,
			'planName' => (string) ($c['plan_name'] ?? ''),
			'percentOff' => (int) $c['percent_off'],
			'maxUses' => (int) $c['max_uses'],
			'usedCount' => (int) $c['used_count'],
			'expiresAt' => $c['expires_at'] !== null ? (int) $c['expires_at'] : null,
			'enabled' => !empty($c['enabled']),
			'createdAt' => (int) $c['created_at'],
		], PromoCodeRepository::all())]);
	}

	public static function handleAdminPromoCodeSave()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		$expires = null;
		if (is_string($input['expires_at'] ?? null) && trim($input['expires_at']) !== '') {
			$ts = strtotime(trim($input['expires_at']) . ' 23:59:59');
			$expires = $ts !== false ? $ts : null;
		}
		$res = PromoCodeRepository::save($id ?: null, [
			'code' => (string) ($input['code'] ?? ''),
			'scope' => (string) ($input['scope'] ?? 'all'),
			'plan_id' => (int) ($input['plan_id'] ?? 0),
			'percent_off' => (int) ($input['percent_off'] ?? 10),
			'max_uses' => (int) ($input['max_uses'] ?? 0),
			'expires_at' => $expires,
			'enabled' => !empty($input['enabled']),
		]);
		if (empty($res['success'])) {
			echo json_encode(['success' => false, 'error' => __('panel.promo.err_' . ($res['error'] ?? 'db'))]);
			return;
		}
		Database::logAudit($id ? 'promo_code_updated' : 'promo_code_created', (string) ($input['code'] ?? ''));
		echo json_encode($res);
	}

	public static function handleAdminPromoCodeDelete()
	{
		if (!self::requireAdmin()) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id || !PromoCodeRepository::delete($id)) {
			echo json_encode(['success' => false, 'error' => __('common.error')]);
			return;
		}
		Database::logAudit('promo_code_deleted', '#' . $id);
		echo json_encode(['success' => true]);
	}

	/** Who currently holds a plan. Admin only. */
	public static function handlePremiumSubscribers()
	{
		if (!self::requirePermission('premium.subscribers')) {
			return;
		}
		$response = ['success' => true, 'subscribers' => PaymentRepository::subscribers()];
		if (Permissions::has('premium.grants')) {
			$response['plans'] = array_values(array_map(static fn(array $plan): array => [
				'id' => (int) $plan['id'],
				'name' => (string) $plan['name'],
				'duration_days' => (int) ($plan['duration_days'] ?? 0),
			], array_filter(PlanRepository::all(), static fn(array $plan): bool => ($plan['kind'] ?? 'paid') !== 'guest')));
		}
		if (Permissions::has('premium.bulk_grants')) {
			$response['groups'] = array_values(array_map(static fn(array $group): array => [
				'id' => (int) $group['id'],
				'name' => (string) $group['name'],
			], array_filter(GroupRepository::all(), static fn(array $group): bool => ($group['slug'] ?? '') !== 'guest')));
		}
		echo json_encode($response);
	}

	public static function handleBulkPlanPreview(): void
	{
		if (!self::requirePermission('premium.bulk_grants')) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$plan = PlanRepository::get((int) ($input['plan_id'] ?? 0));
		$days = max(1, min(3650, (int) ($input['duration_days'] ?? 0)));
		if (!$plan || ($plan['kind'] ?? 'paid') === 'guest' || $days < 1) {
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$source = in_array($input['source'] ?? '', ['active_subscribers', 'group', 'buyers'], true)
			? (string) $input['source'] : 'active_subscribers';
		$criteria = ['source' => $source];
		if ($source === 'group') {
			$criteria['group_id'] = max(1, (int) ($input['group_id'] ?? 0));
		}
		if ($source === 'buyers') {
			$from = self::bulkDate((string) ($input['from'] ?? ''), false);
			$to = self::bulkDate((string) ($input['to'] ?? ''), true);
			if ($from === null || $to === null || $from >= $to) {
				echo json_encode(['success' => false, 'error' => __('panel.prem.bulk_dates_invalid')]);
				return;
			}
			$criteria += ['from' => $from, 'to' => $to, 'purchased_plan_id' => max(0, (int) ($input['purchased_plan_id'] ?? 0))];
		}
		$ids = PaymentRepository::bulkGrantCandidates($criteria, 2001);
		if (count($ids) > 2000) {
			echo json_encode(['success' => false, 'error' => __('panel.prem.bulk_too_many')]);
			return;
		}
		$nonce = bin2hex(random_bytes(24));
		$_SESSION['premium_bulk_grants'] = $_SESSION['premium_bulk_grants'] ?? [];
		$_SESSION['premium_bulk_grants'][$nonce] = [
			'expires' => time() + 600,
			'ids' => $ids,
			'plan_id' => (int) $plan['id'],
			'days' => $days,
			'notify' => !empty($input['notify']),
		];
		echo json_encode(['success' => true, 'nonce' => $nonce, 'count' => count($ids), 'plan' => $plan['name'], 'days' => $days]);
	}

	public static function handleBulkPlanExecute(): void
	{
		if (!self::requirePermission('premium.bulk_grants') || !requireRecentAuthentication()) {
			return;
		}
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$nonce = (string) ($input['nonce'] ?? '');
		$batch = $_SESSION['premium_bulk_grants'][$nonce] ?? null;
		unset($_SESSION['premium_bulk_grants'][$nonce]);
		if (!$batch || (int) ($batch['expires'] ?? 0) < time()) {
			echo json_encode(['success' => false, 'error' => __('panel.prem.bulk_preview_expired')]);
			return;
		}
		$plan = PlanRepository::get((int) $batch['plan_id']);
		if (!$plan) {
			echo json_encode(['success' => false, 'error' => __('api.plan_not_found')]);
			return;
		}
		$done = 0;
		$failed = 0;
		$actorId = (int) $_SESSION['user_id'];
		foreach ($batch['ids'] as $userId) {
			$user = Database::getUserById((int) $userId);
			if (!$user) {
				$failed++;
				continue;
			}
			$result = PlanRepository::grant((int) $userId, $plan, (int) $batch['days']);
			if (empty($result['success'])) {
				$failed++;
				continue;
			}
			$done++;
			PaymentRepository::recordAdminAction(PaymentRepository::KIND_ADMIN_GRANT, (int) $plan['id'], (int) $userId, $actorId, (string) ($plan['currency'] ?? 'PLN'));
			if (!empty($batch['notify'])) {
				self::notifyPlanChange($user, $plan, (int) ($result['expires_at'] ?? 0), false);
			}
		}
		Database::logAudit('premium_bulk_grant', "plan #{$plan['id']}, {$done} granted, {$failed} failed");
		echo json_encode(['success' => true, 'granted' => $done, 'failed' => $failed]);
	}

	private static function bulkDate(string $value, bool $endOfDay): ?int
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		$errors = DateTimeImmutable::getLastErrors();
		if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
			return null;
		}
		return $endOfDay ? $date->modify('+1 day')->getTimestamp() : $date->getTimestamp();
	}

	/**
	 * The signed-in account's own premium state (pt 6).
	 *
	 * Everything here is about the caller and nobody else: which plan they hold, when it runs
	 * out, what that group actually gives them, and what they have paid. No admin fields, and
	 * no way to ask about another user — the session is the subject.
	 */
	public static function handleMyPremium()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		$user = Database::getUserById($userId);
		$group = Database::getUserGroup($userId);
		$effectiveGroup = Database::getUserEffectiveGroup($userId);
		$limits = StorageEnforcer::limitsFor($userId);
		$status = StorageEnforcer::status($userId);
		$transfer = TransferQuotaRepository::forUser($userId, $effectiveGroup);

		// Which plan (if any) this group corresponds to, so the page can name what is held.
		$plan = null;
		foreach (PlanRepository::all() as $p) {
			if ($group && (int) $p['group_id'] === (int) $group['id']) {
				$plan = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'price' => (string) $p['price'], 'period' => (string) $p['period']];
				break;
			}
		}

		echo json_encode([
			'success' => true,
			'enabled' => self::isEnabled(),
			'invoicesEnabled' => Database::getSetting('invoice_enabled', '0') === '1',
			'plan' => $plan,
			'group' => $group ? [
				'name' => (string) $group['name'],
				'quotaMb' => (int) ($effectiveGroup['storage_quota_mb'] ?? 0),
				'maxFileMb' => (int) ($effectiveGroup['max_file_size_mb'] ?? 0),
				'filesPerSession' => (int) ($effectiveGroup['max_files_per_session'] ?? 0),
				'concurrent' => (int) ($effectiveGroup['concurrent_downloads'] ?? 0),
				'retentionDays' => GroupRepository::retentionDays($effectiveGroup),
				'transferLimit' => (int) ($effectiveGroup['transfer_quota_bytes'] ?? 0),
				'transferPeriod' => (string) ($effectiveGroup['transfer_quota_period'] ?? 'week'),
			] : null,
			'expiresAt' => isset($user['group_expires_at']) ? (int) $user['group_expires_at'] : 0,
			'usage' => [
				'used' => (int) $status['used'],
				'quota' => (int) $limits['quota'],
				'files' => (int) ($status['files'] ?? 0),
				'transfer' => $transfer,
			],
			'payments' => array_map(fn($p) => [
				'id' => (int) $p['id'],
				'planName' => (string) ($p['plan_name'] ?? ''),
				'amountMinor' => (int) $p['amount_minor'],
				'currency' => (string) $p['currency'],
				'status' => (string) $p['status'],
				// pt 2: what happened, and who did it when it was not the account itself.
				'kind' => (string) ($p['kind'] ?? 'purchase'),
				'actorName' => (string) ($p['actor_name'] ?? ''),
				'createdAt' => (int) $p['created_at'],
				// Runda 10: what the receipt link needs.
				'extOrderId' => (string) ($p['ext_order_id'] ?? ''),
			], PaymentRepository::forUser($userId)),
		]);
	}

	/* ---------------- Fulfilment ---------------- */

	/**
	 * Confirm a purchase and grant the plan's group. Called by the operator's payment provider
	 * (webhook / IPN) or by their own script — never from a browser session.
	 *
	 * Auth is a bearer secret in `Authorization: Bearer <token>` or an `X-Premium-Token`
	 * header, compared in constant time. The buyer is identified by `user_id`, `email` or
	 * `username`, whichever the provider can carry through its metadata.
	 *
	 * This endpoint grants paid access, so it is deliberately narrow: it does nothing but map
	 * (plan, user) to a group assignment, and it refuses rather than guessing if either side is
	 * ambiguous.
	 */
	public static function handlePremiumActivate()
	{
		header('Content-Type: application/json');

		$expected = trim((string) Database::getSecretSetting('premium_api_token', ''));
		if ($expected === '') {
			http_response_code(503);
			echo json_encode(['success' => false, 'error' => __('api.premium_no_token')]);
			return;
		}

		$supplied = '';
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (stripos($auth, 'Bearer ') === 0) {
			$supplied = trim(substr($auth, 7));
		} elseif (!empty($_SERVER['HTTP_X_PREMIUM_TOKEN'])) {
			$supplied = trim((string) $_SERVER['HTTP_X_PREMIUM_TOKEN']);
		}

		if ($supplied === '' || !hash_equals($expected, $supplied)) {
			http_response_code(401);
			Database::logAudit('premium_activate_denied', 'bad or missing token');
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		try {
			$input = readBoundedJsonBody(65536);
		} catch (Throwable $e) {
			http_response_code($e instanceof LengthException ? 413 : 400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$reference = trim((string) ($input['reference'] ?? ''));
		if (!preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $reference)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.premium_reference_required')]);
			return;
		}
		$plan = PlanRepository::get((int) ($input['plan_id'] ?? 0));
		if (!$plan) {
			echo json_encode(['success' => false, 'error' => __('api.plan_not_found')]);
			return;
		}

		$user = self::resolveUser($input);
		if (!$user) {
			echo json_encode(['success' => false, 'error' => __('api.user_not_found')]);
			return;
		}

		$result = self::activateIdempotently($user, $plan, $reference);
		if (!empty($result['success']) && empty($result['duplicate'])) {
			Database::logAudit(
				'premium_activated',
				'plan #' . $plan['id'] . ' → ' . $user['username'] . ' (ref: ' . substr((string) ($input['reference'] ?? '-'), 0, 64) . ')',
				(int) $user['id']
			);
		}
		echo json_encode($result + ['user_id' => (int) $user['id']]);
	}

	/** Atomically claim an external reference and grant exactly once. */
	private static function activateIdempotently(array $user, array $plan, string $reference): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$eventId = hash('sha256', 'premium-api:' . $reference);
		$eventStatus = 'p' . (int) $plan['id'] . ':u' . (int) $user['id'];
		try {
			$pdo->beginTransaction();
			if (!PaymentRepository::recordProviderEvent(
				'premium-api',
				$eventId,
				$reference,
				$eventStatus
			)) {
				$existing = $pdo->prepare(
					"SELECT `provider_status` FROM `" . Database::table('payment_events') . "`
					 WHERE `provider` = 'premium-api' AND `event_id` = ? FOR UPDATE"
				);
				$existing->execute([$eventId]);
				$previous = (string) ($existing->fetchColumn() ?: '');
				$pdo->rollBack();
				if ($previous !== $eventStatus) {
					http_response_code(409);
					return ['success' => false, 'error' => __('api.premium_reference_conflict')];
				}
				return ['success' => true, 'duplicate' => true];
			}

			$result = PlanRepository::grant(
				(int) $user['id'],
				$plan,
				null,
				'premium-api:' . $reference
			);
			if (empty($result['success'])) {
				throw new RuntimeException((string) ($result['error'] ?? 'grant failed'));
			}
			$pdo->commit();
			return $result;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/** The buyer, identified by whichever of id / email / username the caller could supply. */
	private static function resolveUser(array $input): ?array
	{
		if (!empty($input['user_id'])) {
			return Database::getUserById((int) $input['user_id']) ?: null;
		}
		foreach (['email', 'username'] as $key) {
			if (!empty($input[$key])) {
				return Database::getUserByEmailOrUsername(trim((string) $input[$key])) ?: null;
			}
		}
		return null;
	}

	private static function requireAdmin(): bool
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	private static function requirePermission(string $permission): bool
	{
		header('Content-Type: application/json');
		if (empty($_SESSION['user_id']) || !Permissions::has($permission)) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}
}
