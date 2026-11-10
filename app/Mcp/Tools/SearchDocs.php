<?php

namespace App\Mcp\Tools;

use App\Mcp\Documentation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class SearchDocs extends SwageTool
{
    protected string $description = 'Search the Swage documentation for this panel\'s version, such as "certificate not renewing" or "queue worker". Returns the best matching pages with the section that matched. Read a whole page with read-doc.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to look for.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $results = $this->docs()->search((string) $request->get('query'));

        return $results === []
            ? Response::text('No pages matched. Try other words, or read-doc with one of: '.$this->docs()->pages()->keys()->implode(', ').'.')
            : Response::json($results);
    }

    protected function docs(): Documentation
    {
        return app(Documentation::class);
    }
}
