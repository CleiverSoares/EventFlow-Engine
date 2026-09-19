<?php

namespace Tests\Unit;

use Tests\TestCase;

class K6Phase2ScriptTest extends TestCase
{
    public function test_phase2_k6_script_exists_and_documents_env_vars(): void
    {
        $scriptPath = base_path('k6/phase2-ingest.js');
        $readmePath = base_path('k6/README.md');
        $notesPath = base_path('k6/results/SAMPLE-phase2-notes.md');

        $this->assertFileExists($scriptPath);
        $this->assertFileExists($readmePath);
        $this->assertFileExists($notesPath);

        $script = (string) file_get_contents($scriptPath);
        $readme = (string) file_get_contents($readmePath);
        $notes = (string) file_get_contents($notesPath);

        foreach (['BASE_URL', 'API_KEY', 'TARGET_RPS', 'DURATION'] as $envVar) {
            $this->assertStringContainsString($envVar, $script);
            $this->assertStringContainsString($envVar, $readme);
        }

        $this->assertStringContainsString('/api/leads', $script);
        $this->assertStringContainsString('X-Api-Key', $script);
        $this->assertStringContainsString('3000', $script);
        $this->assertStringContainsString('202', $script);
        $this->assertStringContainsString('eventflow:relay-outbox', $script);
        $this->assertStringContainsString('eventflow:consume-leads', $script);

        $this->assertStringContainsString('Phase 2', $readme);
        $this->assertStringContainsString('relay', $readme);
        $this->assertStringContainsString('Comparison vs Phase 1', $notes);
    }
}
