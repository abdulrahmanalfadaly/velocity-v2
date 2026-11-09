<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'timezone', 'fyp_mode'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->fyp_mode ??= (bool) config('velocity.fyp_default');
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'fyp_mode' => 'boolean',
        ];
    }

    /** A brand-new account uses Velocity's default zone until the user picks one. */
    protected function timezone(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn (?string $value) => $value ?: config('velocity.timezone'));
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class)->orderBy('position');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class)->orderBy('position');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class);
    }

    public function weightProfile(): HasOne
    {
        return $this->hasOne(WeightProfile::class);
    }

    /** The user's weights, created with the report's AHP defaults the first time. */
    public function weights(): WeightProfile
    {
        return $this->weightProfile ??= $this->weightProfile()->create([
            'importance' => 0.42, 'urgency' => 0.23, 'deadline' => 0.23, 'pressure' => 0.12, 'horizon_days' => 7, 'hours_per_day' => 4,
        ]);
    }
}
