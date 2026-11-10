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
class ReadDoc extends SwageTool
{
    protected string $description = 'Read a page of the Swage documentation as Markdown. Links such as /docs/servers point to other pages, whose slug is the last part.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('The page\'s slug, such as "deployments", from search-docs.')->enum($this->docs()->pages()->keys()->all())->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $slug = (string) $request->get('page');
        $markdown = $this->docs()->read($slug);

        return $markdown === null
            ? Response::error("There is no page \"{$slug}\". The pages are: ".$this->docs()->pages()->keys()->implode(', ').'.')
            : Response::text($markdown."\n\n---\nPublished at ".$this->docs()->url($slug));
    }

    protected function docs(): Documentation
    {
        return app(Documentation::class);
    }
}
