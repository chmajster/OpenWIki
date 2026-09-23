<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Security\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    public function testSecretRoundTripUsesAuthenticatedEncryption(): void
    {
        $cipher = new SecretCipher(str_repeat('a', 64));
        $encrypted = $cipher->encrypt('super-secret-value');

        self::assertNotSame('super-secret-value', $encrypted);
        self::assertSame('super-secret-value', $cipher->decrypt($encrypted));
    }

    public function testTamperedSecretCannotBeDecrypted(): void
    {
        $cipher = new SecretCipher(str_repeat('b', 64));
        $encrypted = $cipher->encrypt('secret');
        $tampered = substr($encrypted, 0, -2) . 'AA';

        $this->expectException(\Throwable::class);
        $cipher->decrypt($tampered);
    }
}
