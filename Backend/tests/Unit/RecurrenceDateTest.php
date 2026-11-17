<?php

namespace Tests\Unit;

use App\Models\Commitment;
use App\Models\CommitmentOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurrenceDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurrence_preserves_original_base_day_across_short_months(): void
    {
        $user = User::factory()->create();

        $commitment = Commitment::create([
            'user_id' => $user->id,
            'name' => 'Factura de 31 de cada mes',
            'type' => 'utility',
            'amount' => '1500.00',
            'frequency' => 'monthly',
            'next_due_date' => '2026-01-31',
            'is_active' => true,
        ]);

        // Freeze time to Jan 2026
        Carbon::setTestNow(Carbon::parse('2026-01-31'));

        // Generate occurrences up to March 31
        $this->artisan('finance:generate-occurrences')->assertSuccessful();

        $occurrences = CommitmentOccurrence::where('commitment_id', $commitment->id)
            ->orderBy('due_date')
            ->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())
            ->toArray();

        $this->assertContains('2026-01-31', $occurrences);
        $this->assertContains('2026-02-28', $occurrences);
        $this->assertContains('2026-03-31', $occurrences);
    }
}
