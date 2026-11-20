<?php
namespace App\Services\Membership;

use Google\Client;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GooglePlaySubscriptions
{
    public function ready(): bool
    {
        return config('google_play.auto_cancel_enabled') && is_readable((string) config('google_play.credentials_file'));
    }

    private function request(string $token, bool $cancel = false): array
    {
        // Do not propagate Google exceptions/URLs: their request URL contains the purchase token.
        try {
            if (! $this->ready()) throw new RuntimeException();
            $client = new Client();
            $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => 3, 'timeout' => 10]));
            $client->setAuthConfig(config('google_play.credentials_file'));
            $client->setScopes(['https://www.googleapis.com/auth/androidpublisher']);
            $auth = $client->fetchAccessTokenWithAssertion();
            if (empty($auth['access_token'])) throw new RuntimeException();
            $url = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'
                .rawurlencode(config('google_play.package_name')).'/purchases/subscriptionsv2/tokens/'.rawurlencode($token);
            $http = Http::withToken($auth['access_token'])->acceptJson()->connectTimeout(3)->timeout(10);
            $response = $cancel ? $http->post($url.':cancel', [
                'cancellationContext' => ['cancellationType' => 'USER_REQUESTED_STOP_RENEWALS'],
            ]) : $http->get($url);
            if (! $response->successful()) throw new RuntimeException();
            return $cancel ? [] : $response->json();
        } catch (Throwable) {
            throw new RuntimeException('Google Play verification is temporarily unavailable. Please retry.');
        }
    }

    public function get(string $token): array { return $this->request($token); }
    public function stopRenewal(string $token): void { $this->request($token, true); }
}
