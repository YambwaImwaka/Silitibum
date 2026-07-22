<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoinTopUpRequests extends Model
{
    use HasFactory;
    public $table = "tbl_coin_topup_request";

    public function user()
    {
        return $this->hasOne(Users::class, 'id', 'user_id');
    }
}
