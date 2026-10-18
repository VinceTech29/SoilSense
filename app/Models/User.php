<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'organization',
        'region',
        'phone',
        'avatar_url',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * Calculate 2-letter uppercase initials from user's full name
     */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim($this->name ?? 'User'));
        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1));
        }

        return strtoupper(mb_substr($this->name ?? 'US', 0, 2));
    }

    /**
     * Retrieve a specific user preference with an optional default
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        $prefs = $this->preferences ?? [];
        return $prefs[$key] ?? $default;
    }

    /**
     * Set a preference key-value pair and persist to preferences JSON
     */
    public function setPreference(string $key, mixed $value): void
    {
        $prefs = $this->preferences ?? [];
        $prefs[$key] = $value;
        $this->preferences = $prefs;
    }
}
