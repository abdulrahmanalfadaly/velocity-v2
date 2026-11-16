<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 13: a wallpaper behind the pages, kept on the account; your own picture stays private. */
class WallpaperTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1×1 PNG (no image library needed). */
    private static function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    public function test_choosing_a_wallpaper_and_your_own_picture(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->put(route('wallpaper.update'), ['wallpaper' => 'g-sage'])->assertSessionHas('undo.data', ['wallpaper' => null]);
        $this->get(route('appearance'))->assertInertia(fn (Assert $p) => $p->where('wallpaper', ['name' => 'g-sage', 'url' => null])->where('ownWallpaper', null));
        $this->put(route('wallpaper.update'), ['wallpaper' => 'p-coast']);
        $this->get(route('appearance'))->assertInertia(fn (Assert $p) => $p->where('wallpaper.url', '/wallpapers/quiet-coast.webp'));
        $this->put(route('wallpaper.update'), ['wallpaper' => 'neon'])->assertSessionHasErrors('wallpaper');
        $this->put(route('wallpaper.update'), ['wallpaper' => 'own'])->assertStatus(422);

        // Your own picture: it becomes the wallpaper, only you can see it, and a new one replaces it.
        $this->post(route('wallpaper.upload'), ['picture' => self::png('beach.png')])->assertSessionHasNoErrors();
        $first = $user->fresh()->wallpaper_path;
        $this->assertSame('own', $user->fresh()->wallpaper);
        Storage::disk('local')->assertExists($first);
        $this->get(route('appearance'))->assertInertia(fn (Assert $p) => $p->where('wallpaper.name', 'own')->where('wallpaper.url', fn ($u) => str_contains($u, '/settings/wallpaper/picture')));
        $this->get(route('wallpaper.picture'))->assertOk();
        $this->post(route('wallpaper.upload'), ['picture' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('picture');
        $this->post(route('wallpaper.upload'), ['picture' => self::png('hills.png')]);
        Storage::disk('local')->assertMissing($first);

        $this->delete(route('wallpaper.picture.destroy'));
        $this->assertNull($user->fresh()->wallpaper);
        $this->get(route('wallpaper.picture'))->assertNotFound();

        // Never in FYP mode.
        $user->forceFill(['wallpaper' => 'g-dusk', 'fyp_mode' => true])->save();
        $this->get(route('appearance'))->assertInertia(fn (Assert $p) => $p->where('wallpaper', null));
    }
}
