<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class Borrower extends Model
{
    public $timestamps = false;

    protected $table = 'loan_borrowers';

    protected $guarded = ['id'];

    public function profileVersion(): string
    {
        return hash('sha256', json_encode($this->only(['ci', 'name', 'phone']), JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return [];
    }
}
