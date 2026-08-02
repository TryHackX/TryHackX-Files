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
			if (empty($captchaProof) || !Database::verifyUploadToken($captchaProof, $ip)) {
				// Note: We use verifyUploadToken because frontend likely obtains a proof/token, not raw captcha response string?
				// The frontend likely sends the raw g-recaptcha-response if it's the old modal, or the new proof token?
				// Let's check frontend. If the user says "Internal Error invalid CAPTCHA", it implies we are validating it wrong.
				// Assuming we switched everything to the unified "Get Token -> Verify Token" flow.
				// If not, we should probably stick to verifyRecaptcha if it IS a raw response.
				// BUT the new standard is: frontend resolves captcha -> calls verify_captcha -> gets a token -> sends token.
				echo json_encode(['success' => false, 'error' => __('api.captcha_invalid'), 'require_captcha' => true]);
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

		// Backend Validation
		$missing = [];
		if (empty($data['name']))
			$missing[] = 'Imię i nazwisko';
		if (empty($data['email']))
			$missing[] = 'Email';
		if (empty($data['entity']))
			$missing[] = 'Podmiot zgłaszający';
		if (empty($data['org']))
			$missing[] = 'Organizacja';
		if (empty($data['title']))
			$missing[] = 'Typ naruszenia';
		if (empty($data['info']))
			$missing[] = 'Dodatkowe informacje';

		if (!empty($missing)) {
			echo json_encode(['success' => false, 'error' => __('api.fill_required') . implode(', ', $missing) . '.']);
			return;
		}

		if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_email')]);

			return;
		}
		if (!self::safeHttpUrl($data['link'])) {
			http_response_code(422);
			echo json_encode(['success' => false, 'error' => __('api.bad_report_link')]);
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
			// Send email to $email with $reason
			if ($email) {
				$subject = "Aktualizacja zgłoszenia [#$reportId]";
				$message = "Twoje zgłoszenie dotyczące pliku: $fileName zostało zweryfikowane.\n\nID zgłoszenia: #$reportId\nTytuł zgłoszenia: $reportTitle\nStatus: Odrzucone\nPowód: $reason\n\nDziękujemy za zaangażowanie.\n\nZespół " . APP_NAME;
				Database::sendEmail(
					$email,
					$subject,
					$message,
					'report-rejected:' . $reportId
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

			if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$subject = "Aktualizacja zgłoszenia" . ($reportId ? " [#$reportId]" : "") . " - Podjęto działania";
				$message = "Twoje zgłoszenie dotyczące pliku: $fileName zostało zweryfikowane.\n\n";
				if ($reportId)
					$message .= "ID zgłoszenia: #$reportId\n";
				if ($reportTitle !== '-')
					$message .= "Tytuł zgłoszenia: $reportTitle\n";
				$message .= "Status: Zaakceptowane\nPlik nielegalny lub naruszający regulamin został usunięty.\n\nDziękujemy za zgłoszenie.\n\nZespół " . APP_NAME;
				Database::sendEmail(
					$email,
					$subject,
					$message,
					'report-accepted:' . ($reportId ?: $fileId)
				);
			}

			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.delete_file_failed')]);
		}
	}
}
