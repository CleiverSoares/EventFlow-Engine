<?php
header('Content-Type: application/json');
$path = $_SERVER['REQUEST_URI'] ?? '/';
if (preg_match('#/cnpj/v1/(\d+)#', $path, $m)) {
    echo json_encode([
        'cnpj' => $m[1],
        'razao_social' => 'EMPRESA DEMO LTDA',
        'nome_fantasia' => 'Demo',
        'uf' => 'SP',
        'municipio' => 'Sao Paulo',
    ]);
    exit;
}
http_response_code(404);
echo '{"message":"not found"}';