<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/log_event.php';

class ProxyIpTest extends TestCase
{
    public static function requestIdInvalidoProvider(): array
    {
        return [
            'vazio' => [''],
            'nulo' => [null],
            'curto' => ['abc123'],
            'com espaco' => ['id com espaco'],
            'com caractere especial' => ['id<script>'],
            'acima do limite' => [str_repeat('a', 129)],
        ];
    }

    public static function requestIdValidoProvider(): array
    {
        return [
            'hex do nginx' => ['2fcd3bc1cf532df8dc1e5d8fdadd8042'],
            'uuid de outro proxy' => ['f18e542d-1ddd-661b-9d18-3f188ab75976'],
            'pontos e underline' => ['req.01_abcDEF'],
            'exatamente 8 caracteres' => ['abcd1234'],
            'exatamente 128 caracteres' => [str_repeat('a', 128)],
        ];
    }

    public function testClientIpUsaRemoteAddrIgnorandoHeadersDeProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.9.8.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
        $_SERVER['HTTP_X_REAL_IP'] = '9.8.7.6';

        $this->assertSame('10.9.8.7', getClientIp());
    }

    public function testClientIpSemRemoteAddr(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertSame('unknown', getClientIp());
    }

    #[DataProvider('requestIdValidoProvider')]
    public function testRequestIdDoProxyEPreservado(string $header): void
    {
        $this->assertSame($header, normalizeRequestId($header));
    }

    #[DataProvider('requestIdInvalidoProvider')]
    public function testRequestIdInvalidoGeraIdLocal(string|null $header): void
    {
        $id = normalizeRequestId($header);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        if ($header !== null && ctype_xdigit($header)) {
            $this->assertNotSame($header, $id);
        }
    }

    public function testIdLocalEDiferenteACadaChamada(): void
    {
        $this->assertNotSame(normalizeRequestId(null), normalizeRequestId(''));
    }
}