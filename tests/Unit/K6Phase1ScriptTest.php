<?php

namespace Tests\Unit;

use Tests\TestCase;

class K6Phase1ScriptTest extends TestCase
{
    public function test_phase1_k6_script_exists_and_documents_env_vars(): void
    {
        $scriptPath = base_path('k6/phase1-ingest.js');
        $readmePath = base_path('k6/README.md');

        $this->assertFileExists($scriptPath);
        $this->assertFileExists($readmePath);

        $script = (string) file_get_contents($scriptPath);
        $readme = (string) file_get_contents($readmePath);

        foreach (['BASE_URL', 'API_KEY', 'TARGET_RPS', 'DURATION'] as $envVar) {
            $this->assertStringContainsString($envVar, $script);
            $this->assertStringContainsString($envVar, $readme);
        }

        $this->assertStringContainsString('/api/leads', $script);
        $this->assertStringContainsString('X-Api-Key', $script);
        $this->assertStringContainsString('3000', $script);
    }
}
