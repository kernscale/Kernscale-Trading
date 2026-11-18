<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Member profile pictures: re-encoded to a 256px WebP on the private disk, served only to approved members. */
class AvatarController extends Controller
{
    private const SIZE = 256;

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ]);
        $user = $request->user();
        $encoded = $this->encode($request->file('avatar')->getPathname());
        $path = 'avatars/'.$user->id.'/'.Str::uuid().'.webp';
        abort_unless(Storage::disk('local')->put($path, $encoded, ['visibility' => 'private']) === true, 503, 'Avatar storage is unavailable.');
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture removed.')]);

        return to_route('profile.edit');
    }

    public function show(User $user): StreamedResponse
    {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /** Decode, centre-crop to a square and re-encode, which also drops metadata and any trailing payload. */
    private function encode(string $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file));
        if ($source === false) {
            throw ValidationException::withMessages(['avatar' => 'This image could not be read.']);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagewebp($canvas, null, 86);
        $encoded = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return $encoded;
    }
}
