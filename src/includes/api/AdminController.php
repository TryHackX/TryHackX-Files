<?php
/**
 * AdminController (Faza 5 · #1).
 *
 * Admin-panel endpoints: user management (list/create/action/update), live active
 * downloads, user groups (A8), dashboard, audit log, and IP/blacklist bans. Every action
 * gates on an admin session. Handler bodies are the former api.php handleXxx functions,
 * moved verbatim; the router dispatches here.
 */
final class AdminController
{
	/** Where the `<code>.php` string files live (src/lang, two levels up from this file). */
	private const LANG_DIR = __DIR__ . '/../../lang';
	private const CLEANUP_CONFIRM_TTL = 300;

	public static function handleAdminUsers()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		header('Content-Type: application/json');

		$page = (int) ($_GET['page'] ?? 1);
		if ($page < 1)
			$page = 1;

		$sortBy = $_GET['sort_by'] ?? 'created_at';
		$order = $_GET['order'] ?? 'desc';
		$sorts = [];
		$decodedSorts = json_decode((string) ($_GET['sorts'] ?? ''), true);
		if (is_array($decodedSorts)) {
			foreach (array_slice($decodedSorts, 0, 5) as $item) {
				$key = (string) ($item['key'] ?? '');
				if (!in_array($key, ['username', 'email', 'created_at', 'storage_used', 'files_count', 'role', 'is_active'], true)
					|| isset($sorts[$key])) {
					continue;
				}
				$sorts[$key] = strtoupper((string) ($item['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
			}
		}

		$limit = 20; // Default limit
		$result = Database::getAllUsers($page, $limit, $sortBy, $order, $sorts);

		// pt 4: which row is the owner, so the list can leave out a delete button that would
		// only ever be refused.
		sendCachedJson([
			'success' => true,
			'users' => $result['users'],
			'total' => $result['total'],
			'rootId' => Database::rootAdminId(),
		]);
	}

	public static function handleAdminCreateUser()
	{
		// Session already started in api.php
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			throw new Exception('Database error');
		}

		$data = json_decode(file_get_contents('php://input'), true);
		$username = trim($data['username'] ?? '');
		$email = trim($data['email'] ?? '');
		$password = $data['password'] ?? '';
		$role = $data['role'] ?? 'user';
		$autoActivate = $data['auto_activate'] ?? false;

		if (!$username || !$email || !$password) {
			echo json_encode(['success' => false, 'error' => __('api.all_fields_required')]);
			return;
		}
		// Server-side mirror of the create-user form validation (B3).
		if (mb_strlen($username) < InputLimits::usernameMin()) {
			echo json_encode(['success' => false, 'error' => __('api.username_short_configured', ['min' => InputLimits::usernameMin()])]);
			return;
		}
		if (!InputLimits::validUsername($username)) {
			echo json_encode(['success' => false, 'error' => __('api.username_format')]);
			return;
		}
		if (!InputLimits::validEmail($email)) {
			echo json_encode(['success' => false, 'error' => __('api.email_invalid')]);
			return;
		}
		if (strlen($password) < InputLimits::accountPasswordMin()) {
			echo json_encode(['success' => false, 'error' => __('api.pw_short_configured', ['min' => InputLimits::accountPasswordMin()])]);
			return;
		}
		if (strlen($password) > InputLimits::accountPasswordMax()) {
			echo json_encode(['success' => false, 'error' => __('api.password_too_long')]);
			return;
		}
		if (!in_array($role, ['user', 'moderator', 'admin'], true)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.invalid_role')]);
			return;
		}

		// Use prefix if defined
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$table = $prefix . 'users';

		// Check if exists
		$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE username = ? OR email = ?");
		$stmt->execute([$username, $email]);
		if ($stmt->fetch()) {
			echo json_encode(['success' => false, 'error' => __('api.user_exists')]);
			return;
		}

		$hash = password_hash($password, PASSWORD_BCRYPT);
		$isActive = $autoActivate ? 1 : 0;
		$staffGroupId = null;
		if ($role === 'moderator') {
			$moderatorGroup = GroupRepository::getBySlug('moderator');
			if (!$moderatorGroup || (int) ($moderatorGroup['is_system'] ?? 0) !== 1) {
				http_response_code(503);
				echo json_encode(['success' => false, 'error' => __('api.moderator_group_missing')]);
				return;
			}
			$staffGroupId = (int) $moderatorGroup['id'];
		}

		// Default storage 500MB
		$limit = 500 * 1024 * 1024;

		$stmt = $pdo->prepare("INSERT INTO `{$table}`
			(username, email, password_hash, role, staff_group_id, is_active, storage_limit, created_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
		$stmt->execute([
			$username, $email, $hash, $role, $staffGroupId, $isActive, $limit, time(),
		]);

		echo json_encode(['success' => true]);
	}

	public static function handleAdminUserAction()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$userId = (int) ($input['user_id'] ?? 0);
		$action = $input['action'] ?? '';

		if (!$userId || !$action) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id_or_action')]);
			return;
		}
		if (
			in_array($action, ['deactivate', 'ban', 'ban_advanced', 'delete', 'clean'], true)
			&& !requireRecentAuthentication()
		) {
			return;
		}

		/**
		 * pt 4: the owner account cannot be deleted — not by itself, not by another admin.
		 *
		 * Checked here rather than only in the repository because `delete` wipes the account's
		 * files *first*: the old guard sat in `UserRepository::delete()`, so pressing delete on
		 * the owner destroyed every file it had and then reported "action failed", leaving an
		 * account that had lost everything. Refuse before anything is touched.
		 */
		if (in_array($action, ['deactivate', 'ban', 'ban_advanced', 'delete'], true)
			&& Database::isRootAdmin($userId)) {
			$errorKey = $action === 'delete'
				? 'api.root_admin_protected'
				: 'api.root_admin_access_protected';
			echo json_encode(['success' => false, 'error' => __($errorKey)]);
			return;
		}

		switch ($action) {
			case 'activate':
				$result = Database::updateUserStatus($userId, 1);
				break;

			case 'deactivate':
				$result = Database::updateUserStatus($userId, 0);
				break;

			case 'ban': // Legacy simple ban if called, or mapped to deactivate
				$result = Database::updateUserStatus($userId, 0);
				break;

			case 'ban_advanced':
				$opts = $input['ban_options'] ?? [];
				$reason = $opts['reason'] ?? 'Banned via user action';
				$expiresIn = (int) ($opts['expires_in'] ?? 0);
				$expiresAt = ($expiresIn > 0) ? time() + $expiresIn : null;

				$pdo = Database::getInstance();
				// Get user details
				$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
				$stmt = $pdo->prepare("SELECT `username`, `email` FROM `{$prefix}users` WHERE `id` = ?");
				$stmt->execute([$userId]);
				$u = $stmt->fetch(PDO::FETCH_ASSOC);

				if (!$u) {
					echo json_encode(['success' => false, 'error' => __('api.user_not_found')]);
					return;
				}

				if (!empty($opts['ban_email'])) {
					Database::addToBlacklist('email', $u['email'], $expiresAt, $reason);
				}
				if (!empty($opts['ban_name'])) {
					Database::addToBlacklist('username', $u['username'], $expiresAt, $reason);
				}
				if (!empty($opts['ban_ip'])) {
					// Find last IP from files
					$stmt = $pdo->prepare("SELECT `uploaded_ip` FROM `{$prefix}files` WHERE `user_id` = ? ORDER BY `uploaded_at` DESC LIMIT 1");
					$stmt->execute([$userId]);
					$lastIp = $stmt->fetchColumn();
					if ($lastIp && filter_var($lastIp, FILTER_VALIDATE_IP)) {
						Database::addToBlacklist('ip', $lastIp, $expiresAt, $reason);
					}
					// Also try upload_tokens?
					if (!$lastIp) {
						$stmt = $pdo->prepare("SELECT `ip_address` FROM `{$prefix}upload_tokens` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 1");
						$stmt->execute([$userId]);
						$lastIp = $stmt->fetchColumn();
						if ($lastIp)
							Database::addToBlacklist('ip', $lastIp, $expiresAt, $reason);
					}
				}

				$result = Database::updateUserStatus($userId, 0); // Deactivate as well
				break;

			case 'unban':
				$result = Database::updateUserStatus($userId, 1);
				break;

			case 'delete':
				// UserRepository performs the DB deletion and durable filesystem outbox in one
				// transaction. Doing a preliminary file wipe here would lose data if the final
				// last-admin guard refused the account deletion.
				$result = Database::deleteUser($userId);
				break;

			case 'clean':
				FileManager::deleteAllUserFiles($userId);
				$result = Database::cleanUserFiles($userId);
				break;

			default:
				echo json_encode(['success' => false, 'error' => __('api.unknown_action')]);
				return;
		}

		if ($result) {
			Database::logAudit('user_action', "$action on user #$userId");
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.action_failed')]);
		}
	}

	/**
	 * Admin edit of a user: role, per-user storage limit, and/or password reset. Selecting the
	 * moderator role attaches the system Moderator group in the repository.
	 */
	public static function handleAdminUpdateUser()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$userId = (int) ($input['user_id'] ?? 0);
		if (!$userId) {
			echo json_encode(['success' => false, 'error' => __('api.missing_user_id')]);
			return;
		}

		$data = [];
		if (isset($input['role'])) {
			$data['role'] = $input['role'];
		}
		if (isset($input['storage_limit_mb']) && $input['storage_limit_mb'] !== '') {
			$data['storage_limit'] = max(0, (int) $input['storage_limit_mb']) * 1024 * 1024;
		}
		if (!empty($input['password'])) {
			if (strlen($input['password']) < InputLimits::accountPasswordMin()) {
				echo json_encode(['success' => false, 'error' => __('api.pw_short_configured', ['min' => InputLimits::accountPasswordMin()])]);
				return;
			}
			$data['password'] = $input['password'];
		}

		$res = Database::adminUpdateUser($userId, $data);
		if ($res['success']) {
			$changed = [];
			if (isset($data['role'])) {
				$changed[] = 'role=' . $data['role'];
			}
			if (isset($data['storage_limit'])) {
				$changed[] = 'storage';
			}
			if (isset($data['password'])) {
				$changed[] = 'password reset';
			}
			Database::logAudit('user_updated', "user #$userId: " . implode(', ', $changed));
		}
		echo json_encode($res);
	}

	/* ---------------- Live active downloads (Faza 2.1) ---------------- */

	public static function handleAdminActiveDownloads()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		// ETag keeps quiet polls cheap (304) when the set of active downloads is unchanged.
		// Uploads ride along in the same response: the dashboard shows both widgets side by
		// side and polls once, so splitting them would double the traffic to save nothing.
		sendCachedJson([
			'success' => true,
			'downloads' => Database::getActiveDownloads(100),
			'uploads' => ActiveDownloadRepository::listActiveUploads(100),
			'now' => time(),
		]);
	}

	public static function handleAdminKillDownload()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id3')]);
			return;
		}

		if (Database::killActiveDownload($id)) {
			Database::logAudit('download_killed', 'active_download #' . $id);
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.kill_download_failed')]);
		}
	}

	/**
	 * Sever an upload in flight (pt 8/4).
	 *
	 * Works exactly like killing a download, and for the same reason: the tracking row is the
	 * handle. Deleting it makes the upload server stop reading that request's body within a
	 * second, close the connection and discard the partial file. Requires the upload server to
	 * be running, since it is the one holding the stream.
	 */
	public static function handleAdminKillUpload()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id3')]);
			return;
		}

		if (ActiveDownloadRepository::killUpload($id)) {
			Database::logAudit('upload_killed', 'active_upload #' . $id);
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.kill_download_failed')]);
		}
	}

	/* ---------------- User groups (A8) ---------------- */

	public static function handleAdminGroups()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		// Ship the permission catalogue alongside the rows so the group form renders its
		// checkboxes from one server-side source of truth instead of a JS copy that can drift.
		$groups = array_map(function ($g) {
			$g['permissions'] = Permissions::parse($g['permissions'] ?? '');
			return $g;
		}, Database::getGroups());

		echo json_encode([
			'success' => true,
			'groups' => $groups,
			'catalog' => [
				'file' => array_map(fn($k) => __($k), Permissions::FILE_PERMS),
				'filter' => array_map(fn($k) => __($k), Permissions::FILTER_PERMS),
				'collection' => array_map(fn($k) => __($k), Permissions::COLLECTION_PERMS),
				'cfilter' => array_map(fn($k) => __($k), Permissions::CFILTER_PERMS),
				'myfiles' => array_map(fn($k) => __($k), Permissions::MYFILES_PERMS),
				'ui' => array_map(fn($k) => __($k), Permissions::UI_PERMS),
				'mfilter' => array_map(fn($k) => __($k), Permissions::MFILTER_PERMS),
				'mycoll' => array_map(fn($k) => __($k), Permissions::MYCOLL_PERMS),
				'mcfilter' => array_map(fn($k) => __($k), Permissions::MCFILTER_PERMS),
				'ads' => array_map(fn($k) => __($k), Permissions::ADS_PERMS),
				'moderation' => array_map(fn($k) => __($k), Permissions::MODERATION_PERMS),
				'premium' => array_map(fn($k) => __($k), Permissions::PREMIUM_PERMS),
				// So the "what will this user get" preview (pt 11) can grey out staff-only
				// entries for a plain user without duplicating the rule in JS.
				'staffOnly' => Permissions::STAFF_ONLY,
			],
		]);
	}

	public static function handleAdminGroupSave()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;

		$result = Database::saveGroup($id, $input);
		if ($result['success']) {
			$perms = Permissions::normalize((array) ($input['permissions'] ?? []));
			Database::logAudit(
				$id ? 'group_updated' : 'group_created',
				'group: ' . trim($input['name'] ?? '') . ' (#' . $result['id'] . ')'
					. ($perms ? ' perms: ' . implode(' ', $perms) : '')
			);
		}
		echo json_encode($result);
	}

	public static function handleAdminGroupDelete()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');
		if (!requireRecentAuthentication()) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$id = (int) ($input['id'] ?? 0);
		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_group_id')]);
			return;
		}

		$result = Database::deleteGroup($id);
		if ($result['success']) {
			Database::logAudit('group_deleted', 'group #' . $id);
		}
		echo json_encode($result);
	}

	public static function handleAdminSetUserGroup()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$userId = (int) ($input['user_id'] ?? 0);
		// An empty / 0 group_id means "no explicit group" → resolves to the default group.
		$groupId = isset($input['group_id']) && $input['group_id'] !== '' && (int) $input['group_id'] > 0
			? (int) $input['group_id']
			: null;

		if (!$userId) {
			echo json_encode(['success' => false, 'error' => __('api.missing_user_id')]);
			return;
		}

		if (Database::setUserGroup($userId, $groupId)) {
			Database::logAudit('user_group_set', "user #$userId → group " . ($groupId ?? 'default'));
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.set_group_failed')]);
		}
	}

	public static function handleAdminDashboard()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$stats = FileManager::getStats();
		sendCachedJson([
			'success' => true,
			'stats' => $stats,
			// The traffic chart and the top-files ranking each have their own selectable
			// period, so both are fetched by their own endpoint (pt 5) — shipping a fixed
			// 7-day series here would just be a query nobody reads.
			'top_files' => Database::getTopFiles(5),
			'active_downloads' => Database::getActiveDownloadCount(),
			'traffic_today' => Database::getTrafficStats('day'),
			'traffic_month' => Database::getTrafficStats('month'),
		]);
	}

	/**
	 * Resolve the dashboard widget's period selector into a [from, to] pair of timestamps.
	 * `all` (and anything unrecognised) means no window at all — the all-time counter.
	 * `custom` reads `from`/`to` as Y-m-d dates, each optional and each snapped to the whole day.
	 */
	private static function resolvePeriod(string $period): array
	{
		$presets = [
			'week' => '-1 week', 'month' => '-1 month', '3months' => '-3 months',
			'6months' => '-6 months', 'year' => '-1 year',
		];
		if (isset($presets[$period])) {
			return [strtotime($presets[$period]), null];
		}
		if ($period === 'custom') {
			$from = trim((string) ($_GET['from'] ?? ''));
			$to = trim((string) ($_GET['to'] ?? ''));
			$fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : null;
			$toTs = $to !== '' ? strtotime($to . ' 23:59:59') : null;
			return [$fromTs ?: null, $toTs ?: null];
		}
		return [null, null];
	}

	/**
	 * Traffic series for a selectable range (pt 5).
	 *
	 * The dashboard chart used to be a fixed "last 7 days". It now works like a price chart:
	 * pick a span, and the resolution follows from it — hours for a day or two, days up to a few
	 * months, months beyond that — so a year of history is one readable chart rather than 365
	 * bars. The bucket may also be forced, for when the automatic choice is not what is wanted.
	 */
	public static function handleAdminTraffic()
	{
		if (!isset($_SESSION['user_id']) || !Permissions::has('moderation.traffic.view')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$range = (string) ($_GET['range'] ?? '7d');
		$now = time();
		$spans = [
			'24h' => 86400, '48h' => 172800, '7d' => 604800, '30d' => 2592000,
			'90d' => 7776000, '6m' => 15552000, '1y' => 31536000,
		];

		if ($range === 'custom') {
			$from = strtotime(trim((string) ($_GET['from'] ?? '')) . ' 00:00:00');
			$to = strtotime(trim((string) ($_GET['to'] ?? '')) . ' 23:59:59');
			if (!$from) {
				$from = $now - 604800;
			}
			if (!$to || $to <= $from) {
				$to = $now;
			}
		} else {
			$from = $now - ($spans[$range] ?? $spans['7d']);
			$to = $now;
		}

		// Cap the window at five years so a hand-written request can't ask the DB to seed an
		// unbounded number of buckets.
		$from = max($from, $now - (5 * 31536000));

		$span = $to - $from;
		$bucket = (string) ($_GET['bucket'] ?? '');
		if (!in_array($bucket, ['hour', 'day', 'month'], true)) {
			$bucket = $span <= 172800 ? 'hour' : ($span <= 10368000 ? 'day' : 'month');
		}

		echo json_encode([
			'success' => true,
			'range' => $range,
			'bucket' => $bucket,
			'from' => $from,
			'to' => $to,
			'series' => Database::getTrafficSeriesRange($from, $to, $bucket),
		]);
	}

	/** Top-downloaded files for a chosen period (dashboard widget's gear, pt 5). */
	public static function handleAdminTopFiles()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$period = (string) ($_GET['period'] ?? 'all');
		[$from, $to] = self::resolvePeriod($period);
		$limit = max(1, min(20, (int) ($_GET['limit'] ?? 5)));

		echo json_encode([
			'success' => true,
			'period' => $period,
			'from' => $from,
			'to' => $to,
			'top_files' => Database::getTopFiles($limit, $from, $to),
		]);
	}

	public static function handleAdminAuditLog()
	{
		if (!isset($_SESSION['user_id']) || !Permissions::has('moderation.audit.view')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');
		$page = max(1, (int) ($_GET['page'] ?? 1));
		$data = Database::getAuditLog($page, 30);
		sendCachedJson(['success' => true] + $data);
	}

	/** All collections across users, for the admin Files tab (C4). */
	public static function handleAdminCollections()
	{
		if (!isset($_SESSION['user_id']) || !Permissions::has('collections.view_all')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$page = max(1, (int) ($_GET['page'] ?? 1));
		$perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 20)));
		$result = Database::browseCollections([
			'page' => $page,
			'per_page' => $perPage,
			'search' => trim((string) ($_GET['search'] ?? '')),
			// Which fields the free-text term is allowed to reach. Same rule as the file list:
			// a session that cannot see owners or IPs cannot search by them either — otherwise
			// "does 1.2.3.4 upload here" is answerable by watching which rows come back.
			'search_owner' => Permissions::has('files.see_owner'),
			'search_ip' => Permissions::has('files.see_ip'),
			'sort' => (string) ($_GET['sort'] ?? 'created_at'),
			'order' => (string) ($_GET['order'] ?? 'DESC'),
			'filters' => self::authorisedCollectionFilters(),
		]);

		$showOwner = Permissions::has('files.see_owner');
		$appUrl = APP_URL;
		$cols = array_map(function ($c) use ($appUrl, $showOwner) {
			$userId = $c['user_id'] !== null ? (int) $c['user_id'] : null;
			return [
				'id' => $c['id'],
				'name' => $c['name'],
				// Same rule as the file list: a session without `files.see_owner` gets the
				// collections but not who they belong to.
				'owner' => $showOwner ? ($c['owner_name'] ?? null) : null,
				'userId' => $showOwner ? $userId : null,
				// A collection with a user_id but no matching account belongs to a deleted user.
				// Saying so beats rendering a bare dash that looks like a bug (pt 13).
				'ownerState' => !$showOwner ? 'hidden'
					: ($userId === null ? 'guest' : (($c['owner_name'] ?? null) === null ? 'deleted' : 'ok')),
				'fileCount' => (int) $c['file_count'],
				'totalSize' => (int) $c['total_size'],
				'downloads' => (int) $c['downloads'],
				'createdAt' => (int) $c['created_at'],
				'url' => $appUrl . '/collection.php?id=' . $c['id'],
				'zipUrl' => FileController::collectionZipUrl($c),
				// Sharing state, so the admin list can badge shares and pre-fill the edit modal.
				'hasPassword' => !empty($c['password_hash']),
				'expiresAt' => isset($c['expires_at']) ? (int) $c['expires_at'] : 0,
				'maxDownloads' => isset($c['max_downloads']) ? (int) $c['max_downloads'] : 0,
				'oneTime' => !empty($c['one_time']),
				'consumed' => !empty($c['one_time']) && !empty($c['consumed_at']),
				'onLimitAction' => $c['on_limit_action'] ?? 'keep',
				// Which member files the search term matched, so a row whose own name says
				// nothing about the query does not look like a false positive.
				'fileHits' => array_values($c['file_hits'] ?? []),
			];
		}, $result['collections']);

		sendCachedJson([
			'success' => true,
			'collections' => $cols,
			'total' => $result['total'],
			'page' => $page,
			'per_page' => $perPage,
		]);
	}

	/**
	 * The collection filters the session may actually use (pt 4), mirroring
	 * FileController::authorisedFilters. Anything the group does not hold is dropped rather
	 * than refused, so an over-broad request just gets a broader list — never a wider view.
	 */
	private static function authorisedCollectionFilters(): array
	{
		if (!Permissions::has('collections.filters')) {
			return [];
		}
		$raw = $_GET['filters'] ?? '';
		$in = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
		if (!is_array($in)) {
			return [];
		}

		$groups = [
			'cfilter.date'      => ['date_from', 'date_to'],
			'cfilter.size'      => ['size_min', 'size_max'],
			'cfilter.files'     => ['files_min', 'files_max'],
			'cfilter.downloads' => ['dl_min', 'dl_max'],
			'cfilter.user'      => ['users'],
			'cfilter.sharing'   => ['sharing'],
			'cfilter.empty'     => ['empty'],
		];

		$out = [];
		foreach ($groups as $perm => $keys) {
			if (!Permissions::has($perm)) {
				continue;
			}
			foreach ($keys as $k) {
				if (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null || $in[$k] === []) {
					continue;
				}
				$out[$k] = $in[$k];
			}
		}

		// Dates arrive as Y-m-d from the picker; the column stores timestamps.
		foreach (['date_from' => ' 00:00:00', 'date_to' => ' 23:59:59'] as $k => $suffix) {
			if (isset($out[$k]) && !is_numeric($out[$k])) {
				$ts = strtotime($out[$k] . $suffix);
				if ($ts) {
					$out[$k] = $ts;
				} else {
					unset($out[$k]);
				}
			}
		}
		// Sizes come in MiB (what the modal asks for), the sum is in bytes.
		foreach (['size_min', 'size_max'] as $k) {
			if (isset($out[$k])) {
				$out[$k] = (int) round(((float) $out[$k]) * 1024 * 1024);
			}
		}

		return $out;
	}

	/** Choice lists + allowed sections for the collection half of the filter modal (pt 4). */
	public static function handleCollectionFacets()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || !Permissions::has('collections.filters')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		echo json_encode([
			'success' => true,
			'owners' => Permissions::has('cfilter.user') && Permissions::has('files.see_owner')
				? Database::collectionOwnerFacets()
				: [],
			'allowed' => array_values(array_filter(
				array_keys(Permissions::CFILTER_PERMS),
				fn($p) => Permissions::has($p)
			)),
			'canDelete' => Permissions::has('collections.delete_all'),
		]);
	}

	/**
	 * Delete a batch of collections (pt 4) — what the "empty collections" filter is for.
	 *
	 * Only the collection rows and their membership links go; the member files are the users'
	 * own uploads and exist independently of any collection they happen to sit in.
	 */
	public static function handleAdminDeleteCollections()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || !Permissions::has('collections.delete_all')) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}

		try {
			$input = readBoundedJsonBody();
			$ids = sanitizeFileIdList($input['ids'] ?? [], FileController::COLLECTION_MAX_FILES);
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.too_many_files')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		if (!$ids) {
			echo json_encode(['success' => false, 'error' => __('api.no_files_selected')]);
			return;
		}

		$deleted = Database::deleteCollections($ids, null);
		Database::logAudit('collections_deleted', $deleted . ' of ' . count($ids));
		echo json_encode(['success' => true, 'deleted' => $deleted]);
	}

	/** Return the bounded count/size and a one-time nonce before destructive file cleanup. */
	public static function handleAdminCleanupPreview()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		try {
			$input = readBoundedJsonBody();
		} catch (LengthException $e) {
			http_response_code(413);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		} catch (UnexpectedValueException $e) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}

		$criteria = [
			'older_than_days' => $input['older_than_days'] ?? '',
			'larger_than_mb' => $input['larger_than_mb'] ?? '',
		];
		$preview = FileManager::previewCleanup($criteria);
		if (!$preview['valid']) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.cleanup_criteria_invalid')]);
			return;
		}
		if ($preview['count'] === 0) {
			echo json_encode([
				'success' => true, 'count' => 0, 'total_size' => 0, 'nonce' => '',
			]);
			return;
		}

		$now = time();
		$confirmations = is_array($_SESSION['cleanup_confirmations'] ?? null)
			? $_SESSION['cleanup_confirmations']
			: [];
		foreach ($confirmations as $key => $entry) {
			if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < $now) {
				unset($confirmations[$key]);
			}
		}
		while (count($confirmations) >= 5) {
			array_shift($confirmations);
		}
		$nonce = bin2hex(random_bytes(32));
		$fingerprint = hash('sha256', json_encode([
			$criteria, $preview['ids'], $preview['total_size'],
		], JSON_UNESCAPED_SLASHES));
		$confirmations[$nonce] = [
			'criteria' => $criteria,
			'ids' => $preview['ids'],
			'total_size' => $preview['total_size'],
			'fingerprint' => $fingerprint,
			'expires_at' => $now + self::CLEANUP_CONFIRM_TTL,
		];
		$_SESSION['cleanup_confirmations'] = $confirmations;

		echo json_encode([
			'success' => true,
			'count' => $preview['count'],
			'total_size' => $preview['total_size'],
			'nonce' => $nonce,
		]);
	}

	/** Consume the preview nonce and delete exactly the unchanged, previously shown batch. */
	public static function handleAdminCleanupExecute()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}
		try {
			$input = readBoundedJsonBody();
		} catch (Throwable $e) {
			http_response_code($e instanceof LengthException ? 413 : 400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_request')]);
			return;
		}
		$nonce = (string) ($input['nonce'] ?? '');
		$confirmations = is_array($_SESSION['cleanup_confirmations'] ?? null)
			? $_SESSION['cleanup_confirmations']
			: [];
		$confirmation = $confirmations[$nonce] ?? null;
		unset($confirmations[$nonce]);
		$_SESSION['cleanup_confirmations'] = $confirmations;
		if (
			!is_array($confirmation)
			|| (int) ($confirmation['expires_at'] ?? 0) < time()
		) {
			http_response_code(409);
			echo json_encode(['success' => false, 'error' => __('api.cleanup_preview_expired')]);
			return;
		}

		$fresh = FileManager::previewCleanup((array) $confirmation['criteria']);
		$fingerprint = hash('sha256', json_encode([
			$confirmation['criteria'], $fresh['ids'], $fresh['total_size'],
		], JSON_UNESCAPED_SLASHES));
		if (
			!$fresh['valid']
			|| !hash_equals((string) $confirmation['fingerprint'], $fingerprint)
		) {
			http_response_code(409);
			echo json_encode(['success' => false, 'error' => __('api.cleanup_preview_changed')]);
			return;
		}

		$deleted = FileManager::deleteCleanupCandidates((array) $confirmation['ids']);
		Database::logAudit(
			'files_cleanup',
			sprintf(
				'%d of %d files, previewed %d B',
				$deleted,
				count((array) $confirmation['ids']),
				(int) $confirmation['total_size']
			)
		);
		echo json_encode(['success' => true, 'deleted' => $deleted]);
	}

	/**
	 * Serve one of the project's own docs so the panel can render it in a modal instead of
	 * sending the admin hunting through the filesystem for a raw .md file.
	 *
	 * The name is looked up in a fixed whitelist and never touches the request string beyond
	 * that — no path is ever built from user input, so there is nothing to traverse out of.
	 */
	public static function handleAdminDoc()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$docs = [
			'storage' => ['file' => 'STORAGE.md', 'title' => __('panel.doc.storage_title')],
		];
		$key = (string) ($_GET['name'] ?? '');
		if (!isset($docs[$key])) {
			echo json_encode(['success' => false, 'error' => __('panel.doc.load_error')]);
			return;
		}

		$path = PROJECT_ROOT . '/docs/' . $docs[$key]['file'];
		$body = @file_get_contents($path);
		if ($body === false) {
			echo json_encode(['success' => false, 'error' => __('panel.doc.load_error')]);
			return;
		}

		echo json_encode([
			'success' => true,
			'title' => $docs[$key]['title'],
			'markdown' => $body,
		]);
	}

	/* ---------------- Language management (Faza 6 · #3) ---------------- */

	/** Installed languages with their enabled state and string counts. */
	public static function handleAdminLanguages()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		Lang::invalidateCache();
		$enabled = Lang::enabled();
		$switcher = Lang::forSwitcher();
		$forUsers = Lang::forUsers();
		$base = count(require self::LANG_DIR . '/pl.php');

		$langs = [];
		foreach (Lang::available() as $code => $name) {
			$strings = require self::LANG_DIR . '/' . $code . '.php';
			$count = is_array($strings) ? count($strings) : 0;
			$langs[] = [
				'code' => $code,
				'name' => $name,
				'enabled' => isset($enabled[$code]),
				// pt 6: where an enabled language actually shows up.
				'switcher' => isset($switcher[$code]),
				'users' => isset($forUsers[$code]),
				'builtIn' => in_array($code, Lang::BUILT_IN, true),
				'strings' => $count,
				// How complete the translation is against the reference language.
				'coverage' => $base > 0 ? (int) round(($count / $base) * 100) : 0,
			];
		}

		// The known-code table drives the "de → Deutsch" hint in the add-language form, and
		// tells it which codes are already installed so it doesn't suggest a duplicate.
		$known = [];
		foreach (Lang::NAMES as $code => $label) {
			$known[] = ['code' => $code, 'name' => $label, 'installed' => Lang::installed($code)];
		}

		echo json_encode([
			'success' => true,
			'languages' => $langs,
			'default' => (string) Database::getSetting('default_language', 'pl'),
			'known' => $known,
		]);
	}

	/**
	 * Switch a language on/off, or narrow where an enabled one appears (pt 6).
	 *
	 * `scope` picks the list being edited: `enabled` (the master allow-list — built-ins cannot
	 * leave it, or the fallback chain has nothing to land on), `switcher` (the header control)
	 * or `users` (the account setting and automatic Accept-Language matching). The latter two
	 * are presentation choices, so even a built-in may be hidden from them.
	 */
	public static function handleAdminLanguageToggle()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$code = strtolower(trim((string) ($input['code'] ?? '')));
		$on = !empty($input['enabled']);
		$scope = (string) ($input['scope'] ?? 'enabled');

		if (!Lang::installed($code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}

		$lists = [
			'enabled' => ['enabled_languages', fn() => array_keys(Lang::enabled())],
			'switcher' => [Lang::LIST_SWITCHER, fn() => array_keys(Lang::forSwitcher())],
			'users' => [Lang::LIST_USERS, fn() => array_keys(Lang::forUsers())],
		];
		if (!isset($lists[$scope])) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}
		[$settingKey, $currentFn] = $lists[$scope];

		if ($scope === 'enabled' && !$on && in_array($code, Lang::BUILT_IN, true)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_builtin')]);
			return;
		}

		// Store the positive list so a newly installed language stays off until enabled.
		$current = $currentFn();
		$next = $on
			? array_values(array_unique(array_merge($current, [$code])))
			: array_values(array_diff($current, [$code]));
		if ($scope === 'enabled') {
			$next = array_values(array_unique(array_merge($next, Lang::BUILT_IN)));
		}
		if (!$next) {
			// An empty list reads as "no restriction", which would silently re-show everything.
			// Refuse instead of quietly doing the opposite of what was asked.
			echo json_encode(['success' => false, 'error' => __('api.lang_last_one')]);
			return;
		}

		Database::setSetting($settingKey, implode(',', $next));
		Lang::invalidateCache();
		Database::logAudit('language_toggled', $code . " [{$scope}] → " . ($on ? 'on' : 'off'));
		echo json_encode(['success' => true]);
	}

	/**
	 * Copy an installed language to a new code (pt 6).
	 *
	 * The built-ins ship with the app and are what every other translation falls back to, so
	 * they are never overwritten by an upload. Duplicating is the supported way to base a
	 * translation on one of them — or to keep a customised wording of English alongside the
	 * original — and the copy starts switched off so it can be finished before it goes live.
	 */
	public static function handleAdminLanguageDuplicate()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$source = strtolower(trim((string) ($input['source'] ?? '')));
		$code = strtolower(trim((string) ($input['code'] ?? '')));

		if (!preg_match('/^[a-z]{2,3}$/', $source) || !Lang::installed($source)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}
		if (!preg_match('/^[a-z]{2,3}$/', $code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_code')]);
			return;
		}
		if (Lang::installed($code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_exists')]);
			return;
		}

		$strings = require self::LANG_DIR . '/' . $source . '.php';
		if (!is_array($strings) || !$strings) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_payload')]);
			return;
		}

		// Freeze the current allow-list first, so the copy does not go live the moment it exists
		// (an empty `enabled_languages` means "everything installed").
		Database::setSetting('enabled_languages', implode(',', array_keys(Lang::enabled())));

		if (!self::writeLanguageFile($code, $strings, __('panel.lang.copied_from', ['code' => strtoupper($source)]))) {
			echo json_encode(['success' => false, 'error' => __('api.lang_write_failed')]);
			return;
		}

		Lang::invalidateCache();
		Database::logAudit('language_duplicated', $source . ' → ' . $code);
		echo json_encode(['success' => true, 'code' => $code, 'strings' => count($strings)]);
	}

	/**
	 * Write `src/lang/<code>.php` from a validated string map.
	 *
	 * Always via var_export() of data this code has already vetted: a language file is `require`d
	 * on every request, so nothing an uploader wrote may reach it verbatim.
	 */
	private static function writeLanguageFile(string $code, array $strings, string $note): bool
	{
		if (count($strings) > 10000) {
			return false;
		}
		$body = "<?php\n"
			. "/**\n"
			. " * " . strtoupper($code) . " strings — written by the panel on " . date('Y-m-d H:i') . ".\n"
			. " *\n"
			. " * " . str_replace('*/', '', $note) . "\n"
			. " * Generated by AdminController from vetted data: the array literal below is written\n"
			. " * by the app, never supplied verbatim. Keys missing here fall back to the default\n"
			. " * language (see Lang::t).\n"
			. " */\n"
			. "return " . var_export($strings, true) . ";\n";
		if (strlen($body) > 4 * 1024 * 1024) {
			return false;
		}
		$target = self::LANG_DIR . '/' . $code . '.php';
		$temp = $target . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));
		try {
			if (@file_put_contents($temp, $body, LOCK_EX) !== strlen($body)) {
				return false;
			}
			$verified = require $temp;
			if (!is_array($verified) || $verified !== $strings) {
				return false;
			}
			@chmod($temp, 0640);
			if (!@rename($temp, $target)) {
				// Windows cannot always replace an existing file with rename(). Keep a
				// recoverable sibling backup while making the shortest possible swap.
				$backup = $target . '.bak.' . bin2hex(random_bytes(8));
				if (!is_file($target) || !@rename($target, $backup)) {
					return false;
				}
				if (!@rename($temp, $target)) {
					@rename($backup, $target);
					return false;
				}
				@unlink($backup);
			}
			return true;
		} catch (Throwable $e) {
			return false;
		} finally {
			if (is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	/**
	 * Install a language from an uploaded JSON file.
	 *
	 * JSON, not PHP, and deliberately so: a `src/lang/<code>.php` is `require`d on every
	 * request, so accepting one as an upload would be handing an admin-panel form a way to
	 * put arbitrary code on the include path. Instead the payload is parsed as data, every
	 * key/value is checked to be a flat string pair, and the file is written from
	 * var_export() — so what lands on disk is a literal array this code generated, never
	 * anything the uploader wrote.
	 */
	public static function handleAdminLanguageUpload()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$code = strtolower(trim((string) ($input['code'] ?? '')));
		$strings = $input['strings'] ?? null;

		// A strict code pattern is also what keeps the filename below inside src/lang/.
		if (!preg_match('/^[a-z]{2,3}$/', $code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_code')]);
			return;
		}
		// pt 6: the two shipped languages are the end of every fallback chain and the reference
		// the coverage figure is measured against. An upload must not replace one — a partial
		// file would silently hollow out the fallback for every other language. Duplicate it to
		// a free code instead (handleAdminLanguageDuplicate) and switch to that.
		if (in_array($code, Lang::BUILT_IN, true)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_builtin_upload')]);
			return;
		}
		if (!is_array($strings) || !$strings) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_payload')]);
			return;
		}

		$clean = [];
		if (count($strings) > 10000) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_payload')]);
			return;
		}
		$totalBytes = 0;
		foreach ($strings as $key => $value) {
			// Only flat "some.dotted.key" => "text" pairs; anything else is dropped rather
			// than written out.
			if (!is_string($key) || !is_string($value)
				|| strlen($key) > 160 || strlen($value) > 10000
				|| !preg_match('/^[a-z0-9_.]+$/i', $key)) {
				continue;
			}
			$totalBytes += strlen($key) + strlen($value);
			if ($totalBytes > 2 * 1024 * 1024) {
				echo json_encode(['success' => false, 'error' => __('api.lang_bad_payload')]);
				return;
			}
			$clean[$key] = $value;
		}
		if (!$clean) {
			echo json_encode(['success' => false, 'error' => __('api.lang_bad_payload')]);
			return;
		}

		// A newly uploaded translation is usually partial, so it starts switched off and the
		// admin enables it once they are happy with it. That needs an explicit allow-list: an
		// empty `enabled_languages` means "everything installed", which would publish it at
		// once — so on the first upload, freeze the current set before adding the file.
		$isNew = !Lang::installed($code);
		if ($isNew) {
			$currentlyEnabled = array_keys(Lang::enabled());
			Database::setSetting('enabled_languages', implode(',', $currentlyEnabled));
		}

		if (!self::writeLanguageFile($code, $clean, 'Installed from an uploaded JSON file.')) {
			echo json_encode(['success' => false, 'error' => __('api.lang_write_failed')]);
			return;
		}

		Lang::invalidateCache();
		Database::logAudit('language_installed', $code . ' (' . count($clean) . ' strings)');
		echo json_encode(['success' => true, 'code' => $code, 'strings' => count($clean), 'enabled' => !$isNew]);
	}

	/** Remove an installed language. Built-ins are not removable. */
	public static function handleAdminLanguageDelete()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');
		if (!requireRecentAuthentication()) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$code = strtolower(trim((string) ($input['code'] ?? '')));

		if (!preg_match('/^[a-z]{2,3}$/', $code) || !Lang::installed($code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}
		if (in_array($code, Lang::BUILT_IN, true)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_builtin')]);
			return;
		}

		if (!@unlink(self::LANG_DIR . '/' . $code . '.php')) {
			echo json_encode(['success' => false, 'error' => __('api.lang_write_failed')]);
			return;
		}

		// A deleted language must not stay the site default or linger in any allow-list.
		if ((string) Database::getSetting('default_language', 'pl') === $code) {
			Database::setSetting('default_language', 'pl');
		}
		Lang::invalidateCache();
		foreach ([
			'enabled_languages' => array_keys(Lang::enabled()),
			Lang::LIST_SWITCHER => array_keys(Lang::forSwitcher()),
			Lang::LIST_USERS => array_keys(Lang::forUsers()),
		] as $setting => $list) {
			// Only rewrite a list that was actually set: an untouched one is stored empty,
			// meaning "everything enabled", and must stay that way.
			if (trim((string) Database::getSetting($setting, '')) !== '') {
				Database::setSetting($setting, implode(',', array_values(array_diff($list, [$code]))));
			}
		}
		Lang::invalidateCache();

		Database::logAudit('language_deleted', $code);
		echo json_encode(['success' => true]);
	}

	/** Export a language as JSON — the starting point for a new translation. */
	public static function handleAdminLanguageExport()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		header('Content-Type: application/json');

		$code = strtolower(trim((string) ($_GET['code'] ?? 'pl')));
		if (!preg_match('/^[a-z]{2,3}$/', $code) || !Lang::installed($code)) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}

		$strings = require self::LANG_DIR . '/' . $code . '.php';
		echo json_encode([
			'success' => true,
			'code' => $code,
			'strings' => is_array($strings) ? $strings : [],
		]);
	}

	public static function handleAdminIPBans()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}

		header('Content-Type: application/json');
		$page = (int) ($_GET['page'] ?? 1);
		$bans = Database::getBlacklists($page, 20);

		echo json_encode(['success' => true] + $bans);
	}

	public static function handleAdminBanIP()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$type = $input['type'] ?? 'ip';
		$value = $input['value'] ?? $input['ip'] ?? '';
		$reason = $input['reason'] ?? '';
		$expiresIn = (int) ($input['expires_in'] ?? 0); // minutes? Let's assume input is exact timestamp or duration

		if (!$value) {
			echo json_encode(['success' => false, 'error' => __('api.value_required')]);
			return;
		}

		$expiresAt = ($expiresIn > 0) ? time() + ($expiresIn * 60) : null;
		$success = Database::addToBlacklist($type, $value, $expiresAt, $reason);

		if ($success) {
			Database::logAudit('ban_added', "$type: $value" . ($reason ? " ($reason)" : ''));
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.db_error2')]);
		}
	}

	public static function handleAdminUnbanIP()
	{
		if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$id = (int) ($input['id'] ?? 0);

		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.id_required')]);
			return;
		}

		if (Database::removeFromBlacklist($id)) {
			Database::logAudit('ban_removed', 'ban id: ' . $id);
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.db_error2')]);
		}
	}
}
