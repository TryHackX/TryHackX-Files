<?php
/** Signed Przelewy24 transaction and refund callbacks. */
final class P24Controller
{
	public static function handleNotify(): void
	{
		header('Content-Type: application/json');
		$body = self::body();
		if ($body === null || !P24::verifyNotification($body)) {
			http_response_code(400);
			Database::logAudit('p24_notify_rejected', 'invalid JSON or signature');
			echo json_encode(['success' => false]);
			return;
		}

		$sessionId = trim((string) ($body['sessionId'] ?? ''));
		$orderId = (int) ($body['orderId'] ?? 0);
		$amount = (int) ($body['amount'] ?? 0);
		$currency = strtoupper((string) ($body['currency'] ?? ''));
		PaymentRepository::recordProviderEvent(
			'przelewy24',
			P24::notificationEventId($body),
			$sessionId,
			'paid'
		);

		$payment = $sessionId !== '' ? PaymentRepository::byExtOrderId($sessionId) : null;
		if (!$payment || (string) ($payment['provider'] ?? '') !== 'przelewy24') {
			Database::logAudit('p24_notify_unknown_order', $sessionId);
			echo json_encode(['success' => true]);
			return;
		}
		if (in_array((string) ($payment['status'] ?? ''), [
			PaymentRepository::COMPLETED,
			PaymentRepository::REFUNDING,
			PaymentRepository::REFUNDED,
		], true)) {
			echo json_encode(['success' => true]);
			return;
		}
		$knownOrderId = (int) ($payment['provider_order_id'] ?? 0);
		if ($orderId < 1 || ($knownOrderId > 0 && $knownOrderId !== $orderId)
			|| $amount !== (int) $payment['amount_minor']
			|| $currency !== strtoupper((string) $payment['currency'])) {
			http_response_code(400);
			PaymentRepository::noteGrantError($sessionId, 'p24_notification_mismatch');
			echo json_encode(['success' => false]);
			return;
		}
		if ($knownOrderId < 1) {
			PaymentRepository::setProviderOrderId($sessionId, (string) $orderId);
			$payment['provider_order_id'] = (string) $orderId;
		}

		// P24 does not settle the transaction for the merchant until this explicit verification.
		if (!P24::verifyTransaction($sessionId, $orderId, $amount, $currency)) {
			http_response_code(503);
			echo json_encode(['success' => false]);
			return;
		}
		if (!PaymentReconciler::fulfil($payment, $amount, $currency, 'p24-notify')) {
			$fresh = PaymentRepository::byExtOrderId($sessionId);
			if (($fresh['status'] ?? '') !== PaymentRepository::COMPLETED) {
				http_response_code(503);
				echo json_encode(['success' => false]);
				return;
			}
		}
		echo json_encode(['success' => true]);
	}

	public static function handleRefundNotify(): void
	{
		header('Content-Type: application/json');
		$body = self::body();
		if ($body === null || !P24::verifyRefundNotification($body)) {
			http_response_code(400);
			Database::logAudit('p24_refund_notify_rejected', 'invalid JSON or signature');
			echo json_encode(['success' => false]);
			return;
		}

		$sessionId = trim((string) ($body['sessionId'] ?? ''));
		$payment = $sessionId !== '' ? PaymentRepository::byExtOrderId($sessionId) : null;
		PaymentRepository::recordProviderEvent(
			'p24-refund',
			P24::refundEventId($body),
			$sessionId,
			(int) ($body['status'] ?? -1) === 0 ? 'completed' : 'rejected'
		);
		if (!$payment || (string) ($payment['provider'] ?? '') !== 'przelewy24') {
			Database::logAudit('p24_refund_unknown_order', $sessionId);
			echo json_encode(['success' => true]);
			return;
		}
		if ((string) ($payment['status'] ?? '') === PaymentRepository::REFUNDED) {
			echo json_encode(['success' => true]);
			return;
		}

		$context = PaymentRepository::refundContext($payment);
		$token = (string) ($context['token'] ?? '');
		$matches = $token !== ''
			&& hash_equals((string) ($context['refundsUuid'] ?? ''), (string) ($body['refundsUuid'] ?? ''))
			&& (int) ($payment['provider_order_id'] ?? 0) === (int) ($body['orderId'] ?? 0)
			&& (int) $payment['amount_minor'] === (int) ($body['amount'] ?? 0)
			&& strtoupper((string) $payment['currency']) === strtoupper((string) ($body['currency'] ?? ''));
		if (!empty($body['requestId']) && !hash_equals(
			(string) ($context['requestId'] ?? ''),
			(string) $body['requestId']
		)) {
			$matches = false;
		}
		if (!$matches) {
			http_response_code(409);
			Database::logAudit('p24_refund_mismatch', $sessionId);
			echo json_encode(['success' => false]);
			return;
		}

		if ((int) ($body['status'] ?? -1) !== 0) {
			PaymentRepository::releaseRefund($sessionId, $token);
			Database::logAudit('p24_refund_rejected', $sessionId);
			echo json_encode(['success' => true]);
			return;
		}

		$snapshot = json_decode((string) ($payment['product_snapshot'] ?? ''), true);
		$product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
		$isPlan = ($payment['kind'] ?? '') === PaymentRepository::KIND_PURCHASE;
		$expectedGroupId = $isPlan ? (int) ($product['group_id'] ?? 0) : 0;
		$finalized = PaymentRepository::finalizeRefund($sessionId, $token, $expectedGroupId);
		if (empty($finalized['success'])) {
			http_response_code(503);
			echo json_encode(['success' => false]);
			return;
		}

		$userId = (int) ($finalized['user_id'] ?? 0);
		$name = (string) ($product['name'] ?? $sessionId);
		if ($isPlan && !empty($finalized['revoked'])) {
			$actorId = (int) ($context['actorId'] ?? 0);
			if ($actorId > 0) {
				PaymentRepository::recordAdminAction(
					PaymentRepository::KIND_ADMIN_REVOKE,
					(int) ($payment['plan_id'] ?? 0),
					$userId,
					$actorId
				);
			}
			Notifications::send($userId, 'plan.revoked', [
				'subject' => $name,
				'data' => ['until' => __('mail.plan_no_expiry')],
				'link' => APP_URL . '/panel.php?tab=premium',
			]);
		}
		Notifications::send($userId, 'payment.refunded', [
			'subject' => $name,
			'data' => [
				'amount' => number_format(((int) $payment['amount_minor']) / 100, 2, ',', ' ')
					. ' ' . $payment['currency'],
			],
			'link' => APP_URL . '/panel.php?tab=' . ($isPlan ? 'premium' : 'myads'),
		]);
		Database::logAudit(
			'p24_refund_completed',
			$sessionId . (!empty($finalized['revoked']) ? ' + revoke' : ''),
			$userId ?: null
		);
		echo json_encode(['success' => true]);
	}

	private static function body(): ?array
	{
		$raw = (string) file_get_contents('php://input');
		if ($raw === '' || strlen($raw) > 65536) {
			return null;
		}
		$body = json_decode($raw, true);
		return is_array($body) ? $body : null;
	}
}
