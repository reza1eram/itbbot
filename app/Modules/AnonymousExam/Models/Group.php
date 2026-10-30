<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $table = 'ax_groups';

    protected $fillable = ['chat_id', 'title', 'is_active', 'members_count', 'registered_at'];

    protected $casts = [
        'chat_id' => 'integer',
        'is_active' => 'boolean',
        'registered_at' => 'datetime',
    ];

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'group_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }
}
