<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallsignUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_callsign_taken_is_case_insensitive(): void
    {
        $u = $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);

        $this->assertTrue(User::callsignTaken('vector'));
        $this->assertTrue(User::callsignTaken('VECTOR'));
        $this->assertFalse(User::callsignTaken('Vektor'));
        $this->assertFalse(User::callsignTaken('vector', $u->id)); // the user's own row is ignored
    }

    public function test_onboarding_rejects_a_case_variant_of_an_existing_callsign(): void
    {
        $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);
        $newbie = $this->mkUser(['faction' => null]); // verified, not yet onboarded (no callsign)

        $this->actingAs($newbie)->post('/onboard', ['callsign' => 'vector', 'faction' => 'ENL']);

        $this->assertNull($newbie->fresh()->callsign); // case-variant is taken → not saved
    }

    public function test_database_index_blocks_a_case_variant(): void
    {
        $this->mkUser(['callsign' => 'Vector', 'faction' => 'ENL']);

        $this->expectException(QueryException::class);
        $this->mkUser(['callsign' => 'vector', 'faction' => 'RES']);
    }
}
