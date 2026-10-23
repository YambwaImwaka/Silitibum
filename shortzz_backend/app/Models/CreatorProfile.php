<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A creator's registered payout account. See the creator_profiles migration.
class CreatorProfile extends Model
{
    public $table = 'creator_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;

    protected $hidden = ['payout_account_name', 'name_override_by', 'notes'];

    protected $casts = [
        'adult_confirmed_at' => 'datetime',
        'payout_changed_at' => 'datetime',
    ];
}
