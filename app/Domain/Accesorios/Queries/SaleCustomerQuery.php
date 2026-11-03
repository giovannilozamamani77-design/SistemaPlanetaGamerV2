<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Borrower;
use App\Models\User;

class SaleCustomerQuery
{
    public function find(User $u, string $ci): ?array
    {
        app(AccessorySaleQuery::class)->authorize($u);
        app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
        $ci = LoanInput::normalize(mb_substr($ci, 0, 60));
        if ($ci === '') {
            return null;
        }
        $p = Borrower::where('ci', $ci)->first(['ci', 'name', 'phone']);
        if (! $p) {
            return null;
        }$phone = preg_replace('/\D/', '', $p->phone ?? '');
        if (strlen($phone) === 11 && str_starts_with($phone, '591')) {
            $phone = substr($phone, 3);
        }

        return ['ci' => $p->ci, 'name' => $p->name, 'phone' => $phone];
    }
}
