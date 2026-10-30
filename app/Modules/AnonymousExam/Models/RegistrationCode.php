<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationCode extends Model
{
    protected $table = 'ax_registration_codes';

    protected $fillable = ['code_hash', 'created_by_admin_id', 'group_id', 'expires_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];
}
