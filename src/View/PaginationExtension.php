<?php

declare(strict_types=1);

namespace Ions\View;

use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig pagination renderer (Phase 10.3): `pagination(paginator)`.
 *
 * Registered on every Environment built by ViewFactory (AssetExtension
 * precedent). Renders Bootstrap-friendly, unstyled-usable markup —
 * nav > ul.pagination > li.page-item > a/span.page-link — with Previous/Next
 * controls and a windowed page list (current ±2, first/last always shown,
 * gaps as ellipses). Current query parameters are preserved on every link
 * (?page= excluded) via the paginator's query-string resolver, wired in
 * DatabaseProvider::boot().
 *
 * Template override: when the Twig loader can resolve `pagination.twig`
 * (i.e. the host committed views/pagination.twig at its template source
 * root), it is rendered instead and receives `paginator` plus the computed
 * `elements` window (entries: {type: 'page', page, url, active} or
 * {type: 'gap'}).
 *
 * A simple (non length-aware) paginator renders Previous/Next only.
 * A paginator with a single page renders an empty string.
 */
final class PaginationExtension extends AbstractExtension
{
    private const TEMPLATE = 'pagination.twig';

    /** Pages shown on each side of the current page. */
    private const ON_EACH_SIDE = 2;

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pagination', $this->render(...), [
                'is_safe' => ['html'],
                'needs_environment' => true,
            ]),
        ];
    }

    /**
     * Render the pagination controls for an Illuminate paginator.
     *
     * @param PaginatorContract<array-key, mixed> $paginator
     */
    public function render(Environment $env, PaginatorContract $paginator): string
    {
        // Preserve the current query string on every generated link (the
        // resolver excludes the page parameter itself). A no-op when no
        // query-string resolver is wired (e.g. isolated unit construction).
        $paginator->withQueryString();

        if (!$paginator->hasPages()) {
            return '';
        }

        $elements = $this->elements($paginator);

        if ($env->getLoader()->exists(self::TEMPLATE)) {
            return $env->render(self::TEMPLATE, [
                'paginator' => $paginator,
                'elements' => $elements,
            ]);
        }

        return $this->defaultHtml($paginator, $elements);
    }

    /**
     * The windowed page list: current ±2 plus first/last, with 'gap' entries
     * where pages are elided. Empty for simple paginators (no lastPage()).
     *
     * @param PaginatorContract<array-key, mixed> $paginator
     * @return list<array{type: string, page?: int, url?: string, active?: bool}>
     */
    private function elements(PaginatorContract $paginator): array
    {
        if (!$paginator instanceof LengthAwarePaginator) {
            return [];
        }

        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $from = max(1, $current - self::ON_EACH_SIDE);
        $to = min($last, $current + self::ON_EACH_SIDE);

        $elements = [];

        if ($from > 1) {
            $elements[] = $this->pageElement($paginator, 1, $current);
            if ($from > 2) {
                $elements[] = ['type' => 'gap'];
            }
        }

        for ($page = $from; $page <= $to; $page++) {
            $elements[] = $this->pageElement($paginator, $page, $current);
        }

        if ($to < $last) {
            if ($to < $last - 1) {
                $elements[] = ['type' => 'gap'];
            }
            $elements[] = $this->pageElement($paginator, $last, $current);
        }

        return $elements;
    }

    /**
     * @param LengthAwarePaginator<array-key, mixed> $paginator
     * @return array{type: string, page: int, url: string, active: bool}
     */
    private function pageElement(LengthAwarePaginator $paginator, int $page, int $current): array
    {
        return [
            'type' => 'page',
            'page' => $page,
            'url' => (string) $paginator->url($page),
            'active' => $page === $current,
        ];
    }

    /**
     * @param PaginatorContract<array-key, mixed> $paginator
     * @param list<array{type: string, page?: int, url?: string, active?: bool}> $elements
     */
    private function defaultHtml(PaginatorContract $paginator, array $elements): string
    {
        $html = '<nav class="pagination-nav" aria-label="Pagination"><ul class="pagination">';

        $previous = $paginator->previousPageUrl();
        $html .= is_string($previous) && $previous !== ''
            ? '<li class="page-item"><a class="page-link" href="' . $this->escape($previous) . '" rel="prev">&laquo;</a></li>'
            : '<li class="page-item disabled" aria-disabled="true"><span class="page-link">&laquo;</span></li>';

        foreach ($elements as $element) {
            if ($element['type'] === 'gap') {
                $html .= '<li class="page-item disabled" aria-disabled="true"><span class="page-link">&hellip;</span></li>';
                continue;
            }

            $page = (int) ($element['page'] ?? 0);
            $html .= ($element['active'] ?? false)
                ? '<li class="page-item active" aria-current="page"><span class="page-link">' . $page . '</span></li>'
                : '<li class="page-item"><a class="page-link" href="' . $this->escape((string) ($element['url'] ?? '')) . '">' . $page . '</a></li>';
        }

        $next = $paginator->nextPageUrl();
        $html .= is_string($next) && $next !== ''
            ? '<li class="page-item"><a class="page-link" href="' . $this->escape($next) . '" rel="next">&raquo;</a></li>'
            : '<li class="page-item disabled" aria-disabled="true"><span class="page-link">&raquo;</span></li>';

        return $html . '</ul></nav>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
