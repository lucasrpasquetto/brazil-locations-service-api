<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Cache;
use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LocationController
{
    private Cache $cache;

    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache ?? new Cache();
    }

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
            'cache' => $this->cache->driver(),
            'time' => gmdate('c'),
        ], $db === 'ok' ? 200 : 503, 'BYPASS');
    }

    public function countries(Request $request, Response $response): Response
    {
        return $this->cached($response, 'countries', 86400, function (): array {
            $rows = Database::pdo()
                ->query('select id, name, slug, phone_code from countries order by name')
                ->fetchAll();

            return ['data' => $rows];
        });
    }

    public function regions(Request $request, Response $response): Response
    {
        return $this->cached($response, 'regions', 86400, function (): array {
            $rows = Database::pdo()
                ->query('select id, country_id, name, sigla from regions order by id')
                ->fetchAll();

            return ['data' => $rows];
        });
    }

    public function states(Request $request, Response $response): Response
    {
        return $this->cached($response, 'states', 86400, function (): array {
            $sql = 'select s.id, s.slug as uf, s.name, s.code as ibge_code, r.sigla as region
                    from states s
                    join regions r on r.id = s.region_id
                    order by s.slug';

            return ['data' => Database::pdo()->query($sql)->fetchAll()];
        });
    }

    public function citiesByState(Request $request, Response $response, array $args): Response
    {
        $uf = strtoupper($args['uf'] ?? '');
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 1000);
        $limit = max(1, min($limit, 1000));

        $chave = "cities:$uf:$limit";
        $payload = $this->cache->get($chave);
        $estado = 'HIT';

        if ($payload === null) {
            $estado = 'MISS';
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

            $payload = $rows === []
                ? ['__not_found' => true]
                : ['uf' => $uf, 'count' => count($rows), 'data' => $rows];

            $this->cache->set($chave, $payload, 86400);
        }

        if (isset($payload['__not_found'])) {
            return $this->json($response, ['error' => 'uf_not_found', 'message' => 'UF inexistente ou sem municipios', 'uf' => $uf], 404, $estado);
        }

        return $this->json($response, $payload, 200, $estado);
    }

    public function searchCities(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $term = trim((string) ($params['search'] ?? ''));
        $uf = strtoupper((string) ($params['uf'] ?? ''));
        $limit = (int) ($params['limit'] ?? 20);
        $limit = max(1, min($limit, 100));

        if (mb_strlen($term) < 2) {
            return $this->json($response, ['error' => 'term_too_short', 'message' => 'search exige ao menos 2 caracteres'], 422, 'BYPASS');
        }

        $termo = $this->normalize($term);
        $chave = 'search:' . $termo . ':' . $uf . ':' . $limit;

        // busca muda de acordo com o termo; TTL curto evita encher o cache de lixo
        return $this->cached($response, $chave, 300, function () use ($term, $termo, $uf, $limit): array {
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

            return ['search' => $term, 'uf' => $uf ?: null, 'count' => count($rows), 'data' => $rows];
        });
    }

    public function showCity(Request $request, Response $response, array $args): Response
    {
        $code = preg_replace('/\D/', '', (string) ($args['code'] ?? ''));

        $chave = "city:$code";
        $payload = $this->cache->get($chave);
        $estado = 'HIT';

        if ($payload === null) {
            $estado = 'MISS';
            $stmt = Database::pdo()->prepare(
                'select c.id, c.code as ibge_code, c.name, s.slug as uf, s.name as state_name, r.name as region
                 from cities c
                 join states s on s.id = c.state_id
                 join regions r on r.id = s.region_id
                 where c.code = :code'
            );
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch();

            $payload = $row === false ? ['__not_found' => true] : $row;
            $this->cache->set($chave, $payload, 86400);
        }

        if (isset($payload['__not_found'])) {
            return $this->json($response, ['error' => 'city_not_found', 'code' => $code], 404, $estado);
        }

        return $this->json($response, $payload, 200, $estado);
    }

    private function cached(Response $response, string $key, int $ttl, callable $callback): Response
    {
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $this->json($response, $cached, 200, 'HIT');
        }

        $payload = $callback();
        $this->cache->set($key, $payload, $ttl);

        return $this->json($response, $payload, 200, 'MISS');
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

    private function json(Response $response, array $payload, int $status = 200, string $cacheState = 'BYPASS'): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
            'X-Cache' => $cacheState,
            'X-Cache-Driver' => $this->cache->driver(),
        ];

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withStatus($status);
    }
}
