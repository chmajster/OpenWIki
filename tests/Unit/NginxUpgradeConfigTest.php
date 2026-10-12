<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;
use OpenWiki\Upgrade\NginxUpgradeConfig;
use PHPUnit\Framework\TestCase;

final class NginxUpgradeConfigTest extends TestCase
{
    public function testUpgradeRetainsTlsHeadersAndCustomLimitsWhileChangingTheRelease(): void
    {
        $source = <<<'NGINX'
server {
    listen 443 ssl;
    server_name docs.example.test;
    ssl_certificate /etc/ssl/openwiki.crt;
    ssl_certificate_key /etc/ssl/openwiki.key;
    client_max_body_size 128m;
    add_header X-Custom preserved;
    root /var/www/wiki/public;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
NGINX;
        $prepared = NginxUpgradeConfig::prepare($source, '/var/www/wiki', '/var/www/wiki/current', true);
        foreach (['listen 443 ssl;', 'ssl_certificate /etc/ssl/openwiki.crt;', 'ssl_certificate_key /etc/ssl/openwiki.key;',
            'client_max_body_size 128m;', 'add_header X-Custom preserved;', 'fastcgi_pass unix:/run/php/php8.3-fpm.sock;'] as $directive) self::assertStringContainsString($directive, $prepared);
        self::assertStringContainsString('root "/var/www/wiki/current/public";', $prepared);
        self::assertStringContainsString('$realpath_root$fastcgi_script_name', $prepared);
        self::assertStringContainsString('$request_uri', $prepared);
        self::assertSame($prepared, NginxUpgradeConfig::prepare($prepared, '/var/www/wiki', '/var/www/wiki/current', true));
    }

    public function testAnUnrecognizedRootIsNotReplaced(): void
    {
        $this->expectException(\RuntimeException::class);
        NginxUpgradeConfig::prepare('server { root /srv/another-application/public; }', '/var/www/wiki', '/var/www/wiki/current', true);
    }
}
