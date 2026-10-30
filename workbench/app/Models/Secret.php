<?php

namespace Workbench\App\Models;

use Aurora\Mcp\HiddenFromAi;
use Illuminate\Database\Eloquent\Model;

/**
 * A website's own model kept away from AI, whatever its policy says.
 */
#[HiddenFromAi]
class Secret extends Model
{
    protected $fillable = ['body'];
}
