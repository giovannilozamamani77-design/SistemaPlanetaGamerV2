<?php

namespace App\Infrastructure\Configuraciones;

use Illuminate\Database\Schema\Blueprint;

class PortableIndexBlueprint extends Blueprint
{
    protected function createIndexName($type, array $columns): string
    {
        $name = parent::createIndexName($type, $columns);
        if (in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true) && strlen($name) > 64) {
            return substr($name, 0, 47).'_'.substr(hash('sha256', $name), 0, 16);
        }

        return $name;
    }
}
