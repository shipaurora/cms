<?php

namespace Aurora\Models\Concerns;

/**
 * The person's own preferences that Aurora stores on the users table: their dashboard and
 * their admin theme. A website's own User model (App\Models\User in the starter app) extends
 * Aurora's but declares its own casts() and #[Fillable], which replace Aurora's rather than
 * add to them; without this the dashboard is saved as a flattened array and read back as
 * text, and the admin theme is silently left out when the profile is saved.
 */
trait KeepsAuroraPreferences
{
    protected function initializeKeepsAuroraPreferences(): void
    {
        $this->mergeCasts(['dashboard' => 'array']);
        $this->mergeFillable(['admin_theme']);
    }
}
