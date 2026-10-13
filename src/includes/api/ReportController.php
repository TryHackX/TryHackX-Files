<?php
/**
 * ReportController (Faza 5 · #1).
 *
 * Abuse-report endpoints: public submission (with report-throttle CAPTCHA) and the
 * admin/moderator moderation queue (list, details, reject, delete-file). Handler bodies
 * are the former api.php handleXxx functions, moved verbatim; the router dispatches here.
 */
final class ReportController
{
	private static function text(mixed $value, int $maxLength): string
	{
		$value = trim(is_scalar($value) ? (string) $value : '');
		return mb_substr($value, 0, $maxLength, 'UTF-8');
	}

	private static function safeHttpUrl(string $value): bool
	{
		if ($value === '') {
			return true;
		}
		if (strlen($value) > 255 || preg_match('/[\x00-\x20\x7f]/', $value)) {
			return false;
		}
		$parts = parse_url($value);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}
		$scheme = strtolower((string) $parts['scheme']);
		return in_array($scheme, ['http', 'https'], true)
			&& !isset($parts['user'])
			&& !isset($parts['pass'])
			&& filter_var($value, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * Text for a reporter's e-mail. The reporter has no account and therefore no saved
	 * language, so the installation's default language is the best guess — not whichever
	 * language the moderator who resolved the report happens to use.
	 */
	public static function reporterText(string $key, array $params): string
	{
		return Lang::translateFor(self::reporterLanguage(), $key, $params);
	}

	private static function reporterLanguage(): string
	{
		return (string) Database::getSetting('default_language', 'pl');
	}

	private static function requirePermission(string $permission): bool
	{
		if (empty($_SESSION['user_id']) || !Permissions::has($permission)) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.unauthorized')]);
			return false;
		}
		return true;
	}

	public static function handleReportFile()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true);
		if (!is_array($input)) {
			http_response_code(400);
			echo json_encode(['success' => false, 'error' => __('api.invalid_json')]);
			return;
		}

		$fileId = self::text($input['file_id'] ?? '', 32);
		$captchaProof = $input['captcha_proof'] ?? $input['captcha_response'] ?? ''; // Accept both for verification
		$ip = getClientIP();

		if (!FileManager::isValidFileId($fileId)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.missing_file_id')]);
			return;
		}

		// Check thresholds for CAPTCHA
		$reportThresholdCount = (int) Database::getSetting('recaptcha_report_threshold_count', 5);
		$recaptchaReportRequired = false;

		if ($reportThresholdCount !== -1) {
			// New unified window setting
			$windowName = 'report_submission';
			// Actually, Database::getReportCount uses specific logic.
			// We should probably rely on report_threshold_time which is now synced with security_window
			$reportWindow = (int) Database::getSetting('recaptcha_security_window', 60);

			$userReportCount = Database::getReportCount($ip, $reportWindow);
			if ($userReportCount >= $reportThresholdCount) {
				$recaptchaReportRequired = true;
			}
		}

		if ($recaptchaReportRequired && Database::isRecaptchaEnabled()) {
			// The page solves the CAPTCHA through `verify_captcha` and sends the proof token it
			// got back, the same flow as downloads and deletions.
			if (empty($captchaProof) || !Database::verifyUploadToken($captchaProof, $ip)) {
				echo json_encode([
					'success' => false,
					'error' => __('api.captcha_invalid'),
					'error_kind' => 'captcha',
					'require_captcha' => true,
				]);
				return;
			}
			// Valid proof -> Consume it
			Database::deleteUploadToken($captchaProof);
			// Mark report flow as verified to "reset" the rolling window counter
			Database::markReportVerified($ip);
		}

		$data = [
			'name' => self::text($input['name'] ?? '', 100),
			'email' => self::text($input['email'] ?? '', 255),
			'entity' => self::text($input['entity'] ?? '', 255), // E.g. Copyright Holder
			'org' => self::text($input['org'] ?? '', 255),       // E.g. Company Name
			'title' => self::text($input['title'] ?? '', 255),   // Type of violation, e.g. "Copyright Infringement"
			'link' => self::text($input['link'] ?? '', 255),
			'info' => self::text($input['info'] ?? '', 20000)
		];

		// Backend validation. `error_kind` tells the page what sort of refusal this is; the
		// wording is translated and must never be what the client matches on.
		$missing = [];
		foreach (['name', 'email', 'entity', 'org', 'title', 'info'] as $field) {
			if ($data[$field] === '') {
				$missing[] = __('report.field_' . $field);
			}
		}

		if (!empty($missing)) {
			echo json_encode([
				'success' => false,
				'error' => __('api.fill_required') . implode(', ', $missing) . '.',
				'error_kind' => 'validation',
			]);
			return;
		}

		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_email'), 'error_kind' => 'validation']);
			return;
		}
		if (!self::safeHttpUrl($data['link'])) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.bad_report_link'), 'error_kind' => 'validation']);
			return;
		}

		// Use addReport which matches the schema better
		$result = Database::addReport($fileId, $data);

		if ($result['success']) {
			// Moderation has a queue and until now nothing told anyone it had grown. Stacked per
			// file, because ten complaints about one upload are one thing to look at.
			$staffIds = array_values(array_diff(
				Notifications::staffIds(),
				[(int) ($_SESSION['user_id'] ?? 0)]
			));
			Notifications::sendMany($staffIds, 'report.new', [
				'subject' => (string) ($data['title'] ?? $fileId),
				'group' => 'report.new:' . $fileId,
				'link' => APP_URL . '/panel.php?tab=moderate',
			]);
			// Send email if needed (addReport might not send it, checks later)
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to save report']);
		}
	}

	public static function handleGetReportConfig()
	{
		header('Content-Type: application/json');

		$ip = getClientIP();
		// Default config
		$reportThresholdCount = (int) Database::getSetting('recaptcha_report_threshold_count', 3);
		$reportWindow = (int) Database::getSetting('recaptcha_security_window', 60);

		$requireCaptcha = false;

		if ($reportThresholdCount !== -1) {
			$userReportCount = Database::getReportCount($ip, $reportWindow);
			if ($userReportCount >= $reportThresholdCount) {
				$requireCaptcha = true;
			}
		}

		echo json_encode(['success' => true, 'require_captcha' => $requireCaptcha]);
	}

	public static function handleGetReportedFiles()
	{
		if (!self::requirePermission('moderation.reports.view')) {
			return;
		}

		header('Content-Type: application/json');
		$page = (int) ($_GET['page'] ?? 1);
		$limit = 20;

		$data = Database::getReportedFiles($page, $limit);
		sendCachedJson(['success' => true] + $data);
	}

	public static function handleGetReportDetails()
	{
		if (!self::requirePermission('moderation.reports.view')) {
			return;
		}

		$id = (int) ($_GET['id'] ?? 0);
		if (!$id) {
			echo json_encode(['success' => false, 'error' => __('api.missing_id2')]);
			return;
		}

		$details = Database::getReportDetails($id);
		if ($details) {
			echo json_encode(['success' => true, 'report' => $details]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.report_not_found')]);
		}
	}

	public static function handleRejectReport()
	{
		if (!self::requirePermission('moderation.reports.resolve')) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$reportId = (int) ($input['report_id'] ?? 0);
		$reason = self::text($input['reason'] ?? '', 2000);

		if (!$reportId) {
			echo json_encode(['success' => false, 'error' => __('api.missing_report_id')]);
			return;
		}

		// Fetch details for email
		$details = Database::getReportDetails($reportId);
		if (!$details) {
			http_response_code(404);
			echo json_encode(['success' => false, 'error' => __('api.report_not_found')]);
			return;
		}
		// Never trust a client-provided recipient for an administrative mail.
		$email = self::text($details['reporter_email'] ?? '', 255);
		$reportTitle = $details['report_title'] ?? '-';
		$fileName = $details['original_name'] ?? '-';

		if (Database::rejectReport($reportId)) {
			if ($email) {
				$params = [
					'id' => $reportId,
					'file' => $fileName,
					'title' => $reportTitle,
					'reason' => $reason !== '' ? $reason : '-',
					'app' => APP_NAME,
				];
				MailService::send(
					$email,
					self::reporterText('report.mail_subject_rejected', $params),
					self::reporterText('report.mail_body_rejected', $params),
					'report-rejected:' . $reportId,
					self::reporterLanguage()
				);
			}
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.db_error2')]);
		}
	}

	public static function handleDeleteReportedFile()
	{
		if (!self::requirePermission('moderation.files.delete')) {
			return;
		}
		if (!requireRecentAuthentication()) {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$fileId = self::text($input['file_id'] ?? '', 32);
		$reportId = (int) ($input['report_id'] ?? 0); // Optional, to also close the report
		$email = '';

		if (!FileManager::isValidFileId($fileId)) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.missing_file_id2')]);
			return;
		}

		// Fetch details before deletion for email
		$reportTitle = '-';
		$fileName = '-';

		if ($reportId) {
			$details = Database::getReportDetails($reportId);
			if (!$details) {
				http_response_code(404);
				echo json_encode(['success' => false, 'error' => __('api.report_not_found')]);
				return;
			}
			if (!hash_equals((string) ($details['file_id'] ?? ''), $fileId)) {
				http_response_code(409);
				echo json_encode(['success' => false, 'error' => __('api.report_file_mismatch')]);
				return;
			}
			$reportTitle = $details['report_title'] ?? '-';
			$fileName = $details['original_name'] ?? '-';
			$email = self::text($details['reporter_email'] ?? '', 255);
		} elseif ($fileId) {
			$file = FileManager::getFile($fileId);
			if ($file)
				$fileName = $file['name'] ?? '-';
		}

		// Delete file
		if (FileManager::deleteFileAdmin($fileId)) {
			// Also remove report if ID provided
			if ($reportId) {
				Database::rejectReport($reportId); // Actually this just deletes the report row, which is what we want
			}

			// $email is only ever set from a stored report, so $reportId is known here.
			if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$params = [
					'id' => $reportId,
					'file' => $fileName,
					'title' => $reportTitle,
					'app' => APP_NAME,
				];
				MailService::send(
					$email,
					self::reporterText('report.mail_subject_accepted', $params),
					self::reporterText('report.mail_body_accepted', $params),
					'report-accepted:' . ($reportId ?: $fileId),
					self::reporterLanguage()
				);
			}

			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.delete_file_failed')]);
		}
	}
}
