<?php

require_once PROJECT_ROOT . '/src/includes/api/ApiSupport.php';
require_once PROJECT_ROOT . '/src/includes/api/AuthController.php';
require_once PROJECT_ROOT . '/src/includes/api/FileController.php';
require_once PROJECT_ROOT . '/src/includes/api/AdminController.php';
require_once PROJECT_ROOT . '/src/includes/api/ReportController.php';
require_once PROJECT_ROOT . '/src/includes/Markdown.php';
require_once PROJECT_ROOT . '/src/includes/api/PremiumController.php';
require_once PROJECT_ROOT . '/src/includes/api/PaymentController.php';
require_once PROJECT_ROOT . '/src/includes/api/P24Controller.php';
require_once PROJECT_ROOT . '/src/includes/api/NotificationController.php';
require_once PROJECT_ROOT . '/src/includes/api/AdsController.php';
require_once PROJECT_ROOT . '/src/includes/api/RoutePolicy.php';

final class ApiRoutePolicyTest extends RepoTestCase
{
	private array $routes;

	protected function setUp(): void
	{
		$this->routes = require PROJECT_ROOT . '/src/includes/api/routes.php';
	}

	public function testEveryControllerHandlerHasADeclarativeRouteAndAliasesAreExplicit(): void
	{
		$controllers = [
			FileController::class,
			AuthController::class,
			AdminController::class,
			ReportController::class,
			PremiumController::class,
			PaymentController::class,
			P24Controller::class,
			NotificationController::class,
			AdsController::class,
		];
		$declaredHandlers = [];
		foreach ($controllers as $controller) {
			$reflection = new ReflectionClass($controller);
			foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
				if (str_starts_with($method->getName(), 'handle')) {
					$declaredHandlers[] = $controller . '::' . $method->getName();
				}
			}
		}
		sort($declaredHandlers);

		$mappedHandlers = array_map(
			static fn(array $route): string => $route['handler'][0] . '::' . $route['handler'][1],
			array_values($this->routes)
		);
		$counts = array_count_values($mappedHandlers);
		$duplicates = array_filter($counts, static fn(int $count): bool => $count > 1);
		$mappedHandlers = array_keys($counts);
		sort($mappedHandlers);

		$this->assertCount(
			count($declaredHandlers),
			array_diff_key($this->routes, ['__revert' => true]),
			'The explicit config alias balances the internal __revert route excluded here'
		);
		$this->assertSame($declaredHandlers, $mappedHandlers);
		$this->assertSame(['AuthController::handleConfig' => 2], $duplicates);
	}

	public function testFullMethodMatrixDefaultsToDenyAndReturnsAllowSet(): void
	{
		$methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
		foreach ($this->routes as $action => $route) {
			if ($action === '__revert') {
				continue;
			}
			foreach ($methods as $method) {
				$decision = ApiRoutePolicy::resolve($this->routes, $action, $method);
				$expected = in_array($method, $route['methods'], true) ? 200 : 405;
				$this->assertSame($expected, $decision['status'], $action . ' ' . $method);
				$this->assertSame($route['methods'], $decision['allow'], $action . ' Allow');
			}
		}

		$this->assertSame(404, ApiRoutePolicy::resolve($this->routes, 'not_declared', 'GET')['status']);
		$this->assertSame(404, ApiRoutePolicy::resolve($this->routes, '', 'GET')['status']);
		$this->assertSame(404, ApiRoutePolicy::resolve($this->routes, '__revert', 'DELETE')['status']);
		$this->assertSame(200, ApiRoutePolicy::resolve($this->routes, '', 'DELETE')['status']);
	}

	public function testAllBrowserWritesAreCsrfProtectedUnlessExplicitlySelfAuthorising(): void
	{
		$explicitExceptions = [
			'delete_link',       // one-time capability-bound form nonce
			'premium_activate',  // shared bearer secret
			'payu_notify',       // provider signature
			'p24_notify',        // provider SHA-384 signature
			'p24_refund_notify', // provider SHA-384 signature
			'ad_track',          // opaque active-creative event, no account authority
		];

		foreach ($this->routes as $action => $route) {
			if (!array_intersect($route['methods'], ['POST', 'PUT', 'PATCH', 'DELETE'])) {
				continue;
			}
			if (in_array($action, $explicitExceptions, true)) {
				$this->assertFalse($route['csrf'], $action);
			} else {
				$this->assertTrue($route['csrf'], $action);
			}
		}
	}

	public function testCheckoutRoutesArePostOnlyIdempotentAndUseSeeOther(): void
	{
		foreach (['checkout', 'ad_checkout'] as $action) {
			$route = $this->routes[$action];
			$this->assertSame(['POST'], $route['methods']);
			$this->assertTrue($route['csrf']);
			$this->assertTrue($route['auth']);
			$this->assertTrue($route['idempotency']);
			$this->assertSame(303, $route['redirect_status']);
		}
	}

	public function testIdempotencyKeyCanBeReservedOnlyOnce(): void
	{
		$_SESSION['api_idempotency_keys'] = [];
		$_POST = ['_idempotency_key' => 'checkout-test-key-1234567890'];
		unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);

		$route = $this->routes['checkout'];
		$this->assertTrue(ApiRoutePolicy::reserveIdempotencyKey($route));
		$this->assertFalse(ApiRoutePolicy::reserveIdempotencyKey($route));

		$_POST = ['_idempotency_key' => 'short'];
		$this->assertFalse(ApiRoutePolicy::reserveIdempotencyKey($route));
	}

	/**
	 * Actions that hand out privileges, take accounts over or rewire payments and the page
	 * policy need a password typed in the last few minutes — enforced by the route table, so a
	 * session restored from a device cookie cannot reach them through the API either.
	 */
	public function testPrivilegeAndPaymentConfigurationRoutesRequireRecentAuthentication(): void
	{
		$sensitive = [
			'admin_create_user',
			'admin_update_user',
			'admin_group_save',
			'admin_set_user_group',
			'admin_plan_save',
			'admin_plan_grant',
			'admin_premium_token',
			'admin_payment_plugin_save',
			'admin_ads_settings',
			'admin_notification_broadcast',
		];
		foreach ($this->routes as $action => $route) {
			$this->assertSame(
				in_array($action, $sensitive, true),
				!empty($route['recent_auth']),
				$action
			);
		}
		foreach ($sensitive as $action) {
			$this->assertSame(['POST'], $this->routes[$action]['methods'], $action);
			$this->assertTrue($this->routes[$action]['auth'], $action);
			$this->assertTrue($this->routes[$action]['csrf'], $action);
		}
		$this->assertTrue($this->routes['user_reauth']['csrf']);
		$this->assertTrue($this->routes['user_reauth']['auth']);
		$this->assertSame('auth', $this->routes['user_reauth']['rate_limit']);
	}

	public function testRecentAuthenticationOnlyFitsASignedInProtectedWrite(): void
	{
		$this->expectException(LogicException::class);
		ApiRoutePolicy::route([AuthController::class, 'handleConfig'], ['POST'], true, false, null, 'write', false, null, true);
	}

	public function testReadOnlyChecksNoLongerSpendTheSignInBudget(): void
	{
		$this->assertSame('lookup', $this->routes['check_username']['rate_limit']);
		$this->assertSame('lookup', $this->routes['check_email']['rate_limit']);
		$this->assertSame('read', $this->routes['user_2fa_status']['rate_limit']);
		$this->assertSame('read', $this->routes['user_remember_devices']['rate_limit']);
		foreach (['user_check', 'stats', 'get_user_stats'] as $removed) {
			$this->assertArrayNotHasKey($removed, $this->routes, $removed . ' had no caller and is gone');
		}
	}

	public function testDestructiveActionsRequireFreshAuthentication(): void
	{
		$previous = $_SESSION['recent_auth_at'] ?? null;
		try {
			unset($_SESSION['recent_auth_at']);
			$this->assertFalse(hasRecentAuthentication());
			$_SESSION['recent_auth_at'] = time() - 601;
			$this->assertFalse(hasRecentAuthentication());
			$_SESSION['recent_auth_at'] = time();
			$this->assertTrue(hasRecentAuthentication());
		} finally {
			if ($previous === null) {
				unset($_SESSION['recent_auth_at']);
			} else {
				$_SESSION['recent_auth_at'] = $previous;
			}
		}
	}
}
