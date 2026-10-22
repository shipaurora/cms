<?php

namespace Aurora\Tests\Feature;

use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Which admin screens each role may open (✓), screen by screen.
     *
     * @return array<string, array{string, array<string, bool>}>
     */
    public static function roles(): array
    {
        $screens = ['', 'ai-assistant', 'pages', 'assets', 'menus', 'forms', 'redirects', 'language-lines', 'mcp-activities', 'trash', 'site-settings', 'users-and-roles/users', 'users-and-roles/roles'];

        $matrix = [
            'Super Admin' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
            'Editor' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0],
            'Author' => [1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            'Viewer' => [1, 0, 1, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0],
        ];

        return collect($matrix)
            ->map(fn (array $access, string $role): array => [$role, array_combine($screens, array_map(boolval(...), $access))])
            ->all();
    }

    /**
     * @param  array<string, bool>  $screens
     */
    #[DataProvider('roles')]
    public function test_each_role_opens_only_its_screens(string $role, array $screens): void
    {
        // Something in the trash, so the trash page is there to open.
        Page::factory()->create()->delete();

        $this->actingAs($this->userWithRole($role));

        foreach ($screens as $screen => $allowed) {
            $status = $this->get(rtrim('/admin/main/'.$screen, '/'))->status();

            $this->assertSame($allowed ? 200 : 403, $status, "{$role} opening /{$screen}");
        }
    }
}
