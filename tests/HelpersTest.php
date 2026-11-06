<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public static function sanitizeSpreadsheetCellProvider(): array
    {
        return [
            'normal string' => ['hello', 'hello'],
            'equals prefix' => ['=SUM(A1:A10)', "'=SUM(A1:A10)"],
            'plus prefix' => ['+12345', "'+12345"],
            'minus prefix' => ['-12345', "'-12345"],
            'at prefix' => ['@command', "'@command"],
            'empty string' => ['', ''],
            'null input' => [null, ''],
            'whitespace only' => ['   ', ''],
            'trimmed' => ['  hello  ', 'hello'],
            'equals with spaces' => ['  =cmd  ', "'=cmd"],
            'int zero' => [0, '0'],
            'already quoted' => ["'=formula", "'=formula"],
        ];
    }

    #[DataProvider('sanitizeSpreadsheetCellProvider')]
    public function testSanitizeSpreadsheetCell(mixed $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeSpreadsheetCell($input));
    }
}
