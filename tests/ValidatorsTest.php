<?php

require_once __DIR__ . '/../inc/validators.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidatorsTest extends TestCase
{
    public static function validEmailProvider(): array
    {
        return [
            'simple' => ['user@example.com', true],
            'with dots' => ['user.name@example.com', true],
            'with plus' => ['user+tag@example.com', true],
            'subdomain' => ['user@sub.example.com', true],
            'numeric domain' => ['user@123.123.123.123', false],
            'empty string' => ['', false],
            'spaces' => ['   ', false],
            'no at' => ['userexample.com', false],
            'double at' => ['user@example@com', false],
            'no domain' => ['user@', false],
            'no tld' => ['user@example', false],
            'malformed' => ['user@.com', false],
            'whitespace around' => [' user@example.com ', true],
            'unicode domain' => ['user@éxample.com', false],
        ];
    }

    #[DataProvider('validEmailProvider')]
    public function testValidateEmail(string $email, bool $expected): void
    {
        $this->assertSame($expected, validateEmail($email));
    }

    public static function validCpfProvider(): array
    {
        return [
            'valid with mask' => ['529.982.247-25', true],
            'valid without mask' => ['52998224725', true],
            'valid second' => ['123.456.789-09', true],
            'valid second no mask' => ['12345678909', true],
            'all zeros' => ['00000000000', false],
            'all ones' => ['11111111111', false],
            'all nines' => ['99999999999', false],
            'short' => ['1234567890', false],
            'long' => ['123456789012', false],
            'invalid digits' => ['12345678900', false],
            'with letters' => ['abc.def.ghi-jk', false],
            'empty' => ['', false],
            'whitespace' => ['  529.982.247-25  ', true],
        ];
    }

    #[DataProvider('validCpfProvider')]
    public function testValidateCPF(string $cpf, bool $expected): void
    {
        $this->assertSame($expected, validateCPF($cpf));
    }

    public static function validPlacaProvider(): array
    {
        return [
            'standard ABC-1234' => ['ABC-1234', true],
            'standard no dash' => ['ABC1234', true],
            'mercosul ABC1D23' => ['ABC1D23', true],
            'mercosul with dash' => ['ABC-1D23', true],
            'lowercase' => ['abc-1234', true],
            'lowercase mercosul' => ['abc1d23', true],
            'too short' => ['ABC123', false],
            'too long' => ['ABC12345', false],
            'invalid chars' => ['AB@-1234', false],
            'all zeros' => ['ABC-0000', true],
            'empty' => ['', false],
            'spaces stripped' => ['ABC 1234', true],
        ];
    }

    #[DataProvider('validPlacaProvider')]
    public function testValidatePlaca(string $placa, bool $expected): void
    {
        $this->assertSame($expected, validatePlaca($placa));
    }

    public static function validTelefoneProvider(): array
    {
        return [
            'mobile with mask' => ['(11) 98765-4321', true],
            'mobile no mask' => ['11987654321', true],
            'landline with mask' => ['(11) 3333-4321', true],
            'landline no mask' => ['1133334321', true],
            'rio ddd' => ['21987654321', true],
            'minas ddd' => ['31987654321', true],
            '10 digits valid' => ['1198765432', true],
            'too long' => ['119876543210', false],
            'invalid ddd 10' => ['10987654321', false],
            'invalid ddd 01' => ['01987654321', false],
            'invalid ddd 00' => ['00987654321', false],
            'letters stripped' => ['(11) 9876A-4321', true],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('validTelefoneProvider')]
    public function testValidateTelefone(string $telefone, bool $expected): void
    {
        $this->assertSame($expected, validateTelefone($telefone));
    }

    public static function validNomeProvider(): array
    {
        return [
            'simple name' => ['João Silva', true],
            'with hyphen' => ['Maria Aparecida', true],
            'with apostrophe' => ['D\'Artes', true],
            'min length' => ['Ana', true],
            'max length' => [str_repeat('a', 255), true],
            'too short' => ['Ab', false],
            'too long' => [str_repeat('a', 256), false],
            'with numbers' => ['João 2 Filhos', true],
            'special chars' => ['José María', true],
            'empty' => ['', false],
            'only spaces' => ['   ', false],
            'with period' => ['Dr. João', true],
            'unicode' => ['김철수', true],
        ];
    }

    #[DataProvider('validNomeProvider')]
    public function testValidateNome(string $nome, bool $expected): void
    {
        $this->assertSame($expected, validateNome($nome));
    }

    public static function sanitizeStringProvider(): array
    {
        return [
            'normal string' => ['hello', 'hello'],
            'with spaces' => ['  hello  ', 'hello'],
            'with control chars' => ["he\x00llo", 'hello'],
            'newline removed' => ["he\nllo", 'hello'],
            'tab removed' => ["he\tllo", 'hello'],
            'carriage return removed' => ["he\rllo", 'hello'],
            'mixed control chars' => ["\x00\x01\x02test\x03\x04", 'test'],
            'already clean' => ['clean text', 'clean text'],
            'empty' => ['', ''],
            'only spaces' => ['   ', ''],
            'unicode preserved' => ['joão', 'joão'],
        ];
    }

    #[DataProvider('sanitizeStringProvider')]
    public function testSanitizeString(string $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeString($input));
    }
}
