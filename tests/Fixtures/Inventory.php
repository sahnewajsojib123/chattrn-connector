<?php

namespace Chattrn\Connector\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'test_inventory';
    protected $guarded = [];
}
