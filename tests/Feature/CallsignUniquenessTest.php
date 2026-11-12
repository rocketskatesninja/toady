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

    public function test_onboarding_codename_format_and_uniqueness_messages(): void
    {
        $this->mkUser(['callsign' => 'Taken1', 'faction' => 'ENL']);
        foreach (['ab' => User::CALLSIGN_MESSAGE, 'bad name!' => User::CALLSIGN_MESSAGE, 'TAKEN1' => 'That codename is already taken.'] as $try => $message) {
            $u = $this->mkUser(['faction' => 'ENL', 'email' => md5($try).'@x.test']);
            $this->actingAs($u)->post('/onboard', ['callsign' => $try, 'faction' => 'ENL'])->assertSessionHasErrors(['callsign' => $message]);
            $this->assertNull($u->fresh()->callsign);
        }
        $u = $this->mkUser(['faction' => 'ENL', 'email' => 'fresh@x.test']);
        $this->actingAs($u)->post('/onboard', ['callsign' => 'Fresh7', 'faction' => 'ENL'])->assertSessionHasNoErrors();
        $this->assertSame('Fresh7', $u->fresh()->callsign);
    }
}
