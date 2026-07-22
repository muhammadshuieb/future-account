<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppCloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_unconfigured_by_default(): void
    {
        putenv('WHATSAPP_TOKEN=');
        putenv('WHATSAPP_PHONE_NUMBER_ID=');

        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/whatsapp/status')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
    }

    public function test_send_uploads_media_when_configured(): void
    {
        putenv('WHATSAPP_TOKEN=test-token');
        putenv('WHATSAPP_PHONE_NUMBER_ID=12345');

        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'media-1'], 200),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $file = UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf');

        $this->post('/api/whatsapp/send', [
            'phone' => '0944123456',
            'caption' => 'فاتورة',
            'file' => $file,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.media_id', 'media-1');

        Http::assertSentCount(2);
    }

    public function test_normalize_syrian_phone(): void
    {
        $svc = app(WhatsAppCloudService::class);
        $this->assertSame('963944123456', $svc->normalizePhone('0944123456'));
        $this->assertSame('963944123456', $svc->normalizePhone('+963 944 123 456'));
    }
}
