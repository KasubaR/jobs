<?php

namespace Tests\Feature;

use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_loads_the_register_with_nothing_active_or_approved(): void
    {
        $this->seed(SourceSeeder::class);

        $this->assertSame(7, Source::count());
        $this->assertSame(0, Source::where('is_active', true)->count());
        $this->assertSame(0, Source::where('access_status', 'approved')->count());
        $this->assertSame(3, Source::where('access_status', 'restricted')->count());
        $this->assertSame(0, Source::where('access_status', 'restricted')->where('retain_raw', true)->count());
    }

    public function test_seeder_is_rerunnable_and_keeps_later_decisions(): void
    {
        $this->seed(SourceSeeder::class);
        Source::where('name', 'JobWeb Zambia')->update(['access_status' => 'approved', 'is_active' => true]);

        $this->seed(SourceSeeder::class);

        $this->assertSame(7, Source::count());
        $jobweb = Source::where('name', 'JobWeb Zambia')->first();
        $this->assertSame('approved', $jobweb->access_status);
        $this->assertTrue($jobweb->is_active);
    }

    public function test_a_source_cannot_be_activated_unless_approved(): void
    {
        $this->seed(SourceSeeder::class);

        foreach (['pending', 'restricted'] as $status) {
            $source = Source::where('access_status', $status)->first();
            $source->is_active = true;

            try {
                $source->save();
                $this->fail("A {$status} source was activated.");
            } catch (\DomainException) {
                $this->assertFalse($source->fresh()->is_active);
            }
        }
    }

    public function test_an_approved_source_can_be_activated(): void
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        $this->assertTrue($source->fresh()->is_active);
    }
}
