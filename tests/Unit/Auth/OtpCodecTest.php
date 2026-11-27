<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\OtpCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpCodec::class)]
final class OtpCodecTest extends TestCase
{
    private OtpCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new OtpCodec('unit-test-key');
    }

    public function testGenerateCodeFormatSuccess(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $code = $this->codec->generateCode();
            self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
            $seen[$code] = true;
        }
        self::assertGreaterThan(190, \count($seen), 'Codes must be random, not constant.');
    }

    public function testGenerateCodeUsesWholeAlphabetSuccess(): void
    {
        $chars = '';
        for ($i = 0; $i < 400; ++$i) {
            $chars .= $this->codec->generateCode();
        }
        $unique = array_unique(str_split($chars));
        self::assertGreaterThanOrEqual(34, \count($unique));
    }

    public function testHashCodeIsDeterministicHmacSuccess(): void
    {
        $hash = $this->codec->hashCode('ABCD1234');

        self::assertSame($hash, $this->codec->hashCode('ABCD1234'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame(hash_hmac('sha256', 'ABCD1234', 'unit-test-key'), $hash);
    }

    public function testHashCodeDependsOnKeyAndCodeError(): void
    {
        self::assertNotSame($this->codec->hashCode('ABCD1234'), $this->codec->hashCode('ABCD1235'));
        self::assertNotSame($this->codec->hashCode('ABCD1234'), new OtpCodec('other-key')->hashCode('ABCD1234'));
    }

    /** @return iterable<string, array{string}> */
    public static function equivalentInputs(): iterable
    {
        yield 'lowercase' => ['abcd1234'];
        yield 'padded' => ['  ABCD1234 '];
        yield 'mixed case padded' => ["\tabCD1234\n"];
    }

    #[DataProvider('equivalentInputs')]
    public function testCodeMatchesNormalisedInputSuccess(string $submitted): void
    {
        self::assertSame('ABCD1234', OtpCodec::normalizeCode($submitted));
        self::assertTrue($this->codec->codeMatches($this->codec->hashCode('ABCD1234'), $submitted));
    }

    public function testCodeMatchesWrongCodeError(): void
    {
        self::assertFalse($this->codec->codeMatches($this->codec->hashCode('ABCD1234'), 'ABCD1235'));
        self::assertFalse($this->codec->codeMatches($this->codec->hashCode('ABCD1234'), ''));
    }

    public function testLoginTokenEncodingSuccess(): void
    {
        $token = $this->codec->generateLoginToken();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token, '32 bytes, base64url, no padding');
        self::assertSame(32, \strlen(base64_decode(strtr($token, '-_', '+/') . '=', true) ?: ''));
        self::assertNotSame($token, $this->codec->generateLoginToken());
    }

    public function testHashLoginTokenSuccess(): void
    {
        $hash = $this->codec->hashLoginToken('token');

        self::assertSame(hash('sha256', 'token'), $hash);
        self::assertNotSame($hash, $this->codec->hashLoginToken('token2'));
    }

    public function testEmptyKeyError(): void
    {
        $this->expectException(\LogicException::class);

        new OtpCodec('');
    }
}
