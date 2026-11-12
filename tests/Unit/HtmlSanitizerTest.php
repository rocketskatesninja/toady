<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_keeps_safe_formatting_and_strips_dangerous_html(): void
    {
        $dirty = '<p>Hi <b>team</b></p>'
            .'<script>alert(1)</script>'
            .'<a href="https://x.test" onclick="evil()">ok link</a>'
            .'<a href="javascript:alert(1)">bad link</a>'
            .'<iframe src="//evil"></iframe>'
            .'<img src="x" onerror="evil()">';

        $clean = HtmlSanitizer::clean($dirty);

        // safe formatting + a clean link survive
        $this->assertStringContainsString('<p>Hi <b>team</b></p>', $clean);
        $this->assertStringContainsString('href="https://x.test"', $clean);
        $this->assertStringContainsString('ok link', $clean);

        // every dangerous vector is gone
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }
}
