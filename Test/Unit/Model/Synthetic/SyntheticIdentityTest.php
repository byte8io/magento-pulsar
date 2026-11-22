<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Test\Unit\Model\Synthetic;

use Byte8\Pulsar\Model\Synthetic\SyntheticIdentity;
use PHPUnit\Framework\TestCase;

class SyntheticIdentityTest extends TestCase
{
    public function testHeaderValueBecomesSiteIdOnlyWhenSafe(): void
    {
        $this->assertSame(
            '0b6c1a2e-9f3d-4c5b-8e7a-1d2c3b4a5f60',
            SyntheticIdentity::siteIdFromHeader(' 0B6C1A2E-9F3D-4C5B-8E7A-1D2C3B4A5F60 ')
        );
        $this->assertSame('cli', SyntheticIdentity::siteIdFromHeader('cli'));
        $this->assertNull(SyntheticIdentity::siteIdFromHeader(null));
        $this->assertNull(SyntheticIdentity::siteIdFromHeader(''));
        // Anything that could not be embedded in an email address is rejected
        $this->assertNull(SyntheticIdentity::siteIdFromHeader('x@y.z'));
        $this->assertNull(SyntheticIdentity::siteIdFromHeader("abc\r\nX-Other: 1"));
        $this->assertNull(SyntheticIdentity::siteIdFromHeader(str_repeat('a', 65)));
    }

    public function testEmailMatchesPulsarServerFormat(): void
    {
        $email = SyntheticIdentity::email('0b6c1a2e-9f3d-4c5b-8e7a-1d2c3b4a5f60');
        $this->assertSame('pulsar+0b6c1a2e-9f3d-4c5b-8e7a-1d2c3b4a5f60@synthetic.byte8.io', $email);
        $this->assertTrue(SyntheticIdentity::isSyntheticEmail($email));
        $this->assertTrue(SyntheticIdentity::isSyntheticEmail('PULSAR+X@SYNTHETIC.BYTE8.IO '));
        $this->assertFalse(SyntheticIdentity::isSyntheticEmail('anna@example.de'));
        $this->assertFalse(SyntheticIdentity::isSyntheticEmail('pulsar@synthetic.byte8.io.example.com'));
        $this->assertFalse(SyntheticIdentity::isSyntheticEmail(null));
    }

    public function testLikePatternCoversTheWholeDomain(): void
    {
        $this->assertSame('%@synthetic.byte8.io', SyntheticIdentity::EMAIL_LIKE);
    }
}
