<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedContact extends Model
{
    protected $fillable = ['user_id', 'contact_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contact()
    {
        return $this->belongsTo(User::class, 'contact_id');
    }
}
