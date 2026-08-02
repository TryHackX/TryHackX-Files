<?php

/**
 * Declarative policy for the browser API router.
 *
 * A route is deliberately more than a callable.  The front controller must know whether a
 * request is a read or a write before controller code is allowed to run; otherwise every new
 * handler silently inherits "all methods allowed" and GET requests bypass the CSRF gate.
 */
final class ApiRoutePolicy
{
	private const REQUIRED_KEYS = [
		'handler',
		'methods',
		'csrf',
		'auth',
		'permission',
		'rate_limit',
	];

	private const HTTP_METHODS = [
		'GET',
		'HEAD',
		'POST',
		'PUT',
		'PATCH',
		'DELETE',
	];

	private const RATE_LIMITS = ['auth', 'read', 'write'];

	/**
	 * Build one route descriptor.  All policy fields are always present, including fields
	 * whose value is false/null, so missing metadata can never fall back to permissive defaults.
	 *
	 * @param callable|array{class-string,string} $handler
	 * @param list<string> $methods
	 * @return array{
	 *   handler: callable|array{class-string,string},
	 *   methods: list<string>,
	 *   csrf: bool,
	 *   auth: bool,
	 *   permission: ?string,
	 *   rate_limit: ?string,
	 *   idempotency: bool,
	 *   redirect_status: ?int
	 * }
	 */
	public static function route(
		callable|array $handler,
		array $methods,
		bool $csrf,
		bool $auth = false,
		?string $permission = null,
		?string $rateLimit = 'read',
		bool $idempotency = false,
		?int $redirectStatus = null
	): array {
		$normalised = array_values(array_unique(array_map(
			static fn($method): string => strtoupper(trim((string) $method)),
			$methods
		)));

		$route = [
			'handler' => $handler,
			'methods' => $normalised,
			'csrf' => $csrf,
			'auth' => $auth,
			'permission' => $permission,
			'rate_limit' => $rateLimit,
			'idempotency' => $idempotency,
			'redirect_status' => $redirectStatus,
		];
		self::assertDescriptor($route);
		return $route;
	}

	/**
	 * Reject an incomplete or contradictory descriptor at startup.
	 *
	 * @throws LogicException
	 */
	public static function assertDescriptor(array $route): void
	{
		foreach (self::REQUIRED_KEYS as $key) {
			if (!array_key_exists($key, $route)) {
				throw new LogicException('API route is missing required policy field: ' . $key);
			}
		}
		if (!is_callable($route['handler'])) {
			throw new LogicException('API route handler is not callable');
		}
		if (!is_array($route['methods']) || $route['methods'] === []) {
			throw new LogicException('API route must explicitly allow at least one HTTP method');
		}
		foreach ($route['methods'] as $method) {
			if (!is_string($method) || !in_array($method, self::HTTP_METHODS, true)) {
				throw new LogicException('Unsupported HTTP method in API route descriptor');
			}
		}
		if (!is_bool($route['csrf']) || !is_bool($route['auth'])) {
			throw new LogicException('API route csrf/auth policies must be boolean');
		}
		if ($route['permission'] !== null && (!is_string($route['permission']) || $route['permission'] === '')) {
			throw new LogicException('API route permission must be null or a non-empty string');
		}
		if ($route['rate_limit'] !== null && !in_array($route['rate_limit'], self::RATE_LIMITS, true)) {
			throw new LogicException('Unsupported API rate-limit category');
		}

		// A CSRF check on a safe method gives a false sense of protection: browsers do not send
		// custom headers during ordinary navigations.  Writes must use an unsafe method.
		if ($route['csrf'] && array_intersect($route['methods'], ['GET', 'HEAD'])) {
			throw new LogicException('A CSRF-protected API route cannot allow GET or HEAD');
		}
		if (!empty($route['idempotency']) && !array_intersect($route['methods'], ['POST', 'PUT', 'PATCH'])) {
			throw new LogicException('Idempotency keys are supported only for write routes');
		}
		if (($route['redirect_status'] ?? null) !== null
			&& !in_array((int) $route['redirect_status'], [303, 307, 308], true)
		) {
			throw new LogicException('Unsupported redirect status in API route descriptor');
		}
	}

	/**
	 * Validate the complete table, including duplicate handler mistakes caught by malformed
	 * descriptors. The associative array itself guarantees unique action names.
	 *
	 * @throws LogicException
	 */
	public static function assertTable(array $routes): void
	{
		if ($routes === []) {
			throw new LogicException('API route table cannot be empty');
		}
		foreach ($routes as $action => $route) {
			if (!is_string($action) || !preg_match('/^(?:__[a-z_]+|[a-z][a-z0-9_]*)$/', $action)) {
				throw new LogicException('Invalid API action name');
			}
			if (!is_array($route)) {
				throw new LogicException('API route descriptor must be an array');
			}
			self::assertDescriptor($route);
		}
	}

	/**
	 * Resolve the special no-action upload rollback route and deny every undeclared action.
	 *
	 * @return array{action:string, route:?array, status:int, allow:list<string>}
	 */
	public static function resolve(array $routes, string $action, string $method): array
	{
		$method = strtoupper($method);
		$key = $action;
		if ($key === '' && $method === 'DELETE') {
			$key = '__revert';
		} elseif (str_starts_with($key, '__')) {
			// Internal pseudo-routes can only be selected by the front controller's exact
			// no-action shape; they are never public `?action=` values.
			return ['action' => $key, 'route' => null, 'status' => 404, 'allow' => []];
		}

		if ($key === '' || !isset($routes[$key])) {
			return ['action' => $key, 'route' => null, 'status' => 404, 'allow' => []];
		}

		$route = $routes[$key];
		self::assertDescriptor($route);
		$allow = $route['methods'];
		if (!in_array($method, $allow, true)) {
			return ['action' => $key, 'route' => $route, 'status' => 405, 'allow' => $allow];
		}

		return ['action' => $key, 'route' => $route, 'status' => 200, 'allow' => $allow];
	}

	/**
	 * Reserve a browser checkout key in the current session.  The reservation happens before
	 * any payment/ad controller side effects, and a repeated POST is refused rather than
	 * creating a second provider order.
	 */
	public static function reserveIdempotencyKey(array $route): bool
	{
		if (empty($route['idempotency'])) {
			return true;
		}

		$key = trim((string) (
			$_SERVER['HTTP_IDEMPOTENCY_KEY']
			?? $_POST['_idempotency_key']
			?? ''
		));
		if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key)) {
			return false;
		}

		$now = time();
		$seen = is_array($_SESSION['api_idempotency_keys'] ?? null)
			? $_SESSION['api_idempotency_keys']
			: [];
		foreach ($seen as $oldKey => $createdAt) {
			if ((int) $createdAt < $now - 3600) {
				unset($seen[$oldKey]);
			}
		}
		if (isset($seen[$key])) {
			$_SESSION['api_idempotency_keys'] = $seen;
			return false;
		}
		$seen[$key] = $now;
		$_SESSION['api_idempotency_keys'] = $seen;
		return true;
	}
}
