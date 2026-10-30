<?php

namespace App\Modules\AnonymousExam\Models;

use Illuminate\Database\Eloquent\Model;

/** Manual membership list entry: only an HMAC of the member id, never the raw id */
class GroupMember extends Model
{
    public $timestamps = false;

    protected $table = 'ax_group_members';

    protected $fillable = ['group_id', 'member_hash'];
}
