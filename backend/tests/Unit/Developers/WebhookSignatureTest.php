<?php

namespace Tests\Unit\Developers;

use App\Modules\Developers\Webhooks\WebhookSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Standard Webhooks signatures: integrations verify every webhook with them, so a change here
 * breaks all of them at once.
 */
final class WebhookSignatureTest extends TestCase
{
    /** The example of the Standard Webhooks reference libraries. */
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const string ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    private const int TIMESTAMP = 1614265330;

    private const string BODY = '{"test": 2432232314}';

    public function test_the_reference_example(): void
    {
        $this->assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', WebhookSignature::sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY));
    }

    public function test_verification_accepts_the_signature_among_several(): void
    {
        $signature = WebhookSignature::sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);

        $this->assertTrue(WebhookSignature::verify(self::SECRET, self::ID, self::TIMESTAMP, self::BODY, $signature));
        // During a secret rotation the header carries one signature per secret.
        $this->assertTrue(WebhookSignature::verify(self::SECRET, self::ID, self::TIMESTAMP, self::BODY, 'v1,b3RoZXI= '.$signature));
    }

    public function test_verification_refuses_any_change(): void
    {
        $signature = WebhookSignature::sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);
        $other = WebhookSignature::generateSecret();

        $this->assertFalse(WebhookSignature::verify(self::SECRET, self::ID, self::TIMESTAMP, '{"test": 2432232315}', $signature));
        $this->assertFalse(WebhookSignature::verify(self::SECRET, 'msg_other', self::TIMESTAMP, self::BODY, $signature));
        $this->assertFalse(WebhookSignature::verify(self::SECRET, self::ID, self::TIMESTAMP + 1, self::BODY, $signature));
        $this->assertFalse(WebhookSignature::verify($other, self::ID, self::TIMESTAMP, self::BODY, $signature));
        $this->assertFalse(WebhookSignature::verify(self::SECRET, self::ID, self::TIMESTAMP, self::BODY, ''));
    }

    public function test_secrets_are_whsec_and_32_random_bytes(): void
    {
        $secret = WebhookSignature::generateSecret();

        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame(32, strlen((string) base64_decode(substr($secret, 6), true)));
        $this->assertNotSame($secret, WebhookSignature::generateSecret());
        $this->assertSame('whsec_…'.substr($secret, -4), WebhookSignature::hint($secret));
    }

    public function test_a_secret_that_is_not_base64_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookSignature::sign('whsec_not base64!', self::ID, self::TIMESTAMP, self::BODY);
    }
}
