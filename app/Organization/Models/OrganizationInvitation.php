<?php

namespace App\Organization\Models;

use App\Organization\Database\Factories\OrganizationInvitationFactory;
use App\Organization\Enums\InvitationStatus;
use App\Organization\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitation extends Model
{
    use HasFactory;

    // Wrong codes allowed before an invitation can only be accepted with its link.
    public const MAX_CODE_ATTEMPTS = 5;

    public const CODE_LENGTH = 6;

    // Capital letters and numbers, without the look-alikes 0/O and 1/I.
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'organization_id',
        'email',
        'role',
        'token_hash',
        'code_hash',
        'code_failed_attempts',
        'token_encrypted',
        'code_encrypted',
        'status',
        'expires_at',
        'invited_by',
        'accepted_by_user_id',
        'accepted_at',
    ];

    protected $hidden = [
        'token_hash',
        'code_hash',
        'token_encrypted',
        'code_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'token_encrypted' => 'encrypted',
            'code_encrypted' => 'encrypted',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OrganizationInvitationFactory
    {
        return OrganizationInvitationFactory::new();
    }

    public static function generateCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    // Typed codes are not case sensitive.
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    // Keyed with the app key: a plain hash of a short code could be reversed from a database copy.
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', self::normalizeCode($code), (string) config('app.key'));
    }

    public function matchesCode(string $code): bool
    {
        return $this->code_hash !== null && hash_equals($this->code_hash, self::hashCode($code));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
