<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A website's own model without a policy: for Super Admins only.
 */
class Note extends Model
{
    protected $fillable = ['body'];
}
