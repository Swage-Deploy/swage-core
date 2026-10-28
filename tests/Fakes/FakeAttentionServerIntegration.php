<?php

namespace Tests\Fakes;

use App\Plugins\Attention;
use App\Plugins\HasAttention;
use Illuminate\Database\Eloquent\Collection;

/**
 * A server integration that says the servers given to it need looking at, standing in for a plugin with alerts.
 */
class FakeAttentionServerIntegration extends FakeServerIntegration implements HasAttention
{
    /**
     * How many times it was asked, to check servers are asked about together.
     */
    public int $asked = 0;

    /**
     * @param  array<int, Attention>  $items  By server id.
     */
    public function __construct(public array $items = [])
    {
        parent::__construct();
    }

    public function attention(Collection $servers): array
    {
        $this->asked++;

        return array_intersect_key($this->items, array_flip($servers->modelKeys()));
    }
}
