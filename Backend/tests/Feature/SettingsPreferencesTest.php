<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pay_frequency_is_persisted_and_returned(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.pay_frequency', 'monthly');

        $this->withToken($token)->patchJson('/api/v1/settings', [
            'pay_frequency' => 'biweekly',
        ])->assertOk()->assertJsonPath('data.pay_frequency', 'biweekly');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'pay_frequency' => 'biweekly',
        ]);

        $this->withToken($token)->getJson('/api/v1/financial-plan')
            ->assertOk()
            ->assertJsonPath('data.days', 15);
        $this->withToken($token)->getJson('/api/v1/financial-plan?days=30')
            ->assertOk()
            ->assertJsonPath('data.days', 30);
    }

    public function test_profile_and_piggy_bank_photos_use_private_storage_and_signed_urls(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $profileResponse = $this->withToken($token)->post('/api/v1/settings/photos', [
            'type' => 'profile',
            'photo' => UploadedFile::fake()->image('profile.png', 120, 120),
        ], ['Accept' => 'application/json']);
        $profileResponse->assertCreated();
        $profileUrl = $profileResponse->json('data.profile_photo_url');
        $this->assertStringContainsString('signature=', $profileUrl);
        $this->get($profileUrl)->assertOk();

        $piggyResponse = $this->withToken($token)->post('/api/v1/settings/photos', [
            'type' => 'piggy-bank',
            'photo' => UploadedFile::fake()->image('piggy.webp', 300, 240),
        ], ['Accept' => 'application/json']);
        $piggyResponse->assertCreated();
        $piggyUrl = $piggyResponse->json('data.piggy_bank_photo_url');
        $this->assertStringContainsString('signature=', $piggyUrl);
        $this->get($piggyUrl)->assertOk();

        $this->assertNotNull(UserPreference::where('user_id', $user->id)->value('profile_photo_path'));
        $this->assertNotNull(UserPreference::where('user_id', $user->id)->value('piggy_bank_photo_path'));
    }
}