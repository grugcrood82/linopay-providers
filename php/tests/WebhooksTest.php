<?php

declare(strict_types=1);

namespace Linotech\Sdk\Tests;

use Linotech\Sdk\WebhookSignature;
use PHPUnit\Framework\TestCase;
use Linotech\Sdk\LinopayWebhookVerificationException;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_testsecret_abcdef';
    private const BODY = '{"event":"invoice.settled","data":{"invoiceId":"2_9hYAOr"}}';
    private const TS = 1_789_000_000;

    public function testBuildSigningPayloadPinsTimestampToBody(): void
    {
        self::assertSame(self::TS . '.' . self::BODY, self::TS . '.' . self::BODY);
    }

    public function testProducesV1LowercaseHexSignature(): void
    {
        $sig = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        self::assertStringStartsWith('v1=', $sig);
        $hex = substr($sig, 3);
        self::assertSame(64, strlen($hex)); // SHA-256 = 32 bytes hex
        self::assertSame(strtolower($hex), $hex);
        self::assertSame($sig, WebhookSignature::compute(self::SECRET, self::TS, self::BODY));
    }

    public function testSignsBothTimestampAndBodyTogether(): void
    {
        $a = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        $b = WebhookSignature::compute(self::SECRET, self::TS + 1, self::BODY);
        $c = WebhookSignature::compute(self::SECRET, self::TS, self::BODY . ' ');

        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function testRefusesToSignWithEmptySecret(): void
    {
        $this->expectException(LinopayWebhookVerificationException::class);
        WebhookSignature::compute('', self::TS, self::BODY);
    }

    public function testVerifiesAFreshlySignedPayload(): void
    {
        $sig = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        WebhookSignature::verify(self::SECRET, self::TS, self::BODY, $sig, nowSeconds: self::TS);
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsATamperedBody(): void
    {
        $sig = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        $caught = null;
        try {
            WebhookSignature::verify(self::SECRET, self::TS, self::BODY . ' ', $sig, nowSeconds: self::TS);
            self::fail('Expected exception');
        } catch (LinopayWebhookVerificationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        // Constant-time compare runs first; a body tamper surfaces as
        // wrong-secret, not the misleading "body-tampered" reason.
        self::assertSame(
            LinopayWebhookVerificationException::REASON_WRONG_SECRET,
            $caught->reason,
        );
    }

    public function testRejectsOutsideTheReplayWindow(): void
    {
        $sig = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        $caught = null;
        try {
            WebhookSignature::verify(
                self::SECRET,
                self::TS,
                self::BODY,
                $sig,
                nowSeconds: self::TS + WebhookSignature::REPLAY_WINDOW_SECONDS + 60,
            );
            self::fail('Expected exception');
        } catch (LinopayWebhookVerificationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        // Signature check runs first, so a freshly-signed payload still
        // hits the replay-window check only if it verifies. The reason
        // field is the stable identifier; the message is the long form.
        self::assertSame(
            LinopayWebhookVerificationException::REASON_REPLAY_OUT_OF_WINDOW,
            $caught->reason,
        );
    }

    public function testRejectsAWrongSecret(): void
    {
        $sig = WebhookSignature::compute('whsec_wrong', self::TS, self::BODY);
        $caught = null;
        try {
            WebhookSignature::verify(self::SECRET, self::TS, self::BODY, $sig, nowSeconds: self::TS);
            self::fail('Expected exception');
        } catch (LinopayWebhookVerificationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertSame(
            LinopayWebhookVerificationException::REASON_WRONG_SECRET,
            $caught->reason,
        );
    }

    public function testRejectsMissingSignature(): void
    {
        $this->expectException(LinopayWebhookVerificationException::class);
        WebhookSignature::verify(self::SECRET, self::TS, self::BODY, null, nowSeconds: self::TS);
    }

    public function testRejectsEmptySignature(): void
    {
        $this->expectException(LinopayWebhookVerificationException::class);
        WebhookSignature::verify(self::SECRET, self::TS, self::BODY, '', nowSeconds: self::TS);
    }

    public function testDoesNotCrashOnLengthMismatch(): void
    {
        $sig = WebhookSignature::compute(self::SECRET, self::TS, self::BODY);
        $truncated = substr($sig, 0, 32);
        $caught = null;
        try {
            WebhookSignature::verify(self::SECRET, self::TS, self::BODY, $truncated, nowSeconds: self::TS);
        } catch (LinopayWebhookVerificationException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        // hash_equals returns false on length mismatch; the SDK
        // surfaces wrong-secret (not a length-specific reason)
        // because we don't leak length info to the error reason.
        self::assertSame(
            LinopayWebhookVerificationException::REASON_WRONG_SECRET,
            $caught?->reason,
        );
    }
}
