<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Showcase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_gallery_shows_only_published_entries(): void
    {
        Showcase::create(['title' => 'Live op', 'published' => true, 'images' => []]);
        Showcase::create(['title' => 'Draft op', 'published' => false, 'images' => []]);

        $this->get('/showcase')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Showcase', false)->has('entries', 1)->where('entries.0.title', 'Live op'));
    }

    public function test_admin_creates_an_entry_with_image_and_tags_then_it_serves(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $tagged = $this->mkUser(['google_id' => 't', 'callsign' => 'Tagged', 'faction' => 'ENL']);

        $this->actingAs($admin)->post('/admin/showcase', [
            'title' => 'My Fan', 'story' => 'great op', 'credit' => 'Me',
            'tagged_ids' => [$tagged->id], 'published' => true,
            'images' => [UploadedFile::fake()->image('op.jpg', 800, 600)],
        ])->assertRedirect();

        $entry = Showcase::firstOrFail();
        $this->assertSame('My Fan', $entry->title);
        $this->assertCount(1, $entry->images);
        $this->assertSame([$tagged->id], $entry->tagged_ids);
        Storage::disk('local')->assertExists($entry->images[0]);

        $this->get("/showcase/{$entry->id}/img/0")->assertOk(); // public, serves the image
    }

    public function test_unpublished_entry_images_are_not_served(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('showcase/x.svg', '<svg/>');
        $entry = Showcase::create(['title' => 'Draft', 'published' => false, 'images' => ['showcase/x.svg']]);

        $this->get("/showcase/{$entry->id}/img/0")->assertNotFound();
    }

    public function test_non_admin_cannot_reach_or_post_to_the_manager(): void
    {
        $user = $this->mkUser(['google_id' => 'n', 'callsign' => 'Nope', 'faction' => 'ENL']);

        $this->actingAs($user)->get('/admin/showcase')->assertForbidden();
        $this->actingAs($user)->post('/admin/showcase', ['title' => 'x'])->assertForbidden();
    }

    public function test_update_keeps_selected_photos_deletes_removed_and_appends_new(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        foreach (['a', 'b', 'c'] as $k) {
            Storage::disk('local')->put("showcase/{$k}.svg", '<svg/>');
        }
        $entry = Showcase::create(['title' => 'Op', 'published' => true, 'images' => ['showcase/a.svg', 'showcase/b.svg', 'showcase/c.svg']]);

        // keep indices 0 + 2 (drop index 1, 'b'), append one new upload
        $this->actingAs($admin)->post("/admin/showcase/{$entry->id}", [
            'title' => 'Op', 'published' => true, 'keep' => [0, 2],
            'images' => [UploadedFile::fake()->image('new.jpg', 400, 300)],
        ])->assertRedirect();

        $entry->refresh();
        $this->assertCount(3, $entry->images);                       // a, c, new
        $this->assertSame('showcase/a.svg', $entry->images[0]);
        $this->assertSame('showcase/c.svg', $entry->images[1]);
        Storage::disk('local')->assertMissing('showcase/b.svg');     // the removed photo is gone from disk
        Storage::disk('local')->assertExists($entry->images[2]);     // the new upload landed
    }

    public function test_public_page_is_enabled_by_default(): void
    {
        $this->get('/showcase')->assertOk();
    }

    public function test_admin_can_disable_and_reenable_the_public_page(): void
    {
        $admin = $this->adminUser();
        Showcase::create(['title' => 'Live op', 'published' => true, 'images' => []]);

        $this->actingAs($admin)->put('/admin/showcase/enabled', ['enabled' => false])->assertRedirect();
        $this->assertFalse((bool) Setting::get('showcase_enabled'));
        $this->get('/showcase')->assertNotFound();

        $this->actingAs($admin)->put('/admin/showcase/enabled', ['enabled' => true])->assertRedirect();
        $this->get('/showcase')->assertOk();
    }

    public function test_disabling_the_page_also_blocks_direct_image_urls(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('showcase/x.svg', '<svg/>');
        $entry = Showcase::create(['title' => 'Live', 'published' => true, 'images' => ['showcase/x.svg']]);
        Setting::put('showcase_enabled', false);

        $this->get("/showcase/{$entry->id}/img/0")->assertNotFound();
    }

    public function test_non_admin_cannot_toggle_the_public_page(): void
    {
        $user = $this->mkUser(['google_id' => 'n2', 'callsign' => 'Nope2', 'faction' => 'ENL']);

        $this->actingAs($user)->put('/admin/showcase/enabled', ['enabled' => false])->assertForbidden();
        $this->assertTrue((bool) Setting::get('showcase_enabled', true));
    }

    private function adminUser(): User
    {
        $admin = $this->mkUser(['google_id' => 'adm', 'callsign' => 'Boss', 'faction' => 'ENL']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }
}
