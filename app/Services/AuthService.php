<?php

namespace App\Services;

use App\Events\GroupChallengeStatusUpdated;
use App\Models\User;
use App\Models\TeamConnection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\ResetPasswordMail;
use App\Repositories\Auth\AuthRepositoryInterface;
use App\Repositories\Auth\PasswordResetRepositoryInterface;
use App\Repositories\Auth\LogoutRepositoryInterface;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class AuthService
{
    public function __construct(
        protected AuthRepositoryInterface $authRepositoryInterface,
        protected PasswordResetRepositoryInterface $passwordResetRepositoryInterface,
        protected LogoutRepositoryInterface $logoutRepositoryInterface,
        protected RevenueCatService $revenueCatService,
    ) {}

    public function register(array $data): array
    {
        $user = $this->authRepositoryInterface->createUser($data);

        // TODO: Re-enable free trial granting when the promo flow is ready again.
        // $this->revenueCatService->grantFreeTrial(
        //     (string) $user->id,
        //     $data['os'] ?? 'android'
        // );

        $token = $user->createToken('mobile')->plainTextToken;

        return compact('user', 'token');
    }

    public function login(array $data): array
    {
        if (empty($data['email']) || empty($data['password'])) {
            throw ValidationException::withMessages([
                'email' => ['Email address is required.'],
                'password' => ['Password is required.'],
            ]);
        }

        $user = $this->authRepositoryInterface->findByEmail($data['email']);

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['No account found with this email address.'],
            ]);
        }

        if (!Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $this->authRepositoryInterface->updateLastLogin($user);

        // Load subscription relationship
        $user->load('subscription');

        $token = $user->createToken('mobile')->plainTextToken;

        return compact('user', 'token');
    }

    public function getCurrentUser()
    {
        return $this->authRepositoryInterface->getCurrentUser();
    }

    public function updateCurrentUserStatus(string $status): User
    {
        $user = $this->authRepositoryInterface->getCurrentUser();

        if (! $user) {
            abort(401, 'Authentication is required.');
        }

        return $this->updateStatusForUser((int) $user->id, $status);
    }

    public function updateCurrentUserFcmToken(string $fcmToken): User
    {
        $user = $this->authRepositoryInterface->getCurrentUser();

        if (! $user) {
            abort(401, 'Authentication is required.');
        }

        return $this->authRepositoryInterface->updateFcmTokenByUserId((int) $user->id, $fcmToken);
    }

    /**
     * Update any user's status and broadcast it, regardless of who is
     * currently authenticated. Used by group challenge flows to flip a
     * participant's status (e.g. to `in_challenge`) on their behalf.
     */
    public function updateStatusForUser(int $userId, string $status): User
    {
        $updatedUser = $this->authRepositoryInterface->updateStatusByUserId($userId, $status);

        $recipientIds = TeamConnection::query()
            ->where('user_id', $updatedUser->id)
            ->pluck('connected_user_id')
            ->push($updatedUser->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        event(new GroupChallengeStatusUpdated(
            userId: (int) $updatedUser->id,
            recipientIds: $recipientIds,
            payload: [
                'status' => $updatedUser->status,
                'name' => $updatedUser->name,
                'email' => $updatedUser->email,
            ],
        ));

        return $updatedUser;
    }

    public function logout(): void
    {
        $this->logoutRepositoryInterface->logoutCurrentUser();
    }

    public function sendResetPasswordEmail(string $email): void
    {
        $user = $this->authRepositoryInterface->findByEmail($email);

        if (!$user) {
            abort(404, 'No account found with that email address.');
        }

        $otp = $this->generateOtp();

        $this->passwordResetRepositoryInterface->createOrUpdateOtp($email, $otp);

        $this->sendMail($email, $otp, $user->name);
    }

    public function verifyOtp(array $data): string
    {
        $record = $this->passwordResetRepositoryInterface->getOtpRecord(
            $data['email'],
            $data['otp']
        );

        if (!$record) {
            abort(400, 'Invalid OTP. Please check the code and try again.');
        }

        if (Carbon::parse($record->expires_at)->isPast()) {
            $this->passwordResetRepositoryInterface->deleteOtp($data['email']);
            abort(400, 'OTP has expired. Please request a new one.');
        }

        $this->passwordResetRepositoryInterface->deleteOtp($data['email']);

        $payload = [
            'iss'   => config('app.url'),
            'sub'   => $data['email'],
            'iat'   => Carbon::now()->timestamp,
            'exp'   => Carbon::now()->addMinutes(15)->timestamp,
            'scope' => 'password_reset',
        ];

        $secret = config('app.key');

        return JWT::encode($payload, $secret, 'HS256');
    }

    public function resetPassword(array $data): void
    {
        $secret = config('app.key');

        try {
            $decoded = JWT::decode($data['reset_token'], new Key($secret, 'HS256'));
        } catch (ExpiredException $e) {
            abort(400, 'Reset token has expired. Please request a new OTP.');
        } catch (SignatureInvalidException $e) {
            abort(400, 'Invalid reset token signature.');
        } catch (\Exception $e) {
            abort(400, 'Invalid or malformed reset token.');
        }

        if (($decoded->scope ?? null) !== 'password_reset') {
            abort(400, 'Invalid reset token scope.');
        }

        if (($decoded->sub ?? null) !== $data['email']) {
            abort(400, 'Reset token does not match the provided email address.');
        }

        $this->authRepositoryInterface->updatePassword($data['email'], $data['password']);

        $user = $this->authRepositoryInterface->findByEmail($data['email']);
        if ($user) {
            $user->revokeMobileTokens();
        }
    }

    public function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function sendMail(string $toEmail, string $otp, string $userName): void
    {
        Mail::to($toEmail)->send(new ResetPasswordMail($otp, $userName));
    }
}
