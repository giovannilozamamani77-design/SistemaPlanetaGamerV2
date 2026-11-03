<?php

namespace App\Domain\Tarjetas\Policies;

use App\Models\User;

class CardInventoryPolicy
{
    private function operational(User $u, string $permission): bool
    {
        return $u->is_active && $u->hasAnyRole(['administrador', 'encargado', 'cajera']) && $u->can($permission);
    }

    public function viewAny(User $u): bool
    {
        return $this->operational($u, 'tarjetas.inventario.ver');
    }

    public function history(User $u): bool
    {
        return $this->operational($u, 'tarjetas.movimientos.ver');
    }

    public function create(User $u): bool
    {
        return $this->operational($u, 'tarjetas.productos.crear') && $u->hasAnyRole(['administrador', 'encargado']);
    }

    public function update(User $u): bool
    {
        return $this->operational($u, 'tarjetas.precios.editar') && $u->hasRole('administrador');
    }

    public function initialize(User $u): bool
    {
        return $this->operational($u, 'tarjetas.inventario.inicializar') && $u->hasRole('administrador');
    }

    public function purchase(User $u): bool
    {
        return $this->operational($u, 'tarjetas.compras.registrar');
    }

    public function sell(User $u): bool
    {
        return $this->operational($u, 'tarjetas.ventas.registrar');
    }

    public function openSession(User $u): bool
    {
        return $this->operational($u, 'tarjetas.sesiones.abrir');
    }

    public function closeSession(User $u): bool
    {
        return $this->operational($u, 'tarjetas.sesiones.cerrar');
    }

    public function cashFunding(User $u): bool
    {
        return $this->operational($u, 'tarjetas.efectivo.aportar') && $u->hasAnyRole(['administrador', 'cajera']);
    }

    public function deposit(User $u): bool
    {
        return $this->operational($u, 'tarjetas.depositos.registrar');
    }

    public function configureDeposits(User $u): bool
    {
        return $this->operational($u, 'tarjetas.depositos.configurar') && $u->hasRole('administrador');
    }

    public function receive(User $u): bool
    {
        return $this->operational($u, 'tarjetas.entregas.registrar');
    }
}
