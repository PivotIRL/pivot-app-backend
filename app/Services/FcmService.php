<?php

namespace App\Services;

use App\Models\User;
use App\Models\Users;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected const TOKEN_CACHE_KEY = 'fcm_oauth_access_token';
    protected const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function send(User|Users $user, string $title, string $body, array $data = []): void
    {
        if (! $user->fcm_token) {
            return;
        }

        $this->sendToToken($user->fcm_token, $title, $body, $data);
    }

    /**
     * @param iterable<int, User|Users> $users
     */
    public function sendToMany(iterable $users, string $title, string $body, array $data = []): void
    {
        foreach ($users as $user) {
            $this->send($user, $title, $body, $data);
        }
    }

    protected function sendToToken(string $token, string $title, string $body, array $data): void
    {
        $this->deliver($token, $title, $body, $data);
    }

    /** True means FCM accepted the message, not that the device displayed it. */
    public function deliver(string $token, string $title, string $body, array $data = [], ?string $channel = null, ?string $tag = null): bool
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            return false;
        }

        $projectId = config('services.firebase.project_id');

        try {
            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => array_map('strval', $data),
                        'android' => array_filter([
                            'priority' => 'high',
                            'ttl' => $channel ? '3600s' : null,
                            'notification' => $channel ? array_filter(['channel_id' => $channel, 'tag' => $tag]) : null,
                        ]),
                        'apns' => [
                            'headers' => ['apns-priority' => '10'],
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('FCM push failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('FCM push threw an exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    protected function getAccessToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cached) {
            return $cached;
        }

        $credentialsPath = config('services.firebase.credentials');

        if (! $credentialsPath || ! is_readable($credentialsPath)) {
            Log::warning('FCM credentials file not configured; skipping push notification.');

            return null;
        }

        try {
            $credentials = json_decode(file_get_contents($credentialsPath), true, flags: JSON_THROW_ON_ERROR);

            $now = time();
            $jwt = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('Failed to obtain FCM OAuth token', ['body' => $response->body()]);

                return null;
            }

            $accessToken = $response->json('access_token');

            Cache::put(self::TOKEN_CACHE_KEY, $accessToken, now()->addMinutes(55));

            return $accessToken;
        } catch (\Throwable $e) {
            Log::warning('Failed to build FCM OAuth token', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
