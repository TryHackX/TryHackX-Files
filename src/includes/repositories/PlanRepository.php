<?php
/**
 * PlanRepository (Faza 7 · pt 9) — paid access plans.
 *
 * A plan is "buy this, get that group". Everything about *how* it is bought is left to the
 * operator, because payment is the one part of this that cannot be generalised: a plan carries
 * either a checkout URL to send the buyer to, or a block of HTML to render in place (a Stripe
 * Payment Link, a PayPal button, a Gumroad embed, a bank-transfer note — whatever the operator
 * already uses). The app never touches money.
 *
 * What the app does own is fulfilment: PlanRepository::grant() puts a user into the plan's
 * group for the plan's duration. It is reachable three ways — from the admin panel by hand,
 * from a payment provider's webhook via the token-authenticated activation endpoint, and from
 * any script an operator writes against that same endpoint.
 *
 * Expiry is a timestamp on the user (`group_expires_at`); GroupRepository::forUser drops a
 * lapsed assignment back to the default group, so nothing has to run on a schedule for a
 * subscription to end on time.
 */
final class PlanRepository
{
	/**
	 * What a plan is for.
	 *
	 * `paid` is a product. The other two are the page telling the truth about what someone
	 * already has: `free` is the tier every registered account sits on, `guest` is what an
	 * anonymous visitor gets. Neither is purchasable — that is enforced on save (checkout is
	 * forced to `none`) and again at checkout, not merely left off the card.
	 */
	public const KINDS = ['paid', 'free', 'guest'];

	/** Columns a plan row carries, with the defaults used when a field is absent. */
	private const FIELDS = [
		'name' => '',
		'kind' => 'paid',
		'show_limits' => 0,
		'limit_fields' => 'quota,file,files,concurrent,retention,transfer',
		'auto_content' => 0,
		'group_id' => null,
		'price' => '',
		'period' => '',
		// pt 10: `price` is display copy ("19 zł / mies."); these two are what a provider is
		// actually asked to charge. Minor units (grosze / cents) — never a float.
		'amount_minor' => 0,
		'currency' => 'PLN',
		'duration_days' => 0,
		'description' => '',
		'description_format' => 'markdown',
		'features' => '',
		'badge' => '',
		'checkout_type' => 'link',
		'checkout_url' => '',
		'checkout_html' => '',
		'button_label' => '',
		'highlighted' => 0,
		'enabled' => 0,
		'sort_order' => 0,
	];

	/** Every plan, in display order — the admin list. */
	public static function all(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('plans');
		$groups = Database::table('groups');
		try {
			$stmt = $pdo->query("SELECT p.*, g.`name` AS group_name
				FROM `{$table}` p
				LEFT JOIN `{$groups}` g ON g.`id` = p.`group_id`
				ORDER BY p.`sort_order` ASC, p.`id` ASC");
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** The plans a visitor may see. */
	public static function enabled(): array
	{
		return array_values(array_filter(self::all(), fn($p) => !empty($p['enabled'])));
	}

	public static function get(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('plans') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Create ($id = null) or update a plan. Returns ['success'=>bool, 'id'=>int]. */
	public static function save(?int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$name = trim((string) ($data['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 100) {
			return ['success' => false, 'error' => __('api.plan_bad_name')];
		}

		$row = [];
		foreach (self::FIELDS as $col => $default) {
			$row[$col] = array_key_exists($col, $data) ? $data[$col] : $default;
		}
		$row['name'] = $name;
		$row['kind'] = in_array($row['kind'], self::KINDS, true) ? $row['kind'] : 'paid';
		$row['show_limits'] = !empty($row['show_limits']) ? 1 : 0;
		$allowedLimitFields = ['quota', 'file', 'files', 'concurrent', 'retention', 'transfer'];
		$rawLimitFields = is_array($row['limit_fields'])
			? $row['limit_fields']
			: explode(',', (string) $row['limit_fields']);
		$row['limit_fields'] = implode(',', array_values(array_filter(
			$allowedLimitFields,
			static fn(string $field): bool => in_array($field, $rawLimitFields, true)
		)));
		// Only a showcase card can write itself; there is nothing to derive a price or a sales
		// pitch from, and a paid plan is exactly the case where the operator has something to say.
		$row['auto_content'] = ($row['kind'] !== 'paid' && !empty($row['auto_content'])) ? 1 : 0;
		if ($row['auto_content']) {
			$row['show_limits'] = 1;   // the generated card is the limits; without them it says nothing
		}
		$row['group_id'] = ((int) $row['group_id']) ?: null;
		$row['duration_days'] = max(0, (int) $row['duration_days']);
		$row['amount_minor'] = max(0, (int) $row['amount_minor']);
		// ISO 4217 or nothing: a bad currency code is rejected by the provider anyway, and a
		// blank one would be charged in whatever the POS defaults to.
		$currency = strtoupper(trim((string) $row['currency']));
		$row['currency'] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'PLN';
		$row['sort_order'] = (int) $row['sort_order'];
		$row['highlighted'] = !empty($row['highlighted']) ? 1 : 0;
		$row['enabled'] = !empty($row['enabled']) ? 1 : 0;
		$row['description_format'] = in_array($row['description_format'], ['markdown', 'html'], true)
			? $row['description_format'] : 'markdown';
		foreach ([
			'price' => 50,
			'period' => 50,
			'badge' => 50,
			'button_label' => 80,
			'checkout_url' => 500,
			'description' => InputLimits::LONG_TEXT_MAX,
			'features' => InputLimits::LONG_TEXT_MAX,
			'checkout_html' => InputLimits::HTML_MAX,
		] as $field => $maxLength) {
			$row[$field] = (string) $row[$field];
			if (!InputLimits::fits($row[$field], $maxLength)) {
				return ['success' => false, 'error' => __('api.input_too_long')];
			}
		}
		// `builtin` = this project's native payment checkout (pt 10); the button is generated from the
		// plan id, so no URL is stored.
		$row['checkout_type'] = in_array($row['checkout_type'], ['link', 'html', 'none', 'builtin'], true)
			? $row['checkout_type'] : 'link';
		if ($row['checkout_type'] === 'builtin') {
			$row['checkout_url'] = '';
			$row['checkout_html'] = '';
		}

		// A showcase card describes a tier somebody is already on, so there is nothing to sell,
		// nothing to charge and no subscription to run out. Forced here rather than merely
		// hidden in the form: `checkout_type` is what every other layer reads to decide whether
		// a buy button exists, and handleCheckout refuses on `kind` as well.
		if ($row['kind'] !== 'paid') {
			$row['checkout_type'] = 'none';
			$row['checkout_url'] = '';
			$row['checkout_html'] = '';
			$row['amount_minor'] = 0;
			$row['duration_days'] = 0;
			// The tier each showcase kind describes is a system group, so it does not have to be
			// picked by hand — and picking the wrong one would put wrong numbers on the page.
			if (!$row['group_id']) {
				$slug = $row['kind'] === 'guest' ? 'guest' : 'user';
				$sys = GroupRepository::getBySlug($slug) ?: ($slug === 'user' ? GroupRepository::getDefault() : null);
				$row['group_id'] = $sys ? (int) $sys['id'] : null;
			}
		}
		if ($row['group_id']) {
			$targetGroup = GroupRepository::getById((int) $row['group_id']);
			if (!$targetGroup || ($targetGroup['slug'] ?? null) === 'moderator') {
				return ['success' => false, 'error' => __('api.plan_invalid_group')];
			}
		}

		// A plan that points at no group would take money for nothing. Enabling one is the
		// moment that stops being a draft, so that is where the check belongs.
		if ($row['enabled'] && !$row['group_id']) {
			return ['success' => false, 'error' => __('api.plan_needs_group')];
		}

		$table = Database::table('plans');
		try {
			if ($id) {
				$sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($row)));
				$stmt = $pdo->prepare("UPDATE `{$table}` SET {$sets} WHERE `id` = ?");
				$stmt->execute(array_merge(array_values($row), [$id]));
				return ['success' => true, 'id' => $id];
			}
			$row['created_at'] = time();
			$cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
			$ph = implode(', ', array_fill(0, count($row), '?'));
			$stmt = $pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$ph})");
			$stmt->execute(array_values($row));
			return ['success' => true, 'id' => (int) $pdo->lastInsertId()];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/**
	 * Remove a plan. The two seeded showcase cards refuse: they describe tiers the installation
	 * has whether or not anyone wants to talk about them, so the way to be rid of one is to
	 * switch it off, not to delete a row that the next migration would put back.
	 */
	public static function delete(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$plan = self::get($id);
		if (!$plan || !empty($plan['is_system'])) {
			return false;
		}
		try {
			$pending = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('payments') . "`
				 WHERE `plan_id` = ? AND `granted_at` IS NULL
				   AND `status` IN (?, ?, ?)"
			);
			$pending->execute([
				$id,
				PaymentRepository::NEW,
				PaymentRepository::PENDING,
				PaymentRepository::PROCESSING,
			]);
			if ((int) $pending->fetchColumn() > 0) {
				$stmt = $pdo->prepare(
					"UPDATE `" . Database::table('plans') . "` SET `enabled` = 0 WHERE `id` = ?"
				);
				$stmt->execute([$id]);
				return $stmt->rowCount() > 0;
			}
			$stmt = $pdo->prepare("DELETE FROM `" . Database::table('plans') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * The card a showcase plan writes for itself, from the group it is bound to.
	 *
	 * Name, price, period, description and the feature list all come from what the server will
	 * actually do for that tier: the group's limits give the numbers (rendered separately, as
	 * the limits ledger), its permissions give the capabilities. Nothing here is stored, so a
	 * permission granted in Settings is a bullet point on the pricing page a moment later, and
	 * the two can never disagree.
	 *
	 * @return array{name: string, price: string, period: string, description: string, features: list<string>}
	 */
	public static function autoContent(array $plan): array
	{
		$kind = (string) ($plan['kind'] ?? 'paid');
		$group = !empty($plan['group_id']) ? GroupRepository::getById((int) $plan['group_id']) : null;
		$isGuest = $kind === 'guest';

		$features = [];
		$add = static function (string $key) use (&$features): void {
			$features[] = __($key);
		};

		// What this tier can do, read from the group's own permission set rather than from the
		// session: this is a description of a tier, and the reader is usually not on it.
		$granted = $group !== null ? Permissions::forGroup($group) : [];
		$can = static fn(string $perm): bool => in_array($perm, $granted, true);

		if ($isGuest) {
			$add('premium.auto_feat_upload_guest');
		} else {
			$add('premium.auto_feat_account');
			$add('premium.auto_feat_keys');
		}
		if ($can('myfiles.coll_create')) {
			$add('premium.auto_feat_collections');
		}
		if (!$isGuest && $can('myfiles.filters')) {
			$add('premium.auto_feat_filters');
		}
		if ($can('files.view_all')) {
			$add('premium.auto_feat_browse_all');
		}
		// Retention is the difference people notice first, so it earns a bullet of its own when
		// this tier is the one that keeps things.
		if ($group !== null && GroupRepository::retentionDays($group) === 0) {
			$add('premium.auto_feat_forever');
		}

		return [
			'name' => __($isGuest ? 'premium.auto_name_guest' : 'premium.auto_name_free'),
			'price' => __('premium.price_free'),
			'period' => __($isGuest ? 'premium.period_no_account' : 'premium.period_registered'),
			'description' => __($isGuest ? 'premium.auto_desc_guest' : 'premium.auto_desc_free'),
			'features' => $features,
		];
	}

	/**
	 * Put $userId into $plan's group for the plan's duration — the fulfilment step.
	 *
	 * Re-buying an active plan extends it rather than restarting the clock, which is what a
	 * renewal should do; buying a different plan replaces the assignment outright. A plan with
	 * `duration_days = 0` never expires.
	 *
	 * pt 3: `$overrideDays` lets an administrator hand out a different length than the plan
	 * sells — a week as an apology, a year as a prize. `null` means "use the plan's own
	 * duration", `0` means "no expiry at all"; both are things an operator asks for, and they
	 * are not the same thing, which is why the parameter is nullable rather than just 0.
	 *
	 * @return array{success: bool, expires_at?: ?int, error?: string}
	 */
	public static function grant(
		int $userId,
		array $plan,
		?int $overrideDays = null,
		?string $paymentReference = null
	): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$groupId = (int) ($plan['group_id'] ?? 0);
		if (!$groupId) {
			return ['success' => false, 'error' => __('api.plan_needs_group')];
		}
		$targetGroup = GroupRepository::getById($groupId);
		if (!$targetGroup || ($targetGroup['slug'] ?? null) === 'moderator') {
			return ['success' => false, 'error' => __('api.plan_invalid_group')];
		}

		$users = Database::table('users');
		$days = $overrideDays !== null
			? max(0, min(3650, $overrideDays))       // ten years is already absurd; beyond it is a typo
			: max(0, (int) ($plan['duration_days'] ?? 0));
		$paymentReference = $paymentReference !== null
			? mb_substr(trim($paymentReference), 0, 64)
			: null;
		if ($paymentReference === '') {
			$paymentReference = null;
		}

		try {
			$ownsTransaction = !$pdo->inTransaction();
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}

			// Keep the target group alive until the assignment commits. A plan pointing at a
			// group concurrently deleted by an administrator must fail, never leave a dangling
			// group_id on the account. Every entitlement path locks group → user in this order,
			// matching group deletion and avoiding lock-order deadlocks.
			$groups = Database::table('groups');
			$groupLock = $pdo->prepare("SELECT `id` FROM `{$groups}` WHERE `id` = ? FOR UPDATE");
			$groupLock->execute([$groupId]);
			if (!$groupLock->fetchColumn()) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return ['success' => false, 'error' => __('api.plan_needs_group')];
			}

			// Serialize every entitlement mutation for this account. Without the row lock two
			// paid renewals can both read the same expiry and one purchased period disappears.
			$stmt = $pdo->prepare(
				"SELECT `group_id`, `group_expires_at` FROM `{$users}` WHERE `id` = ? FOR UPDATE"
			);
			$stmt->execute([$userId]);
			$current = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$current) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return ['success' => false, 'error' => __('api.user_not_found')];
			}

			$expires = null;
			if ($days > 0) {
				$sameGroup = (int) ($current['group_id'] ?? 0) === $groupId;
				$remaining = (int) ($current['group_expires_at'] ?? 0);
				$base = ($sameGroup && $remaining > time()) ? $remaining : time();
				$expires = $base + ($days * 86400);
			}

			// pt 6: a move between groups restarts the retention clock, so the new group's
			// rules apply from now on rather than retroactively to old uploads. Extending the
			// same plan is not a move and leaves the stamp alone.
			$sameGroupNow = (int) ($current['group_id'] ?? 0) === $groupId;
			if ($sameGroupNow) {
				$upd = $pdo->prepare(
					"UPDATE `{$users}` SET `group_id` = ?, `group_expires_at` = ?,
					 `group_payment_ext_order_id` = ? WHERE `id` = ?"
				);
				$upd->execute([$groupId, $expires, $paymentReference, $userId]);
			} else {
				$upd = $pdo->prepare(
					"UPDATE `{$users}` SET `group_id` = ?, `group_expires_at` = ?,
					 `group_changed_at` = ?, `group_payment_ext_order_id` = ? WHERE `id` = ?"
				);
				$upd->execute([$groupId, $expires, time(), $paymentReference, $userId]);
			}

			Database::logAudit(
				'plan_granted',
				'plan #' . ($plan['id'] ?? '?') . ' → user #' . $userId
					. ', group #' . $groupId . ', ' . ($expires ? date('Y-m-d', $expires) : 'no expiry'),
				$userId
			);
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return ['success' => true, 'expires_at' => $expires];
		} catch (Throwable $e) {
			if (isset($ownsTransaction) && $ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/**
	 * Warn subscribers whose plan runs out soon (cron).
	 *
	 * A subscription ending is the one event where a notification has something to *do*: the
	 * link goes to the plans page, where the same plan can be extended before the limits drop.
	 * Keyed on the exact expiry timestamp, so extending produces a new deadline and therefore a
	 * new warning later, while re-running the sweep produces nothing.
	 *
	 * @param int $daysAhead how much notice to give
	 * @return int accounts warned
	 */
	public static function warnExpiring(int $daysAhead = 7): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $daysAhead <= 0) {
			return 0;
		}
		$now = time();
		$until = $now + ($daysAhead * 86400);

		try {
			$users = Database::table('users');
			$groups = Database::table('groups');
			$stmt = $pdo->prepare("SELECT u.`id`, u.`group_expires_at`, g.`name` AS group_name
				FROM `{$users}` u
				LEFT JOIN `{$groups}` g ON g.`id` = u.`group_id`
				WHERE u.`group_expires_at` IS NOT NULL AND u.`group_expires_at` > ? AND u.`group_expires_at` <= ?");
			$stmt->execute([$now, $until]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return 0;
		}

		foreach ($rows as $row) {
			$expires = (int) $row['group_expires_at'];
			Notifications::send((int) $row['id'], 'plan.expiring', [
				'subject' => (string) ($row['group_name'] ?? ''),
				'data' => [
					'date' => date('d.m.Y', $expires),
					'days' => max(1, (int) ceil(($expires - $now) / 86400)),
				],
				'group' => 'plan.expiring:' . $expires,
				'once' => true,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/premium',
			]);
		}
		return count($rows);
	}

	/** Revoke a paid assignment early — back to the default group, no expiry. */
	public static function revoke(int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			// pt 6: back to the default group means its retention starts counting now.
			$stmt = $pdo->prepare("UPDATE `" . Database::table('users')
				. "` SET `group_id` = NULL, `group_expires_at` = NULL, `group_changed_at` = ?,
				 `group_payment_ext_order_id` = NULL WHERE `id` = ?");
			$stmt->execute([time(), $userId]);
			Database::logAudit('plan_revoked', 'user #' . $userId, $userId);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}
}
