<?php

use PHPUnit\Framework\TestCase;

require_once PROJECT_ROOT . '/src/includes/CheckoutHtml.php';

final class CheckoutHtmlTest extends TestCase
{
	public function testPlainTextLinksAndImagesAreAccepted(): void
	{
		foreach ([
			'',
			'<p><strong>Bank transfer</strong><br>PL61 1090 1014 0000 0712 1981 2874<br>Reference: PRO-1</p>',
			'<a class="btn btn-primary" href="https://pay.example/checkout?plan=3" rel="noopener">Pay online</a>',
			'<ul><li>Step one</li><li>Step <em>two</em></li></ul><img src="https://cdn.example/qr.png" alt="QR">',
			'Price: 5 < 10 and 20 > 15',
			'<img src="data:image/png;base64,iVBORw0KGgo=" alt="QR">',
		] as $html) {
			$this->assertNull(CheckoutHtml::problem($html), $html);
			$this->assertSame($html, CheckoutHtml::render($html));
		}
	}

	public function testWhatTheCspWouldKillAnywayIsRefusedWithAReason(): void
	{
		$cases = [
			'<script>pay()</script>' => '<script>',
			'<SCRIPT src="https://js.stripe.com/v3/"></SCRIPT>' => '<script>',
			'< script >x</ script >' => '<script>',
			'<form action="https://pay.example" method="post"><input type="submit"></form>' => '<form>',
			'<iframe src="https://pay.example/embed"></iframe>' => '<iframe>',
			'<object data="x"></object>' => '<object>',
			'<button>Pay</button>' => '<button>',
			'<svg onload="x()"></svg>' => '<svg>',
			'<p onclick="x()">pay</p>' => 'on…= attribute',
			'<img src=x onerror=pay()>' => 'on…= attribute',
			'<a href="javascript:pay()">pay</a>' => 'javascript:/data: URL',
			"<a href=' JavaScript:pay()'>pay</a>" => 'javascript:/data: URL',
			'<img src="data:text/html;base64,AAAA">' => 'javascript:/data: URL',
		];
		foreach ($cases as $html => $reason) {
			$this->assertSame($reason, CheckoutHtml::problem((string) $html), (string) $html);
			$this->assertSame('', CheckoutHtml::render((string) $html), 'an unsafe stored block is not printed');
		}
	}

	public function testTagNamesThatMerelyStartWithABlockedWordAreNotBlocked(): void
	{
		$this->assertNull(CheckoutHtml::problem('<scripture>text</scripture><formal>x</formal><linkedin-card>y</linkedin-card>'));
	}

	/** The hand-off page passes on only a real payment URL, escaped, with no inline script. */
	public function testTheHandOffPageCarriesOnlyAnEscapedHttpsLinkAndAScriptFile(): void
	{
		foreach ([
			'',
			'javascript:alert(1)',
			'data:text/html,x',
			'//secure.payu.com/pay',
			'http://secure.payu.com/pay',
			'https://user:pass@secure.payu.com/pay',
			"https://secure.payu.com/pay\n",
		] as $url) {
			$this->assertFalse(CheckoutHtml::isHandOffUrl($url), $url);
			$this->assertNull(CheckoutHtml::handOffPage($url), $url);
		}
		$this->assertTrue(CheckoutHtml::isHandOffUrl('http://127.0.0.1:8123/pay'), 'a local stand-in');

		$page = (string) CheckoutHtml::handOffPage('https://secure.payu.com/pay/?orderId=1&token="x"');
		$this->assertStringContainsString(
			'id="checkoutContinue" href="https://secure.payu.com/pay/?orderId=1&amp;token=&quot;x&quot;"',
			$page
		);
		$this->assertStringContainsString('/assets/js/checkout-continue.js', $page);
		$this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $page);
		$this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $page);
	}

	public function testTheBuiltInBankTransferPresetPassesItsOwnRule(): void
	{
		$catalog = PaymentPlugins::all();
		foreach ($catalog as $name => $plugin) {
			if (isset($plugin['html_template'])) {
				$this->assertNull(CheckoutHtml::problem((string) $plugin['html_template']), $name);
			}
		}
	}
}
