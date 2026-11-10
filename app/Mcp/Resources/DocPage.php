<?php

namespace App\Mcp\Resources;

use App\Mcp\Documentation;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * A page of the Swage documentation, for clients that attach pages to a conversation, such as swage://docs/servers.
 */
#[MimeType('text/markdown')]
class DocPage extends Resource implements HasUriTemplate
{
    protected string $description = 'A page of the Swage documentation for this panel\'s version, by slug, such as swage://docs/deployments.';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('swage://docs/{page}');
    }

    public function handle(Request $request): Response
    {
        $markdown = $this->docs()->read((string) $request->get('page'));

        return $markdown === null ? Response::error('There is no such page.') : Response::text($markdown);
    }

    protected function docs(): Documentation
    {
        return app(Documentation::class);
    }
}
