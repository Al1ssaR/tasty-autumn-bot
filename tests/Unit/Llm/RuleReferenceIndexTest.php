<?php

namespace Tests\Unit\Llm;

use App\Llm\RuleReferenceIndex;
use PHPUnit\Framework\TestCase;

class RuleReferenceIndexTest extends TestCase
{
    private RuleReferenceIndex $index;

    protected function setUp(): void
    {
        $rules = file_get_contents(dirname(__DIR__, 3).'/docs/promo-rules.md');
        $this->assertIsString($rules);
        $this->index = new RuleReferenceIndex($rules);
    }

    public function test_existing_reference_is_accepted(): void
    {
        $this->assertTrue($this->index->contains('2.3'));
    }

    public function test_multiple_existing_references_are_indexed(): void
    {
        $this->assertTrue($this->index->contains('5.5'));
        $this->assertTrue($this->index->contains('10.3'));
        $this->assertGreaterThan(20, count($this->index->all()));
    }

    public function test_unknown_and_malformed_references_are_rejected(): void
    {
        $this->assertFalse($this->index->contains('99.99'));
        $this->assertFalse($this->index->contains('п. 2.3'));
        $this->assertFalse($this->index->contains('2'));
    }
}
