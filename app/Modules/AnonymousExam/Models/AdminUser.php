<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';
    public const ROLES = [self::ROLE_OWNER, self::ROLE_EDITOR, self::ROLE_VIEWER];

    protected $table = 'ax_admin_users';

    protected $fillable = ['name', 'username', 'password', 'role', 'is_active', 'bale_admin_chat_id', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /** viewer is read-only; owner and editor may change things */
    public function canEdit(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_EDITOR], true);
    }
}
