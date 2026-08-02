<?php

require_once dirname(__DIR__, 2) . '/src/includes/AdRenderer.php';

final class AdRendererCspTest extends RepoTestCase
{
	private string $previousClient = '';

	protected function setUp(): void
	{
		$this->previousClient = (string) Database::getSetting('ads_adsense_client', '');
		Database::setSetting('ads_adsense_client', 'ca-pub-test');
	}

	protected function tearDown(): void
	{
		Database::setSetting('ads_adsense_client', $this->previousClient);
	}

	public function testConsentGateUsesInertDataAndExternalScriptOnly(): void
	{
		$method = new ReflectionMethod(AdRenderer::class, 'consentGate');
		$html = (string) $method->invoke(
			null,
			'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-test'
		);

		$this->assertStringContainsString('data-loader-src="https://pagead2.googlesyndication.com/', $html);
		$this->assertStringContainsString('/assets/js/ads-consent.js?v=' . APP_VERSION, $html);
		$this->assertDoesNotMatchRegularExpression('/<script(?![^>]+src=)[^>]*>/i', $html);
		$this->assertDoesNotMatchRegularExpression('/\son(?:click|change|load|error)\s*=/i', $html);
	}

	public function testAdsenseSlotDoesNotEmitAnInlineQueueScript(): void
	{
		$method = new ReflectionMethod(AdRenderer::class, 'render');
		$html = (string) $method->invoke(null, [
			'id' => 17,
			'type' => 'adsense',
			'adsense_slot' => '1234567890',
		]);

		$this->assertStringContainsString('<ins class="adsbygoogle"', $html);
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('data-ad-slot="1234567890"', $html);
	}
}
