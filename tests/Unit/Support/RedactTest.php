<?php

namespace Tests\Unit\Support;

use App\Support\Redact;
use PHPUnit\Framework\TestCase;

class RedactTest extends TestCase
{
    public function test_emails_keep_only_the_first_character_and_the_domain(): void
    {
        $this->assertSame('m***@fleetfuel.test', Redact::email('manager.atlas@fleetfuel.test'));
        $this->assertSame('***', Redact::email('not-an-email'));
        $this->assertSame('***', Redact::email('@fleetfuel.test'));
        $this->assertSame('***', Redact::email(null));
    }

    public function test_card_numbers_keep_only_the_last_four_characters(): void
    {
        $this->assertSame('••••-001', Redact::cardNumber('FF-ATLAS-001'));
        $this->assertSame('••••CKED', Redact::cardNumber('FF-ATLAS-BLOCKED'));
    }
}
