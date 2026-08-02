<?php
/**
 * Group permissions (Faza 6 · #1).
 *
 * Groups used to be limit profiles only. They now also carry a permission set, which is what
 * opens the "all files" surfaces to non-admins: browsing every upload, searching it, sorting
 * it, building collections from it, seeing who owns a file, and the advanced filter panel.
 *
 * Storage is a plain comma-separated list in `groups`.`permissions` — the set is small, fixed
 * and read on nearly every request, so a normalised table would buy nothing.
 *
 * Two rules are baked in and cannot be granted around:
 *   - admins hold every permission implicitly (there is nothing to configure for them);
 *   - IP addresses are staff-only. `files.see_ip` is honoured for admins and moderators; for
 *     anyone else it is ignored no matter what the group says, so "can browse all files"
 *     never leaks uploader IPs to ordinary users.
 *
 * Dependencies are declared, not just documented: DEPENDS lists the permission each entry
 * requires, and normalize() drops anything whose parent is missing. That means a saved set is
 * always internally consistent, so callers can test one key without re-checking its parents.
 */
final class Permissions
{
	private static ?string $currentCacheKey = null;
	private static array $currentCache = [];

	/** Browsing surfaces, in display order. Values are the labels' i18n keys. */
	public const FILE_PERMS = [
		'files.view_all'          => 'perm.files.view_all',
		'files.search_all'        => 'perm.files.search_all',
		'files.sort_all'          => 'perm.files.sort_all',
		'files.collection_all'    => 'perm.files.collection_all',
		'files.see_owner'         => 'perm.files.see_owner',
		'files.see_ip'            => 'perm.files.see_ip',
		'files.advanced_filters'  => 'perm.files.advanced_filters',
		// pt 5: drop someone else's file into one of your own collections. Separate from
		// `files.collection_all` (which builds a *new* collection out of a selection).
		'files.coll_add'          => 'perm.files.coll_add',
	];

	/** Individual filters the advanced panel can expose, gated by `files.advanced_filters`. */
	public const FILTER_PERMS = [
		'filter.date'          => 'perm.filter.date',
		'filter.size'          => 'perm.filter.size',
		'filter.downloads'     => 'perm.filter.downloads',
		'filter.type'          => 'perm.filter.type',
		'filter.user'          => 'perm.filter.user',
		'filter.ip'            => 'perm.filter.ip',
		'filter.inactive'      => 'perm.filter.inactive',
		'filter.dead'          => 'perm.filter.dead',
		'filter.sharing'       => 'perm.filter.sharing',
		'filter.in_collection' => 'perm.filter.in_collection',
	];

	/**
	 * The collections surface (pt 4).
	 *
	 * Collections were an admin-only list with no way to search it, which made "find the empty
	 * ones and clear them out" a manual scroll. They now have their own browse/filter/delete
	 * permissions, kept separate from the file ones: a group may be trusted to tidy up
	 * collections without being handed the file browser's filters, or the other way round.
	 */
	public const COLLECTION_PERMS = [
		'collections.view_all'   => 'perm.collections.view_all',
		'collections.filters'    => 'perm.collections.filters',
		'collections.delete_all' => 'perm.collections.delete_all',
	];

	/**
	 * The user's own files (pt 8).
	 *
	 * "Moje pliki" is everybody's tab — it lists what the account itself uploaded, so nothing
	 * here needs `files.view_all`, and that is exactly why it gets its own section: an operator
	 * can hand out a rich filter panel over one's own uploads without opening the all-files
	 * browser to anyone. Which is also why the set is smaller: owner and IP criteria would be
	 * answering a question ("whose is this?") that has one answer here.
	 */
	public const MYFILES_PERMS = [
		'myfiles.filters' => 'perm.myfiles.filters',
	];

	/** Interface capabilities which are independent from access to a particular data set. */
	public const UI_PERMS = [
		'tables.multi_sort' => 'perm.tables.multi_sort',
	];

	/**
	 * The account's own collections (pt 4).
	 *
	 * The all-files surface has had its own collections block since pt 4 of the previous round;
	 * "Moje pliki" showed a collections table underneath with nothing configurable about it at
	 * all. These are the same questions asked about one's *own* collections — may this group
	 * have them, make them, delete them, add to them, search them.
	 *
	 * Everything here was previously ungated, so the migration that introduces it grants the
	 * lot to every existing group: a permission system arriving is not a reason for anyone to
	 * lose a feature they were already using.
	 */
	public const MYCOLL_PERMS = [
		'myfiles.collections'  => 'perm.myfiles.collections',
		'myfiles.coll_create'  => 'perm.myfiles.coll_create',
		'myfiles.coll_add'     => 'perm.myfiles.coll_add',
		'myfiles.coll_delete'  => 'perm.myfiles.coll_delete',
		'myfiles.coll_filters' => 'perm.myfiles.coll_filters',
	];

	/** Criteria for filtering one's own collections, gated by `myfiles.coll_filters`. */
	public const MCFILTER_PERMS = [
		'mcfilter.date'      => 'perm.mcfilter.date',
		'mcfilter.size'      => 'perm.mcfilter.size',
		'mcfilter.files'     => 'perm.mcfilter.files',
		'mcfilter.downloads' => 'perm.mcfilter.downloads',
		'mcfilter.sharing'   => 'perm.mcfilter.sharing',
		'mcfilter.empty'     => 'perm.mcfilter.empty',
	];

	/** Individual "My files" filters, gated by `myfiles.filters`. */
	public const MFILTER_PERMS = [
		'mfilter.date'          => 'perm.mfilter.date',
		'mfilter.size'          => 'perm.mfilter.size',
		'mfilter.downloads'     => 'perm.mfilter.downloads',
		'mfilter.type'          => 'perm.mfilter.type',
		'mfilter.sharing'       => 'perm.mfilter.sharing',
		'mfilter.inactive'      => 'perm.mfilter.inactive',
		'mfilter.dead'          => 'perm.mfilter.dead',
		'mfilter.in_collection' => 'perm.mfilter.in_collection',
	];

	/**
	 * Advertising (Faza 8). All roots — none depends on any file permission.
	 *
	 * `ads.exempt` frees a group from seeing ads at all — which makes "no ads" a sellable
	 * feature of a pricier plan, since plans grant groups. `ads.buy` gates the "Moje
	 * reklamy" purchase surface (granted to every existing non-guest group by migration
	 * v31). The remaining three open slices of the ads manager to non-admin groups: who may
	 * review the queue, who may manage creatives/zones, who may see the metrics, who may
	 * shape the packages on sale.
	 */
	public const ADS_PERMS = [
		'ads.exempt' => 'perm.ads.exempt',
		'ads.buy' => 'perm.ads.buy',
		'ads.metrics' => 'perm.ads.metrics',
		'ads.approve' => 'perm.ads.approve',
		'ads.refund' => 'perm.ads.refund',
		'ads.manage' => 'perm.ads.manage',
		'ads.packages' => 'perm.ads.packages',
	];

	/**
	 * Operational moderation permissions.
	 *
	 * These keys never apply to ordinary users, even if their group contains them. A group is
	 * reusable as a limit profile, while the account role remains the hard trust boundary:
	 * only a moderator may receive delegated staff capabilities and administrators implicitly
	 * receive all of them.
	 */
	public const MODERATION_PERMS = [
		'moderation.reports.view' => 'perm.moderation.reports_view',
		'moderation.reports.resolve' => 'perm.moderation.reports_resolve',
		'moderation.files.delete' => 'perm.moderation.files_delete',
		'moderation.traffic.view' => 'perm.moderation.traffic_view',
		'moderation.audit.view' => 'perm.moderation.audit_view',
	];

	/** Delegated, read-mostly premium operations plus the separately sensitive refund action. */
	public const PREMIUM_PERMS = [
		'premium.metrics' => 'perm.premium.metrics',
		'premium.payments' => 'perm.premium.payments',
		'premium.subscribers' => 'perm.premium.subscribers',
		'premium.grants' => 'perm.premium.grants',
		'premium.bulk_grants' => 'perm.premium.bulk_grants',
		'premium.refunds' => 'perm.premium.refunds',
	];

	/** Individual collection filters, gated by `collections.filters`. */
	public const CFILTER_PERMS = [
		'cfilter.date'      => 'perm.cfilter.date',
		'cfilter.size'      => 'perm.cfilter.size',
		'cfilter.files'     => 'perm.cfilter.files',
		'cfilter.downloads' => 'perm.cfilter.downloads',
		'cfilter.user'      => 'perm.cfilter.user',
		'cfilter.sharing'   => 'perm.cfilter.sharing',
		'cfilter.empty'     => 'perm.cfilter.empty',
	];

	/** permission => the permission it requires. Enforced by normalize(). */
	private const DEPENDS = [
		'files.search_all'       => 'files.view_all',
		'files.sort_all'         => 'files.view_all',
		'files.collection_all'   => 'files.view_all',
		'files.see_owner'        => 'files.view_all',
		'files.see_ip'           => 'files.view_all',
		'files.advanced_filters' => 'files.view_all',
		'filter.date'            => 'files.advanced_filters',
		'filter.size'            => 'files.advanced_filters',
		'filter.downloads'       => 'files.advanced_filters',
		'filter.type'            => 'files.advanced_filters',
		'filter.user'            => 'files.advanced_filters',
		'filter.ip'              => 'files.advanced_filters',
		'filter.inactive'        => 'files.advanced_filters',
		'filter.dead'            => 'files.advanced_filters',
		'filter.sharing'         => 'files.advanced_filters',
		'filter.in_collection'   => 'files.advanced_filters',
		// The collections list lives on the same tab as the file browser, so seeing it at all
		// starts from the same place.
		'collections.view_all'   => 'files.view_all',
		'collections.filters'    => 'collections.view_all',
		'collections.delete_all' => 'collections.view_all',
		'cfilter.date'           => 'collections.filters',
		'cfilter.size'           => 'collections.filters',
		'cfilter.files'          => 'collections.filters',
		'cfilter.downloads'      => 'collections.filters',
		'cfilter.user'           => 'collections.filters',
		'cfilter.sharing'        => 'collections.filters',
		'cfilter.empty'          => 'collections.filters',
		// "My files" is the account's own tab, so `myfiles.filters` depends on nothing — it is
		// a root permission like `files.view_all`, not a branch of it.
		'mfilter.date'           => 'myfiles.filters',
		'mfilter.size'           => 'myfiles.filters',
		'mfilter.downloads'      => 'myfiles.filters',
		'mfilter.type'           => 'myfiles.filters',
		'mfilter.sharing'        => 'myfiles.filters',
		'mfilter.inactive'       => 'myfiles.filters',
		'mfilter.dead'           => 'myfiles.filters',
		'mfilter.in_collection'  => 'myfiles.filters',
		'files.coll_add'         => 'files.view_all',
		// The own-collections block hangs off `myfiles.collections`, which hangs off nothing —
		// like `myfiles.filters`, it is about the tab every account already has.
		'myfiles.coll_create'    => 'myfiles.collections',
		'myfiles.coll_add'       => 'myfiles.collections',
		'myfiles.coll_delete'    => 'myfiles.collections',
		'myfiles.coll_filters'   => 'myfiles.collections',
		'mcfilter.date'          => 'myfiles.coll_filters',
		'mcfilter.size'          => 'myfiles.coll_filters',
		'mcfilter.files'         => 'myfiles.coll_filters',
		'mcfilter.downloads'     => 'myfiles.coll_filters',
		'mcfilter.sharing'       => 'myfiles.coll_filters',
		'mcfilter.empty'         => 'myfiles.coll_filters',
		'ads.refund' => 'ads.approve',
		'moderation.reports.resolve' => 'moderation.reports.view',
		'moderation.files.delete' => 'moderation.reports.view',
		'premium.payments' => 'premium.metrics',
		'premium.refunds' => 'premium.payments',
		'premium.grants' => 'premium.subscribers',
		'premium.bulk_grants' => 'premium.grants',
	];

	/** Permissions only staff (admin / moderator) may ever hold, whatever the group says. */
	public const STAFF_ONLY = [
		'files.see_ip',
		'filter.ip',
		'ads.metrics',
		'ads.approve',
		'ads.refund',
		'ads.manage',
		'ads.packages',
		'moderation.reports.view',
		'moderation.reports.resolve',
		'moderation.files.delete',
		'moderation.traffic.view',
		'moderation.audit.view',
		'premium.metrics',
		'premium.payments',
		'premium.subscribers',
		'premium.grants',
		'premium.bulk_grants',
		'premium.refunds',
	];

	/**
	 * Conservative permissions for a freshly-created Moderator system group. They cover file
	 * and report triage, but deliberately exclude advertising administration, payment data,
	 * refunds and the audit log until an administrator explicitly delegates them.
	 */
	public const DEFAULT_MODERATOR_PERMS = [
		'files.view_all',
		'files.search_all',
		'files.sort_all',
		'files.see_owner',
		'files.see_ip',
		'files.advanced_filters',
		'filter.date',
		'filter.size',
		'filter.downloads',
		'filter.type',
		'filter.user',
		'filter.ip',
		'filter.inactive',
		'filter.dead',
		'filter.sharing',
		'filter.in_collection',
		'collections.view_all',
		'collections.filters',
		'cfilter.date',
		'cfilter.size',
		'cfilter.files',
		'cfilter.downloads',
		'cfilter.user',
		'cfilter.sharing',
		'cfilter.empty',
		'moderation.reports.view',
		'moderation.reports.resolve',
		'moderation.files.delete',
		'moderation.traffic.view',
	];

	/** Every known permission key. */
	public static function all(): array
	{
		return array_merge(
			array_keys(self::FILE_PERMS),
			array_keys(self::FILTER_PERMS),
			array_keys(self::COLLECTION_PERMS),
			array_keys(self::CFILTER_PERMS),
			array_keys(self::MYFILES_PERMS),
			array_keys(self::UI_PERMS),
			array_keys(self::MFILTER_PERMS),
			array_keys(self::MYCOLL_PERMS),
			array_keys(self::MCFILTER_PERMS),
			array_keys(self::ADS_PERMS),
			array_keys(self::MODERATION_PERMS),
			array_keys(self::PREMIUM_PERMS)
		);
	}

	/** Parse a stored permission string into a list of known, consistent keys. */
	public static function parse(?string $stored): array
	{
		if ($stored === null || trim($stored) === '') {
			return [];
		}
		$raw = array_map('trim', explode(',', $stored));
		return self::normalize($raw);
	}

	/** Serialise a permission list for storage. */
	public static function serialize(array $perms): string
	{
		return implode(',', self::normalize($perms));
	}

	/**
	 * Keep only known keys, then drop any whose required parent is absent. Repeats until the
	 * set stops shrinking so a chain (filter.ip → advanced_filters → view_all) collapses whole.
	 */
	public static function normalize(array $perms): array
	{
		$known = self::all();
		$set = array_values(array_unique(array_filter(
			array_map(fn($p) => (string) $p, $perms),
			fn($p) => in_array($p, $known, true)
		)));

		do {
			$before = count($set);
			$set = array_values(array_filter($set, function ($p) use ($set) {
				$needs = self::DEPENDS[$p] ?? null;
				return $needs === null || in_array($needs, $set, true);
			}));
		} while (count($set) < $before);

		// Preserve the canonical display order so stored values are stable and diffable.
		return array_values(array_filter($known, fn($p) => in_array($p, $set, true)));
	}

	/** The effective permissions of the current session (admins hold everything). */
	public static function forCurrentUser(): array
	{
		$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
		if (!$user) {
			self::$currentCacheKey = null;
			self::$currentCache = [];
			return []; // guests browse nothing beyond their own upload results
		}
		$cacheKey = (int) $user['id'] . ':'
			. (string) ($user['role'] ?? 'user') . ':'
			. (int) ($user['session_version'] ?? ($_SESSION['auth_version'] ?? 0));
		if (self::$currentCacheKey === $cacheKey) {
			return self::$currentCache;
		}
		if (($user['role'] ?? 'user') === 'admin') {
			self::$currentCacheKey = $cacheKey;
			self::$currentCache = self::all();
			return self::$currentCache;
		}
		$group = Database::getUserGroup((int) $user['id']);
		// The plan group supplies ordinary account capabilities. Staff-only grants are
		// deliberately removed here so buying Premium can never add or remove moderation.
		$perms = array_values(array_diff(
			self::parse($group['permissions'] ?? ''),
			self::STAFF_ONLY
		));
		if (($user['role'] ?? 'user') === 'moderator') {
			// The role-bound Moderator system group contributes capabilities but none of its
			// quotas, retention or paid-plan state.
			$staffGroup = Database::getUserStaffGroup((int) $user['id']);
			$perms = self::normalize(array_merge(
				$perms,
				self::parse($staffGroup['permissions'] ?? '')
			));
		}
		self::$currentCacheKey = $cacheKey;
		self::$currentCache = $perms;
		return self::$currentCache;
	}

	/** Clear the per-request permission snapshot after an identity transition. */
	public static function resetCurrentUserCache(): void
	{
		self::$currentCacheKey = null;
		self::$currentCache = [];
	}

	/** True when the current session holds $permission. */
	public static function has(string $permission): bool
	{
		return in_array($permission, self::forCurrentUser(), true);
	}

	/** Admins and moderators — the roles allowed to see IP addresses. */
	public static function isStaff(): bool
	{
		$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
		return $user && in_array(($user['role'] ?? 'user'), ['admin', 'moderator'], true);
	}

	/**
	 * Permissions contributed by a plan/limits group. Used by the assignment preview, so
	 * staff-only grants are always omitted; those come from the Moderator system group.
	 */
	public static function forGroup(?array $group, string $role = 'user'): array
	{
		if ($role === 'admin') {
			return self::all();
		}
		// A plan/limit group never grants staff-only capabilities. Moderator permissions come
		// from the role-bound Moderator system group.
		return array_values(array_diff(
			self::parse($group['permissions'] ?? ''),
			self::STAFF_ONLY
		));
	}

	/** Labels for a permission list, ready for display. */
	public static function labels(array $perms): array
	{
		$map = self::FILE_PERMS + self::FILTER_PERMS + self::COLLECTION_PERMS + self::CFILTER_PERMS
			+ self::MYFILES_PERMS + self::UI_PERMS + self::MFILTER_PERMS + self::MYCOLL_PERMS + self::MCFILTER_PERMS
			+ self::ADS_PERMS + self::MODERATION_PERMS + self::PREMIUM_PERMS;
		$out = [];
		foreach ($perms as $p) {
			if (isset($map[$p])) {
				$out[$p] = __($map[$p]);
			}
		}
		return $out;
	}
}
