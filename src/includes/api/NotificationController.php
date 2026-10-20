<?php
/**
 * NotificationController — the bell, the history page and the two preference screens.
 *
 * Everything here is about the calling session and nothing else: there is no endpoint that
 * takes a user id, and every id that arrives from the browser is used only inside a WHERE that
 * already pins `user_id` to the session. The one exception is the admin half at the bottom
 * (defaults + broadcast), which is guarded on `is_admin` like the rest of the panel.
 */
final class NotificationController
{
	/** How many the bell's popover carries. Deliberately small — it is a peek, not the list. */
	private const BELL_LIMIT = 8;

	private static function requireUser(): ?int
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return null;
		}
		return (int) $_SESSION['user_id'];
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

	private static function input(): array
	{
		$raw = json_decode(file_get_contents('php://input'), true);
		return is_array($raw) ? $raw : [];
	}

	/**
	 * The ten-minute bucket a broadcast's retry keys are made from. A retry seconds after the
	 * first send can fall into the next bucket and would have sent everything again, so when
	 * this very message went out under the previous bucket within the last ten minutes, that
	 * bucket is reused.
	 */
	public static function broadcastWindow(string $channel, string $format, string $text, string $subject, string $emailBody, int $now): int
	{
		$window = intdiv($now, 600);
		$previous = $window - 1;
		$since = $now - 600;
		if (in_array($channel, ['app', 'both'], true)
			&& NotificationRepository::dedupeKeySince('broadcast:' . sha1($text) . ':' . $previous, $since)) {
			return $previous;
		}
		if (in_array($channel, ['email', 'both'], true)) {
			$batchId = sha1($format . "\0" . $subject . "\0" . $emailBody) . ':' . $previous;
			$keys = array_map(
				static fn(array $recipient): string => 'broadcast:' . $batchId . ':' . $recipient['id'],
				NotificationRepository::activeRecipients()
			);
			if (MailService::queuedSince($keys, $since)) {
				return $previous;
			}
		}
		return $window;
	}

	/**
	 * The list, rendered.
	 *
	 * `?scope=bell` is the popover's short read; anything else pages the full history. The
	 * unread count comes back either way, because that is what the badge needs and asking for
	 * it separately would double the polling.
	 */
	public static function handleNotifications()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}

		$bell = ($_GET['scope'] ?? '') === 'bell';
		$limit = $bell ? self::BELL_LIMIT : max(1, min(50, (int) ($_GET['per_page'] ?? 20)));
		$page = max(1, (int) ($_GET['page'] ?? 1));
		$unreadOnly = !$bell && ($_GET['filter'] ?? '') === 'unread';

		$res = NotificationRepository::browse($userId, $limit, ($page - 1) * $limit, $unreadOnly);

		echo json_encode([
			'success' => true,
			'items' => array_map([Notifications::class, 'render'], $res['items']),
			'total' => $res['total'],
			'unread' => $res['unread'],
			// What the bell badge shows: unread arrivals since the popover was last opened.
			'fresh' => $bell ? NotificationRepository::freshCount($userId) : $res['unread'],
			'page' => $page,
			'per_page' => $limit,
		]);
	}

	/** Just the badge. Cheap enough to poll. `fresh` is what the bell shows (runda 4, pt 4). */
	public static function handleNotificationCount()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		echo json_encode([
			'success' => true,
			'unread' => NotificationRepository::unreadCount($userId),
			'fresh' => NotificationRepository::freshCount($userId),
		]);
	}

	/**
	 * The bell was opened: zero the badge WITHOUT touching read state (runda 4, pt 4).
	 * Rows keep their unread styling until clicked or marked read explicitly.
	 */
	public static function handleNotificationSeen()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		NotificationRepository::markSeen($userId);
		echo json_encode(['success' => true]);
	}

	/** Mark the given ids read, or everything when `all` is set. */
	public static function handleNotificationRead()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		$input = self::input();
		$n = !empty($input['all'])
			? NotificationRepository::markAllRead($userId)
			: NotificationRepository::markRead($userId, (array) ($input['ids'] ?? []));

		echo json_encode(['success' => true, 'changed' => $n, 'unread' => NotificationRepository::unreadCount($userId)]);
	}

	/** Delete the given ids, or empty the list (`all`, optionally `read_only`). */
	public static function handleNotificationDelete()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		$input = self::input();
		$n = !empty($input['all'])
			? NotificationRepository::clear($userId, !empty($input['read_only']))
			: NotificationRepository::delete($userId, (array) ($input['ids'] ?? []));

		echo json_encode(['success' => true, 'deleted' => $n, 'unread' => NotificationRepository::unreadCount($userId)]);
	}

	/** The account's own per-type switches, with the labels the screen prints. */
	public static function handleNotificationPrefs()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		// The role is read from the account rather than the session: `completeLogin` stores
		// `is_admin` but not the role, so a moderator would otherwise look like an ordinary user
		// here and never see the row that is actually addressed to them.
		$me = Database::getUserById($userId);
		$isStaff = !empty($_SESSION['is_admin']) || ($me['role'] ?? '') === 'moderator';
		$rows = array_values(array_filter(
			Notifications::userMatrix($userId),
			// A moderation notice is not a choice an ordinary account has to make about itself.
			fn($r) => !$r['staff'] || $isStaff
		));
		echo json_encode(['success' => true, 'types' => $rows]);
	}

	public static function handleNotificationPrefsSave()
	{
		$userId = self::requireUser();
		if ($userId === null) {
			return;
		}
		$input = self::input();
		$ok = Notifications::saveUserPrefs($userId, is_array($input['prefs'] ?? null) ? $input['prefs'] : []);
		echo json_encode(['success' => $ok]);
	}

	/* ---------------- Admin ---------------- */

	/** The installation-wide defaults, plus which types exist at all. */
	public static function handleAdminNotificationDefaults()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$defaults = Notifications::defaults();
		$rows = [];
		foreach (Notifications::TYPES as $type => $meta) {
			$rows[] = [
				'type' => $type,
				'icon' => $meta['icon'],
				'mailable' => (bool) $meta['mailable'],
				'stacks' => !empty($meta['stacks']),
				'staff' => !empty($meta['staff']),
			] + $defaults[$type];
		}
		echo json_encode(['success' => true, 'types' => $rows]);
	}

	public static function handleAdminNotificationDefaultsSave()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = self::input();
		Notifications::saveDefaults(is_array($input['defaults'] ?? null) ? $input['defaults'] : []);
		Database::logAudit('notification_defaults_saved', '');
		echo json_encode(['success' => true]);
	}

	/**
	 * Say something to everyone.
	 *
	 * Goes through the ordinary emitter, so an account that muted announcements stays muted —
	 * a broadcast is a message, not an override.
	 */
	public static function handleAdminNotificationBroadcast()
	{
		if (!self::requireAdmin()) {
			return;
		}
		$input = self::input();
		$channel = (string) ($input['channel'] ?? 'app');
		$format = (string) ($input['format'] ?? 'standard');
		$text = trim((string) ($input['message'] ?? ''));
		$subject = trim((string) ($input['subject'] ?? ''));
		$emailBody = trim((string) ($input['email_body'] ?? ''));
		if (!in_array($channel, ['app', 'email', 'both'], true)
			|| !in_array($format, ['standard', 'html'], true)
			|| (in_array($channel, ['app', 'both'], true)
				&& ($text === '' || mb_strlen($text) > 255))
			|| (in_array($channel, ['email', 'both'], true)
				&& ($subject === '' || mb_strlen($subject) > 180
					|| $emailBody === '' || mb_strlen($emailBody) > 20000))) {
			echo json_encode(['success' => false, 'error' => __('api.notif_bad_message')]);
			return;
		}

		// The same message sent again within ten minutes is treated as a retry of the first
		// (a double click, a timed-out request): accounts that already have it get nothing new.
		$window = self::broadcastWindow($channel, $format, $text, $subject, $emailBody, time());
		$appSent = 0;
		if (in_array($channel, ['app', 'both'], true)) {
			$appSent = Notifications::broadcastApp(
				$text,
				APP_URL . '/panel.php?tab=notifications',
				'broadcast:' . sha1($text) . ':' . $window
			);
		}

		$emailQueued = 0;
		if (in_array($channel, ['email', 'both'], true)) {
			$batchId = sha1($format . "\0" . $subject . "\0" . $emailBody) . ':' . $window;
			$recipients = NotificationRepository::activeRecipients();
			NotificationRepository::warmPrefs(array_column($recipients, 'id'));
			$pdo = Database::getInstance();
			// Queued in transactions of a few hundred: one commit per message made a large
			// broadcast slow enough to run into the request time limit half-way.
			foreach (array_chunk($recipients, 200) as $chunk) {
				$pdo?->beginTransaction();
				foreach ($chunk as $recipient) {
					if (!Notifications::allows($recipient['id'], 'system.announcement', 'mail')) {
						continue;
					}
					$key = 'broadcast:' . $batchId . ':' . $recipient['id'];
					$queued = $format === 'html'
						? MailService::sendHtml($recipient['email'], $subject, $emailBody, $key)
						: MailService::send($recipient['email'], $subject, $emailBody, $key);
					if ($queued) {
						$emailQueued++;
					}
				}
				if ($pdo?->inTransaction()) {
					$pdo->commit();
				}
			}
		}

		$auditSubject = $text !== '' ? $text : $subject;
		Database::logAudit(
			'notification_broadcast',
			mb_substr($auditSubject, 0, 120)
				. ' [' . $channel . '/' . $format . '] app=' . $appSent
				. ', mail=' . $emailQueued
		);
		echo json_encode([
			'success' => true,
			'sent' => $appSent + $emailQueued,
			'appSent' => $appSent,
			'emailQueued' => $emailQueued,
		]);
	}
}
