<?php
/**
 * Notifications — what the app is allowed to tell an account, and how it says it.
 *
 * `NotificationRepository` is storage. This is the part with opinions:
 *
 *   - the **catalogue** (`TYPES`) of everything that can be announced, each declaring its icon,
 *     whether it stacks, whether it is worth an e-mail, and what it does by default;
 *   - the **three-way decision** — operator default, operator veto, account preference — made
 *     in one place (`allows()`), so no emitter has to remember the rules;
 *   - **rendering**, done at read time in the reader's language rather than baked into the row.
 *
 * Emitters are deliberately fire-and-forget: `send()` never throws and never reports failure
 * upward. A download must not fail because the owner's notification could not be written, and a
 * plan must not go ungranted because a mail server was unreachable.
 */
final class Notifications
{
	/**
	 * Every kind of thing this installation can announce.
	 *
	 *   icon     — FontAwesome glyph shown in the list.
	 *   stacks   — repeats about the same subject merge into one line with a count.
	 *   mailable — may additionally be sent by e-mail. Deliberately false for anything that can
	 *              repeat: "someone downloaded your file" is a fine bell and terrible mail.
	 *   app/mail — what a fresh installation does before anyone changes anything.
	 *   staff    — addressed to moderators/admins rather than to the account it concerns.
	 */
	public const TYPES = [
		'file.downloaded'      => ['icon' => 'fa-download',             'stacks' => true,  'mailable' => false, 'app' => true,  'mail' => false],
		// An embedded image fires on every page view by every reader of whatever it was pasted
		// into, so this one ships off. It exists because "my picture is doing the rounds" is
		// worth knowing for some people — just never by default, and never by e-mail.
		'file.embedded'        => ['icon' => 'fa-code',                 'stacks' => true,  'mailable' => false, 'app' => false, 'mail' => false],
		'file.expiring'        => ['icon' => 'fa-hourglass-half',       'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => true],
		'file.deleted'         => ['icon' => 'fa-trash',                'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => false],
		'file.removed'         => ['icon' => 'fa-shield-halved',        'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => true],
		'collection.downloaded' => ['icon' => 'fa-box-archive',         'stacks' => true,  'mailable' => false, 'app' => true,  'mail' => false],
		'storage.quota'        => ['icon' => 'fa-database',             'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => true],
		'plan.granted'         => ['icon' => 'fa-gem',                  'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'plan.expiring'        => ['icon' => 'fa-triangle-exclamation', 'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => true],
		'plan.expired'         => ['icon' => 'fa-circle-xmark',         'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'plan.revoked'         => ['icon' => 'fa-circle-minus',         'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'payment.completed'    => ['icon' => 'fa-circle-check',         'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'payment.failed'       => ['icon' => 'fa-circle-exclamation',   'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		// Money returned after completion — today: a paid ad rejected in review (runda 8).
		'payment.refunded'     => ['icon' => 'fa-rotate-left',          'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'security.password'    => ['icon' => 'fa-key',                  'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'security.email'       => ['icon' => 'fa-envelope',             'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'report.new'           => ['icon' => 'fa-flag',                 'stacks' => true,  'mailable' => false, 'app' => true,  'mail' => false, 'staff' => true],
		'system.announcement'  => ['icon' => 'fa-bullhorn',             'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => false],
		// Advertising (Faza 8): a paid placement moving through its lifecycle. `ad.submitted`
		// goes to staff (something to review); the rest go to the buyer.
		'ad.submitted'         => ['icon' => 'fa-rectangle-ad',         'stacks' => true,  'mailable' => false, 'app' => true,  'mail' => false, 'staff' => true],
		// A live/paid ad was EDITED and re-entered the queue (runda 6) — without this the
		// reviewers only heard about brand-new purchases, never about changed creatives.
		'ad.resubmitted'       => ['icon' => 'fa-pen-to-square',       'stacks' => true,  'mailable' => false, 'app' => true,  'mail' => false, 'staff' => true],
		'ad.paid'              => ['icon' => 'fa-circle-check',        'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'ad.boosted'           => ['icon' => 'fa-bolt',               'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'ad.renewed'           => ['icon' => 'fa-rotate-right',      'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'ad.approved'          => ['icon' => 'fa-circle-check',        'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'ad.rejected'          => ['icon' => 'fa-circle-xmark',        'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
		'ad.expiring'          => ['icon' => 'fa-hourglass-half',      'stacks' => true,  'mailable' => true,  'app' => true,  'mail' => true],
		'ad.expired'           => ['icon' => 'fa-rectangle-ad',        'stacks' => false, 'mailable' => true,  'app' => true,  'mail' => true],
	];

	/** Settings key holding the operator's per-type defaults and vetoes. */
	private const DEFAULTS_KEY = 'notification_defaults';

	/**
	 * Per-request cache of the resolved defaults, together with the raw setting it was built
	 * from.
	 *
	 * Keyed on the stored string rather than simply "have I read this yet": the panel saves and
	 * re-reads inside one request, and a plain flag would answer that read with the values the
	 * operator just replaced. Comparing the raw value makes the cache correct no matter who
	 * wrote the setting or how.
	 */
	private static ?array $defaultsCache = null;
	private static ?string $defaultsRaw = null;

	public static function isType(string $type): bool
	{
		return isset(self::TYPES[$type]);
	}

	/**
	 * The operator's configuration, defaults filled in.
	 *
	 * Each type carries `enabled` (may this installation announce it at all — a veto no account
	 * preference can lift), plus the `app` / `mail` values a fresh account starts with.
	 *
	 * @return array<string, array{enabled: bool, app: bool, mail: bool}>
	 */
	public static function defaults(): array
	{
		$raw = (string) Database::getSetting(self::DEFAULTS_KEY, '');
		if (self::$defaultsCache !== null && self::$defaultsRaw === $raw) {
			return self::$defaultsCache;
		}
		self::$defaultsRaw = $raw;
		$stored = json_decode($raw, true);
		$stored = is_array($stored) ? $stored : [];

		$out = [];
		foreach (self::TYPES as $type => $meta) {
			$row = is_array($stored[$type] ?? null) ? $stored[$type] : [];
			$out[$type] = [
				'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
				'app' => array_key_exists('app', $row) ? (bool) $row['app'] : (bool) $meta['app'],
				'mail' => $meta['mailable'] && (array_key_exists('mail', $row) ? (bool) $row['mail'] : (bool) $meta['mail']),
			];
		}
		return self::$defaultsCache = $out;
	}

	/** Save the operator's defaults; unknown types and non-mailable mail flags are dropped. */
	public static function saveDefaults(array $input): void
	{
		$clean = [];
		foreach (self::TYPES as $type => $meta) {
			$row = is_array($input[$type] ?? null) ? $input[$type] : [];
			$clean[$type] = [
				'enabled' => !empty($row['enabled']),
				'app' => !empty($row['app']),
				'mail' => $meta['mailable'] && !empty($row['mail']),
			];
		}
		Database::setSetting(self::DEFAULTS_KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * What one account's settings screen should show: the effective on/off per type, and why
	 * a row might be untouchable.
	 *
	 * @return list<array{type: string, icon: string, mailable: bool, staff: bool, app: bool, mail: bool}>
	 */
	public static function userMatrix(int $userId): array
	{
		$defaults = self::defaults();
		$prefs = NotificationRepository::prefs($userId);
		$out = [];
		foreach (self::TYPES as $type => $meta) {
			if (!$defaults[$type]['enabled']) {
				continue; // switched off for the whole installation — not a choice to offer
			}
			$mine = is_array($prefs[$type] ?? null) ? $prefs[$type] : [];
			$out[] = [
				'type' => $type,
				'icon' => $meta['icon'],
				'mailable' => (bool) $meta['mailable'],
				'staff' => !empty($meta['staff']),
				'app' => array_key_exists('app', $mine) ? (bool) $mine['app'] : $defaults[$type]['app'],
				'mail' => $meta['mailable'] && (array_key_exists('mail', $mine) ? (bool) $mine['mail'] : $defaults[$type]['mail']),
			];
		}
		return $out;
	}

	/**
	 * May this account be told about this, on this channel?
	 *
	 * Order matters: the operator's veto wins over everything, then the account's own choice,
	 * then the default. A channel the type does not support is always no.
	 */
	public static function allows(int $userId, string $type, string $channel = 'app'): bool
	{
		if (!isset(self::TYPES[$type])) {
			return false;
		}
		if ($channel === 'mail' && empty(self::TYPES[$type]['mailable'])) {
			return false;
		}
		$defaults = self::defaults();
		if (empty($defaults[$type]['enabled'])) {
			return false;
		}
		$prefs = NotificationRepository::prefs($userId);
		$mine = is_array($prefs[$type] ?? null) ? $prefs[$type] : [];
		return array_key_exists($channel, $mine) ? (bool) $mine[$channel] : (bool) $defaults[$type][$channel];
	}

	/** Store one account's choices, keeping only known types and supported channels. */
	public static function saveUserPrefs(int $userId, array $input): bool
	{
		$clean = [];
		foreach (self::TYPES as $type => $meta) {
			if (!array_key_exists($type, $input) || !is_array($input[$type])) {
				continue;
			}
			$row = ['app' => !empty($input[$type]['app'])];
			if ($meta['mailable']) {
				$row['mail'] = !empty($input[$type]['mail']);
			}
			$clean[$type] = $row;
		}
		return NotificationRepository::savePrefs($userId, $clean);
	}

	/* ---------------- Emitting ---------------- */

	/**
	 * Announce something to one account.
	 *
	 * @param array $opts subject: what the sentence is about · link: where clicking goes ·
	 *                    data: extra placeholders · group: stacking key (defaults to type+subject)
	 */
	public static function send(int $userId, string $type, array $opts = []): bool
	{
		if ($userId <= 0 || !isset(self::TYPES[$type])) {
			return false;
		}
		$meta = self::TYPES[$type];
		$subject = (string) ($opts['subject'] ?? '');
		$link = (string) ($opts['link'] ?? '');
		$data = is_array($opts['data'] ?? null) ? $opts['data'] : [];
		$by = max(1, (int) ($opts['by'] ?? 1));

		try {
			$group = '';
			if (!empty($meta['stacks'])) {
				// Same type, same object → same line. `group` lets a caller be explicit when
				// the subject text is not a stable identity (a renamed file, say).
				$group = (string) ($opts['group'] ?? ($type . ':' . substr(sha1($subject), 0, 24)));
			}
			// `once` marks a warning rather than an event: say it the first time the condition
			// is true and then stay quiet, however often the sweep re-notices it. The stored row
			// *is* the record of having said it, which is why it is written even when the bell
			// is muted — otherwise a cron running every quarter hour would mail the same warning
			// ninety-six times a day to anyone who kept mail and turned the bell off. In that
			// case it is written already-read, so it is history rather than something new.
			$once = !empty($opts['once']) && $group !== '';
			$requestedChannels = is_array($opts['channels'] ?? null)
				? array_values(array_intersect(['app', 'mail'], $opts['channels']))
				: ['app', 'mail'];
			$app = in_array('app', $requestedChannels, true)
				&& self::allows($userId, $type, 'app');
			$mail = in_array('mail', $requestedChannels, true)
				&& self::allows($userId, $type, 'mail');
			$sent = false;
			if ($app || ($once && $mail)) {
				$written = NotificationRepository::push(
					$userId,
					$type,
					$subject,
					$link,
					$data,
					$group,
					$by,
					!$app,
					$once ? $group : ''
				);
				if ($once && !$written) {
					return false;
				}
				$sent = $written;
			}
			if ($mail) {
				$sent = self::mail($userId, $type, $subject, $link, $data) || $sent;
			}
			return $sent;
		} catch (\Throwable $e) {
			// Never let telling someone about an event break the event itself.
			return false;
		}
	}

	/** The same announcement to several accounts (the broadcast, the staff list). */
	public static function sendMany(array $userIds, string $type, array $opts = []): int
	{
		$sent = 0;
		foreach (array_unique(array_map('intval', $userIds)) as $id) {
			if ($id > 0) {
				if (self::send($id, $type, $opts)) {
					$sent++;
				}
			}
		}
		return $sent;
	}

	/** Everyone who should hear about moderation work. */
	public static function staffIds(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$stmt = $pdo->query("SELECT `id` FROM `" . Database::table('users') . "`
				WHERE `is_active` = 1 AND `role` IN ('admin', 'moderator')");
			return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * The e-mail version of a notification.
	 *
	 * Composed from the same sentence the bell shows plus a link, rather than a second set of
	 * templates: two wordings of one event drift apart, and the one nobody is looking at drifts
	 * first. Rendered in the recipient's saved language (or the installation default).
	 */
	private static function mail(int $userId, string $type, string $subject, string $link, array $data): bool
	{
		$user = Database::getUserById($userId);
		$email = trim((string) ($user['email'] ?? ''));
		if ($email === '') {
			return false;
		}
		$language = trim((string) ($user['language'] ?? ''));
		if ($language === '') {
			$language = (string) Database::getSetting('default_language', 'pl');
		}
		$rendered = self::renderMessage($type, $subject, 1, $data, $language);
		$appName = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
		$body = $rendered . "\n\n" . Lang::translateFor($language, 'notif.mail.footer', [
			'url' => $link !== '' ? $link : (defined('APP_URL') ? APP_URL . '/panel.php?tab=notifications' : ''),
		]);
		return Database::sendEmail(
			$email,
			$appName . ' — ' . Lang::translateFor($language, 'notif.type.' . $type),
			$body
		);
	}

	/* ---------------- Rendering ---------------- */

	/**
	 * Turn a stored row into something printable, in the reader's current language.
	 *
	 * @return array{id:int,type:string,icon:string,title:string,message:string,link:string,count:int,unread:bool,at:int}
	 */
	public static function render(array $row): array
	{
		$type = (string) $row['type'];
		$meta = self::TYPES[$type] ?? ['icon' => 'fa-bell'];
		$data = json_decode((string) ($row['data'] ?? ''), true);
		$count = max(1, (int) ($row['count'] ?? 1));

		return [
			'id' => (int) $row['id'],
			'type' => $type,
			'icon' => $meta['icon'],
			'title' => __('notif.type.' . $type),
			'message' => self::renderMessage($type, (string) $row['subject'], $count, is_array($data) ? $data : []),
			'link' => (string) $row['link'],
			'count' => $count,
			'unread' => $row['read_at'] === null,
			'at' => (int) $row['updated_at'],
		];
	}

	/**
	 * The sentence itself.
	 *
	 * `:subject` is whatever the event was about and `:n` is how many times it happened; the
	 * count is *not* pasted into the text as "[x30]" — the list renders that as a badge, so it
	 * stays legible when the sentence is long and translatable when it is short.
	 */
	private static function renderMessage(
		string $type,
		string $subject,
		int $count,
		array $data,
		?string $language = null
	): string
	{
		$translate = static fn(string $key, array $params = []): string => $language === null
			? __($key, $params)
			: Lang::translateFor($language, $key, $params);
		$params = [
			'subject' => $subject !== '' ? $subject : $translate('notif.subject_unknown'),
			'n' => $count,
		];
		foreach ($data as $k => $v) {
			if (is_scalar($v)) {
				$params[(string) $k] = (string) $v;
			}
		}
		return $translate('notif.msg.' . $type, $params);
	}
}
