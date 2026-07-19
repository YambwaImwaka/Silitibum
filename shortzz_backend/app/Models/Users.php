<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Users extends Model
{
    use HasFactory;
    public $table = "tbl_users";

    // Never serialize credential material into API payloads. (Admin blades
    // read $user->password directly for dummy users — attribute access is
    // unaffected by $hidden.)
    protected $hidden = ['password'];

    public function links()
    {
        return $this->hasMany(UserLinks::class, 'user_id', 'id');
    }
    public function stories()
    {
        return $this->hasMany(Story::class, 'user_id', 'id');
    }

}
