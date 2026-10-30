<?php

namespace Tests\Feature;

use App\Models\Op;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uploads_a_photo_that_is_stored_served_and_shown_in_the_roster(): void
    {
        Storage::fake('local');
        $user = $this->mkUser(['callsign' => 'Vec', 'faction' => 'ENL']);

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->avatar);
        Storage::disk('local')->assertExists($user->avatar);

        // streamed back to a signed-in agent
        $this->actingAs($user)->get("/users/{$user->id}/avatar")->assertOk();

        // present in the roster serialization
        $op = Op::create(['owner_id' => $user->id, 'name' => 'Op', 'type' => 'any_order', 'status' => 'active', 'join_token' => 'AV'.uniqid()]);
        $op->participants()->create(['user_id' => $user->id, 'role' => 'operative']);
        $this->actingAs($user)->get("/ops/{$op->public_id}")
            ->assertInertia(fn (Assert $p) => $p->where('participants.0.avatar', fn ($v) => $v !== null)->etc());
    }

    public function test_user_removes_their_photo(): void
    {
        Storage::fake('local');
        $user = $this->mkUser(['callsign' => 'Nyx', 'faction' => 'RES']);

        $this->actingAs($user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)])->assertRedirect();
        $path = $user->refresh()->avatar;

        $this->actingAs($user)->delete('/profile/avatar')->assertRedirect();
        $this->assertNull($user->refresh()->avatar);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_non_images_are_rejected(): void
    {
        $user = $this->mkUser(['callsign' => 'Z', 'faction' => 'ENL']);
        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('payload.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');
    }
}
