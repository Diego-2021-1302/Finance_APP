<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_login_requires_a_device_identifier(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'not-a-real-password',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['device_id']]]);
    }

    public function test_personal_access_tokens_do_not_expire_automatically(): void
    {
        $this->assertNull(config('sanctum.expiration'));
    }

    public function test_unauthenticated_api_requests_return_json_without_a_login_redirect(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required.',
                    'details' => null,
                ],
            ]);
    }

    public function test_session_restoration_checks_are_not_limited_as_login_attempts(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        }
    }
}
