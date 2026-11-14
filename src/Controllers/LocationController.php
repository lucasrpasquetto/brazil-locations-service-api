<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LocationController
{
    public function health(Request $request, Response $response): Response
    {
        try {
            Database::pdo()->query('select 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'error';
        }

        return $this->json($response, [
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'database' => $db,
            'time' => gmdate('c'),
        ], $db === 'ok' ? 200 : 503);
    }

    public function countries(Request $request, Response $response): Response
    {
        $rows = Database::pdo()
            ->query('select id, name, slug, phone_code from countries order by name')
            ->fetchAll();

        return $this->json($response, ['data' => $rows]);
    }

    public function regions(Request $request, Response $response): Response
    {
        $rows = Database::pdo()
            ->query('select id, country_id, name, sigla from regions order by id')
            ->fetchAll();

        return $this->json($response, ['data' => $rows]);
    }

    public function states(Request $request, Response $response): Response
    {
        $sql = 'select s.id, s.slug as uf, s.name, s.code as ibge_code, r.sigla as region
                from states s
                join regions r on r.id = s.region_id
                order by s.slug';

        return $this->json($response, ['data' => Database::pdo()->query($sql)->fetchAll()]);
    }

    public function citiesByState(Request $request, Response $response, array $args): Response
    {
        $uf = strtoupper($args['uf'] ?? '');
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 1000);
        $limit = max(1, min($limit, 1000));

        $stmt = Database::pdo()->prepare(
            'select c.id, c.code as ibge_code, c.name, s.slug as uf
             from cities c
             join states s on s.id = c.state_id
             where s.slug = :uf
             order by c.name
             limit ' . $limit
        );
        $stmt->execute(['uf' => $uf]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return $this->json($response, ['error' => 'uf_not_found', 'message' => 'UF inexistente ou sem municipios', 'uf' => $uf], 404);
        }

        return $this->json($response, ['uf' => $uf, 'count' => count($rows), 'data' => $rows]);
    }

    public function searchCities(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $term = trim((string) ($params['search'] ?? ''));
        $uf = strtoupper((string) ($params['uf'] ?? ''));
        $limit = (int) ($params['limit'] ?? 20);
        $limit = max(1, min($limit, 100));

        if (mb_strlen($term) < 2) {
            return $this->json($response, ['error' => 'term_too_short', 'message' => 'search exige ao menos 2 caracteres'], 422);
        }

        $termo = $this->normalize($term);

        $sql = 'select c.id, c.code as ibge_code, c.name, s.slug as uf
                from cities c
                join states s on s.id = c.state_id
                where c.name_search like :term';
        $bind = ['term' => '%' . $termo . '%'];

        if ($uf !== '') {
            $sql .= ' and s.slug = :uf';
            $bind['uf'] = $uf;
        }

        $sql .= ' order by (c.name_search like :prefixo) desc, c.name limit ' . $limit;
        $bind['prefixo'] = $termo . '%';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return $this->json($response, ['search' => $term, 'uf' => $uf ?: null, 'count' => count($rows), 'data' => $rows]);
    }

    public function showCity(Request $request, Response $response, array $args): Response
    {
        $code = preg_replace('/\D/', '', (string) ($args['code'] ?? ''));

        $stmt = Database::pdo()->prepare(
            'select c.id, c.code as ibge_code, c.name, s.slug as uf, s.name as state_name, r.name as region
             from cities c
             join states s on s.id = c.state_id
             join regions r on r.id = s.region_id
             where c.code = :code'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        if (! $row) {
            return $this->json($response, ['error' => 'city_not_found', 'code' => $code], 404);
        }

        return $this->json($response, $row);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        return strtr($value, $map);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=86400, stale-while-revalidate=604800')
            ->withStatus($status);
    }
}
