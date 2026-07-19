<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    use HasFactory;
    public $table = 'verification_codes';

    const TYPE_VERIFY_EMAIL = 'verify_email';
    const TYPE_RESET_PASSWORD = 'reset_password';

    const EXPIRY_MINUTES = 15;
    const MAX_ATTEMPTS = 5;
    const MAX_SENDS_PER_HOUR = 3;
}
