<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Settings → Appearance: the theme (kept on this device) and the wallpaper (kept on the account). */
class AppearanceController extends Controller
{
    /** Soft gradients (each with a light and a dark look), photos, or the user's own picture. */
    public const WALLPAPERS = [
        'g-lavender' => null, 'g-mist' => null, 'g-dawn' => null, 'g-sage' => null, 'g-dusk' => null,
        'p-alpine' => 'alpine-stillness.webp', 'p-mist' => 'morning-mist.webp', 'p-dusk' => 'open-dusk.webp', 'p-coast' => 'quiet-coast.webp',
        'own' => null,
    ];

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/appearance', ['ownWallpaper' => self::ownUrl($request->user())]);
    }

    /** Choose one, or none (null). */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $name = $request->validate(['wallpaper' => ['present', 'nullable', Rule::in(array_keys(self::WALLPAPERS))]])['wallpaper'];
        abort_if($name === 'own' && ! $user->wallpaper_path, 422, 'Upload a picture first.');
        $before = $user->wallpaper;
        $user->forceFill(['wallpaper' => $name])->save();

        return back()->with('toast', $name ? 'Wallpaper set' : 'No wallpaper')->with('undo', $this->undo('put-wallpaper', 0, ['wallpaper' => $before]));
    }

    /** Your own picture: it replaces the one before, and becomes the wallpaper. */
    public function upload(Request $request): RedirectResponse
    {
        $user = $request->user();
        $file = $request->validate(['picture' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192']])['picture'];
        $old = $user->wallpaper_path;
        $ext = strtolower($file->guessExtension() ?: 'jpg');
        $path = $file->storeAs("wallpapers/{$user->id}", Str::uuid().'.'.$ext, 'local');
        $user->forceFill(['wallpaper' => 'own', 'wallpaper_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('toast', 'Wallpaper set');
    }

    /** Takes your picture away (and off the pages, if it was there). */
    public function destroyPicture(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->wallpaper_path) {
            Storage::disk('local')->delete($user->wallpaper_path);
        }
        $user->forceFill(['wallpaper_path' => null, 'wallpaper' => $user->wallpaper === 'own' ? null : $user->wallpaper])->save();

        return back()->with('toast', 'Picture removed');
    }

    /** Your picture, for your own pages only. */
    public function picture(Request $request): StreamedResponse
    {
        $path = $request->user()->wallpaper_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }

    /** What the pages need: its name and, for a picture, where it is. Null in FYP mode or with none. */
    public static function shared(User $user): ?array
    {
        $name = $user->wallpaper;
        if ($user->fyp_mode || ! $name || ! array_key_exists($name, self::WALLPAPERS)) {
            return null;
        }
        $url = match (true) {
            $name === 'own' => self::ownUrl($user),
            self::WALLPAPERS[$name] !== null => '/wallpapers/'.self::WALLPAPERS[$name],
            default => null,
        };

        return $name === 'own' && ! $url ? null : ['name' => $name, 'url' => $url];
    }

    /** The address of your picture; it changes with each new one, so browsers can keep it. */
    private static function ownUrl(User $user): ?string
    {
        return $user->wallpaper_path ? route('wallpaper.picture', ['v' => substr(md5($user->wallpaper_path), 0, 10)]) : null;
    }
}
