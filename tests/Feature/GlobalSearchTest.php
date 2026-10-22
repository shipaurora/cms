<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Assets\Pages\ListAssets;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\Users\UserResource;
use Aurora\Models\Form;
use Aurora\Models\Page;
use Aurora\Support\AssetLibrary;
use Aurora\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_images_show_their_thumbnail_and_folder_in_search_results(): void
    {
        Storage::fake('public');
        $this->actingAs($this->userWithRole('Editor'));
        $asset = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg', 400, 300), ['folder' => 'Photos', 'alt' => 'A fjord']);

        $result = AssetResource::getGlobalSearchResults('fjord')->first();

        $this->assertStringContainsString('cms-search-thumb', (string) $result->title);
        $this->assertStringContainsString(e($asset->url('thumb')), (string) $result->title);
        $this->assertSame('Photos', $result->details['Folder']);
    }

    public function test_files_are_found_by_the_name_they_were_uploaded_with(): void
    {
        Storage::fake('public');
        $this->actingAs($this->userWithRole('Editor'));
        $report = AssetLibrary::fromFile($this->fixture('brand-guidelines.pdf'), ['title' => 'Quarterly numbers']);
        $other = AssetLibrary::fromUpload(UploadedFile::fake()->image('fjord.jpg'), ['title' => 'Fjord']);

        $results = AssetResource::getGlobalSearchResults('guidelines');

        $this->assertCount(1, $results);
        $this->assertStringContainsString($report->getKey(), $results->first()->url);

        Livewire::test(ListAssets::class)
            ->searchTable('brand-guide')
            ->assertCanSeeTableRecords([$report])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_people_show_their_avatar_and_role_in_search_results(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        $this->userWithRole('Author', ['name' => 'Hanna Author', 'email' => 'hanna@example.com']);

        $result = UserResource::getGlobalSearchResults('Hanna')->first();

        $this->assertStringContainsString('cms-search-thumb cms-search-thumb-round', (string) $result->title);
        $this->assertStringContainsString('Hanna Author', (string) $result->title);
        $this->assertSame('Author', $result->details['Role']);
    }

    public function test_results_without_a_picture_show_their_sidebar_icon(): void
    {
        $this->actingAs($this->userWithRole('Super Admin'));
        Page::factory()->create(['title' => 'Careers']);
        Form::factory()->create(['name' => 'Careers application']);

        $page = PageResource::getGlobalSearchResults('Careers')->first();
        $form = FormResource::getGlobalSearchResults('Careers')->first();

        $this->assertStringContainsString('cms-search-thumb-icon', (string) $page->title);
        $this->assertStringContainsString('<svg', (string) $page->title);
        $this->assertStringContainsString('Careers', (string) $page->title);
        $this->assertStringContainsString('cms-search-thumb-icon', (string) $form->title);
    }
}
