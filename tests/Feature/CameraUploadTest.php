<?php

namespace Tests\Feature;

use App\Models\CustomerDocument;
use App\Models\DocumentRequest;
use App\Support\Uploads\CameraUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrmFixtures;
use Tests\TestCase;

/** 25 Nov: camera scan script registered; JPEG still accepted as a normal upload (no headless camera). */
class CameraUploadTest extends TestCase
{
    use CrmFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('uploads');
        $this->seedRoles();
    }

    public function test_camera_script_tag_points_at_public_js(): void
    {
        $this->assertFileExists(public_path('js/rp-camera.js'));
        $tag = CameraUpload::scriptTag();
        $this->assertStringContainsString('js/rp-camera.js', $tag);
        $this->assertStringContainsString('<script', $tag);
    }

    public function test_with_scan_adds_button_and_keeps_file_upload(): void
    {
        $upload = FileUpload::make('path')->disk('private')->directory('docs')->visibility('private');
        [$field, $button] = CameraUpload::withScan($upload, 'path');

        $this->assertSame($upload, $field);
        $this->assertSame('path', $field->getExtraAttributes()['data-rp-camera-target'] ?? null);
        $this->assertSame('_camera_path', $button->getName());
        $this->assertInstanceOf(\Filament\Forms\Components\ViewField::class, $button);
    }

    public function test_secure_upload_page_shows_scan_with_camera_and_accepts_jpeg(): void
    {
        $customer = $this->customer();
        $link = DocumentRequest::issue($customer, ['passport'], 72);
        $url = $link->url();

        $this->get($url)
            ->assertOk()
            ->assertSee('Scan with camera', false)
            ->assertSee('rp-camera.js', false)
            ->assertSee('data-rp-camera-input="upload-file"', false);

        // JPEG as a normal file (no headless camera). create() avoids needing GD for fake()->image().
        $this->post($url, [
            'type' => 'passport',
            'file' => UploadedFile::fake()->create('scan_20261125_120000.jpg', 200, 'image/jpeg'),
        ])->assertRedirect();

        $doc = CustomerDocument::first();
        $this->assertNotNull($doc);
        $this->assertSame('passport', $doc->type);
        Storage::disk('private')->assertExists($doc->path);
    }

    public function test_crm_loads_camera_script_for_staff(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->get('/crm')
            ->assertOk()
            ->assertSee('rp-camera.js', false);
    }
}
