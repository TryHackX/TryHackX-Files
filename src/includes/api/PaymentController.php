<?php
/**
 * PaymentController (pt 10) — the two ends of a real payment.
 *
 * `checkout` sends a signed-in buyer to the provider; `payu_notify` receives the provider's
 * verdict and grants the plan. Between them sits a `payments` row, which is what makes the
 * second able to trust the first.
 *
 * This does not replace `premium_activate` — that endpoint is still how an operator wires up
 * Stripe, Gumroad or a script of their own, and it remains the only way in for anything this
 * project has no client for. What it replaces is the "write your own bridge script" note in
 * the PayU preset, which was the honest answer right up until there were credentials to test
 * against.
 *
 * Fulfilment goes through `PlanRepository::grant()` in-process rather than by calling
 * `premium_activate` over HTTP: same code path, one less shared secret in flight, and no
 * dependency on the instance being able to reach itself.
 */
final class PaymentController
{
	/**
	 * Start a checkout: `?plan=<id>`.
	 *
	 * Requires a session, because the entire product is "this account gets that group" — an
	 * anonymous purchase would have nothing to grant. Redirects the buyer to the provider on
	 * success and back to the plans page with an error code otherwise, since this is a browser
	 * navigation and not an API call.
	 */
	public static function handleCheckout()
	{
		$back = APP_URL . '/premium.php';

		if (!PremiumController::isEnabled()) {
			self::bounce($back, 'disabled');
		}
		if (empty($_SESSION['user_id'])) {
			// Nothing to grant without an account; the plans page prompts for sign-in.
			self::bounce($back, 'login');
		}

		$plan = PlanRepository::get((int) ($_GET['plan'] ?? 0));
		if (!$plan || empty($plan['enabled'])) {
			self::bounce($back, 'plan');
		}
		// A showcase card (`free` / `guest`) describes a tier nobody pays for. The page renders
		// no button for it, but the checkout URL is just a plan id in a query string — so the
		// refusal belongs here, where it cannot be routed around.
		if (($plan['kind'] ?? 'paid') !== 'paid') {
			self::bounce($back, 'plan');
		}
		if (empty($plan['group_id'])) {
			self::bounce($back, 'plan');
		}

		$amount = (int) ($plan['amount_minor'] ?? 0);
		if ($amount <= 0) {
			// The plan has display copy but no chargeable amount — an operator oversight, not
			// something to improvise a number for.
			self::bounce($back, 'amount');
		}
		$provider = PaymentPlugins::builtinProvider();
		if (($provider === 'przelewy24' && !P24::isConfigured())
			|| ($provider === 'payu' && !PayU::isConfigured())) {
			self::bounce($back, 'unconfigured');
		}

		$user = Database::getUserById((int) $_SESSION['user_id']);
		if (!$user) {
			self::bounce($back, 'login');
		}

		// A promo code rides the URL (runda 9): validated here, priced here, but REDEEMED
		// only at fulfilment — a failed payment must not spend one of the code's uses. An
		// invalid code bounces rather than silently charging full price.
		$promo = null;
		$promoCode = trim((string) ($_GET['code'] ?? ''));
		if ($promoCode !== '') {
			$promo = PromoCodeRepository::validate($promoCode, (int) $plan['id']);
			if (!$promo) {
				self::bounce($back, 'code');
			}
			$amount = PromoCodeRepository::discountedAmount($promo, $amount);
		}

		$currency = strtoupper((string) ($plan['currency'] ?? 'PLN'));
		$extOrderId = PaymentRepository::start(
			$provider,
			(int) $plan['id'],
			(int) $user['id'],
			$amount,
			$currency,
			$promo
		);
		if ($extOrderId === null) {
			self::bounce($back, 'internal');
		}

		$order = [
			'ext_order_id' => $extOrderId,
			'amount_minor' => $amount,
			'currency' => $currency,
			'description' => (string) $plan['name'],
			'customer_ip' => getClientIP(),
			'buyer_email' => (string) $user['email'],
			'buyer_name' => (string) $user['username'],
			'language' => Lang::current(),
			'notify_url' => APP_URL . '/api.php?action=' . ($provider === 'przelewy24' ? 'p24_notify' : 'payu_notify'),
			'continue_url' => APP_URL . '/premium.php?order=' . urlencode($extOrderId),
		];
		$res = $provider === 'przelewy24' ? P24::createOrder($order) : PayU::createOrder($order);

		if (empty($res['success'])) {
			PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
			Database::logAudit('payment_start_failed', $provider . ', plan #' . $plan['id'] . ', ' . ($res['error'] ?? '?'), (int) $user['id']);
			self::bounce($back, 'provider');
		}

		if (!empty($res['orderId'])) {
			PaymentRepository::setProviderOrderId($extOrderId, (string) $res['orderId']);
		}
		PaymentRepository::setStatus($extOrderId, PaymentRepository::PENDING);
		Database::logAudit('payment_started', $provider . ', plan #' . $plan['id'] . ', order ' . $extOrderId, (int) $user['id']);

		header('Location: ' . $res['redirectUri']);
		exit;
	}

	/**
	 * PayU's order notification.
	 *
	 * Three rules, in order of importance:
	 *   1. **Verify before reading.** The signature is checked against the raw body before the
	 *      JSON is trusted for anything. An unsigned or badly signed call is a stranger asking
	 *      for a free plan.
	 *   2. **Grant once.** `claimForGrant()` is an atomic claim, so PayU's retries (and its
	 *      several status updates per order) cannot pay out twice.
	 *   3. **Answer 200 whenever the message was understood.** PayU retries anything else for
	 *      days. A PENDING status is understood — it just is not a reason to grant.
	 */
	public static function handlePayuNotify()
	{
		header('Content-Type: application/json');

		$raw = (string) file_get_contents('php://input');
		$signature = (string) ($_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? $_SERVER['HTTP_X_OPENPAYU_SIGNATURE'] ?? '');

		if (!PayU::verifyNotification($raw, $signature)) {
			http_response_code(400);
			Database::logAudit('payu_notify_rejected', 'bad signature, ' . strlen($raw) . ' bytes');
			echo json_encode(['success' => false]);
			return;
		}

		$body = json_decode($raw, true) ?: [];
		$order = $body['order'] ?? [];
		$extOrderId = (string) ($order['extOrderId'] ?? '');
		$status = strtoupper((string) ($order['status'] ?? ''));
		PaymentRepository::recordProviderEvent(
			'payu',
			hash('sha256', $raw),
			$extOrderId,
			$status
		);

		if ($extOrderId === '') {
			// A signed message we have no use for (a refund notification, say). Understood.
			http_response_code(200);
			echo json_encode(['success' => true]);
			return;
		}

		$payment = PaymentRepository::byExtOrderId($extOrderId);
		if (!$payment) {
			http_response_code(200);
			Database::logAudit('payu_notify_unknown_order', $extOrderId);
			echo json_encode(['success' => true]);
			return;
		}

		if ($status !== 'COMPLETED') {
			PaymentRepository::setStatus(
				$extOrderId,
				in_array($status, ['CANCELED', 'REJECTED'], true) ? PaymentRepository::CANCELED : PaymentRepository::PENDING
			);
			http_response_code(200);
			echo json_encode(['success' => true]);
			return;
		}

		// From here the notification and the polling path are the same code: same amount check,
		// same atomic claim, same grant. Whichever arrives first wins; the other is a no-op.
		PaymentReconciler::fulfil(
			$payment,
			(int) ($order['totalAmount'] ?? 0),
			(string) ($order['currencyCode'] ?? ''),
			'notify'
		);

		http_response_code(200);
		echo json_encode(['success' => true]);
	}

	/** Send the browser back to the plans page with a code the page can explain. */
	private static function bounce(string $url, string $code): never
	{
		header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . 'err=' . urlencode($code));
		exit;
	}
}
