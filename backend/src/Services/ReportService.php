<?php

declare(strict_types=1);

namespace App\Services;

final class ReportService
{
    public function buildHtml(array $jobs, array $summary, array $config = []): string
    {
        $date = (new \DateTimeImmutable('now'))->format('d/m/Y');
        $searches = $this->configuredSearches($config, $jobs);
        $sections = '';

        foreach ($searches as $search) {
            $key = $search['key'];
            $label = $search['label'];
            $items = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => ($job['category'] ?? null) === $key
            ));
            $rows = '';

            foreach ($items as $job) {
                $rows .= sprintf(
                    '<li><strong>%s</strong><br>%s<br>%s<br>Fecha: %s<br>Fuente: %s<br><a href="%s">Ver oferta</a></li>',
                    htmlspecialchars($job['title']),
                    htmlspecialchars($job['institution'] ?: 'Institución no indicada'),
                    htmlspecialchars($job['location'] ?: 'Ubicación no indicada'),
                    htmlspecialchars($job['posted_date'] ?? 'Sin fecha'),
                    htmlspecialchars($job['source']),
                    htmlspecialchars($job['url'])
                );
            }

            if ($rows === '') {
                $rows = '<li>Sin nuevas ofertas relevantes.</li>';
            }

            $sections .= sprintf('<h2>%s</h2><ul>%s</ul>', htmlspecialchars($label), $rows);
        }

        $incidents = sprintf(
            '<ul><li>Fuentes con error o bloqueo: %d</li><li>Duplicadas descartadas: %d</li><li>Antiguas descartadas: %d</li></ul>',
            (int) ($summary['errors'] ?? 0),
            (int) ($summary['duplicates_discarded'] ?? 0),
            (int) ($summary['discarded']['too_old'] ?? 0)
        );

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte diario</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
  <h1>Reporte diario de ofertas - {$date}</h1>
  <p>Total de nuevas ofertas relevantes: <strong>{$summary['new_jobs_count']}</strong></p>
  {$sections}
  <h2>Incidencias</h2>
  {$incidents}
</body>
</html>
HTML;
    }

    private function configuredSearches(array $config, array $jobs): array
    {
        $searches = $config['searches'] ?? null;
        if (is_array($searches) && $searches !== []) {
            $normalized = array_values(array_filter(array_map(static function ($search): ?array {
                if (!is_array($search)) {
                    return null;
                }

                $key = trim((string) ($search['key'] ?? ''));
                $label = trim((string) ($search['label'] ?? ''));
                if ($key === '') {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $label !== '' ? $label : ucwords(str_replace('_', ' ', $key)),
                ];
            }, $searches)));

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $derived = [];
        foreach ($jobs as $job) {
            $key = trim((string) ($job['category'] ?? ''));
            if ($key === '' || isset($derived[$key])) {
                continue;
            }

            $derived[$key] = [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
            ];
        }

        return array_values($derived);
    }
}
