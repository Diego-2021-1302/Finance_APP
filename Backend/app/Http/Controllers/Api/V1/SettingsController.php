<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = UserPreference::query()->firstOrCreate(['user_id' => $request->user()->id], [
            'currency' => 'DOP', 'timezone' => 'America/Santo_Domingo', 'reminders' => 3, 'week_starts' => 'Monday', 'appearance' => 'system', 'pay_frequency' => 'monthly',
        ]);
        return response()->json(['data' => $this->serialize($settings)]);
    }

    public function update(SettingsRequest $request): JsonResponse
    {
        $settings = UserPreference::query()->updateOrCreate(['user_id' => $request->user()->id], $request->validated());
        return response()->json(['data' => $this->serialize($settings)]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:profile,piggy-bank'],
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=2048,max_height=2048'],
        ]);

        $settings = UserPreference::query()->firstOrCreate(['user_id' => $request->user()->id]);
        $column = $validated['type'] === 'profile' ? 'profile_photo_path' : 'piggy_bank_photo_path';
        $oldPath = $settings->{$column};
        $path = $request->file('photo')->store('profile-photos/' . $request->user()->id, 'local');

        if (!$path) {
            abort(500, 'No se pudo guardar la imagen.');
        }

        $settings->update([$column => $path]);
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json(['data' => $this->serialize($settings->refresh())], 201);
    }

    public function photo(int $user, string $type): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(in_array($type, ['profile', 'piggy-bank'], true), 404);
        $settings = UserPreference::query()->where('user_id', $user)->firstOrFail();
        $column = $type === 'profile' ? 'profile_photo_path' : 'piggy_bank_photo_path';
        $path = $settings->{$column};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function serialize(UserPreference $settings): array
    {
        $photoUrl = fn (string $type, ?string $path): ?string => $path
            ? URL::temporarySignedRoute(
                'api.v1.settings.photos.show',
                now()->addDays(30),
                ['user' => $settings->user_id, 'type' => $type],
                false,
            )
            : null;

        return [
            'currency' => $settings->currency,
            'timezone' => $settings->timezone,
            'reminders' => $settings->reminders,
            'week_starts' => $settings->week_starts,
            'appearance' => $settings->appearance,
            'pay_frequency' => $settings->pay_frequency ?? 'monthly',
            'profile_photo_url' => $photoUrl('profile', $settings->profile_photo_path),
            'piggy_bank_photo_url' => $photoUrl('piggy-bank', $settings->piggy_bank_photo_path),
        ];
    }
}
