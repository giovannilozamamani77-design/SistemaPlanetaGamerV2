<?php

namespace App\Domain\Prestamos\Policies;

use App\Models\User;

class LoanPolicy
{
    private function allowed(User $u, string $permission): bool
    {
        return $u->is_active && $u->hasAnyRole(['administrador', 'encargado', 'cajera']) && $u->can($permission);
    }

    public function viewAny(User $u): bool
    {
        return $this->allowed($u, 'prestamos.ver');
    }

    public function create(User $u): bool
    {
        return $this->allowed($u, 'prestamos.registrar');
    }

    public function importHistorical(User $u): bool
    {
        return $u->hasAnyRole(['administrador', 'encargado']) && $this->manualTerms($u) && $this->viewAll($u) && $this->viewAny($u);
    }

    public function annul(User $u): bool
    {
        return $u->hasRole('administrador') && $this->allowed($u, 'prestamos.anular');
    }

    public function manageLost(User $u): bool
    {
        return $u->hasRole('administrador') && $this->allowed($u, 'prestamos.objetos.gastos');
    }

    public function sell(User $u): bool
    {
        return $this->allowed($u, 'prestamos.objetos.vender');
    }

    public function settle(User $u): bool
    {
        return $this->allowed($u, 'prestamos.pagar_entregar');
    }

    public function renew(User $u): bool
    {
        return $this->allowed($u, 'prestamos.renovar');
    }

    public function viewAll(User $u): bool
    {
        return $this->allowed($u, 'prestamos.ver_todos');
    }

    public function manualTerms(User $u): bool
    {
        return $this->create($u) && $this->allowed($u, 'prestamos.condiciones.manuales');
    }

    public function configureObjects(User $u): bool
    {
        return $u->hasRole('administrador') && $this->allowed($u, 'prestamos.objetos.configurar');
    }

    public function manageFund(User $u): bool
    {
        return $u->hasRole('administrador') && $this->allowed($u, 'prestamos.fondo.administrar');
    }
}
