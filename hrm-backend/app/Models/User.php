<?php

namespace App\Models;

use App\Support\Files;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_hr' => 'boolean',
            'is_active' => 'boolean',
            'birth_date' => 'date',
            'joining_date' => 'date',
            'passport_expire_date' => 'date',
        ];
    }

    /** Only HR and admins can open the web admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && ($this->is_admin || $this->is_hr);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }

    public function department() { return $this->belongsTo(Department::class); }
    public function designation() { return $this->belongsTo(Designation::class); }
    public function manager() { return $this->belongsTo(User::class, 'manager_id'); }
    public function team() { return $this->hasMany(User::class, 'manager_id'); }
    public function attendances() { return $this->hasMany(Attendance::class); }
    public function appreciations() { return $this->hasMany(Appreciation::class)->latest(); }

    /** Absolute avatar URL (falls back to a generated placeholder). */
    public function avatarUrl(): string
    {
        return Files::url($this->avatar)
            ?? 'https://ui-avatars.com/api/?background=0D6EFD&color=fff&name='.urlencode($this->name);
    }

    /** Admin, HR, or manager of someone can approve requests. */
    public function canApprove(): bool
    {
        return $this->is_admin || $this->is_hr || $this->team()->exists();
    }

    /** Short card used by phonebook / search / members lists. */
    public function card(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => (string) $this->phone,
            'email' => $this->email,
            'designation' => $this->designation?->title ?? '',
            'department' => $this->department?->title ?? '',
            'avatar' => $this->avatarUrl(),
        ];
    }
}
