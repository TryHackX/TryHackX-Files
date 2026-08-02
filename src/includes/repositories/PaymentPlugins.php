<?php
/**
 * Payment provider catalogue.
 *
 * A catalogue entry either describes an external checkout preset or one of the native
 * server-side clients:
 *
 *   - Some providers really are a **link**. A Stripe Payment Link, a PayPal.me address, a
 *     Gumroad product — you paste the URL and you are done. Those presets work end to end with
 *     nothing else to write (`server_side = false`).
 *   - Przelewy24 and PayU are native integrations. TryHackX Files registers the order, validates the
 *     provider callback, reconciles lost callbacks and fulfills the immutable ledger snapshot.
 *
 * Only providers get an entry. BLIK, Apple Pay and Google Pay are payment *methods*: you never
 * integrate them here, you tick them on inside P24 / PayU / Stripe and they appear in that
 * provider's checkout. They are listed in each provider's `methods` line for exactly that
 * reason — a card of their own would have been a setup screen with nothing to set.
 *
 * Credentials typed into a plugin are stored as ordinary settings under
 * `payplugin_<plugin>_<field>` (secret-looking ones encrypted), so they can be filled in once
 * and reused by every plan.
 */
final class PaymentPlugins
{
	/** Field names whose values are stored encrypted rather than in plain text. */
	private const SECRET_FIELDS = ['crc', 'api_key', 'secret', 'client_secret', 'md5'];

	/**
	 * The catalogue. `type` is the checkout mode the preset suggests for a plan;
	 * `url_template` / `html_template` may use `{{field}}` placeholders, filled from the values
	 * saved for that plugin.
	 */
	public static function all(): array
	{
		return [
			'link' => [
				'name' => 'Payment link (Stripe, PayPal, Gumroad...)',
				'icon' => 'fa-link',
				'methods' => 'Stripe Payment Link · PayPal.me · Gumroad · Buy Me a Coffee',
				'type' => 'link',
				'server_side' => false,
				'docs' => 'https://docs.stripe.com/payment-links',
				'url_template' => '{{checkout_url}}',
				'fields' => ['checkout_url' => 'Hosted checkout page URL'],
				'notes' => 'panel.plug.notes_link',
			],
			'przelewy24' => [
				'name' => 'Przelewy24',
				'icon' => 'fa-building-columns',
				'methods' => 'BLIK · cards · bank transfers · Apple Pay · Google Pay',
				'type' => 'builtin',
				'server_side' => true,
				'built_in' => true,
				'docs' => 'https://developers.przelewy24.pl/',
				'fields' => [
					'merchant_id' => 'Merchant ID',
					'pos_id' => 'POS ID',
					'crc' => 'CRC key',
					'api_key' => 'REST API key',
					'currency' => 'Account currency (PLN / EUR...)',
					'environment' => 'Environment (sandbox / production)',
				],
				'notes' => 'panel.plug.notes_p24',
			],
			'payu' => [
				'name' => 'PayU',
				'icon' => 'fa-wallet',
				'methods' => 'BLIK · cards · bank transfers · Google Pay · Apple Pay',
				// `builtin`: the plan carries no checkout URL at all. The buy button is generated
				// from the plan's own id at render time, so nothing has to substitute a
				// `{{plan_id}}` placeholder by hand and the address is never frozen into a row
				// (which would break the day the site moves to another domain).
				'type' => 'builtin',
				'server_side' => true,
				'built_in' => true,
				'docs' => 'https://developers.payu.com/europe/pl/docs/testing/sandbox/',
				'fields' => [
					'pos_id' => 'POS ID (merchantPosId)',
					'client_id' => 'OAuth client_id (usually the POS ID)',
					'client_secret' => 'OAuth client_secret',
					'md5' => 'Second MD5 key (notification signature)',
					'currency' => 'POS currency (PLN / BGN / RON...)',
					'environment' => 'Environment (sandbox / production)',
				],
				'notes' => 'panel.plug.notes_payu',
			],
			'transfer' => [
				'name' => 'Manual bank transfer',
				'icon' => 'fa-money-bill-transfer',
				'methods' => 'manual confirmation by an administrator',
				'type' => 'html',
				'server_side' => false,
				'docs' => '',
				'html_template' => "<p><strong>Bank transfer details</strong><br>\n{{account_name}}<br>\n{{account_number}}<br>\nReference: {{title}}</p>",
				'fields' => [
					'account_name' => 'Recipient',
					'account_number' => 'Account number',
					'title' => 'Transfer reference',
				],
				'notes' => 'panel.plug.notes_transfer',
			],
		];
	}

	/** Setting key holding one plugin field's value. */
	private static function key(string $plugin, string $field): string
	{
		return 'payplugin_' . $plugin . '_' . $field;
	}

	private static function isSecret(string $field): bool
	{
		return in_array($field, self::SECRET_FIELDS, true);
	}

	/**
	 * Saved values for a plugin. Secrets come back as a fixed marker rather than the value —
	 * the panel only needs to know whether one is set, never what it is.
	 */
	public static function values(string $plugin, bool $reveal = false): array
	{
		$catalog = self::all();
		if (!isset($catalog[$plugin])) {
			return [];
		}
		$out = [];
		foreach (array_keys($catalog[$plugin]['fields']) as $field) {
			if (self::isSecret($field)) {
				$stored = (string) Database::getSecretSetting(self::key($plugin, $field), '');
				$out[$field] = $reveal ? $stored : ($stored !== '' ? '••••••••' : '');
			} else {
				$out[$field] = (string) Database::getSetting(self::key($plugin, $field), '');
			}
		}
		return $out;
	}

	/** Store a plugin's field values. A secret left at the masked marker keeps its old value. */
	public static function save(string $plugin, array $values): bool
	{
		$catalog = self::all();
		if (!isset($catalog[$plugin])) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			foreach (array_keys($catalog[$plugin]['fields']) as $field) {
				if (!array_key_exists($field, $values)) {
					continue;
				}
				$value = trim((string) $values[$field]);
				if (self::isSecret($field)) {
					if ($value === '••••••••') {
						continue; // untouched in the form
					}
					if (!Database::setSecretSetting(self::key($plugin, $field), $value)) {
						throw new RuntimeException("Could not store payment field {$field}.");
					}
				} elseif (!Database::setSetting(self::key($plugin, $field), $value)) {
					throw new RuntimeException("Could not store payment field {$field}.");
				}
			}
			if (!Database::setSetting(
				'payplugin_' . $plugin . '_config_version',
				bin2hex(random_bytes(16))
			)) {
				throw new RuntimeException('Could not publish payment configuration version.');
			}
			if (!empty($catalog[$plugin]['built_in'])
				&& !Database::setSetting('payment_builtin_provider', $plugin)) {
				throw new RuntimeException('Could not select the built-in payment provider.');
			}
			if ($plugin === 'payu') {
				if (!Database::setSecretSetting('payu_access_token', '')
					|| !Database::setSetting('payu_token_expires', '0')
					|| !Database::setSetting('payu_token_config_hash', '')) {
					throw new RuntimeException('Could not invalidate the PayU OAuth cache.');
				}
			}
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Payment plugin configuration failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * The checkout a plan should get from this plugin, with `{{field}}` placeholders resolved.
	 * `{{plan_id}}` is left in place — the plan may not exist yet when the preset is applied,
	 * and the bridge script is what fills it in anyway.
	 *
	 * @return array{checkout_type: string, checkout_url: string, checkout_html: string}|null
	 */
	public static function checkoutFor(string $plugin): ?array
	{
		$catalog = self::all();
		if (!isset($catalog[$plugin])) {
			return null;
		}
		$def = $catalog[$plugin];
		$values = self::values($plugin, true);

		// `{{app_url}}` is not a plugin field — it is this installation's own address, which a
		// built-in integration's checkout URL points back at (pt 10).
		$values['app_url'] = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

		$fill = function (string $tpl) use ($values): string {
			foreach ($values as $field => $value) {
				$tpl = str_replace('{{' . $field . '}}', $value, $tpl);
			}
			return $tpl;
		};

		return [
			'checkout_type' => $def['type'],
			'checkout_url' => isset($def['url_template']) ? $fill($def['url_template']) : '',
			'checkout_html' => isset($def['html_template']) ? $fill($def['html_template']) : '',
		];
	}

	/**
	 * Active server-side checkout. Saving a built-in provider selects it; the fallback keeps
	 * installations created before the selector was introduced fully backward compatible.
	 */
	public static function builtinProvider(): string
	{
		$selected = strtolower(trim((string) Database::getSetting('payment_builtin_provider', '')));
		if ($selected === 'przelewy24' && P24::isConfigured()) {
			return 'przelewy24';
		}
		if ($selected === 'payu' && PayU::isConfigured()) {
			return 'payu';
		}
		if (PayU::isConfigured()) {
			return 'payu';
		}
		if (P24::isConfigured()) {
			return 'przelewy24';
		}
		return $selected === 'przelewy24' ? 'przelewy24' : 'payu';
	}
}
