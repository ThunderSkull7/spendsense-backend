<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetWorthValuation extends Model
{
    use HasFactory;

    protected $fillable = [
        'net_worth_entry_id',
        'value_amount',
        'valuation_date',
        'note',
    ];

    protected $casts = [
        'value_amount' => 'decimal:2',
        'valuation_date' => 'date',
    ];

    public function entry()
    {
        return $this->belongsTo(NetWorthEntry::class, 'net_worth_entry_id');
    }
}
