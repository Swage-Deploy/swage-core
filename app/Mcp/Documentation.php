<?php

namespace App\Mcp;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The Swage documentation, copied into each release by `swage:sync-docs` so it always describes the running version.
 * The same pages are published at swagedeploy.app/docs.
 */
class Documentation
{
    public const string SITE = 'https://swagedeploy.app/docs';

    /**
     * @var Collection<string, array{slug: string, title: string, section: string}>|null
     */
    protected ?Collection $pages = null;

    public function __construct(protected ?string $path = null)
    {
        $this->path ??= config('swage.docs_path', resource_path('docs'));
    }

    /**
     * Every page, in the order the docs list them, keyed by slug.
     *
     * @return Collection<string, array{slug: string, title: string, section: string}>
     */
    public function pages(): Collection
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        $index = File::exists("{$this->path}/index.json") ? json_decode(File::get("{$this->path}/index.json"), true) : [];

        return $this->pages = collect($index['sections'] ?? [])
            ->flatMap(fn (array $section): array => array_map(fn (array $page): array => [...$page, 'section' => $section['title']], $section['pages']))
            ->filter(fn (array $page): bool => preg_match('/^[a-z0-9-]+$/', $page['slug']) === 1 && File::exists("{$this->path}/{$page['slug']}.md"))
            ->keyBy('slug');
    }

    /**
     * A page's Markdown, or null when there is no such page.
     */
    public function read(string $slug): ?string
    {
        return $this->pages()->has($slug) ? File::get("{$this->path}/{$slug}.md") : null;
    }

    public function url(string $slug): string
    {
        return self::SITE.'/'.$slug;
    }

    /**
     * The pages that best match a search, best first, each with the section that matched it most.
     *
     * @return list<array{slug: string, title: string, section: string, url: string, heading: ?string, excerpt: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $terms = collect(preg_split('/[^\pL\pN.]+/u', Str::lower($query)))
            ->filter(fn (string $term): bool => mb_strlen($term) > 1)
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            return [];
        }

        return $this->pages()
            ->flatMap(fn (array $page): array => $this->sections($page['slug'])->map(fn (array $section): array => [
                'page' => $page,
                'heading' => $section['heading'],
                'body' => $section['body'],
                'score' => $this->score($terms, $page['title'], $section['heading'] ?? '', $section['body']),
            ])->all())
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortByDesc('score')
            ->unique(fn (array $match): string => $match['page']['slug'])
            ->take($limit)
            ->map(fn (array $match): array => [
                'slug' => $match['page']['slug'],
                'title' => $match['page']['title'],
                'section' => $match['page']['section'],
                'url' => $this->url($match['page']['slug']).($match['heading'] ? '#'.Str::slug($match['heading']) : ''),
                'heading' => $match['heading'],
                'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', $match['body'])), 600),
            ])
            ->values()
            ->all();
    }

    /**
     * A page split at its second and third level headings.
     *
     * @return Collection<int, array{heading: ?string, body: string}>
     */
    protected function sections(string $slug): Collection
    {
        $parts = preg_split('/^#{2,3}\s+(.+)$/m', $this->read($slug) ?? '', -1, PREG_SPLIT_DELIM_CAPTURE);
        $sections = [['heading' => null, 'body' => array_shift($parts)]];

        foreach (array_chunk($parts, 2) as [$heading, $body]) {
            $sections[] = ['heading' => trim($heading), 'body' => $body ?? ''];
        }

        return collect($sections);
    }

    /**
     * @param  Collection<int, string>  $terms
     */
    protected function score(Collection $terms, string $title, string $heading, string $body): int
    {
        [$title, $heading, $body] = [Str::lower($title), Str::lower($heading), Str::lower($body)];

        return $terms->sum(fn (string $term): int => min(substr_count($body, $term), 10)
            + (str_contains($heading, $term) ? 8 : 0)
            + (str_contains($title, $term) ? 5 : 0));
    }
}
