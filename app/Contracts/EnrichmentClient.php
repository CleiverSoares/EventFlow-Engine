<?php

namespace App\Contracts;

interface EnrichmentClient
{
    /**
     * @return array<string, mixed>
     */
    public function enrichByCnpj(string $cnpj): array;
}
