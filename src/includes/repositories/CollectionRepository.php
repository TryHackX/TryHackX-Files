<?php
/**
 * CollectionRepository (Faza 5 · #2).
 *
 * Collections group files under one shareable link (ZIP on the fly). Extracted from
 * the Database god-object; the matching Database::* methods delegate here. Ownership
 * is enforced by scoping writes to $ownerId (null = admin/no scope).
 */
final class CollectionRepository
{
	/**
	 * Create a collection, validate its members and persist all sharing options in one commit.
	 *
	 * @return array{success: bool, added: int}
	 */
	public static function createWithFiles(
		string $id,
		string $name,
		int $userId,
		string $deleteTokenHash,
		array $fileIds,
		?int $fileOwnerId,
		array $options,
		int $minimumFiles = 2
	): array {
		$pdo = Database::getInstance();
		$fileIds = array_values(array_unique(array_map('strval', $fileIds)));
		if (!$pdo || $userId < 1 || count($fileIds) < $minimumFiles || count($fileIds) > 200) {
			return ['success' => false, 'added' => 0];
		}

		$collections = Database::table('collections');
		$files = Database::table('files');
		$links = Database::table('collection_files');
		$allowedOptions = [
			'password_hash', 'expires_at', 'max_downloads', 'one_time', 'on_limit_action',
		];

		try {
			$pdo->beginTransaction();
			$columns = ['id', 'name', 'user_id', 'delete_token', 'downloads', 'created_at'];
			$values = [$id, $name, $userId, $deleteTokenHash, 0, time()];
			foreach ($allowedOptions as $column) {
				if (array_key_exists($column, $options)) {
					$columns[] = $column;
					$values[] = $options[$column];
				}
			}
			$insertCollection = $pdo->prepare(
				"INSERT INTO `{$collections}` (`" . implode('`,`', $columns) . "`)
				 VALUES (" . implode(',', array_fill(0, count($values), '?')) . ')'
			);
			$insertCollection->execute($values);

			$in = implode(',', array_fill(0, count($fileIds), '?'));
			$params = $fileIds;
			$ownerSql = '';
			if ($fileOwnerId !== null) {
				$ownerSql = ' AND `user_id` = ?';
				$params[] = $fileOwnerId;
			}
			$owned = $pdo->prepare(
				"SELECT `id` FROM `{$files}` WHERE `id` IN ({$in}){$ownerSql} FOR UPDATE"
			);
			$owned->execute($params);
			$eligible = array_fill_keys($owned->fetchAll(PDO::FETCH_COLUMN), true);
			$ordered = array_values(array_filter(
				$fileIds,
				static fn(string $fileId): bool => isset($eligible[$fileId])
			));
			if (count($ordered) < $minimumFiles) {
				throw new RuntimeException('Too few eligible collection members.');
			}

			$insertLink = $pdo->prepare(
				"INSERT INTO `{$links}` (`collection_id`, `file_id`, `position`) VALUES (?, ?, ?)"
			);
			foreach ($ordered as $position => $fileId) {
				$insertLink->execute([$id, $fileId, $position]);
			}
			$pdo->commit();
			return ['success' => true, 'added' => count($ordered)];
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'added' => 0];
		}
	}

	public static function create(string $id, string $name, ?int $userId, string $deleteTokenHash): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('collections');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`id`, `name`, `user_id`, `delete_token`, `downloads`, `created_at`) VALUES (?, ?, ?, ?, 0, ?)");
			return $stmt->execute([$id, $name, $userId, $deleteTokenHash, time()]);
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Attach files to a collection. When $ownerId is given, only that user's files are
	 * added (so users can't collect files they don't own). Returns the number added.
	 */
	public static function addFiles(string $collectionId, array $fileIds, ?int $ownerId): int
	{
		$pdo = Database::getInstance();
		$fileIds = array_values(array_unique(array_filter(
			array_map('strval', $fileIds),
			static fn(string $id): bool => FileManager::isValidFileId($id)
		)));
		if (!$pdo || !$fileIds || count($fileIds) > 200) {
			return 0;
		}
		$filesTable = Database::table('files');
		$linkTable = Database::table('collection_files');
		$added = 0;
		try {
			$pdo->beginTransaction();
			$collection = $pdo->prepare(
				"SELECT `id` FROM `" . Database::table('collections') . "`
				 WHERE `id` = ? FOR UPDATE"
			);
			$collection->execute([$collectionId]);
			if (!$collection->fetchColumn()) {
				$pdo->rollBack();
				return 0;
			}
			// Current highest position, so appends keep a stable order.
			$stmt = $pdo->prepare("SELECT COALESCE(MAX(`position`), -1) FROM `{$linkTable}` WHERE `collection_id` = ?");
			$stmt->execute([$collectionId]);
			$pos = (int) $stmt->fetchColumn();

			$in = implode(',', array_fill(0, count($fileIds), '?'));
			$params = $fileIds;
			$ownerSql = '';
			if ($ownerId !== null) {
				$ownerSql = ' AND `user_id` = ?';
				$params[] = $ownerId;
			}
			$own = $pdo->prepare(
				"SELECT `id` FROM `{$filesTable}` WHERE `id` IN ({$in}){$ownerSql} FOR UPDATE"
			);
			$own->execute($params);
			$eligible = array_fill_keys($own->fetchAll(PDO::FETCH_COLUMN), true);
			$ins = $pdo->prepare("INSERT IGNORE INTO `{$linkTable}` (`collection_id`, `file_id`, `position`) VALUES (?, ?, ?)");

			foreach ($fileIds as $fid) {
				if (!isset($eligible[$fid])) {
					continue; // not found / not owned
				}
				$ins->execute([$collectionId, $fid, ++$pos]);
				if ($ins->rowCount() > 0) {
					$added++;
				}
			}
			$pdo->commit();
			return $added;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return 0;
		}
	}

	/**
	 * Set per-collection sharing controls (C2). Only keys present in $opts are written;
	 * scoped to $ownerId unless null (admin). `password_hash` null clears the password.
	 */
	public static function setOptions(string $id, ?int $ownerId, array $opts): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$cTable = Database::table('collections');
		$sets = [];
		$params = [];
		foreach (['password_hash', 'expires_at', 'max_downloads', 'one_time', 'consumed_at', 'on_limit_action'] as $col) {
			if (array_key_exists($col, $opts)) {
				$sets[] = "`{$col}` = ?";
				$params[] = $opts[$col];
			}
		}
		if (!$sets) {
			return true;
		}
		$where = '`id` = ?';
		$params2 = $params;
		$params2[] = $id;
		if ($ownerId !== null) {
			$where .= ' AND `user_id` = ?';
			$params2[] = $ownerId;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `{$cTable}` SET " . implode(', ', $sets) . " WHERE {$where}");
			$stmt->execute($params2);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Collection meta + its existing member files (missing files are skipped). */
	public static function get(string $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$cTable = Database::table('collections');
		$linkTable = Database::table('collection_files');
		$filesTable = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$cTable}` WHERE `id` = ?");
			$stmt->execute([$id]);
			$col = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$col) {
				return null;
			}

			$stmt = $pdo->prepare("SELECT f.`id`, f.`original_name`, f.`mime_type`, f.`size`,
					(f.`password_hash` IS NOT NULL AND f.`password_hash` <> '') AS `is_protected`
				FROM `{$linkTable}` cf
				JOIN `{$filesTable}` f ON f.`id` = cf.`file_id`
				WHERE cf.`collection_id` = ?
				ORDER BY cf.`position` ASC");
			$stmt->execute([$id]);
			$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$col['files'] = $files;
			$col['total_size'] = array_sum(array_map(fn($f) => (int) $f['size'], $files));
			$col['file_count'] = count($files);
			return $col;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** A user's collections with a live count/size of their still-existing member files. */
	public static function forUser(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$cTable = Database::table('collections');
		$linkTable = Database::table('collection_files');
		$filesTable = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT c.`id`, c.`name`, c.`delete_token`, c.`downloads`, c.`created_at`,
					c.`password_hash`, c.`expires_at`, c.`max_downloads`, c.`one_time`, c.`consumed_at`, c.`on_limit_action`,
					COUNT(f.`id`) AS `file_count`,
					COALESCE(SUM(f.`size`), 0) AS `total_size`
				FROM `{$cTable}` c
				LEFT JOIN `{$linkTable}` cf ON cf.`collection_id` = c.`id`
				LEFT JOIN `{$filesTable}` f ON f.`id` = cf.`file_id`
				WHERE c.`user_id` = ?
				GROUP BY c.`id`
				ORDER BY c.`created_at` DESC");
			$stmt->execute([$userId]);
			$collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if (!$collections) {
				return [];
			}
			$names = $pdo->prepare(
				"SELECT cf.`collection_id`, f.`original_name`
				 FROM `{$linkTable}` cf
				 JOIN `{$cTable}` c ON c.`id` = cf.`collection_id`
				 JOIN `{$filesTable}` f ON f.`id` = cf.`file_id`
				 WHERE c.`user_id` = ?
				 ORDER BY cf.`collection_id`, cf.`position`, cf.`file_id`"
			);
			$names->execute([$userId]);
			$byCollection = [];
			foreach ($names->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$byCollection[(string) $row['collection_id']][] = (string) $row['original_name'];
			}
			foreach ($collections as &$collection) {
				$collection['file_names'] = $byCollection[(string) $collection['id']] ?? [];
			}
			unset($collection);
			return $collections;
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Columns the collection browser may sort by → the SQL they map to. */
	private const SORT_COLUMNS = [
		'created_at' => 'c.`created_at`',
		'name'       => 'c.`name`',
		'files'      => 'file_count',
		'size'       => 'total_size',
		'downloads'  => 'c.`downloads`',
		'owner'      => 'owner_name',
	];

	/**
	 * Searchable, filterable, paginated collection listing for the admin Files tab (pt 4).
	 *
	 * Replaces a flat "newest 200" listing, which was no help once an install had more than
	 * that — and no help at all for the question this was built to answer: which collections are
	 * empty and can be cleared out. Filters mirror the file browser's where the concept carries
	 * over (date, owner, downloads, sharing state) and add the collection-only ones: how many
	 * files it holds and how much they weigh.
	 *
	 * Counts and sizes are aggregates over the *still-existing* member files, so a collection
	 * whose files were all deleted correctly reads as empty — which is exactly the case worth
	 * finding. That is why the count/size predicates are HAVING clauses, not WHERE ones.
	 *
	 * @return array{collections: array, total: int}
	 */
	public static function browse(array $opts): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['collections' => [], 'total' => 0];
		}

		$cTable = Database::table('collections');
		$linkTable = Database::table('collection_files');
		$filesTable = Database::table('files');
		$usersTable = Database::table('users');

		$page = max(1, (int) ($opts['page'] ?? 1));
		$perPage = min(100, max(1, (int) ($opts['per_page'] ?? 20)));
		$sort = self::SORT_COLUMNS[$opts['sort'] ?? 'created_at'] ?? self::SORT_COLUMNS['created_at'];
		$order = strtoupper((string) ($opts['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

		$f = $opts['filters'] ?? [];
		$where = [];
		$params = [];

		/**
		 * Free-text search, matching what the file browser looks at — a collection has no IP or
		 * filename of its own, so those terms reach through to its members. Which terms are in
		 * play is the caller's decision (AdminController), because a session that may not see
		 * owners or IPs must not be able to confirm one by searching for it and watching rows
		 * appear.
		 */
		$search = trim((string) ($opts['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $search . '%';
			$terms = ['c.`name` LIKE ?', 'c.`id` LIKE ?'];
			array_push($params, $like, $like);

			if (!empty($opts['search_owner'])) {
				$terms[] = 'u.`username` LIKE ?';
				$params[] = $like;
			}
			// A collection is what its files are: "the ZIP with invoice.pdf in it" is how anyone
			// actually looks for one.
			$terms[] = "EXISTS (SELECT 1 FROM `{$linkTable}` sl JOIN `{$filesTable}` sf ON sf.`id` = sl.`file_id`
				WHERE sl.`collection_id` = c.`id` AND (sf.`original_name` LIKE ? OR sf.`id` LIKE ?))";
			array_push($params, $like, $like);

			if (!empty($opts['search_ip'])) {
				$terms[] = "EXISTS (SELECT 1 FROM `{$linkTable}` il JOIN `{$filesTable}` if_ ON if_.`id` = il.`file_id`
					WHERE il.`collection_id` = c.`id` AND if_.`uploaded_ip` LIKE ?)";
				$params[] = $like;
			}

			$where[] = '(' . implode(' OR ', $terms) . ')';
		}

		foreach ([
			['date_from', 'c.`created_at` >= ?'],
			['date_to', 'c.`created_at` <= ?'],
			['dl_min', 'c.`downloads` >= ?'],
			['dl_max', 'c.`downloads` <= ?'],
		] as [$key, $clause]) {
			if (isset($f[$key]) && $f[$key] !== '' && $f[$key] !== null) {
				$where[] = $clause;
				$params[] = (int) $f[$key];
			}
		}

		// Owner set, where 0 stands for "no account" (guest / deleted owner).
		if (!empty($f['users']) && is_array($f['users'])) {
			$ids = array_values(array_unique(array_map('intval', $f['users'])));
			$parts = [];
			$real = array_values(array_filter($ids, fn($i) => $i > 0));
			if ($real) {
				$parts[] = 'c.`user_id` IN (' . implode(',', array_fill(0, count($real), '?')) . ')';
				$params = array_merge($params, $real);
			}
			if (in_array(0, $ids, true)) {
				$parts[] = 'c.`user_id` IS NULL';
			}
			if ($parts) {
				$where[] = '(' . implode(' OR ', $parts) . ')';
			}
		}

		// Sharing state, the collection-side mirror of FileManager::browse's.
		$shareClauses = [];
		$now = time();
		foreach ((array) ($f['sharing'] ?? []) as $state) {
			switch ($state) {
				case 'password':  $shareClauses[] = 'c.`password_hash` IS NOT NULL'; break;
				case 'onetime':   $shareClauses[] = 'c.`one_time` = 1'; break;
				case 'burned':    $shareClauses[] = '(c.`one_time` = 1 AND c.`consumed_at` IS NOT NULL)'; break;
				case 'expiring':  $shareClauses[] = '(c.`expires_at` IS NOT NULL AND c.`expires_at` > ?)'; $params[] = $now; break;
				case 'expired':   $shareClauses[] = '(c.`expires_at` IS NOT NULL AND c.`expires_at` <= ?)'; $params[] = $now; break;
				case 'capped':    $shareClauses[] = 'c.`max_downloads` IS NOT NULL'; break;
				case 'public':
					$shareClauses[] = '(c.`password_hash` IS NULL AND c.`one_time` = 0 AND c.`expires_at` IS NULL AND c.`max_downloads` IS NULL)';
					break;
			}
		}
		if ($shareClauses) {
			$where[] = '(' . implode(' OR ', $shareClauses) . ')';
		}

		// Aggregate predicates. `empty` is the shorthand the cleanup use case is built around.
		$having = [];
		$havingParams = [];
		if (!empty($f['empty'])) {
			$having[] = 'file_count = 0';
		} else {
			foreach ([
				['files_min', 'file_count >= ?'],
				['files_max', 'file_count <= ?'],
				['size_min', 'total_size >= ?'],
				['size_max', 'total_size <= ?'],
			] as [$key, $clause]) {
				if (isset($f[$key]) && $f[$key] !== '' && $f[$key] !== null) {
					$having[] = $clause;
					$havingParams[] = (int) $f[$key];
				}
			}
		}

		$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
		$havingSql = $having ? ('HAVING ' . implode(' AND ', $having)) : '';
		$from = "FROM `{$cTable}` c
			LEFT JOIN `{$usersTable}` u ON u.`id` = c.`user_id`
			LEFT JOIN `{$linkTable}` cf ON cf.`collection_id` = c.`id`
			LEFT JOIN `{$filesTable}` fl ON fl.`id` = cf.`file_id`
			{$whereSql}
			GROUP BY c.`id`
			{$havingSql}";

		try {
			// COUNT over the grouped set — a plain COUNT(*) would count member rows, not
			// collections, and HAVING has to be applied before the total is known.
			$countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT c.`id`,
					COUNT(fl.`id`) AS file_count, COALESCE(SUM(fl.`size`), 0) AS total_size
				{$from}) t");
			$countStmt->execute(array_merge($params, $havingParams));
			$total = (int) $countStmt->fetchColumn();

			// `delete_token` is the key the ZIP link's viewer tag is signed with (see
			// FileController::collectionViewerTag) — never sent to the browser itself.
			$sql = "SELECT c.`id`, c.`name`, c.`delete_token`, c.`downloads`, c.`created_at`, c.`user_id`,
					u.`username` AS owner_name,
					c.`password_hash`, c.`expires_at`, c.`max_downloads`, c.`one_time`, c.`consumed_at`, c.`on_limit_action`,
					COUNT(fl.`id`) AS file_count,
					COALESCE(SUM(fl.`size`), 0) AS total_size
				{$from}
				ORDER BY {$sort} {$order}
				LIMIT ? OFFSET ?";
			$stmt = $pdo->prepare($sql);
			$bound = array_merge($params, $havingParams, [$perPage, ($page - 1) * $perPage]);
			foreach ($bound as $i => $v) {
				$stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
			}
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			return ['collections' => self::attachMatches($rows, $search, $opts), 'total' => $total];
		} catch (PDOException $e) {
			return ['collections' => [], 'total' => 0];
		}
	}

	/**
	 * Say *why* a collection is in the results.
	 *
	 * A search for "faktura" can match a collection whose own name says nothing about invoices —
	 * the hit was a file inside it. Without naming that file the row looks like a false positive,
	 * so this attaches the member names that matched, exactly as the "My files" list has always
	 * done for its own collections.
	 *
	 * One query for the whole page rather than one per row, and only when something was actually
	 * searched for.
	 *
	 * @param list<array> $rows collections already fetched for this page
	 */
	private static function attachMatches(array $rows, string $search, array $opts): array
	{
		if ($search === '' || !$rows) {
			return $rows;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $rows;
		}

		$ids = array_column($rows, 'id');
		$in = implode(',', array_fill(0, count($ids), '?'));
		$like = '%' . $search . '%';

		// The same fields the WHERE above was allowed to look at — a name that may not be
		// searched must not come back as the reason a row matched either.
		$terms = ['f.`original_name` LIKE ?', 'f.`id` LIKE ?'];
		$params = array_merge($ids, [$like, $like]);
		if (!empty($opts['search_ip'])) {
			$terms[] = 'f.`uploaded_ip` LIKE ?';
			$params[] = $like;
		}

		$linkTable = Database::table('collection_files');
		$filesTable = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT l.`collection_id`, f.`original_name`
				FROM `{$linkTable}` l JOIN `{$filesTable}` f ON f.`id` = l.`file_id`
				WHERE l.`collection_id` IN ({$in}) AND (" . implode(' OR ', $terms) . ')');
			$stmt->execute($params);
		} catch (PDOException $e) {
			return $rows;
		}

		$hits = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hit) {
			$hits[$hit['collection_id']][] = (string) $hit['original_name'];
		}
		foreach ($rows as &$row) {
			$row['file_hits'] = $hits[$row['id']] ?? [];
		}
		return $rows;
	}

	/** Owners that actually have collections — the choice list for the owner filter. */
	public static function ownerFacets(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$cTable = Database::table('collections');
		$usersTable = Database::table('users');
		try {
			$stmt = $pdo->query("SELECT COALESCE(c.`user_id`, 0) AS id, u.`username` AS name, COUNT(*) AS count
				FROM `{$cTable}` c
				LEFT JOIN `{$usersTable}` u ON u.`id` = c.`user_id`
				GROUP BY COALESCE(c.`user_id`, 0), u.`username`
				ORDER BY count DESC");
			return array_map(fn($r) => [
				'id' => (int) $r['id'],
				'name' => $r['name'],
				'count' => (int) $r['count'],
			], $stmt->fetchAll(PDO::FETCH_ASSOC));
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Delete several collections at once (pt 4) — the point of being able to find the empty
	 * ones. Scoped to $ownerId unless null. Returns how many rows actually went.
	 */
	public static function deleteMany(array $ids, ?int $ownerId): int
	{
		$pdo = Database::getInstance();
		$ids = array_values(array_unique(array_filter(
			array_map('strval', $ids),
			static fn(string $id): bool => FileManager::isValidFileId($id)
		)));
		if (!$pdo || !$ids || count($ids) > 200) {
			return 0;
		}

		$collections = Database::table('collections');
		$links = Database::table('collection_files');
		$in = implode(',', array_fill(0, count($ids), '?'));
		$params = $ids;
		$ownerSql = '';
		if ($ownerId !== null) {
			$ownerSql = ' AND `user_id` = ?';
			$params[] = $ownerId;
		}

		try {
			$pdo->beginTransaction();
			$lock = $pdo->prepare(
				"SELECT `id` FROM `{$collections}` WHERE `id` IN ({$in}){$ownerSql} FOR UPDATE"
			);
			$lock->execute($params);
			$lockedIds = array_values(array_map('strval', $lock->fetchAll(PDO::FETCH_COLUMN)));
			if (!$lockedIds) {
				$pdo->commit();
				return 0;
			}

			$lockedIn = implode(',', array_fill(0, count($lockedIds), '?'));
			$pdo->prepare(
				"DELETE FROM `{$links}` WHERE `collection_id` IN ({$lockedIn})"
			)->execute($lockedIds);
			$delete = $pdo->prepare(
				"DELETE FROM `{$collections}` WHERE `id` IN ({$lockedIn})"
			);
			$delete->execute($lockedIds);
			$deleted = $delete->rowCount();
			if ($deleted !== count($lockedIds)) {
				throw new RuntimeException('Collection batch changed during deletion.');
			}
			$pdo->commit();
			return $deleted;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return 0;
		}
	}

	/** Delete a collection (and its links). Scoped to $ownerId unless it is null (admin). */
	public static function delete(string $id, ?int $ownerId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$cTable = Database::table('collections');
		$linkTable = Database::table('collection_files');
		try {
			$where = '`id` = ?';
			$params = [$id];
			if ($ownerId !== null) {
				$where .= ' AND `user_id` = ?';
				$params[] = $ownerId;
			}
			$pdo->beginTransaction();
			$lock = $pdo->prepare("SELECT `id` FROM `{$cTable}` WHERE {$where} FOR UPDATE");
			$lock->execute($params);
			if (!$lock->fetchColumn()) {
				$pdo->rollBack();
				return false;
			}
			$pdo->prepare("DELETE FROM `{$linkTable}` WHERE `collection_id` = ?")->execute([$id]);
			$stmt = $pdo->prepare("DELETE FROM `{$cTable}` WHERE {$where}");
			$stmt->execute($params);
			if ($stmt->rowCount() !== 1) {
				throw new RuntimeException('Collection changed during deletion.');
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Take ONE file out of a collection (runda 9) — the file itself stays untouched.
	 * Ownership is checked on the collection row, like every other mutation here.
	 */
	public static function removeFile(string $collectionId, string $fileId, ?int $ownerId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$pdo->beginTransaction();
			if (!self::lockOwnedCollection($pdo, $collectionId, $ownerId)) {
				$pdo->rollBack();
				return false;
			}
			$stmt = $pdo->prepare("DELETE FROM `" . Database::table('collection_files') . "`
				WHERE `collection_id` = ? AND `file_id` = ?");
			$stmt->execute([$collectionId, $fileId]);
			$removed = $stmt->rowCount() > 0;
			$pdo->commit();
			return $removed;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Persist a new member order (runda 9): the given file ids get positions 0..n in the
	 * order supplied; ids that are not members are simply ignored, members not mentioned
	 * keep their old positions (they sort after, having larger values).
	 */
	public static function reorder(string $collectionId, array $fileIds, ?int $ownerId): bool
	{
		$pdo = Database::getInstance();
		$fileIds = array_values(array_unique(array_map('strval', $fileIds)));
		if (!$pdo || !$fileIds || count($fileIds) > 200) {
			return false;
		}
		try {
			$pdo->beginTransaction();
			if (!self::lockOwnedCollection($pdo, $collectionId, $ownerId)) {
				$pdo->rollBack();
				return false;
			}
			$current = $pdo->prepare(
				"SELECT `file_id` FROM `" . Database::table('collection_files') . "`
				 WHERE `collection_id` = ? ORDER BY `position`, `file_id` FOR UPDATE"
			);
			$current->execute([$collectionId]);
			$currentIds = $current->fetchAll(PDO::FETCH_COLUMN);
			$expected = $currentIds;
			$requested = $fileIds;
			sort($expected, SORT_STRING);
			sort($requested, SORT_STRING);
			if ($expected !== $requested) {
				$pdo->rollBack();
				return false;
			}
			// Move out of the final range first, so this remains compatible with a unique
			// (collection_id, position) constraint and never creates a transient collision.
			$pdo->prepare(
				"UPDATE `" . Database::table('collection_files') . "`
				 SET `position` = `position` + 1000000 WHERE `collection_id` = ?"
			)->execute([$collectionId]);
			$stmt = $pdo->prepare("UPDATE `" . Database::table('collection_files') . "`
				SET `position` = ? WHERE `collection_id` = ? AND `file_id` = ?");
			foreach ($fileIds as $pos => $fileId) {
				$stmt->execute([$pos, $collectionId, (string) $fileId]);
				if ($stmt->rowCount() !== 1) {
					throw new RuntimeException('Collection membership changed during reorder.');
				}
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	private static function lockOwnedCollection(PDO $pdo, string $collectionId, ?int $ownerId): bool
	{
		$sql = "SELECT `id` FROM `" . Database::table('collections') . "` WHERE `id` = ?";
		$params = [$collectionId];
		if ($ownerId !== null) {
			$sql .= ' AND `user_id` = ?';
			$params[] = $ownerId;
		}
		$stmt = $pdo->prepare($sql . ' FOR UPDATE');
		$stmt->execute($params);
		return (bool) $stmt->fetchColumn();
	}

	/** Does this collection exist and belong to $ownerId (null = admin, any owner passes)? */
	private static function ownedBy(string $collectionId, ?int $ownerId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$sql = "SELECT 1 FROM `" . Database::table('collections') . "` WHERE `id` = ?";
			$params = [$collectionId];
			if ($ownerId !== null) {
				$sql .= ' AND `user_id` = ?';
				$params[] = $ownerId;
			}
			$stmt = $pdo->prepare($sql . ' LIMIT 1');
			$stmt->execute($params);
			return (bool) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Rename a collection. Scoped to $ownerId unless null (admin). */
	public static function rename(string $id, string $name, ?int $ownerId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$cTable = Database::table('collections');
		try {
			$where = '`id` = ?';
			$params = [$name, $id];
			if ($ownerId !== null) {
				$where .= ' AND `user_id` = ?';
				$params[] = $ownerId;
			}
			$stmt = $pdo->prepare("UPDATE `{$cTable}` SET `name` = ? WHERE {$where}");
			$stmt->execute($params);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}
}
