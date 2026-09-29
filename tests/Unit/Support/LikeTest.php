<?php

namespace Tests\Unit\Support;

use App\Support\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase
{
    public function test_wildcards_typed_by_a_user_are_escaped(): void
    {
        $this->assertSame('%ATL%', Like::contains('ATL'));
        $this->assertSame('%50\%%', Like::contains('50%'));
        $this->assertSame('%ATL\_10%', Like::contains('ATL_10'));
        $this->assertSame('%a\\\\b%', Like::contains('a\\b'));
    }
}
