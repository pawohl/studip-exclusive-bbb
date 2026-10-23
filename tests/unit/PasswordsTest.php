<?php

namespace ExclusiveBBB\Tests;

use ExclusiveBBB\Passwords;
use PHPUnit\Framework\TestCase;

class PasswordsTest extends TestCase
{
    public function testGeneratedPasswordsAreUnambiguousAndRandom(): void
    {
        $a = Passwords::generate();
        $b = Passwords::generate();
        $this->assertSame(10, strlen($a));
        $this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]+$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function testRoleIsDerivedFromPassword(): void
    {
        $attendee = Passwords::hash('attendee-pw');
        $moderator = Passwords::hash('moderator-pw');

        $this->assertSame(Passwords::ROLE_ATTENDEE, Passwords::roleFor('attendee-pw', $attendee, $moderator));
        $this->assertSame(Passwords::ROLE_MODERATOR, Passwords::roleFor('moderator-pw', $attendee, $moderator));
        $this->assertNull(Passwords::roleFor('wrong', $attendee, $moderator));
        $this->assertNull(Passwords::roleFor('', $attendee, $moderator));
    }

    public function testHashesAreNotPlainText(): void
    {
        $this->assertStringNotContainsString('secret-pw', Passwords::hash('secret-pw'));
    }

    public function testPairValidation(): void
    {
        $this->assertNull(Passwords::validatePair('attendee1', 'moderator1'));
        $this->assertNotNull(Passwords::validatePair('short', 'moderator1'));
        $this->assertNotNull(Passwords::validatePair('samesame1', 'samesame1'));
    }
}
