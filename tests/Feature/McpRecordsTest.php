<?php

namespace Aurora\Tests\Feature;

use Aurora\Mcp\Servers\CmsServer;
use Aurora\Mcp\Tools\DeleteRecord;
use Aurora\Mcp\Tools\GetRecord;
use Aurora\Mcp\Tools\ListModels;
use Aurora\Mcp\Tools\ListRecords;
use Aurora\Mcp\Tools\SaveRecord;
use Aurora\Models\McpActivity;
use Aurora\Models\User;
use Aurora\Support\AiModels;
use Aurora\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Gadget;
use Workbench\App\Models\Note;
use Workbench\App\Policies\GadgetPolicy;
use Workbench\App\Resources\GadgetResource;

class McpRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['gadgets', 'notes', 'secrets'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('site_id')->nullable();
                $table->string('name')->nullable();
                $table->string('status')->default('draft');
                $table->text('notes')->nullable();
                $table->text('api_key')->nullable();
                $table->text('body')->nullable();
                $table->timestamps();
            });
        }

        config()->set('aurora.mcp.models', [__DIR__.'/../../workbench/app/Models' => 'Workbench\App\Models']);
        Gate::policy(Gadget::class, GadgetPolicy::class);
        AiModels::forget();
    }

    protected function tearDown(): void
    {
        AiModels::forget();

        parent::tearDown();
    }

    public function test_a_model_is_offered_only_to_those_its_policy_lets_see_it(): void
    {
        $editor = $this->userWithRole('Editor');

        // The Editor sees gadgets (their policy says so); notes have no policy, secrets are hidden.
        $this->assertSame(['gadget'], array_keys(AiModels::for($editor)));

        $models = CmsServer::actingAs($editor)->tool(ListModels::class)->assertOk();
        $models->assertSee('"model":"gadget"', false)->assertSee('"may_create":true', false)->assertDontSee('"model":"note"', false);

        // Fields the model hides, or encrypts, aren't even named.
        $models->assertSee('"name":"name"', false)->assertDontSee('"name":"notes"', false)->assertDontSee('"name":"api_key"', false);

        // Someone the policy keeps out gets no record tools at all, and a direct call is refused.
        $author = $this->userWithRole('Author');

        $this->assertSame([], AiModels::for($author));
        CmsServer::actingAs($author)->tool(ListRecords::class, ['model' => 'gadget'])->assertHasErrors(['not found']);
        CmsServer::actingAs($editor)->tool(ListRecords::class, ['model' => 'note'])->assertHasErrors(['No model "note" that you can work with']);
    }

    public function test_a_model_without_a_policy_is_for_super_admins_only(): void
    {
        $admin = $this->userWithRole(User::SUPER_ADMIN);

        // Not the hidden one, and not Aurora's own models, which have tools of their own.
        $this->assertSame(['gadget', 'note'], array_keys(AiModels::for($admin)));

        CmsServer::actingAs($admin)->tool(SaveRecord::class, ['model' => 'note', 'data' => ['body' => 'Call the printer']])->assertOk();
        $this->assertSame('Call the printer', Note::query()->sole()->body);
    }

    public function test_records_are_read_and_changed_through_the_policy(): void
    {
        $editor = $this->userWithRole('Editor');

        CmsServer::actingAs($editor)->tool(SaveRecord::class, ['model' => 'gadget', 'data' => ['name' => 'Sprocket', 'status' => 'live']])
            ->assertOk()->assertSee('Created the gadget');
        $gadget = Gadget::query()->sole();
        $this->assertTrue(McpActivity::query()->where('tool', 'save_record')->where('subject_id', $gadget->id)->exists());

        // Hidden, encrypted and kept fields can't be written.
        CmsServer::actingAs($editor)->tool(SaveRecord::class, ['model' => 'gadget', 'id' => $gadget->id, 'data' => ['notes' => 'x', 'api_key' => 'y', 'site_id' => 'z']])
            ->assertHasErrors(['"notes" can\'t be set', '"api_key" can\'t be set', '"site_id" can\'t be set']);

        Gadget::query()->create(['name' => 'Widget', 'notes' => 'Only for us', 'api_key' => 'sk-123']);

        CmsServer::actingAs($editor)->tool(ListRecords::class, ['model' => 'gadget', 'search' => 'widg'])
            ->assertOk()->assertSee('Widget')->assertDontSee('Sprocket')->assertDontSee('Only for us')->assertDontSee('sk-123');
        CmsServer::actingAs($editor)->tool(ListRecords::class, ['model' => 'gadget', 'where' => ['status' => 'live']])
            ->assertOk()->assertSee('Sprocket')->assertDontSee('Widget');

        // The policy decides per record: a live gadget can't be deleted by an Editor.
        CmsServer::actingAs($editor)->tool(GetRecord::class, ['model' => 'gadget', 'id' => $gadget->id])
            ->assertOk()->assertSee('"may_update":true', false)->assertSee('"may_delete":false', false);
        CmsServer::actingAs($editor)->tool(DeleteRecord::class, ['model' => 'gadget', 'id' => $gadget->id])->assertHasErrors(['Permission denied']);
        $this->assertTrue(Gadget::query()->whereKey($gadget->id)->exists());

        CmsServer::actingAs($editor)->tool(SaveRecord::class, ['model' => 'gadget', 'id' => $gadget->id, 'data' => ['status' => 'retired']])->assertOk();
        CmsServer::actingAs($editor)->tool(DeleteRecord::class, ['model' => 'gadget', 'id' => $gadget->id])->assertOk();
        $this->assertFalse(Gadget::query()->whereKey($gadget->id)->exists());
    }

    public function test_a_model_with_a_resource_is_described_and_checked_by_its_form(): void
    {
        Filament::getPanel('admin')->resources([GadgetResource::class]);
        $editor = $this->userWithRole('Editor');

        CmsServer::actingAs($editor)->tool(ListModels::class)
            ->assertOk()->assertSee('"label":"Gadget name"', false)->assertSee('"options":{"draft":"Draft","live":"Live","retired":"Retired"}', false);

        CmsServer::actingAs($editor)->tool(SaveRecord::class, ['model' => 'gadget', 'data' => ['status' => 'broken']])
            ->assertHasErrors(['"gadget.status" must be one of: draft, live, retired', '"name" is required']);
        $this->assertFalse(Gadget::query()->exists());
    }
}
