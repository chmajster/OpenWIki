<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testQuotedJsonEnvironmentValuesAreDecodedLosslessly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openwiki-env-');
        self::assertNotFalse($path);

        file_put_contents($path, 'OPENWIKI_ENV_TEST=' . json_encode('p"a\\ss=word') . PHP_EOL);
        Env::load($path);

        self::assertSame('p"a\\ss=word', Env::get('OPENWIKI_ENV_TEST'));
        @unlink($path);
        putenv('OPENWIKI_ENV_TEST');
    }
}
